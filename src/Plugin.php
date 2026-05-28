<?php
namespace CRMBizNewsletter;

use CRMBizNewsletter\Admin\DiagnosticsPage;
use CRMBizNewsletter\Admin\HistoryPage;
use CRMBizNewsletter\Admin\MetaBox;
use CRMBizNewsletter\Admin\SettingsPage;

defined('ABSPATH') || exit;

class Plugin {

    private const CRON_HOOK = 'crmbiz_nl_send_newsletter';

    private static ?self $instance = null;
    private Settings $settings;

    public static function getInstance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {
        $this->settings = new Settings();
        $this->registerHooks();
    }

    private function registerHooks(): void {
        (new UnsubscribeHandler())->init();
        (new TrackingHandler())->init();

        if (Database::getVersion() !== Database::DB_VERSION) {
            Database::install();
        }

        add_action('transition_post_status', [$this, 'onPostPublished'], 10, 3);
        add_action(self::CRON_HOOK,          [$this, 'handleCronSend']);

        add_action('wp_ajax_crmbiz_nl_test_email',       [$this, 'handleTestEmail']);
        add_action('wp_ajax_crmbiz_nl_count_recipients', [$this, 'handleCountRecipients']);
        add_action('wp_ajax_crmbiz_nl_manual_send',      [$this, 'handleManualSend']);
        add_action('wp_ajax_crmbiz_nl_resend',           [$this, 'handleResend']);
        add_action('wp_ajax_crmbiz_nl_resend_single',    [$this, 'handleResendSingle']);
        add_action('wp_ajax_crmbiz_nl_get_log',          [$this, 'handleGetLog']);
        add_action('wp_ajax_crmbiz_nl_preview_email',    [$this, 'handlePreviewEmail']);

        if (is_admin()) {
            add_action('admin_menu',     [$this, 'registerAdminPages']);
            add_action('add_meta_boxes', [$this, 'registerMetaBox']);
            add_action('save_post',      [$this, 'savePostMeta']);
        }
    }

    // -------------------------------------------------------------------------
    // 포스트 발행
    // -------------------------------------------------------------------------

    public function onPostPublished(string $newStatus, string $oldStatus, \WP_Post $post): void {
        if ($newStatus !== 'publish' || $oldStatus === 'publish') {
            return;
        }
        if ($post->post_type !== 'post') {
            return;
        }
        if (!get_post_meta($post->ID, '_crmbiz_nl_enabled', true)) {
            return;
        }

        $this->dispatchNewsletter($post->ID);
    }

    // -------------------------------------------------------------------------
    // 관리자 메뉴
    // -------------------------------------------------------------------------

    public function registerAdminPages(): void {
        add_menu_page(
            'CRMBiz Newsletter',
            'CRMBiz NL',
            'manage_options',
            'crmbiz-newsletter',
            [$this, 'renderDiagnosticsPage'],
            'dashicons-email-alt',
            58
        );
        add_submenu_page('crmbiz-newsletter', '진단',      '진단',      'manage_options', 'crmbiz-newsletter',  [$this, 'renderDiagnosticsPage']);
        add_submenu_page('crmbiz-newsletter', '발송 이력', '발송 이력', 'manage_options', 'crmbiz-nl-history',  [$this, 'renderHistoryPage']);
        add_submenu_page('crmbiz-newsletter', '설정',      '설정',      'manage_options', 'crmbiz-nl-settings', [$this, 'renderSettingsPage']);
    }

    public function renderDiagnosticsPage(): void {
        (new DiagnosticsPage($this->settings))->render();
    }

    public function renderHistoryPage(): void {
        (new HistoryPage())->render();
    }

    public function renderSettingsPage(): void {
        (new SettingsPage($this->settings))->render();
    }

    // -------------------------------------------------------------------------
    // 메타박스
    // -------------------------------------------------------------------------

    public function registerMetaBox(): void {
        (new MetaBox($this->settings))->register();
    }

    public function savePostMeta(int $postId): void {
        (new MetaBox($this->settings))->savePostMeta($postId);

        if (wp_is_post_revision($postId)) {
            return;
        }
        $post = get_post($postId);
        if (!$post || $post->post_type !== 'post') {
            return;
        }

        // Gutenberg 경쟁 조건 보완: REST API가 publish 전환 후 meta를 저장하므로
        // save_post 시점에 "발행 + 활성 + 레코드 없음" 이면 여기서 처리
        if (get_post_status($postId) !== 'publish') {
            return;
        }
        if (!get_post_meta($postId, '_crmbiz_nl_enabled', true)) {
            return;
        }
        if ($this->newsletterRecordExists($postId)) {
            return;
        }

        $this->dispatchNewsletter($postId);
    }

