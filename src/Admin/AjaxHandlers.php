<?php
namespace CRMBizNewsletter\Admin;

use CRMBizNewsletter\EmailTemplateRenderer;
use CRMBizNewsletter\FluentCRMBridge;
use CRMBizNewsletter\NewsletterSender;
use CRMBizNewsletter\Logger;
use CRMBizNewsletter\Settings;
use CRMBizNewsletter\Scheduler;

defined('ABSPATH') || exit;

class AjaxHandlers {

    private Settings $settings;
    private string $cronHook;

    public function __construct(Settings $settings, string $cronHook) {
        $this->settings = $settings;
        $this->cronHook = $cronHook;
    }

    private function requireAuth(string $nonce, string $cap = 'manage_options'): void {
        if (!check_ajax_referer($nonce, 'nonce', false)) {
            wp_send_json_error(['message' => '보안 검증 실패.'], 403);
        }
        if (!current_user_can($cap)) {
            wp_send_json_error(['message' => '권한이 없습니다.'], 403);
        }
    }

    public function handleTestEmail(): void {
        $this->requireAuth('crmbiz_nl_diagnostics');

        $to = sanitize_email($_POST['test_email'] ?? '');
        if (!is_email($to)) {
            wp_send_json_error(['message' => '유효하지 않은 이메일 주소입니다.']);
        }

        if ($this->settings->isDryRun()) {
            FluentCRMBridge::debugLog('CRMBiz Newsletter', 'DRY-RUN: 테스트 이메일 건너뜀. To: ' . $to);
            wp_send_json_success(['dry_run' => true, 'to' => $to]);
        }

        $fromName  = str_replace(["\r", "\n"], '', $this->settings->getFromName());
        $fromEmail = str_replace(["\r", "\n"], '', $this->settings->getFromEmail());
        $result = wp_mail(
            $to,
            '[테스트] CRMBiz Newsletter 발송 테스트',
            $this->buildTestEmailBody($to),
            ['Content-Type: text/html; charset=UTF-8', 'From: ' . $fromName . ' <' . $fromEmail . '>']
        );

        $result
            ? wp_send_json_success(['message' => '발송 성공: ' . $to])
            : wp_send_json_error(['message' => '발송 실패. FluentSMTP 설정을 확인하세요.']);
    }

    public function handleCountRecipients(): void {
        $this->requireAuth('crmbiz_nl_metabox', 'edit_posts');

        if (!FluentCRMBridge::isAvailable()) {
            wp_send_json_success(['count' => 0]);
        }

        $tagIds  = array_filter(array_map('intval', (array) ($_POST['tag_ids']  ?? [])));
        $listIds = array_filter(array_map('intval', (array) ($_POST['list_ids'] ?? [])));

        if (empty($tagIds) && empty($listIds)) {
            wp_send_json_success(['count' => 0]);
        }

        try {
            $query = new \FluentCrm\App\Services\ContactsQuery([
                'tags'     => $tagIds,
                'lists'    => $listIds,
                'statuses' => ['subscribed'],
            ]);
            wp_send_json_success(['count' => $query->getModel()->count()]);
        } catch (\Throwable $e) {
            Logger::error('수신자 수 조회 실패', ['error' => $e->getMessage()]);
            wp_send_json_success(['count' => 0]);
        }
    }

