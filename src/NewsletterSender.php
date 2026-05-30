<?php
namespace CRMBizNewsletter;

defined('ABSPATH') || exit;

class NewsletterSender {

    private const BATCH_SIZE  = 50; // 1회 크론 실행당 최대 발송 수
    private const MAX_RETRIES = 3;  // 영구 실패 처리 전 최대 시도 횟수

    private Settings $settings;
    private EmailTemplateRenderer $renderer;

    public function __construct(Settings $settings) {
        $this->settings = $settings;
        $this->renderer = new EmailTemplateRenderer($settings);
    }

    /**
     * 포스트 발행 시 draft 레코드만 생성 (수동 발송 모드).
     */
    public function createDraftRecord(int $postId): int {
        $tagIds  = array_filter(array_map('intval', (array) get_post_meta($postId, '_crmbiz_nl_tag_ids',  true)));
        $listIds = array_filter(array_map('intval', (array) get_post_meta($postId, '_crmbiz_nl_list_ids', true)));

        return $this->createRecord($postId, $tagIds, $listIds, 0, 'draft');
    }

    /**
     * 즉시 발송용 queued 레코드 생성 — Cron이 sendFromRecord()로 실제 발송.
     */
    public function createQueuedRecord(int $postId): int {
        $tagIds  = array_filter(array_map('intval', (array) get_post_meta($postId, '_crmbiz_nl_tag_ids',  true)));
        $listIds = array_filter(array_map('intval', (array) get_post_meta($postId, '_crmbiz_nl_list_ids', true)));

        return $this->createRecord($postId, $tagIds, $listIds, 0, 'queued');
    }

    /**
     * 예약 발송용 scheduled 레코드 생성 — Cron이 지정 시각에 sendFromRecord()로 발송.
     */
    public function createScheduledRecord(int $postId, string $scheduledAt): int {
        $tagIds  = array_filter(array_map('intval', (array) get_post_meta($postId, '_crmbiz_nl_tag_ids',  true)));
        $listIds = array_filter(array_map('intval', (array) get_post_meta($postId, '_crmbiz_nl_list_ids', true)));

        return $this->createRecord($postId, $tagIds, $listIds, 0, 'scheduled', $scheduledAt);
    }

