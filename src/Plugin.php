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
        add_action('init', [self::class, 'loadTextDomain']);

        // AS가 활성화된 경우 HTTP Loopback Async Runner 활성화
        // — WP Cron 트리거 없이도 새 액션 예약 시 즉시 큐 처리
        if (function_exists('as_schedule_single_action')) {
            add_filter('action_scheduler_run_async', '__return_true');
            add_filter('action_scheduler_queue_runner_time_limit', fn() => 30);
        }

        (new UnsubscribeHandler())->init();
        (new TrackingHandler())->init();
        (new RestApi($this->settings))->init();
        Privacy::register();

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
            add_action('admin_notices',               [$this, 'showCronNotice']);
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
            __('뉴스레터', 'crmbiz-newsletter'),
            'manage_options',
            'crmbiz-newsletter',
            [$this, 'renderDashboardPage'],
            'dashicons-email-alt',
            58
        );
        add_submenu_page('crmbiz-newsletter', __('대시보드', 'crmbiz-newsletter'),  __('대시보드', 'crmbiz-newsletter'),  'manage_options', 'crmbiz-newsletter',       [$this, 'renderDashboardPage']);
        add_submenu_page('crmbiz-newsletter', __('발송 이력', 'crmbiz-newsletter'), __('발송 이력', 'crmbiz-newsletter'), 'manage_options', 'crmbiz-nl-history',       [$this, 'renderHistoryPage']);
        add_submenu_page('crmbiz-newsletter', __('수신 거부 관리', 'crmbiz-newsletter'), __('수신 거부', 'crmbiz-newsletter'), 'manage_options', 'crmbiz-nl-unsubscribers', [$this, 'renderUnsubscribePage']);
        add_submenu_page('crmbiz-newsletter', __('설정', 'crmbiz-newsletter'),      __('설정', 'crmbiz-newsletter'),      'manage_options', 'crmbiz-nl-settings',      [$this, 'renderSettingsPage']);
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
            $this->syncPendingRecord($postId);
            return;
        }

        $this->dispatchNewsletter($postId);
    }

    public static function loadTextDomain(): void {
        load_plugin_textdomain(
            'crmbiz-newsletter',
            false,
            dirname(plugin_basename(CRMBIZ_NL_FILE)) . '/languages'
        );
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

    private function syncPendingRecord(int $postId): void {
        global $wpdb;
        $sendMode = get_post_meta($postId, '_crmbiz_nl_send_mode', true) ?: 'immediate';
        $tagIds   = array_filter(array_map('intval', (array) get_post_meta($postId, '_crmbiz_nl_tag_ids',  true)));
        $listIds  = array_filter(array_map('intval', (array) get_post_meta($postId, '_crmbiz_nl_list_ids', true)));
        $table    = $wpdb->prefix . 'crmbiz_newsletters';

        // draft 레코드 태그/리스트 동기화 (수동 발송 모드에서 수신자 변경 반영)
        $wpdb->update(
            $table,
            [
                'tag_ids'        => wp_json_encode(array_values($tagIds)),
                'list_ids'       => wp_json_encode(array_values($listIds)),
                'updated_at'     => current_time('mysql'),
                'updated_at_gmt' => current_time('mysql', true),
            ],
            ['post_id' => $postId, 'status' => 'draft'],
            ['%s', '%s', '%s', '%s'],
            ['%d', '%s']
        );

        // queued/scheduled 레코드의 예약 상태 재동기화
        // ① Gutenberg 경쟁 조건: transition_post_status가 save_post보다 먼저 실행되어
        //    이전 send_mode(immediate)로 queued 레코드가 생성된 경우 → scheduled로 교정
        // ② 발행 후 예약 시각 변경: MetaBox가 DB는 갱신하지만 Scheduler 이벤트는 재등록 안 함
        $record = $wpdb->get_row($wpdb->prepare(
            "SELECT id, status FROM {$table}
             WHERE post_id = %d AND status IN ('queued','scheduled')
             ORDER BY created_at DESC LIMIT 1",
            $postId
        ));
        if (!$record) {
            return;
        }

        $nlId = (int) $record->id;

        if ($sendMode === 'scheduled') {
            $schedAt   = (string) get_post_meta($postId, '_crmbiz_nl_scheduled_at', true);
            $timestamp = $this->parseScheduledAt($schedAt);
            if ($timestamp > 0) {
                // 기존 Scheduler 이벤트(이전 시각 또는 즉시 발송)를 취소하고 올바른 시각에 재등록
                Scheduler::unschedule(self::CRON_HOOK, [$nlId]);
                $wpdb->update(
                    $table,
                    ['status' => 'scheduled', 'scheduled_at' => $schedAt, 'scheduled_at_gmt' => get_gmt_from_date($schedAt), 'updated_at' => current_time('mysql'), 'updated_at_gmt' => current_time('mysql', true)],
                    ['id' => $nlId],
                    ['%s', '%s', '%s'],
                    ['%d']
                );
                Scheduler::scheduleSingle($timestamp, self::CRON_HOOK, [$nlId]);
                Logger::info('예약 발송 동기화', ['post_id' => $postId, 'nl_id' => $nlId, 'sched_at' => $schedAt]);
            }
        } elseif ($record->status === 'scheduled') {
            // scheduled → immediate/manual 전환: Scheduler 이벤트 취소
            Scheduler::unschedule(self::CRON_HOOK, [$nlId]);
            if ($sendMode === 'immediate') {
                $wpdb->update(
                    $table,
                    ['status' => 'queued', 'scheduled_at' => null, 'scheduled_at_gmt' => null, 'updated_at' => current_time('mysql'), 'updated_at_gmt' => current_time('mysql', true)],
                    ['id' => $nlId],
                    ['%s', '%s', '%s'],
                    ['%d']
                );
                Scheduler::scheduleSingle(time(), self::CRON_HOOK, [$nlId]);
                Logger::info('예약 → 즉시 발송 전환', ['post_id' => $postId, 'nl_id' => $nlId]);
            }
        }
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

    public function showCronNotice(): void {
        // 뉴스레터 관련 페이지에서만 표시
        $page = sanitize_key($_GET['page'] ?? '');
        if (!in_array($page, ['crmbiz-newsletter', 'crmbiz-nl-history', 'crmbiz-nl-settings'], true)) {
            return;
        }

        // 대기 중인 뉴스레터가 없으면 알림 불필요
        global $wpdb;
        $pending = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}crmbiz_newsletters WHERE status IN ('queued','sending')"
        );
        if ($pending === 0) {
            return;
        }

        // handleCronSend() 실행 시 갱신되는 타임스탬프로 마지막 처리 시각 확인
        // AS / WP Cron 모두 이 옵션을 갱신하므로 스케줄러 종류와 무관하게 감지 가능
        $lastRun = (int) get_option('crmbiz_nl_last_cron_run', 0);
        $stale   = $lastRun > 0 && (time() - $lastRun) > 1800;
        $never   = $lastRun === 0;

        if (!$stale && !$never) {
            return;
        }

        $ago      = $lastRun > 0 ? human_time_diff($lastRun) . ' ' . __('전', 'crmbiz-newsletter') : __('한 번도 실행되지 않음', 'crmbiz-newsletter');
        $usingAs  = function_exists('as_next_scheduled_action');

        if ($usingAs) {
            // AS 큐 자체가 멈춘 경우 — 서버 cron 이상, WP 충돌 등으로 AS runner가 실행 안 됨
            $msg = sprintf(
                wp_kses(__('⚠️ <strong>CRMBiz Newsletter</strong>: 발송 큐가 %s 동안 처리되지 않았습니다. Action Scheduler 큐가 멈췄을 수 있습니다. <a href="%s">Action Scheduler 상태</a>를 확인하거나 <a href="%s">즉시 발송</a> 버튼을 사용하세요.', 'crmbiz-newsletter'), ['strong' => [], 'a' => ['href' => []]]),
                esc_html($ago),
                esc_url(admin_url('tools.php?page=action-scheduler')),
                esc_url(admin_url('admin.php?page=crmbiz-nl-history'))
            );
        } elseif (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
            $msg = sprintf(
                wp_kses(__('⚠️ <strong>CRMBiz Newsletter</strong>: <code>DISABLE_WP_CRON</code>이 활성화되어 있습니다. 발송이 실행되지 않을 수 있습니다. 서버 crontab에 <code>wp cron event run --due-now</code>를 등록하거나, <a href="%s">즉시 발송</a> 버튼을 사용하세요.', 'crmbiz-newsletter'), ['strong' => [], 'code' => [], 'a' => ['href' => []]]),
                esc_url(admin_url('admin.php?page=crmbiz-nl-history'))
            );
        } else {
            $msg = sprintf(
                wp_kses(__('⚠️ <strong>CRMBiz Newsletter</strong>: WP Cron이 마지막으로 실행된 시간 — %s. 트래픽이 없으면 예약 발송이 지연됩니다. <a href="%s">즉시 발송</a> 버튼을 이용하거나 서버 cron 설정을 확인하세요.', 'crmbiz-newsletter'), ['strong' => [], 'a' => ['href' => []]]),
                esc_html($ago),
                esc_url(admin_url('admin.php?page=crmbiz-nl-history'))
            );
        }

        echo '<div class="notice notice-warning is-dismissible"><p>' . wp_kses($msg, [
            'strong' => [], 'code' => [], 'a' => ['href' => []],
        ]) . '</p></div>';
    }

    public function handleCronSend(int $newsletterId): void {
        update_option('crmbiz_nl_last_cron_run', time(), false);
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

        // 이벤트 로그 90일 초과분 제거
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}crmbiz_nl_events WHERE occurred_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            self::RETAIN_DAYS
        ));

        // 만료된 Rate Limit 레코드 제거
        $wpdb->query("DELETE FROM {$wpdb->prefix}crmbiz_nl_ratelimit WHERE expires_at < NOW()");

        // 고아 큐 안전망: failed/cancelled 뉴스레터에 잔여 큐 행이 남아있으면 정리
        // 정상 경로(finalizeSend, cancelNewsletter)에서 이미 삭제하므로 극히 드물게 실행됨
        $wpdb->query(
            "DELETE q FROM {$wpdb->prefix}crmbiz_nl_queue q
             JOIN {$wpdb->prefix}crmbiz_newsletters n ON n.id = q.newsletter_id
             WHERE n.status IN ('failed','cancelled')"
        );

        Logger::cleanup(); // 시스템 로그 7일 보관
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

        if (
            in_array($page, ['crmbiz-newsletter', 'crmbiz-nl-history', 'crmbiz-nl-settings', 'crmbiz-nl-unsubscribers'], true)
            || in_array($hookSuffix, ['post.php', 'post-new.php'], true)
        ) {
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
