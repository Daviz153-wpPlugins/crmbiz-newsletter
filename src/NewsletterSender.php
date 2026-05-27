<?php
namespace CRMBizNewsletter;

defined('ABSPATH') || exit;

class NewsletterSender {

    private Settings $settings;
    private EmailTemplateRenderer $renderer;

    public function __construct(Settings $settings) {
        $this->settings = $settings;
        $this->renderer = new EmailTemplateRenderer($settings);
    }

    /**
     * 포스트 발행 시 즉시 발송.
     * DB 레코드를 생성하고 수신자 전원에게 wp_mail() 발송.
     */
    public function sendForPost(int $postId): void {
        if (!FluentCRMBridge::isAvailable()) {
            return;
        }

        $post = get_post($postId);
        if (!$post) {
            return;
        }

        $tagIds  = array_filter(array_map('intval', (array) get_post_meta($postId, '_crmbiz_nl_tag_ids',  true)));
        $listIds = array_filter(array_map('intval', (array) get_post_meta($postId, '_crmbiz_nl_list_ids', true)));

        if (empty($tagIds) && empty($listIds)) {
            return;
        }

        $subscribers = $this->getSubscribers($tagIds, $listIds);
        if ($subscribers->isEmpty()) {
            return;
        }

        $newsletterId = $this->createRecord($postId, $tagIds, $listIds, $subscribers->count(), 'sending');

        $success = 0;
        $fail    = 0;
        $errors  = [];

        foreach ($subscribers as $subscriber) {
            if (UnsubscribeHandler::isUnsubscribed($subscriber->email)) {
                continue;
            }

            if ($this->dispatch($post, $subscriber)) {
                $success++;
            } else {
                $fail++;
                $errors[] = $subscriber->email;
            }
        }

        $this->updateRecord($newsletterId, $success, $fail, $errors, $subscribers->count());
    }

    /**
     * 수동 발송: HistoryPage에서 트리거.
     * draft 상태 레코드를 찾아 발송 후 상태 갱신.
     */
    public function sendManual(int $newsletterId): array {
        if (!FluentCRMBridge::isAvailable()) {
            return ['success' => false, 'message' => 'FluentCRM이 활성화되지 않았습니다.'];
        }

        global $wpdb;
        $record = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}crmbiz_newsletters WHERE id = %d AND status = 'draft'",
                $newsletterId
            )
        );

        if (!$record) {
            return ['success' => false, 'message' => '발송 가능한 레코드를 찾을 수 없습니다.'];
        }

        $post    = get_post((int) $record->post_id);
        $tagIds  = array_filter(array_map('intval', json_decode($record->tag_ids,  true) ?? []));
        $listIds = array_filter(array_map('intval', json_decode($record->list_ids, true) ?? []));

        if (!$post) {
            return ['success' => false, 'message' => '포스트를 찾을 수 없습니다.'];
        }

        // 수신자 없이 발송하면 전체 구독자에게 발송되므로 반드시 가드
        if (empty($tagIds) && empty($listIds)) {
            return ['success' => false, 'message' => '수신자 태그/리스트가 지정되지 않았습니다.'];
        }

        // 상태를 sending으로 변경
        $wpdb->update(
            $wpdb->prefix . 'crmbiz_newsletters',
            ['status' => 'sending'],
            ['id' => $newsletterId],
            ['%s'], ['%d']
        );

        $subscribers = $this->getSubscribers($tagIds, $listIds);
        $success = 0;
        $fail    = 0;
        $errors  = [];

        foreach ($subscribers as $subscriber) {
            if (UnsubscribeHandler::isUnsubscribed($subscriber->email)) {
                continue;
            }
            if ($this->dispatch($post, $subscriber)) {
                $success++;
            } else {
                $fail++;
                $errors[] = $subscriber->email;
            }
        }

        $this->updateRecord($newsletterId, $success, $fail, $errors, $subscribers->count());

        return [
            'success' => true,
            'message' => "발송 완료: 성공 {$success}건, 실패 {$fail}건",
        ];
    }

    /**
     * 포스트 발행 시 draft 레코드만 생성 (수동 발송 모드).
     */
    public function createDraftRecord(int $postId): int {
        $tagIds  = array_filter(array_map('intval', (array) get_post_meta($postId, '_crmbiz_nl_tag_ids',  true)));
        $listIds = array_filter(array_map('intval', (array) get_post_meta($postId, '_crmbiz_nl_list_ids', true)));

        return $this->createRecord($postId, $tagIds, $listIds, 0, 'draft');
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

    private function dispatch(\WP_Post $post, $subscriber): bool {
        $html = $this->renderer->render($post, $subscriber);

        if ($this->settings->isDryRun()) {
            FluentCRMBridge::debugLog(
                'CRMBiz Newsletter',
                'DRY-RUN To=' . $subscriber->email . ' Post=' . $post->ID
            );
            return true;
        }

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $this->settings->getFromName() . ' <' . $this->settings->getFromEmail() . '>',
        ];

        return (bool) wp_mail($subscriber->email, get_the_title($post), $html, $headers);
    }

    private function createRecord(int $postId, array $tagIds, array $listIds, int $recipientCount, string $status): int {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'crmbiz_newsletters',
            [
                'post_id'         => $postId,
                'status'          => $status,
                'send_mode'       => (string) get_post_meta($postId, '_crmbiz_nl_send_mode', true) ?: 'immediate',
                'recipient_count' => $recipientCount,
                'tag_ids'         => wp_json_encode(array_values($tagIds)),
                'list_ids'        => wp_json_encode(array_values($listIds)),
                'created_at'      => current_time('mysql'),
                'updated_at'      => current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s']
        );

        return (int) $wpdb->insert_id;
    }

    private function updateRecord(int $newsletterId, int $success, int $fail, array $errors, int $total = 0): void {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'crmbiz_newsletters',
            [
                'status'          => ($success === 0 && $fail > 0) ? 'failed' : 'sent',
                'recipient_count' => $total,
                'success_count'   => $success,
                'fail_count'      => $fail,
                'sent_at'         => current_time('mysql'),
                'updated_at'      => current_time('mysql'),
                'error_log'       => !empty($errors) ? wp_json_encode($errors) : null,
            ],
            ['id' => $newsletterId],
            ['%s', '%d', '%d', '%d', '%s', '%s', '%s'],
            ['%d']
        );
    }
}