    /**
     * WP Cron에서 호출 — 배치 단위로 발송. true 반환 시 다음 배치 필요, false 반환 시 완료.
     */
    public function sendFromRecord(int $newsletterId): bool {
        if (!FluentCRMBridge::isAvailable()) {
            Logger::error('FluentCRM 비활성화 — 발송 실패 처리', ['nl_id' => $newsletterId]);
            global $wpdb;
            $wpdb->update(
                $wpdb->prefix . 'crmbiz_newsletters',
                ['status' => 'failed', 'updated_at' => current_time('mysql')],
                ['id' => $newsletterId, 'status' => 'queued'],
                ['%s', '%s'], ['%d', '%s']
            );
            return false;
        }

        global $wpdb;
        $record = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}crmbiz_newsletters WHERE id = %d",
                $newsletterId
            )
        );

        if (!$record || !in_array($record->status, ['queued', 'scheduled', 'sending'], true)) {
            Logger::warning('발송 레코드 없음 또는 잘못된 상태', ['nl_id' => $newsletterId, 'status' => $record ? $record->status : 'null']);
            return false;
        }

        $post    = get_post((int) $record->post_id);
        $tagIds  = array_filter(array_map('intval', json_decode($record->tag_ids,  true) ?? []));
        $listIds = array_filter(array_map('intval', json_decode($record->list_ids, true) ?? []));

        if (!$post || (empty($tagIds) && empty($listIds))) {
            Logger::error('포스트 없음 또는 수신자 미설정', ['nl_id' => $newsletterId, 'post_id' => (int) $record->post_id]);
            return false;
        }

        // 동시 실행 방지 — GET_LOCK(timeout=0): 이미 다른 프로세스가 실행 중이면 즉시 포기
        $lockName = $wpdb->prefix . 'crmbiz_nl_send_' . $newsletterId;
        $got = (int) $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s, 0)", $lockName));
        if ($got !== 1) {
            Logger::info('발송 스킵: 다른 프로세스가 이미 처리 중', ['nl_id' => $newsletterId]);
            return false;
        }

        try {

        // 큐가 비어있으면 구독자 목록으로 채움 (INSERT IGNORE — 재시작 시 중복 방지)
        $queued = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}crmbiz_nl_queue WHERE newsletter_id = %d",
            $newsletterId
        ));
        if ($queued === 0) {
            $this->populateQueue($newsletterId, $tagIds, $listIds);
        } elseif ($record->status !== 'sending') {
            $wpdb->update(
                $wpdb->prefix . 'crmbiz_newsletters',
                ['status' => 'sending'],
                ['id' => $newsletterId],
                ['%s'], ['%d']
            );
        }

        // 이번 배치 큐에서 꺼내기
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, email, retry_count FROM {$wpdb->prefix}crmbiz_nl_queue
             WHERE newsletter_id = %d
             ORDER BY id ASC
             LIMIT %d",
            $newsletterId, self::BATCH_SIZE
        ), ARRAY_A);

        if (empty($rows)) {
            $this->finalizeSend($newsletterId);
            return false;
        }

        // 이메일 → 큐 행 맵 (O(1) 조회용)
        $emailToRow = [];
        foreach ($rows as $row) {
            $emailToRow[$row['email']] = $row;
        }

        $subscribers = $this->getSubscribersByEmails(array_keys($emailToRow));
        $success     = 0;
        $fail        = 0;
        $toDelete    = []; // 즉시 삭제할 큐 ID (성공·영구실패·수신거부)
        $toRetry     = []; // retry_count만 증가시킬 큐 ID (재시도 대상)

        foreach ($subscribers as $subscriber) {
            $email = $subscriber->email;
            if (!isset($emailToRow[$email])) {
                continue;
            }
            $row = $emailToRow[$email];
            unset($emailToRow[$email]); // 처리됨 표시

            if (UnsubscribeHandler::isUnsubscribed($email)) {
                $toDelete[] = (int) $row['id']; // 수신거부 → 삭제, fail 카운트 없음
                continue;
            }

            $sent = $this->dispatch($post, $subscriber, $newsletterId);
            TrackingHandler::recordSend($newsletterId, $email, $sent);

            if ($sent) {
                $success++;
                $toDelete[] = (int) $row['id'];
            } elseif ((int) $row['retry_count'] + 1 >= self::MAX_RETRIES) {
                $fail++;
                $toDelete[] = (int) $row['id'];
                Logger::error('이메일 영구 실패 (재시도 초과)', ['email' => $email, 'nl_id' => $newsletterId, 'retries' => self::MAX_RETRIES]);
            } else {
                $toRetry[] = (int) $row['id']; // 다음 배치에서 재시도
            }
        }

        // FluentCRM에서 찾지 못한 이메일 → 구독자 삭제됨, 조용히 건너뜀
        foreach ($emailToRow as $row) {
            $toDelete[] = (int) $row['id'];
        }

        if (!empty($toDelete)) {
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->prefix}crmbiz_nl_queue WHERE id IN ("
                    . implode(',', array_fill(0, count($toDelete), '%d')) . ")",
                    ...$toDelete
                )
            );
        }

        if (!empty($toRetry)) {
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$wpdb->prefix}crmbiz_nl_queue SET retry_count = retry_count + 1 WHERE id IN ("
                    . implode(',', array_fill(0, count($toRetry), '%d')) . ")",
                    ...$toRetry
                )
            );
        }

        // 카운터 누적 업데이트
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}crmbiz_newsletters
             SET success_count = success_count + %d,
                 fail_count    = fail_count    + %d,
                 updated_at    = %s
             WHERE id = %d",
            $success, $fail, current_time('mysql'), $newsletterId
        ));

        // 남은 큐 확인
        $remaining = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}crmbiz_nl_queue WHERE newsletter_id = %d",
            $newsletterId
        ));

        if ($remaining > 0) {
            return true;
        }

        $this->finalizeSend($newsletterId);
        return false;

        } finally {
            $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lockName));
        }
    }

    private function populateQueue(int $newsletterId, array $tagIds, array $listIds): void {
        global $wpdb;

        $allEmails = $this->getSubscriberEmails($tagIds, $listIds);
        if (empty($allEmails)) {
            Logger::warning('수신자 없음 — 발송 중단', ['nl_id' => $newsletterId]);
            global $wpdb;
            $wpdb->update(
                $wpdb->prefix . 'crmbiz_newsletters',
                ['status' => 'failed', 'sent_at' => current_time('mysql'), 'updated_at' => current_time('mysql')],
                ['id' => $newsletterId],
                ['%s', '%s', '%s'], ['%d']
            );
            return;
        }

        $table = $wpdb->prefix . 'crmbiz_nl_queue';
        foreach (array_chunk($allEmails, 200) as $chunk) {
            $values = [];
            foreach ($chunk as $email) {
                $values[] = $newsletterId;
                $values[] = $email;
            }
            $wpdb->query(
                $wpdb->prepare(
                    "INSERT IGNORE INTO {$table} (newsletter_id, email) VALUES "
                    . implode(',', array_fill(0, count($chunk), '(%d,%s)')),
                    ...$values
                )
            );
        }

        $wpdb->update(
            $wpdb->prefix . 'crmbiz_newsletters',
            ['status' => 'sending', 'recipient_count' => count($allEmails)],
            ['id' => $newsletterId],
            ['%s', '%d'], ['%d']
        );
    }

    private function finalizeSend(int $newsletterId): void {
        global $wpdb;

        $final = $wpdb->get_row($wpdb->prepare(
            "SELECT post_id, success_count, fail_count FROM {$wpdb->prefix}crmbiz_newsletters WHERE id = %d",
            $newsletterId
        ));

        if (!$final) {
            return;
        }

        $status = ((int)$final->success_count === 0 && (int)$final->fail_count > 0) ? 'failed' : 'sent';

        $wpdb->update(
            $wpdb->prefix . 'crmbiz_newsletters',
            ['status' => $status, 'sent_at' => current_time('mysql'), 'updated_at' => current_time('mysql')],
            ['id' => $newsletterId],
            ['%s', '%s', '%s'], ['%d']
        );

        $wpdb->delete($wpdb->prefix . 'crmbiz_nl_queue', ['newsletter_id' => $newsletterId], ['%d']);

        $this->notifyAdmin($newsletterId, (int) $final->post_id, (int) $final->success_count, (int) $final->fail_count, $status);
    }

    private function notifyAdmin(int $newsletterId, int $postId, int $success, int $fail, string $status): void {
        if ($this->settings->isDryRun()) {
            return;
        }

        $adminEmail = $this->settings->getNotifyEmail();
        if (!is_email($adminEmail)) {
            return;
        }

        $title   = get_the_title($postId) ?: "Newsletter #{$newsletterId}";
        $label   = $status === 'sent' ? '발송 완료' : '발송 실패';
        $histUrl = admin_url('admin.php?page=crmbiz-nl-history');
        $total   = $success + $fail;
        $rate    = $total > 0 ? round($success / $total * 100) : 0;

        $body = sprintf(
            '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:32px;background:#f3f4f6">' .
            '<div style="max-width:540px;margin:0 auto;background:#fff;padding:32px;border-radius:8px">' .
            '<h2 style="color:#1a1a2e;margin:0 0 16px">뉴스레터 %s</h2>' .
            '<p style="color:#555;margin:0 0 20px">아래 뉴스레터 발송이 완료되었습니다.</p>' .
            '<table style="font-size:14px;color:#374151;border-collapse:collapse;width:100%%">' .
            '<tr><td style="padding:6px 16px 6px 0;color:#6b7280">제목</td><td>%s</td></tr>' .
            '<tr><td style="padding:6px 16px 6px 0;color:#6b7280">성공</td><td style="color:#0f5132">%s 건</td></tr>' .
            '<tr><td style="padding:6px 16px 6px 0;color:#6b7280">실패</td><td style="color:%s">%s 건</td></tr>' .
            '<tr><td style="padding:6px 16px 6px 0;color:#6b7280">성공률</td><td>%s%%</td></tr>' .
            '</table>' .
            '<p style="margin:24px 0 0"><a href="%s" style="background:#1d4ed8;color:#fff;padding:10px 20px;border-radius:6px;text-decoration:none;font-size:14px">발송 이력 보기</a></p>' .
            '</div></body></html>',
            esc_html($label),
            esc_html($title),
            number_format($success),
            $fail > 0 ? '#842029' : '#6b7280',
            number_format($fail),
            $rate,
            esc_url($histUrl)
        );

        $fromName  = str_replace(["\r", "\n"], '', $this->settings->getFromName());
        $fromEmail = str_replace(["\r", "\n"], '', $this->settings->getFromEmail());
        wp_mail(
            $adminEmail,
            '[CRMBiz Newsletter] ' . $label . ': ' . $title,
            $body,
            [
                'Content-Type: text/html; charset=UTF-8',
                'From: ' . $fromName . ' <' . $fromEmail . '>',
            ]
        );
    }

    /**
     * 특정 수신자 한 명에게만 재발송.
     */
    public function sendToEmail(int $newsletterId, string $email): bool {
        global $wpdb;
        $record = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}crmbiz_newsletters WHERE id = %d",
            $newsletterId
        ));
        if (!$record) {
            return false;
        }
        $post = get_post((int) $record->post_id);
        if (!$post) {
            return false;
        }
        if (UnsubscribeHandler::isUnsubscribed($email)) {
            return false;
        }

        $subscriber = null;
        if (FluentCRMBridge::isAvailable()) {
            $api = FluentCRMBridge::getContactsApi();
            if ($api) {
                $subscriber = $api->getContact($email);
            }
        }
        if (!$subscriber) {
            $subscriber = (object) ['email' => $email, 'first_name' => '', 'last_name' => '', 'full_name' => ''];
        }

        $sent = $this->dispatch($post, $subscriber, $newsletterId);
        TrackingHandler::recordSend($newsletterId, $email, $sent);
        return $sent;
    }

    // 전체 구독자 이메일 주소만 조회 — 발송 시작 시 1회만 호출
    private function getSubscriberEmails(array $tagIds, array $listIds): array {
        try {
            $query = new \FluentCrm\App\Services\ContactsQuery([
                'tags'     => $tagIds,
                'lists'    => $listIds,
                'statuses' => ['subscribed'],
            ]);
            return $query->get()->pluck('email')->toArray();
        } catch (\Throwable $e) {
            Logger::error('FluentCRM 구독자 조회 실패', ['error' => $e->getMessage()]);
            return [];
        }
    }

    // 배치 이메일 목록으로 구독자 객체 조회 — 배치당 1회, batch_size건만 조회
    private function getSubscribersByEmails(array $emails) {
        if (empty($emails)) {
            return new \Illuminate\Support\Collection();
        }
        try {
            return \FluentCrm\App\Models\Subscriber::whereIn('email', $emails)
            ->where('status', 'subscribed')
            ->get();
        } catch (\Throwable $e) {
            Logger::error('FluentCRM 배치 구독자 조회 실패', ['error' => $e->getMessage()]);
            return new \Illuminate\Support\Collection();
        }
    }

    private function dispatch(\WP_Post $post, $subscriber, int $newsletterId = 0): bool {
        $html = $this->renderer->render($post, $subscriber, $newsletterId);

        if ($this->settings->isDryRun()) {
            FluentCRMBridge::debugLog(
                'CRMBiz Newsletter',
                'DRY-RUN To=[redacted] Post=' . $post->ID
            );
            return true;
        }

        $fromName  = str_replace(["\r", "\n"], '', $this->settings->getFromName());
        $fromEmail = str_replace(["\r", "\n"], '', $this->settings->getFromEmail());
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $fromName . ' <' . $fromEmail . '>',
        ];

        $result = (bool) wp_mail($subscriber->email, get_the_title($post), $html, $headers);
        if (!$result) {
            Logger::error('wp_mail 발송 실패', ['email' => $subscriber->email, 'nl_id' => $newsletterId]);
        }
        return $result;
    }

    private function createRecord(int $postId, array $tagIds, array $listIds, int $recipientCount, string $status, string $scheduledAt = ''): int {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'crmbiz_newsletters',
            [
                'post_id'         => $postId,
                'status'          => $status,
                'send_mode'       => (string) get_post_meta($postId, '_crmbiz_nl_send_mode', true) ?: 'immediate',
                'scheduled_at'    => $scheduledAt ?: null,
                'recipient_count' => $recipientCount,
                'tag_ids'         => wp_json_encode(array_values($tagIds)),
                'list_ids'        => wp_json_encode(array_values($listIds)),
                'created_at'      => current_time('mysql'),
                'updated_at'      => current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s']
        );

        return (int) $wpdb->insert_id;
    }

}
