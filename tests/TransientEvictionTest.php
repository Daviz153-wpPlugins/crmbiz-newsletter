<?php
declare(strict_types=1);

use CRMBizNewsletter\RestApi;
use CRMBizNewsletter\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Managed 호스팅 Transient Eviction 테스트
 *
 * WP Engine / Kinsta 같은 환경은 Redis/Memcached 기반 Object Cache로
 * transient를 언제든 날릴 수 있다. 캐시가 항상 miss여도 getDashboard()가
 * 오류 없이 동일한 데이터를 반환하는지 검증한다.
 */

class EvictionWpdbStub extends WpdbStub {

    public function get_row(string $sql, string $output = 'OBJECT') {
        // stats 집계: SELECT COUNT(*) AS total_nl, SUM(success_count)...
        if (strpos($sql, 'SUM(success_count)') !== false) {
            $sent = array_values(array_filter(
                $GLOBALS['_wpdb_newsletters'],
                fn($r) => ($r['status'] ?? '') === 'sent'
            ));
            return (object) [
                'total_nl'      => count($sent),
                'total_success' => (string) array_sum(array_column($sent, 'success_count')),
                'total_fail'    => (string) array_sum(array_column($sent, 'fail_count')),
            ];
        }
        // pending 집계: COUNT(CASE WHEN status = 'scheduled'...)
        if (strpos($sql, 'CASE WHEN') !== false) {
            return (object) ['scheduled' => 0, 'queued' => 0, 'sending' => 0, 'draft' => 0];
        }
        return parent::get_row($sql, $output);
    }

    public function get_results(string $sql, string $output = 'OBJECT'): array {
        // 차트 쿼리: SELECT DATE(sent_at) AS day, SUM(success_count) ... GROUP BY DATE
        if (strpos($sql, 'DATE(sent_at)') !== false) {
            $sent = array_filter(
                $GLOBALS['_wpdb_newsletters'],
                fn($r) => ($r['status'] ?? '') === 'sent'
            );
            $byDay = [];
            foreach ($sent as $r) {
                if (!empty($r['sent_at'])) {
                    $day = substr($r['sent_at'], 0, 10);
                    $byDay[$day] = ($byDay[$day] ?? 0) + (int) ($r['success_count'] ?? 0);
                }
            }
            return array_values(array_map(
                fn($day, $cnt) => (object) ['day' => $day, 'cnt' => (string) $cnt],
                array_keys($byDay), $byDay
            ));
        }
        // 캠페인 목록: SELECT n.id, ... LEFT JOIN ... WHERE n.status = 'sent'
        if (strpos($sql, 'LEFT JOIN') !== false && strpos($sql, 'status') !== false) {
            return [];
        }
        return parent::get_results($sql, $output);
    }

    public function get_var(string $sql): ?string {
        // campaign_total: COUNT(*) WHERE status = 'sent'
        if (preg_match("/COUNT\(\*\).*status\s*=\s*'sent'/is", $sql)) {
            return (string) count(array_filter(
                $GLOBALS['_wpdb_newsletters'],
                fn($r) => ($r['status'] ?? '') === 'sent'
            ));
        }
        // nextScheduled
        if (strpos($sql, "status = 'scheduled'") !== false && strpos($sql, 'scheduled_at') !== false) {
            return null;
        }
        return parent::get_var($sql);
    }
}

class TransientEvictionTest extends TestCase {

    private RestApi $api;

    protected function setUp(): void {
        $GLOBALS['wpdb']              = new EvictionWpdbStub();
        $GLOBALS['_wpdb_newsletters'] = [];
        $GLOBALS['_wp_options']       = [];
        $GLOBALS['_wp_transients']    = [];
        $this->api = new RestApi(new Settings());
    }

    protected function tearDown(): void {
        $GLOBALS['wpdb']              = new WpdbStub();
        $GLOBALS['_wpdb_newsletters'] = [];
        $GLOBALS['_wp_options']       = [];
        $GLOBALS['_wp_transients']    = [];
    }

    private function seedSent(int $count, int $success = 100, int $fail = 5): void {
        $base = strtotime('2026-01-01');
        for ($i = 1; $i <= $count; $i++) {
            $GLOBALS['_wpdb_newsletters'][] = [
                'id'              => $i,
                'status'          => 'sent',
                'success_count'   => $success,
                'fail_count'      => $fail,
                'recipient_count' => $success + $fail,
                'sent_at'         => date('Y-m-d H:i:s', $base + $i * 3600),
            ];
        }
    }