    public function handleManualSend(): void {
        $this->requireAuth('crmbiz_nl_manual_send');

        $newsletterId = (int) ($_POST['newsletter_id'] ?? 0);
        if ($newsletterId <= 0) {
            wp_send_json_error(['message' => '유효하지 않은 ID입니다.']);
        }

        if (!FluentCRMBridge::isAvailable()) {
            wp_send_json_error(['message' => 'FluentCRM이 활성화되지 않았습니다.']);
        }

        global $wpdb;
        $record = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}crmbiz_newsletters WHERE id = %d AND status = 'draft'",
                $newsletterId
            )
        );

        if (!$record) {
            wp_send_json_error(['message' => '발송 가능한 레코드를 찾을 수 없습니다.']);
        }

        $tagIds  = array_filter(array_map('intval', json_decode($record->tag_ids,  true) ?? []));
        $listIds = array_filter(array_map('intval', json_decode($record->list_ids, true) ?? []));

        if (empty($tagIds) && empty($listIds)) {
            wp_send_json_error(['message' => '수신자 태그/리스트가 지정되지 않았습니다.']);
        }

        if (!get_post((int) $record->post_id)) {
            wp_send_json_error(['message' => '포스트를 찾을 수 없습니다.']);
        }

        $wpdb->update(
            $wpdb->prefix . 'crmbiz_newsletters',
            ['status' => 'queued'],
            ['id' => $newsletterId],
            ['%s'], ['%d']
        );
        Scheduler::scheduleSingle(time(), $this->cronHook, [$newsletterId]);

        wp_send_json_success(['message' => '발송이 예약되었습니다. 잠시 후 이력 페이지에서 결과를 확인하세요.']);
    }

    public function handleResend(): void {
        $this->requireAuth('crmbiz_nl_manual_send');

        $newsletterId = (int) ($_POST['newsletter_id'] ?? 0);
        if ($newsletterId <= 0) {
            wp_send_json_error(['message' => '유효하지 않은 ID입니다.']);
        }

        global $wpdb;
        $record = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}crmbiz_newsletters WHERE id = %d",
                $newsletterId
            )
        );

        if (!$record) {
            wp_send_json_error(['message' => '레코드를 찾을 수 없습니다.']);
        }

        if (!FluentCRMBridge::isAvailable()) {
            wp_send_json_error(['message' => 'FluentCRM이 활성화되지 않았습니다.']);
        }

        $postId = (int) $record->post_id;
        if (!get_post($postId)) {
            wp_send_json_error(['message' => '포스트를 찾을 수 없습니다.']);
        }

        $tagIds  = array_filter(array_map('intval', (array) get_post_meta($postId, '_crmbiz_nl_tag_ids',  true)));
        $listIds = array_filter(array_map('intval', (array) get_post_meta($postId, '_crmbiz_nl_list_ids', true)));
        if (empty($tagIds) && empty($listIds)) {
            wp_send_json_error(['message' => '수신자 태그/리스트가 지정되지 않았습니다.']);
        }

        $newId = (new NewsletterSender($this->settings))->createQueuedRecord($postId);
        if ($newId <= 0) {
            wp_send_json_error(['message' => '레코드 생성에 실패했습니다.']);
        }
        Scheduler::scheduleSingle(time(), $this->cronHook, [$newId]);

        wp_send_json_success(['message' => '재발송이 예약되었습니다. 잠시 후 이력 페이지에서 결과를 확인하세요.']);
    }

    public function handleResendSingle(): void {
        $this->requireAuth('crmbiz_nl_resend_single');

        $newsletterId = (int) ($_POST['newsletter_id'] ?? 0);
        $email        = sanitize_email($_POST['email'] ?? '');

        if ($newsletterId <= 0 || !is_email($email)) {
            wp_send_json_error(['message' => '유효하지 않은 입력입니다.']);
        }

        $sent = (new NewsletterSender($this->settings))->sendToEmail($newsletterId, $email);

        $sent
            ? wp_send_json_success(['message' => $email . ' 재발송 완료'])
            : wp_send_json_error(['message' => $email . ' 발송 실패 (수신거부 또는 오류)']);
    }

    public function handleCancelSend(): void {
        $this->requireAuth('crmbiz_nl_manual_send');

        $newsletterId = (int) ($_POST['newsletter_id'] ?? 0);
        if ($newsletterId <= 0) {
            wp_send_json_error(['message' => '유효하지 않은 ID입니다.']);
        }

        global $wpdb;
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}crmbiz_newsletters
             SET status = 'cancelled'
             WHERE id = %d AND status IN ('queued', 'sending', 'scheduled')",
            $newsletterId
        ));

        if ($updated) {
            $wpdb->delete($wpdb->prefix . 'crmbiz_nl_queue', ['newsletter_id' => $newsletterId], ['%d']);
            Scheduler::unschedule($this->cronHook, [$newsletterId]);
            wp_send_json_success(['message' => '발송이 취소되었습니다.']);
        } else {
            wp_send_json_error(['message' => '이미 발송 완료되었거나 취소할 수 없는 상태입니다.']);
        }
    }

    public function handleGetLog(): void {
        $this->requireAuth('crmbiz_nl_get_log');

        $newsletterId = (int) ($_POST['newsletter_id'] ?? 0);
        if ($newsletterId <= 0) {
            wp_send_json_error([]);
        }

        wp_send_json_success(['html' => (new HistoryPage())->renderLogPublic($newsletterId)]);
    }

    public function handlePreviewEmail(): void {
        $postId = (int) ($_GET['post_id'] ?? 0);
        $nonce  = $_GET['nonce'] ?? '';

        if (!wp_verify_nonce($nonce, 'crmbiz_nl_preview_' . $postId)) {
            wp_die('보안 검증 실패.', '오류', ['response' => 403]);
        }
        if (!current_user_can('manage_options')) {
            wp_die('권한이 없습니다.', '오류', ['response' => 403]);
        }

        $post = get_post($postId);
        if (!$post) {
            wp_die('포스트를 찾을 수 없습니다.');
        }

        $dummy = (object) [
            'email'      => wp_get_current_user()->user_email,
            'first_name' => wp_get_current_user()->display_name,
            'last_name'  => '',
            'full_name'  => wp_get_current_user()->display_name,
        ];

        $html = (new EmailTemplateRenderer($this->settings))->render($post, $dummy);

        header('Content-Type: text/html; charset=UTF-8');
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $html;
        exit;
    }

    public function handleProgress(): void {
        $this->requireAuth('crmbiz_nl_progress');

        $ids = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));
        if (empty($ids)) {
            wp_send_json_error([]);
        }

        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, status, success_count, fail_count, recipient_count
                 FROM {$wpdb->prefix}crmbiz_newsletters
                 WHERE id IN ($placeholders)",
                ...$ids
            )
        );

        $data = [];
        foreach ($rows as $row) {
            $done  = (int) $row->success_count + (int) $row->fail_count;
            $total = (int) $row->recipient_count;
            $data[] = [
                'id'              => (int) $row->id,
                'status'          => $row->status,
                'done'            => $done,
                'recipient_count' => $total,
                'percent'         => $total > 0 ? min(100, (int) round($done / $total * 100)) : 0,
            ];
        }

        wp_send_json_success($data);
    }

    public function handleForceSend(): void {
        $this->requireAuth('crmbiz_nl_manual_send');

        $newsletterId = (int) ($_POST['newsletter_id'] ?? 0);
        if ($newsletterId <= 0) {
            wp_send_json_error(['message' => '유효하지 않은 ID입니다.']);
        }

        // 발송 가능한 상태인지 확인 (이미 완료된 뉴스레터 반복 발송 방지)
        global $wpdb;
        $status = $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$wpdb->prefix}crmbiz_newsletters WHERE id = %d",
            $newsletterId
        ));
        if (!in_array($status, ['queued', 'sending'], true)) {
            wp_send_json_error(['message' => '이미 발송 완료되었거나 발송 불가 상태입니다.']);
        }

        $hasMore = (new \CRMBizNewsletter\NewsletterSender($this->settings))->sendFromRecord($newsletterId);
        if ($hasMore && !Scheduler::isScheduled($this->cronHook, [$newsletterId])) {
            Scheduler::scheduleSingle(time() + 60, $this->cronHook, [$newsletterId]);
        }

        wp_send_json_success(['message' => '발송 실행됨', 'has_more' => $hasMore]);
    }

    public function handleTestNewsletter(): void {
        $this->requireAuth('crmbiz_nl_metabox', 'edit_posts');

        $postId = (int) ($_POST['post_id'] ?? 0);
        $to     = sanitize_email($_POST['test_email'] ?? '');

        if ($postId <= 0 || !is_email($to)) {
            wp_send_json_error(['message' => '유효하지 않은 입력입니다.']);
        }

        $post = get_post($postId);
        if (!$post) {
            wp_send_json_error(['message' => '포스트를 찾을 수 없습니다.']);
        }

        $currentUser = wp_get_current_user();
        $dummy = (object) [
            'email'      => $to,
            'first_name' => $currentUser->display_name,
            'last_name'  => '',
            'full_name'  => $currentUser->display_name,
        ];

        $html = (new EmailTemplateRenderer($this->settings))->render($post, $dummy);

        $fromName  = str_replace(["\r", "\n"], '', $this->settings->getFromName());
        $fromEmail = str_replace(["\r", "\n"], '', $this->settings->getFromEmail());
        $result    = wp_mail(
            $to,
            '[테스트] ' . get_the_title($post),
            $html,
            [
                'Content-Type: text/html; charset=UTF-8',
                'From: ' . $fromName . ' <' . $fromEmail . '>',
            ]
        );

        $result
            ? wp_send_json_success(['message' => esc_html($to) . '로 테스트 발송 완료'])
            : wp_send_json_error(['message' => '발송 실패. FluentSMTP 설정을 확인하세요.']);
    }


    public function handleDeleteNewsletter(): void {
        $this->requireAuth('crmbiz_nl_manual_send');

        $newsletterId = (int) ($_POST['newsletter_id'] ?? 0);
        if ($newsletterId <= 0) {
            wp_send_json_error(['message' => '유효하지 않은 ID입니다.']);
        }

        global $wpdb;

        $status = $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$wpdb->prefix}crmbiz_newsletters WHERE id = %d",
            $newsletterId
        ));
        if ($status === 'sending') {
            wp_send_json_error(['message' => '현재 발송 중입니다. 먼저 발송을 취소한 후 삭제하세요.']);
        }

        wp_clear_scheduled_hook($this->cronHook, [$newsletterId]);
        $wpdb->delete($wpdb->prefix . 'crmbiz_nl_queue',  ['newsletter_id' => $newsletterId], ['%d']);
        $wpdb->delete($wpdb->prefix . 'crmbiz_nl_events', ['newsletter_id' => $newsletterId], ['%d']);

        $deleted = (bool) $wpdb->delete($wpdb->prefix . 'crmbiz_newsletters', ['id' => $newsletterId], ['%d']);

        $deleted
            ? wp_send_json_success(['message' => '삭제되었습니다.'])
            : wp_send_json_error(['message' => '삭제 실패 또는 이미 삭제된 항목입니다.']);
    }

    public function handleUnsubRemove(): void {
        $this->requireAuth('crmbiz_nl_unsub_manage');

        global $wpdb;
        $table = $wpdb->prefix . 'crmbiz_nl_unsubscribers';

        // 단건 또는 일괄
        $ids = array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])));
        if (empty($ids) && isset($_POST['id'])) {
            $ids = [(int) $_POST['id']];
        }
        if (empty($ids)) {
            wp_send_json_error(['message' => '유효하지 않은 요청입니다.']);
        }

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $deleted = $wpdb->query(
            $wpdb->prepare("DELETE FROM {$table} WHERE id IN ($placeholders)", ...$ids)
        );

        $deleted
            ? wp_send_json_success(['deleted' => $deleted])
            : wp_send_json_error(['message' => '삭제 실패 또는 이미 삭제된 항목입니다.']);
    }

    public function handleUnsubAdd(): void {
        $this->requireAuth('crmbiz_nl_unsub_manage');

        $email = sanitize_email($_POST['email'] ?? '');
        if (!is_email($email)) {
            wp_send_json_error(['message' => '유효하지 않은 이메일 주소입니다.']);
        }

        global $wpdb;
        $result = $wpdb->replace(
            $wpdb->prefix . 'crmbiz_nl_unsubscribers',
            ['email' => $email, 'unsubscribed_at' => current_time('mysql'), 'token_used' => null],
            ['%s', '%s', '%s']
        );

        $result !== false
            ? wp_send_json_success(['message' => '추가되었습니다.'])
            : wp_send_json_error(['message' => '추가 실패.']);
    }

    private function buildTestEmailBody(string $to): string {
        return sprintf(
            '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:32px;background:#f3f4f6">' .
            '<div style="max-width:500px;margin:0 auto;background:#fff;padding:32px;border-radius:8px">' .
            '<h2 style="color:#1a1a2e;margin:0 0 16px">테스트 이메일</h2>' .
            '<p>CRMBiz Newsletter 플러그인에서 발송한 테스트 이메일입니다.</p>' .
            '<table style="font-size:13px;color:#555;margin-top:16px"><tr><td style="padding:3px 12px 3px 0">발신자</td><td>%s &lt;%s&gt;</td></tr>' .
            '<tr><td>수신자</td><td>%s</td></tr>' .
            '<tr><td>시각</td><td>%s</td></tr></table>' .
            '<p style="margin-top:24px;color:#0f5132;background:#d1e7dd;padding:10px 14px;border-radius:4px;font-size:13px">FluentSMTP 연결이 정상입니다.</p>' .
            '</div></body></html>',
            esc_html($this->settings->getFromName()),
            esc_html($this->settings->getFromEmail()),
            esc_html($to),
            esc_html(current_time('Y-m-d H:i:s'))
        );
    }
}
