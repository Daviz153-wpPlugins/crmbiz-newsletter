<?php
namespace CRMBizNewsletter;

use CRMBizNewsletter\Admin\AjaxHandlers;
use CRMBizNewsletter\Scheduler;
use CRMBizNewsletter\RestApi;
use CRMBizNewsletter\Admin\DashboardPage;
use CRMBizNewsletter\Admin\HistoryPage;
use CRMBizNewsletter\Admin\MetaBox;
use CRMBizNewsletter\Admin\SettingsPage;
use CRMBizNewsletter\Admin\PostListColumn;
use CRMBizNewsletter\Admin\UnsubscribePage;

defined('ABSPATH') || exit;

class Plugin {

    private const CRON_HOOK    = 'crmbiz_nl_send_newsletter';
    private const CLEANUP_HOOK = 'crmbiz_nl_cleanup';
    private const RETAIN_DAYS  = 90;

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
        (new RestApi())->init();

        if (Database::getVersion() !== Database::DB_VERSION) {
            Database::install();
        }

        add_action('transition_post_status', [$this, 'onPostPublished'], 10, 3);
        add_action(self::CRON_HOOK,          [$this, 'handleCronSend']);
        add_action(self::CLEANUP_HOOK,       [$this, 'handleCleanup']);

        if (!wp_next_scheduled(self::CLEANUP_HOOK)) {
            wp_schedule_event(time(), 'daily', self::CLEANUP_HOOK);
        }

        $ajax = new AjaxHandlers($this->settings, self::CRON_HOOK);
        add_action('wp_ajax_crmbiz_nl_test_email',        [$ajax, 'handleTestEmail']);
        add_action('wp_ajax_crmbiz_nl_count_recipients', [$ajax, 'handleCountRecipients']);
        add_action('wp_ajax_crmbiz_nl_preview_email',    [$ajax, 'handlePreviewEmail']);
        add_action('wp_ajax_crmbiz_nl_settings_preview', [$ajax, 'handleSettingsPreview']);
        add_action('wp_ajax_crmbiz_nl_test_newsletter',  [$ajax, 'handleTestNewsletter']);
        add_action('wp_ajax_crmbiz_nl_unsub_remove',     [$ajax, 'handleUnsubRemove']);
        add_action('wp_ajax_crmbiz_nl_unsub_add',        [$ajax, 'handleUnsubAdd']);

        if (is_admin()) {
            add_action('admin_menu',                  [$this, 'registerAdminPages']);
            add_action('add_meta_boxes',              [$this, 'registerMetaBox']);
            add_action('save_post',                   [$this, 'savePostMeta']);
            (new PostListColumn())->init();
            add_action('admin_enqueue_scripts',       [$this, 'enqueueAdminAssets']);
            add_action('enqueue_block_editor_assets', [$this, 'enqueueBlockEditorAssets']);
            add_filter('script_loader_tag',           [$this, 'addModuleType'], 10, 2);
            add_filter('admin_body_class',            [$this, 'addBodyClass']);
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
        add_submenu_page('crmbiz-newsletter', '수신 거부 관리', '수신 거부', 'manage_options', 'crmbiz-nl-unsubscribers', [$this, 'renderUnsubscribePage']);
        add_submenu_page('crmbiz-newsletter', '설정',      '설정',      'manage_options', 'crmbiz-nl-settings',  [$this, 'renderSettingsPage']);
    }

    public function renderDashboardPage(): void {
        (new DashboardPage())->render();
    }

    public function renderHistoryPage(): void {
        (new HistoryPage())->render();
    }

