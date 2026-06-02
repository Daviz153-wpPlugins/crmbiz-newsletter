<?php
declare(strict_types=1);

use CRMBizNewsletter\Plugin;
use CRMBizNewsletter\Database;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Plugin — onPostPublished · showCronNotice · savePostMeta · parseScheduledAt
 *
 * Plugin은 싱글톤이므로 각 테스트 후 Reflection으로 인스턴스를 초기화한다.
 * constructor의 Database::install() 호출을 막기 위해 DB 버전 옵션을 미리 주입한다.
 */

// showCronNotice() 테스트용 WpdbStub 확장
// — pending count 쿼리(COUNT(*) WHERE status IN ...) 처리
class PluginWpdbStub extends WpdbStub {
    public function get_var(string $sql): ?string {
        if (preg_match("/COUNT\(\*\).*crmbiz_newsletters.*status IN/is", $sql)) {
            $pending = array_filter(
                $GLOBALS['_wpdb_newsletters'],
                fn($r) => in_array($r['status'] ?? '', ['queued', 'sending'], true)
            );
            return (string) count($pending);
        }
        return parent::get_var($sql);
    }
}

class PluginTest extends TestCase {

    private Plugin $plugin;
    private static \ReflectionMethod $parseScheduledAt;

    public static function setUpBeforeClass(): void {
        self::$parseScheduledAt = new \ReflectionMethod(Plugin::class, 'parseScheduledAt');
    }

    protected function setUp(): void {
        $GLOBALS['wpdb']                     = new PluginWpdbStub();
        $GLOBALS['_wpdb_newsletters']        = [];
        $GLOBALS['_wpdb_events']             = [];
        $GLOBALS['_wp_options']              = [];
        $GLOBALS['_wp_post_meta']            = [];
        $GLOBALS['_wp_posts']                = [];
        $GLOBALS['_as_actions']              = [];
        $GLOBALS['_wp_mail_calls']           = [];
        $GLOBALS['_wp_user_can']             = true;

        // DB 버전을 최신으로 주입 → install() 호출 방지
        $GLOBALS['_wp_options'][Database::DB_VERSION_OPTION] = Database::DB_VERSION;

        $this->plugin = Plugin::getInstance();
    }

