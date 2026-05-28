<?php
namespace CRMBizNewsletter;

defined('ABSPATH') || exit;

class NewsletterSender {

    private const BATCH_SIZE = 50; // 1회 크론 실행당 최대 발송 수

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
     * WP Cron에서 호출 — 배치 단위로 발송 후 다음 배치 오프셋 반환 (0 = 완료).
     */
    public function sendFromRecord(int $newsletterId, int $offset = 0): int {
        if (!FluentCRMBridge::isAvailable()) {
            return 0;
        }

        global $wpdb;
        $record = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}crmbiz_newsletters WHERE id = %d",
                $newsletterId
            )
        );

        if (!$record || !in_array($record->status, ['queued', 'scheduled', 'sending'], true)) {
            return 0;
        }

        $post    = get_post((int) $record->post_id);
        $tagIds  = array_filter(array_map('intval', json_decode($record->tag_ids,  true) ?? []));
        $listIds = array_filter(array_map('intval', json_decode($record->list_ids, true) ?? []));

        if (!$post || (empty($tagIds) && empty($listIds))) {
            return 0;
        }

        $subscribers = $this->getSubscribers($tagIds, $listIds);
        $total       = $subscribers->count();

        // 첫 배치: 상태를 sending으로 전환하고 총 수신자 수 기록
        if ($offset === 0) {
            $wpdb->update(
                $wpdb->prefix . 'crmbiz_newsletters',
                ['status' => 'sending', 'recipient_count' => $total],
                ['id' => $newsletterId],
                ['%s', '%d'], ['%d']
            );
        }

        $batch   = $subscribers->slice($offset, self::BATCH_SIZE);
        $success = 0;
        $fail    = 0;

        foreach ($batch as $subscriber) {
            if (UnsubscribeHandler::isUnsubscribed($subscriber->email)) {
                continue;
            }
            $sent = $this->dispatch($post, $subscriber, $newsletterId);
            TrackingHandler::recordSend($newsletterId, $subscriber->email, $sent);
            $sent ? $success++ : $fail++;
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

        $nextOffset = $offset + self::BATCH_SIZE;

        if ($nextOffset < $total) {
            return $nextOffset; // 다음 배치 오프셋 반환
        }

        // 마지막 배치 — 최종 상태 업데이트
        $final = $wpdb->get_row($wpdb->prepare(
            "SELECT success_count, fail_count FROM {$wpdb->prefix}crmbiz_newsletters WHERE id = %d",
            $newsletterId
        ));

        $wpdb->update(
            $wpdb->prefix . 'crmbiz_newsletters',
            [
                'status'     => ((int)$final->success_count === 0 && (int)$final->fail_count > 0) ? 'failed' : 'sent',
                'sent_at'    => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $newsletterId],
            ['%s', '%s', '%s'], ['%d']
        );

        return 0;
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

    private function getSubscribers(array $tagIds, array $listIds) {
        try {
            $query = new \FluentCrm\App\Services\ContactsQuery([
                'tags'     => $tagIds,
                'lists'    => $listIds,
                'statuses' => ['subscribed'],
            ]);
            return $query->get();
        } catch (\Throwable $e) {
            return new \Illuminate\Support\Collection();
        }
    }

    private function dispatch(\WP_Post $post, $subscriber, int $newsletterId = 0): bool {
        $html = $this->renderer->render($post, $subscriber, $newsletterId);

        if ($this->settings->isDryRun()) {
            FluentCRMBridge::debugLog(
                'CRMBiz Newsletter',
                'DRY-RUN To=' . $subscriber->email . ' Post=' . $post->ID
            );
            return true;
        }

        $fromName  = str_replace(["\r", "\n"], '', $this->settings->getFromName());
        $fromEmail = str_replace(["\r", "\n"], '', $this->settings->getFromEmail());
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $fromName . ' <' . $fromEmail . '>',
        ];

        return (bool) wp_mail($subscriber->email, get_the_title($post), $html, $headers);
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