    private function newsletterRecordExists(int $postId): bool {
        global $wpdb;
        return (bool) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}crmbiz_newsletters WHERE post_id = %d LIMIT 1",
                $postId
            )
        );
    }

    // -------------------------------------------------------------------------
    // 발송 디스패치 (Cron 예약)
    // -------------------------------------------------------------------------

    private function dispatchNewsletter(int $postId): void {
        $sendMode = get_post_meta($postId, '_crmbiz_nl_send_mode', true) ?: 'immediate';
        $sender   = new NewsletterSender($this->settings);

        if ($sendMode === 'immediate') {
            $newsletterId = $sender->createQueuedRecord($postId);
            if ($newsletterId > 0) {
                wp_schedule_single_event(time(), self::CRON_HOOK, [$newsletterId]);
            }
        } elseif ($sendMode === 'manual') {
            $sender->createDraftRecord($postId);
        } elseif ($sendMode === 'scheduled') {
            $schedAt   = (string) get_post_meta($postId, '_crmbiz_nl_scheduled_at', true);
            $timestamp = $this->parseScheduledAt($schedAt);
            if ($timestamp > 0) {
                $newsletterId = $sender->createScheduledRecord($postId, $schedAt);
                if ($newsletterId > 0) {
                    wp_schedule_single_event($timestamp, self::CRON_HOOK, [$newsletterId]);
                }
            } else {
                // 예약 시각 미설정 또는 과거 → 즉시 큐로 폴백
                $newsletterId = $sender->createQueuedRecord($postId);
                if ($newsletterId > 0) {
                    wp_schedule_single_event(time(), self::CRON_HOOK, [$newsletterId]);
                }
            }
        }
    }

    private function parseScheduledAt(string $schedAt): int {
        if (!$schedAt) {
            return 0;
        }
        try {
            $dt = new \DateTime($schedAt, wp_timezone());
            $ts = $dt->getTimestamp();
            return $ts > time() ? $ts : 0;
        } catch (\Exception $e) {
            return 0;
        }
    }

    // -------------------------------------------------------------------------
    // WP Cron 핸들러
    // -------------------------------------------------------------------------

    public function handleCronSend(int $newsletterId, int $offset = 0): void {
        $nextOffset = (new NewsletterSender($this->settings))->sendFromRecord($newsletterId, $offset);
        if ($nextOffset > 0) {
            wp_schedule_single_event(time() + 60, self::CRON_HOOK, [$newsletterId, $nextOffset]);
        }
    }

    // -------------------------------------------------------------------------
    // AJAX 핸들러
    // -------------------------------------------------------------------------

    public function handleTestEmail(): void {
        if (!check_ajax_referer('crmbiz_nl_diagnostics', 'nonce', false)) {
            wp_send_json_error(['message' => '보안 검증 실패.'], 403);
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => '권한이 없습니다.'], 403);
        }

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
        if (!check_ajax_referer('crmbiz_nl_metabox', 'nonce', false)) {
            wp_send_json_error([], 403);
        }
        if (!current_user_can('edit_posts')) {
            wp_send_json_error([], 403);
        }

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
            wp_send_json_success(['count' => 0]);
        }
    }

    public function handleManualSend(): void {
        if (!check_ajax_referer('crmbiz_nl_manual_send', 'nonce', false)) {
            wp_send_json_error(['message' => '보안 검증 실패.'], 403);
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => '권한이 없습니다.'], 403);
        }

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
        wp_schedule_single_event(time(), self::CRON_HOOK, [$newsletterId]);

        wp_send_json_success(['message' => '발송이 예약되었습니다. 잠시 후 이력 페이지에서 결과를 확인하세요.']);
    }

    public function handleResend(): void {
        if (!check_ajax_referer('crmbiz_nl_manual_send', 'nonce', false)) {
            wp_send_json_error(['message' => '보안 검증 실패.'], 403);
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => '권한이 없습니다.'], 403);
        }

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
        wp_schedule_single_event(time(), self::CRON_HOOK, [$newId]);

        wp_send_json_success(['message' => '재발송이 예약되었습니다. 잠시 후 이력 페이지에서 결과를 확인하세요.']);
    }

    public function handleResendSingle(): void {
        if (!check_ajax_referer('crmbiz_nl_resend_single', 'nonce', false)) {
            wp_send_json_error(['message' => '보안 검증 실패.'], 403);
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => '권한이 없습니다.'], 403);
        }

        $newsletterId = (int) ($_POST['newsletter_id'] ?? 0);
        $email        = sanitize_email($_POST['email'] ?? '');

        if ($newsletterId <= 0 || !is_email($email)) {
            wp_send_json_error(['message' => '유효하지 않은 입력입니다.']);
        }

        $sent = (new NewsletterSender($this->settings))->sendToEmail($newsletterId, $email);

        if ($sent) {
            wp_send_json_success(['message' => $email . ' 재발송 완료']);
        } else {
            wp_send_json_error(['message' => $email . ' 발송 실패 (수신거부 또는 오류)']);
        }
    }

    public function handleGetLog(): void {
        if (!check_ajax_referer('crmbiz_nl_get_log', 'nonce', false)) {
            wp_send_json_error([], 403);
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error([], 403);
        }

        $newsletterId = (int) ($_POST['newsletter_id'] ?? 0);
        if ($newsletterId <= 0) {
            wp_send_json_error([]);
        }

        wp_send_json_success(['html' => (new Admin\HistoryPage())->renderLogPublic($newsletterId)]);
    }

    public function handlePreviewEmail(): void {
        $postId = (int) ($_GET['post_id'] ?? 0);
        $nonce  = $_GET['nonce'] ?? '';

        if (!wp_verify_nonce($nonce, 'crmbiz_nl_preview_' . $postId)) {
            wp_die('보안 검증 실패.', '오류', ['response' => 403]);
        }
        if (!current_user_can('edit_post', $postId)) {
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

    // -------------------------------------------------------------------------
    // 내부 유틸
    // -------------------------------------------------------------------------

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
