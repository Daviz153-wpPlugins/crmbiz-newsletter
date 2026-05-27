<?php
namespace CRMBizNewsletter;

use CRMBizNewsletter\Admin\DiagnosticsPage;
use CRMBizNewsletter\Admin\HistoryPage;
use CRMBizNewsletter\Admin\MetaBox;
use CRMBizNewsletter\Admin\SettingsPage;

defined('ABSPATH') || exit;

class Plugin {

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
        // 수신거부 처리 (프론트엔드 포함)
        (new UnsubscribeHandler())->init();

        // 포스트 발행 훅
        add_action('transition_post_status', [$this, 'onPostPublished'], 10, 3);

        // AJAX
        add_action('wp_ajax_crmbiz_nl_test_email',       [$this, 'handleTestEmail']);
        add_action('wp_ajax_crmbiz_nl_count_recipients', [$this, 'handleCountRecipients']);
        add_action('wp_ajax_crmbiz_nl_manual_send',      [$this, 'handleManualSend']);
        add_action('wp_ajax_crmbiz_nl_resend',           [$this, 'handleResend']);

        // 미리보기는 admin-ajax.php GET 요청으로 처리
        add_action('wp_ajax_crmbiz_nl_preview_email',    [$this, 'handlePreviewEmail']);

        if (is_admin()) {
            add_action('admin_menu',    [$this, 'registerAdminPages']);
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

        $sendMode = get_post_meta($post->ID, '_crmbiz_nl_send_mode', true) ?: 'immediate';
        $sender   = new NewsletterSender($this->settings);

        if ($sendMode === 'immediate') {
            $sender->sendForPost($post->ID);
        } elseif ($sendMode === 'manual') {
            // draft 레코드만 생성 — HistoryPage에서 수동 발송
            $sender->createDraftRecord($post->ID);
        }
        // scheduled: Phase 2에서 처리
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

        // Gutenberg 경쟁 조건 보완:
        // REST API가 먼저 publish 상태로 바꾼 뒤 메타박스가 저장되므로,
        // save_post 시점에 "이미 발행된 포스트 + 뉴스레터 활성 + DB 레코드 없음" 이면 여기서 처리
        if (get_post_status($postId) !== 'publish') {
            return;
        }
        if (!get_post_meta($postId, '_crmbiz_nl_enabled', true)) {
            return;
        }
        if ($this->newsletterRecordExists($postId)) {
            return;
        }

        $sendMode = get_post_meta($postId, '_crmbiz_nl_send_mode', true) ?: 'immediate';
        $sender   = new NewsletterSender($this->settings);

        if ($sendMode === 'immediate') {
            $sender->sendForPost($postId);
        } elseif ($sendMode === 'manual') {
            $sender->createDraftRecord($postId);
        }
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

        $result = wp_mail(
            $to,
            '[테스트] CRMBiz Newsletter 발송 테스트',
            $this->buildTestEmailBody($to),
            ['Content-Type: text/html; charset=UTF-8', 'From: ' . $this->settings->getFromName() . ' <' . $this->settings->getFromEmail() . '>']
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

        $result = (new NewsletterSender($this->settings))->sendManual($newsletterId);

        $result['success']
            ? wp_send_json_success($result)
            : wp_send_json_error($result);
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

        $sender = new NewsletterSender($this->settings);
        $sender->sendForPost((int) $record->post_id);

        wp_send_json_success(['message' => '재발송이 완료되었습니다.']);
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

        // 미리보기용 더미 구독자 객체
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