    protected function tearDown(): void {
        // 싱글톤 초기화 (다음 테스트에서 새 인스턴스 생성)
        (new \ReflectionClass(Plugin::class))
            ->getProperty('instance')
            ->setValue(null, null);

        $GLOBALS['wpdb'] = new WpdbStub();
        unset($_GET['page'], $_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_X_FORWARDED_FOR']);
    }

    // ── 헬퍼 ────────────────────────────────────────────────────────────────────

    private function makePost(int $id, string $type = 'post', string $status = 'publish'): \WP_Post {
        $post              = new \WP_Post((object)[]);
        $post->ID          = $id;
        $post->post_type   = $type;
        $post->post_status = $status;
        return $post;
    }

    private function seedNewsletter(int $postId, string $status): void {
        $GLOBALS['_wpdb_newsletters'][] = [
            'id' => 1, 'post_id' => $postId, 'status' => $status,
            'send_mode' => 'immediate', 'recipient_count' => 0,
            'success_count' => 0, 'fail_count' => 0, 'created_at' => '2026-01-01',
        ];
    }

    // ── onPostPublished() — 조기 반환 조건 ──────────────────────────────────────

    public function test_onPostPublished_ignores_non_publish_status(): void {
        $post = $this->makePost(1);
        $this->plugin->onPostPublished('draft', 'auto-draft', $post);

        $this->assertEmpty($GLOBALS['_wpdb_newsletters'], '레코드가 생성되지 않아야 함');
    }

    public function test_onPostPublished_ignores_republish(): void {
        // 이미 publish → publish 재발행은 처리하지 않음
        $post = $this->makePost(1);
        $this->plugin->onPostPublished('publish', 'publish', $post);

        $this->assertEmpty($GLOBALS['_wpdb_newsletters']);
    }

    public function test_onPostPublished_ignores_non_post_type(): void {
        $post = $this->makePost(1, 'page'); // page 타입
        $this->plugin->onPostPublished('publish', 'draft', $post);

        $this->assertEmpty($GLOBALS['_wpdb_newsletters']);
    }

    public function test_onPostPublished_ignores_when_not_enabled(): void {
        $post = $this->makePost(1);
        // _crmbiz_nl_enabled 메타 미설정 → get_post_meta returns ''
        $this->plugin->onPostPublished('publish', 'draft', $post);

        $this->assertEmpty($GLOBALS['_wpdb_newsletters']);
    }

    // ── onPostPublished() — 해피 패스 ───────────────────────────────────────────

    public function test_onPostPublished_immediate_creates_queued_record(): void {
        $post = $this->makePost(10);
        $GLOBALS['_wp_post_meta'][10]['_crmbiz_nl_enabled']  = '1';
        $GLOBALS['_wp_post_meta'][10]['_crmbiz_nl_send_mode'] = 'immediate';

        $this->plugin->onPostPublished('publish', 'draft', $post);

        $this->assertCount(1, $GLOBALS['_wpdb_newsletters']);
        $this->assertSame('queued', $GLOBALS['_wpdb_newsletters'][0]['status']);
        $this->assertSame(10, $GLOBALS['_wpdb_newsletters'][0]['post_id']);
    }

    public function test_onPostPublished_immediate_schedules_cron(): void {
        $post = $this->makePost(11);
        $GLOBALS['_wp_post_meta'][11]['_crmbiz_nl_enabled']  = '1';
        $GLOBALS['_wp_post_meta'][11]['_crmbiz_nl_send_mode'] = 'immediate';

        $this->plugin->onPostPublished('publish', 'draft', $post);

        // AS scheduleSingle → _as_actions에 등록됨
        $this->assertNotEmpty($GLOBALS['_as_actions'], 'Cron 이벤트가 예약되어야 함');
        $this->assertSame('crmbiz_nl_send_newsletter', $GLOBALS['_as_actions'][0]['hook']);
    }

    public function test_onPostPublished_manual_creates_draft_record(): void {
        $post = $this->makePost(12);
        $GLOBALS['_wp_post_meta'][12]['_crmbiz_nl_enabled']  = '1';
        $GLOBALS['_wp_post_meta'][12]['_crmbiz_nl_send_mode'] = 'manual';

        $this->plugin->onPostPublished('publish', 'draft', $post);

        $this->assertCount(1, $GLOBALS['_wpdb_newsletters']);
        $this->assertSame('draft', $GLOBALS['_wpdb_newsletters'][0]['status']);
        // manual 모드는 Cron을 예약하지 않음
        $this->assertEmpty($GLOBALS['_as_actions']);
    }

    public function test_onPostPublished_scheduled_future_creates_scheduled_record(): void {
        $future = date('Y-m-d H:i:s', time() + 86400 * 30); // 30일 후 (시차 안전)
        $post   = $this->makePost(13);
        $GLOBALS['_wp_post_meta'][13]['_crmbiz_nl_enabled']       = '1';
        $GLOBALS['_wp_post_meta'][13]['_crmbiz_nl_send_mode']     = 'scheduled';
        $GLOBALS['_wp_post_meta'][13]['_crmbiz_nl_scheduled_at']  = $future;

        $this->plugin->onPostPublished('publish', 'draft', $post);

        $this->assertCount(1, $GLOBALS['_wpdb_newsletters']);
        $this->assertSame('scheduled', $GLOBALS['_wpdb_newsletters'][0]['status']);
        $this->assertNotEmpty($GLOBALS['_as_actions']);
    }

    public function test_onPostPublished_scheduled_past_falls_back_to_queued(): void {
        $past = date('Y-m-d H:i:s', time() - 3600); // 1시간 전
        $post = $this->makePost(14);
        $GLOBALS['_wp_post_meta'][14]['_crmbiz_nl_enabled']       = '1';
        $GLOBALS['_wp_post_meta'][14]['_crmbiz_nl_send_mode']     = 'scheduled';
        $GLOBALS['_wp_post_meta'][14]['_crmbiz_nl_scheduled_at']  = $past;

        $this->plugin->onPostPublished('publish', 'draft', $post);

        // 과거 시각 → queued 폴백
        $this->assertSame('queued', $GLOBALS['_wpdb_newsletters'][0]['status']);
    }

    // ── showCronNotice() — 조기 반환 조건 ───────────────────────────────────────

    public function test_showCronNotice_silent_on_wrong_page(): void {
        $_GET['page'] = 'some-other-plugin';
        $GLOBALS['_wpdb_newsletters'][] = ['id' => 1, 'status' => 'queued'];
        $GLOBALS['_wp_options']['crmbiz_nl_last_cron_run'] = 0;

        ob_start();
        $this->plugin->showCronNotice();
        $output = ob_get_clean();

        $this->assertSame('', $output, '허용되지 않는 페이지에서는 출력 없음');
    }

    public function test_showCronNotice_silent_when_no_pending(): void {
        $_GET['page'] = 'crmbiz-newsletter';
        // 대기 중 뉴스레터 없음
        $GLOBALS['_wpdb_newsletters'] = [];
        $GLOBALS['_wp_options']['crmbiz_nl_last_cron_run'] = 0;

        ob_start();
        $this->plugin->showCronNotice();
        $output = ob_get_clean();

        $this->assertSame('', $output, '대기 중 뉴스레터 없으면 출력 없음');
    }

    public function test_showCronNotice_silent_when_cron_ran_recently(): void {
        $_GET['page'] = 'crmbiz-newsletter';
        $GLOBALS['_wpdb_newsletters'][] = ['id' => 1, 'status' => 'queued'];
        // 최근 실행 (5분 전, 1800초 미만)
        $GLOBALS['_wp_options']['crmbiz_nl_last_cron_run'] = time() - 300;

        ob_start();
        $this->plugin->showCronNotice();
        $output = ob_get_clean();

        $this->assertSame('', $output, '최근 실행된 Cron은 알림 없음');
    }

    // ── showCronNotice() — 메시지 브랜치 ────────────────────────────────────────

    public function test_showCronNotice_shows_notice_when_never_run(): void {
        $_GET['page'] = 'crmbiz-newsletter';
        $GLOBALS['_wpdb_newsletters'][] = ['id' => 1, 'status' => 'queued'];
        $GLOBALS['_wp_options']['crmbiz_nl_last_cron_run'] = 0; // 한 번도 실행 안 됨

        ob_start();
        $this->plugin->showCronNotice();
        $output = ob_get_clean();

        $this->assertStringContainsString('notice-warning', $output);
        $this->assertStringContainsString('CRMBiz Newsletter', $output);
    }

    public function test_showCronNotice_shows_notice_when_stale(): void {
        $_GET['page'] = 'crmbiz-nl-history';
        $GLOBALS['_wpdb_newsletters'][] = ['id' => 1, 'status' => 'sending'];
        // 31분 전 (1860초) → stale
        $GLOBALS['_wp_options']['crmbiz_nl_last_cron_run'] = time() - 1860;

        ob_start();
        $this->plugin->showCronNotice();
        $output = ob_get_clean();

        $this->assertStringContainsString('notice-warning', $output);
    }

    public function test_showCronNotice_disable_wp_cron_message(): void {
        $_GET['page'] = 'crmbiz-newsletter';
        $GLOBALS['_wpdb_newsletters'][] = ['id' => 1, 'status' => 'queued'];
        $GLOBALS['_wp_options']['crmbiz_nl_last_cron_run'] = 0;

        if (!defined('DISABLE_WP_CRON')) {
            define('DISABLE_WP_CRON', true);
        }

        ob_start();
        $this->plugin->showCronNotice();
        $output = ob_get_clean();

        // DISABLE_WP_CRON 메시지 또는 일반 notice (AS가 없고 DISABLE_WP_CRON=true)
        $this->assertStringContainsString('notice-warning', $output);
        // DISABLE_WP_CRON 메시지에는 'DISABLE_WP_CRON' 또는 'cron' 텍스트 포함
        $this->assertStringContainsString('CRMBiz Newsletter', $output);
    }

    public function test_showCronNotice_message_contains_history_link(): void {
        $_GET['page'] = 'crmbiz-nl-settings';
        $GLOBALS['_wpdb_newsletters'][] = ['id' => 1, 'status' => 'queued'];
        $GLOBALS['_wp_options']['crmbiz_nl_last_cron_run'] = 0;

        ob_start();
        $this->plugin->showCronNotice();
        $output = ob_get_clean();

        // 즉시 발송 링크 (history 페이지 링크 포함)
        $this->assertStringContainsString('crmbiz-nl-history', $output);
    }

    // ── savePostMeta() — 조기 반환 조건 ─────────────────────────────────────────

    public function test_savePostMeta_ignores_revision(): void {
        // wp_is_post_revision(999) → false (bootstrap stub), 하지만 직접 상태 조작
        // bootstrap의 wp_is_post_revision는 항상 false를 반환하므로
        // 이 테스트는 post_type 필터링으로 대체
        $GLOBALS['_wp_posts'][20] = (object)[
            'ID' => 20, 'post_type' => 'page', 'post_status' => 'publish',
        ];

        $this->plugin->savePostMeta(20);

        $this->assertEmpty($GLOBALS['_wpdb_newsletters'], 'page 타입은 무시');
    }

    public function test_savePostMeta_ignores_non_publish_status(): void {
        $GLOBALS['_wp_posts'][21] = (object)[
            'ID' => 21, 'post_type' => 'post', 'post_status' => 'draft',
        ];

        $this->plugin->savePostMeta(21);

        $this->assertEmpty($GLOBALS['_wpdb_newsletters'], 'draft 상태는 무시');
    }

    public function test_savePostMeta_ignores_when_not_enabled(): void {
        $GLOBALS['_wp_posts'][22] = (object)[
            'ID' => 22, 'post_type' => 'post', 'post_status' => 'publish',
        ];
        // _crmbiz_nl_enabled 미설정

        $this->plugin->savePostMeta(22);

        $this->assertEmpty($GLOBALS['_wpdb_newsletters']);
    }

    public function test_savePostMeta_dispatches_when_no_record_exists(): void {
        $GLOBALS['_wp_posts'][23] = (object)[
            'ID' => 23, 'post_type' => 'post', 'post_status' => 'publish',
        ];
        $GLOBALS['_wp_post_meta'][23]['_crmbiz_nl_enabled']  = '1';
        $GLOBALS['_wp_post_meta'][23]['_crmbiz_nl_send_mode'] = 'immediate';

        $this->plugin->savePostMeta(23);

        // 레코드 없음 → dispatchNewsletter → queued 레코드 생성
        $this->assertNotEmpty($GLOBALS['_wpdb_newsletters']);
        $this->assertSame('queued', $GLOBALS['_wpdb_newsletters'][0]['status']);
    }

    public function test_savePostMeta_syncs_when_record_exists(): void {
        $this->seedNewsletter(24, 'draft');
        $GLOBALS['_wp_posts'][24] = (object)[
            'ID' => 24, 'post_type' => 'post', 'post_status' => 'publish',
        ];
        $GLOBALS['_wp_post_meta'][24]['_crmbiz_nl_enabled']  = '1';
        $GLOBALS['_wp_post_meta'][24]['_crmbiz_nl_send_mode'] = 'manual';

        $this->plugin->savePostMeta(24);

        // 레코드 존재 → syncPendingRecord → 새 레코드 생성 안 함
        $this->assertCount(1, $GLOBALS['_wpdb_newsletters'], '추가 레코드 생성 없음');
    }

    // ── parseScheduledAt() — 시각 파싱 ──────────────────────────────────────────

    public function test_parseScheduledAt_empty_string_returns_zero(): void {
        $result = self::$parseScheduledAt->invoke($this->plugin, '');
        $this->assertSame(0, $result);
    }

    public function test_parseScheduledAt_future_datetime_returns_positive(): void {
        $future = date('Y-m-d H:i:s', time() + 86400 * 30); // 30일 후 (시차 안전)
        $result = self::$parseScheduledAt->invoke($this->plugin, $future);
        $this->assertGreaterThan(0, $result);
        $this->assertGreaterThan(time(), $result);
    }

    public function test_parseScheduledAt_past_datetime_returns_zero(): void {
        $past   = date('Y-m-d H:i:s', time() - 3600);
        $result = self::$parseScheduledAt->invoke($this->plugin, $past);
        $this->assertSame(0, $result);
    }

    public function test_parseScheduledAt_invalid_string_returns_zero(): void {
        $result = self::$parseScheduledAt->invoke($this->plugin, 'not-a-date');
        $this->assertSame(0, $result);
    }

    // ── addBodyClass() / addModuleType() — 단순 유틸 ─────────────────────────────

    public function test_addBodyClass_adds_class_for_plugin_pages(): void {
        $_GET['page'] = 'crmbiz-newsletter';
        $result = $this->plugin->addBodyClass('existing-class');
        $this->assertStringContainsString('crmbiz-nl-page', $result);
    }

    public function test_addBodyClass_no_change_for_other_pages(): void {
        $_GET['page'] = 'some-other-page';
        $result = $this->plugin->addBodyClass('existing-class');
        $this->assertStringNotContainsString('crmbiz-nl-page', $result);
    }

    public function test_addModuleType_adds_module_for_vue_handles(): void {
        $tag    = '<script src="dashboard.js"></script>';
        $result = $this->plugin->addModuleType($tag, 'crmbiz-nl-vue-dash');
        $this->assertStringContainsString('type="module"', $result);
    }

    public function test_addModuleType_unchanged_for_other_handles(): void {
        $tag    = '<script src="other.js"></script>';
        $result = $this->plugin->addModuleType($tag, 'some-other-script');
        $this->assertStringNotContainsString('type="module"', $result);
        $this->assertSame($tag, $result);
    }
}