    public function renderUnsubscribePage(): void {
        (new UnsubscribePage())->render();
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
            $this->syncDraftRecord($postId);
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


    private function syncDraftRecord(int $postId): void {
        global $wpdb;
        $tagIds  = array_filter(array_map('intval', (array) get_post_meta($postId, '_crmbiz_nl_tag_ids',  true)));
        $listIds = array_filter(array_map('intval', (array) get_post_meta($postId, '_crmbiz_nl_list_ids', true)));

        $wpdb->update(
            $wpdb->prefix . 'crmbiz_newsletters',
            [
                'tag_ids'    => wp_json_encode(array_values($tagIds)),
                'list_ids'   => wp_json_encode(array_values($listIds)),
                'updated_at' => current_time('mysql'),
            ],
            ['post_id' => $postId, 'status' => 'draft'],
            ['%s', '%s', '%s'],
            ['%d', '%s']
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
                Scheduler::scheduleSingle(time(), self::CRON_HOOK, [$newsletterId]);
                Logger::info('즉시 발송 예약', ['post_id' => $postId, 'nl_id' => $newsletterId]);
            } else {
                Logger::error('즉시 발송 레코드 생성 실패', ['post_id' => $postId]);
            }
        } elseif ($sendMode === 'manual') {
            $sender->createDraftRecord($postId);
        } elseif ($sendMode === 'scheduled') {
            $schedAt   = (string) get_post_meta($postId, '_crmbiz_nl_scheduled_at', true);
            $timestamp = $this->parseScheduledAt($schedAt);
            if ($timestamp > 0) {
                $newsletterId = $sender->createScheduledRecord($postId, $schedAt);
                if ($newsletterId > 0) {
                    Scheduler::scheduleSingle($timestamp, self::CRON_HOOK, [$newsletterId]);
                }
            } else {
                // 예약 시각 미설정 또는 과거 → 즉시 큐로 폴백
                $newsletterId = $sender->createQueuedRecord($postId);
                if ($newsletterId > 0) {
                    Scheduler::scheduleSingle(time(), self::CRON_HOOK, [$newsletterId]);
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
            Logger::error('예약 시각 파싱 실패', ['scheduled_at' => $schedAt, 'error' => $e->getMessage()]);
            return 0;
        }
    }

    // -------------------------------------------------------------------------
    // WP Cron 핸들러
    // -------------------------------------------------------------------------

    public function handleCronSend(int $newsletterId): void {
        Logger::info('Cron 발송 시작', ['nl_id' => $newsletterId]);
        $hasMore = (new NewsletterSender($this->settings))->sendFromRecord($newsletterId);
        if ($hasMore) {
            Scheduler::scheduleSingle(time() + 60, self::CRON_HOOK, [$newsletterId]);
            Logger::info('다음 배치 예약', ['nl_id' => $newsletterId]);
        } else {
            Logger::info('발송 완료', ['nl_id' => $newsletterId]);
        }
    }

    public function handleCleanup(): void {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}crmbiz_nl_events WHERE occurred_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            self::RETAIN_DAYS
        ));
        // 만료된 rate limit 행 정리
        $wpdb->query("DELETE FROM {$wpdb->prefix}crmbiz_nl_ratelimit WHERE expires_at < NOW()");
    }

    // -------------------------------------------------------------------------
    // 어드민 에셋 등록
    // -------------------------------------------------------------------------

    public function addBodyClass(string $classes): string {
        $page = sanitize_key($_GET['page'] ?? '');
        if (in_array($page, ['crmbiz-newsletter', 'crmbiz-nl-history', 'crmbiz-nl-settings', 'crmbiz-nl-unsubscribers'], true)) {
            $classes .= ' crmbiz-nl-page';
        }
        return $classes;
    }

    public function addModuleType(string $tag, string $handle): string {
        static $handles = ['crmbiz-nl-vue-dash', 'crmbiz-nl-vue-history'];
        if (in_array($handle, $handles, true)) {
            return str_replace(' src=', ' type="module" src=', $tag);
        }
        return $tag;
    }

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

        if (in_array($page, ['crmbiz-newsletter', 'crmbiz-nl-history', 'crmbiz-nl-settings', 'crmbiz-nl-unsubscribers'], true)) {
            wp_enqueue_style('crmbiz-nl-admin', CRMBIZ_NL_URL . 'assets/admin.css', [], CRMBIZ_NL_VERSION);
        }

        if ($page === 'crmbiz-newsletter') {
            wp_enqueue_style('crmbiz-nl-vue-dash',  CRMBIZ_NL_URL . 'assets/vue/app.css', [], CRMBIZ_NL_VERSION);
            wp_enqueue_script('crmbiz-nl-vue-dash', CRMBIZ_NL_URL . 'assets/vue/dashboard.js', [], CRMBIZ_NL_VERSION, true);
            wp_localize_script('crmbiz-nl-vue-dash', 'CrmbizNL', [
                'restUrl'    => rest_url('crmbiz-nl/v1/'),
                'nonce'      => wp_create_nonce('wp_rest'),
                'historyUrl' => admin_url('admin.php?page=crmbiz-nl-history'),
            ]);
        }

        if ($page === 'crmbiz-nl-history') {
            wp_enqueue_style('crmbiz-nl-vue-history',  CRMBIZ_NL_URL . 'assets/vue/app.css', [], CRMBIZ_NL_VERSION);
            wp_enqueue_script('crmbiz-nl-vue-history', CRMBIZ_NL_URL . 'assets/vue/history.js', [], CRMBIZ_NL_VERSION, true);
            wp_localize_script('crmbiz-nl-vue-history', 'CrmbizNL', [
                'restUrl' => rest_url('crmbiz-nl/v1/'),
                'nonce'   => wp_create_nonce('wp_rest'),
            ]);
        }

        if ($page === 'crmbiz-nl-settings') {
            wp_enqueue_media();
            wp_enqueue_script('crmbiz-nl-test-email', CRMBIZ_NL_URL . 'assets/admin-test-email.js', ['jquery'], CRMBIZ_NL_VERSION, true);
            wp_localize_script('crmbiz-nl-test-email', 'crmbizNLDiag', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce('crmbiz_nl_diagnostics'),
            ]);
        }
    }

}
