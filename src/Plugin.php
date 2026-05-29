<?php
namespace CRMBizNewsletter;

use CRMBizNewsletter\Admin\AjaxHandlers;
use CRMBizNewsletter\Admin\DashboardPage;
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

        $ajax = new AjaxHandlers($this->settings, self::CRON_HOOK);
        add_action('wp_ajax_crmbiz_nl_test_email',       [$ajax, 'handleTestEmail']);
        add_action('wp_ajax_crmbiz_nl_count_recipients', [$ajax, 'handleCountRecipients']);
        add_action('wp_ajax_crmbiz_nl_manual_send',      [$ajax, 'handleManualSend']);
        add_action('wp_ajax_crmbiz_nl_resend',           [$ajax, 'handleResend']);
        add_action('wp_ajax_crmbiz_nl_resend_single',    [$ajax, 'handleResendSingle']);
        add_action('wp_ajax_crmbiz_nl_get_log',          [$ajax, 'handleGetLog']);
        add_action('wp_ajax_crmbiz_nl_cancel_send',      [$ajax, 'handleCancelSend']);
        add_action('wp_ajax_crmbiz_nl_preview_email',    [$ajax, 'handlePreviewEmail']);

        if (is_admin()) {
            add_action('admin_menu',                  [$this, 'registerAdminPages']);
            add_action('add_meta_boxes',              [$this, 'registerMetaBox']);
            add_action('save_post',                   [$this, 'savePostMeta']);
            add_action('admin_enqueue_scripts',       [$this, 'enqueueAdminAssets']);
            add_action('enqueue_block_editor_assets', [$this, 'enqueueBlockEditorAssets']);
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
            '뉴스레터',
            'manage_options',
            'crmbiz-newsletter',
            [$this, 'renderDashboardPage'],
            'dashicons-email-alt',
            58
        );
        add_submenu_page('crmbiz-newsletter', '대시보드',  '대시보드',  'manage_options', 'crmbiz-newsletter',   [$this, 'renderDashboardPage']);
        add_submenu_page('crmbiz-newsletter', '발송 이력', '발송 이력', 'manage_options', 'crmbiz-nl-history',   [$this, 'renderHistoryPage']);
        add_submenu_page('crmbiz-newsletter', '설정',      '설정',      'manage_options', 'crmbiz-nl-settings',  [$this, 'renderSettingsPage']);
    }

    public function renderDashboardPage(): void {
        (new DashboardPage())->render();
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

    public function handleCronSend(int $newsletterId): void {
        $hasMore = (new NewsletterSender($this->settings))->sendFromRecord($newsletterId);
        if ($hasMore) {
            wp_schedule_single_event(time() + 60, self::CRON_HOOK, [$newsletterId]);
        }
    }

    // -------------------------------------------------------------------------
    // 어드민 에셋 등록
    // -------------------------------------------------------------------------

    public function enqueueBlockEditorAssets(): void {
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'post') {
            return;
        }
        wp_enqueue_script(
            'crmbiz-nl-prepublish',
            CRMBIZ_NL_URL . 'assets/metabox-prepublish.js',
            ['wp-plugins', 'wp-edit-post', 'wp-element'],
            CRMBIZ_NL_VERSION,
            true
        );
    }

    public function enqueueAdminAssets(string $hookSuffix): void {
        $page = sanitize_key($_GET['page'] ?? '');

        if ($page === 'crmbiz-nl-history') {
            wp_enqueue_style('crmbiz-nl-admin', CRMBIZ_NL_URL . 'assets/admin.css', [], CRMBIZ_NL_VERSION);
            wp_enqueue_script('crmbiz-nl-history', CRMBIZ_NL_URL . 'assets/admin-history.js', ['jquery'], CRMBIZ_NL_VERSION, true);
            wp_localize_script('crmbiz-nl-history', 'crmbizNL', [
                'ajaxUrl'           => admin_url('admin-ajax.php'),
                'nonce'             => wp_create_nonce('crmbiz_nl_manual_send'),
                'logNonce'          => wp_create_nonce('crmbiz_nl_get_log'),
                'singleResendNonce' => wp_create_nonce('crmbiz_nl_resend_single'),
            ]);
        }

        if ($page === 'crmbiz-nl-settings') {
            wp_enqueue_script('crmbiz-nl-diagnostics', CRMBIZ_NL_URL . 'assets/admin-diagnostics.js', ['jquery'], CRMBIZ_NL_VERSION, true);
            wp_localize_script('crmbiz-nl-diagnostics', 'crmbizNLDiag', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce('crmbiz_nl_diagnostics'),
            ]);
        }
    }

}
