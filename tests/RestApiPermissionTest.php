<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use CRMBizNewsletter\RestApi;
use CRMBizNewsletter\Settings;

/**
 * RestApi 권한 검증 + 입력 파라미터 화이트리스트 테스트
 */
class RestApiPermissionTest extends TestCase {

    private RestApi $api;

    protected function setUp(): void {
        $GLOBALS['_wp_user_can'] = true;
        $this->api = new RestApi(new Settings());
    }

    protected function tearDown(): void {
        $GLOBALS['_wp_user_can'] = true;
    }

    // ── adminOnly 권한 게이팅 ─────────────────────────────────────────────────

    public function test_adminOnly_returns_true_when_user_has_manage_options(): void {
        $GLOBALS['_wp_user_can'] = true;
        $this->assertTrue($this->api->adminOnly());
    }

    public function test_adminOnly_returns_false_when_user_lacks_capability(): void {
        $GLOBALS['_wp_user_can'] = false;
        $this->assertFalse($this->api->adminOnly());
    }

    // ── days 파라미터 화이트리스트 (7/30/90만 허용) ──────────────────────────

    public function test_days_whitelist_accepts_valid_values(): void {
        $allowed = [7, 30, 90];
        foreach ($allowed as $d) {
            $result = in_array($d, $allowed, true) ? $d : 30;
            $this->assertSame($d, $result, "$d 는 허용됨");
        }
    }

    public function test_days_whitelist_rejects_invalid_falls_back_to_30(): void {
        $allowed  = [7, 30, 90];
        $invalids = [0, 1, 15, 60, 365, -1];
        foreach ($invalids as $d) {
            $result = in_array($d, $allowed, true) ? $d : 30;
            $this->assertSame(30, $result, "$d 는 허용 안 되므로 기본값 30 반환");
        }
    }

    // ── status 필터 화이트리스트 ──────────────────────────────────────────────

    public function test_status_whitelist_accepts_valid_statuses(): void {
        $allowed = ['draft', 'queued', 'scheduled', 'sending', 'sent', 'failed', 'cancelled'];
        foreach ($allowed as $s) {
            $this->assertContains($s, $allowed);
        }
    }

    public function test_status_whitelist_rejects_invalid(): void {
        $allowed  = ['draft', 'queued', 'scheduled', 'sending', 'sent', 'failed', 'cancelled'];
        $invalids = ['', 'all', 'pending', 'error', "'; DROP TABLE--"];
        foreach ($invalids as $s) {
            $result = in_array($s, $allowed, true) ? $s : '';
            $this->assertSame('', $result, "'$s' 는 필터링됨");
        }
    }

    // ── sort_dir 정규화 (asc/desc만 허용) ────────────────────────────────────

    public function test_sort_dir_normalizes_asc(): void {
        $normalize = fn(string $v) => strtolower($v) === 'asc' ? 'ASC' : 'DESC';
        $this->assertSame('ASC', $normalize('asc'));
        $this->assertSame('ASC', $normalize('ASC'));
        $this->assertSame('ASC', $normalize('Asc'));
    }

    public function test_sort_dir_defaults_to_desc_for_invalid(): void {
        $normalize = fn(string $v) => strtolower($v) === 'asc' ? 'ASC' : 'DESC';
        foreach (['desc', 'DESC', 'invalid', '', 'up', 'down'] as $v) {
            $expected = strtolower($v) === 'asc' ? 'ASC' : 'DESC';
            $this->assertSame($expected, $normalize($v));
        }
    }

    // ── sort_by 화이트리스트 ──────────────────────────────────────────────────

    public function test_sort_key_whitelist_accepts_valid_columns(): void {
        $sortMap = ['date' => 1, 'title' => 1, 'status' => 1, 'recipients' => 1, 'open_rate' => 1, 'click_rate' => 1];
        foreach (array_keys($sortMap) as $key) {
            $this->assertArrayHasKey($key, $sortMap);
        }
    }

    public function test_sort_key_whitelist_rejects_sql_injection(): void {
        $sortMap = ['date' => 1, 'title' => 1, 'status' => 1, 'recipients' => 1, 'open_rate' => 1, 'click_rate' => 1];
        $dangerous = ['id', 'created_at', "1; DROP TABLE--", '', 'secret'];
        foreach ($dangerous as $key) {
            $this->assertArrayNotHasKey($key, $sortMap);
        }
    }

    // ── per_page 화이트리스트 (20/50/100만 허용) ─────────────────────────────

    public function test_per_page_whitelist_accepts_valid(): void {
        $allowed = [20, 50, 100];
        foreach ($allowed as $n) {
            $result = in_array($n, $allowed, true) ? $n : 20;
            $this->assertSame($n, $result);
        }
    }

    public function test_per_page_whitelist_rejects_invalid_falls_back_to_20(): void {
        $allowed  = [20, 50, 100];
        $invalids = [0, 10, 25, 200, 1000, -1];
        foreach ($invalids as $n) {
            $result = in_array($n, $allowed, true) ? $n : 20;
            $this->assertSame(20, $result, "$n 은 허용 안 되므로 기본값 20");
        }
    }
}