    private function req(array $params = []): \WP_REST_Request {
        return new \WP_REST_Request('GET', $params);
    }

    // ── 빈 DB ────────────────────────────────────────────────────────────────

    public function test_empty_db_returns_200_without_error(): void {
        $data = $this->api->getDashboard($this->req())->get_data();

        $this->assertSame(0, $data['stats']['total_nl']);
        $this->assertSame(0, $data['stats']['success_rate']);
        $this->assertArrayHasKey('chart', $data);
    }

    // ── Eviction 후 데이터 일관성 ─────────────────────────────────────────────

    public function test_data_identical_after_single_eviction(): void {
        $this->seedSent(3, 100, 10);

        $data1 = $this->api->getDashboard($this->req())->get_data();

        $GLOBALS['_wp_transients'] = []; // Eviction 시뮬레이션

        $data2 = $this->api->getDashboard($this->req())->get_data();

        $this->assertSame($data1['stats']['total_nl'],      $data2['stats']['total_nl']);
        $this->assertSame($data1['stats']['total_success'], $data2['stats']['total_success']);
        $this->assertSame($data1['stats']['total_fail'],    $data2['stats']['total_fail']);
        $this->assertSame($data1['stats']['success_rate'],  $data2['stats']['success_rate']);
        $this->assertSame($data1['chart']['labels'],        $data2['chart']['labels']);
    }

    public function test_data_stable_under_repeated_evictions(): void {
        $this->seedSent(5, 200, 20);
        $expected = $this->api->getDashboard($this->req())->get_data()['stats'];

        for ($i = 0; $i < 5; $i++) {
            $GLOBALS['_wp_transients'] = []; // 매 호출 전 강제 eviction
            $stats = $this->api->getDashboard($this->req())->get_data()['stats'];
            $this->assertSame($expected['total_nl'],      $stats['total_nl'],      "반복 #{$i} total_nl 불일치");
            $this->assertSame($expected['total_success'], $stats['total_success'], "반복 #{$i} total_success 불일치");
        }
    }

    public function test_chart_days_variants_survive_eviction(): void {
        $this->seedSent(2);

        foreach ([7, 30, 90] as $days) {
            $GLOBALS['_wp_transients'] = [];
            $data = $this->api->getDashboard($this->req(['days' => $days]))->get_data();
            $this->assertCount($days, $data['chart']['labels'], "days={$days} chart labels 수 불일치");
            $this->assertCount($days, $data['chart']['counts'], "days={$days} chart counts 수 불일치");
        }
    }

    // ── clearDashboardCache 안전성 ────────────────────────────────────────────

    public function test_clearDashboardCache_safe_when_transients_empty(): void {
        $GLOBALS['_wp_transients'] = []; // 이미 비어있는 상태에서 삭제
        RestApi::clearDashboardCache();  // 예외 없어야 함
        $this->assertEmpty($GLOBALS['_wp_transients']);
    }

    public function test_clearDashboardCache_invalidates_all_keys(): void {
        $this->seedSent(2);
        $this->api->getDashboard($this->req(['days' => 7]));
        $this->api->getDashboard($this->req(['days' => 30]));
        $this->api->getDashboard($this->req(['days' => 90]));

        $keysBefore = count($GLOBALS['_wp_transients']);
        $this->assertGreaterThan(0, $keysBefore);

        RestApi::clearDashboardCache();

        $this->assertEmpty($GLOBALS['_wp_transients'], 'clearDashboardCache 후 transient 잔존');
    }

    // ── 응답 구조 완전성 ──────────────────────────────────────────────────────

    public function test_required_keys_present_after_eviction(): void {
        $this->seedSent(2, 50, 5);
        $GLOBALS['_wp_transients'] = [];
        $data = $this->api->getDashboard($this->req())->get_data();

        foreach (['stats', 'chart', 'pending', 'campaigns', 'campaign_total'] as $key) {
            $this->assertArrayHasKey($key, $data, "eviction 후 '{$key}' 키 누락");
        }
        foreach (['total_nl', 'total_success', 'total_fail', 'success_rate'] as $key) {
            $this->assertArrayHasKey($key, $data['stats'], "stats.{$key} 누락");
        }
    }
}
