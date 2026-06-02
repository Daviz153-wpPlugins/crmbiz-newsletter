<?php
declare(strict_types=1);

use CRMBizNewsletter\RestApi;
use CRMBizNewsletter\Settings;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * RestApi 비즈니스 로직 — 계산식·응답 구조 검증
 *
 * RestApiPermissionTest가 권한 확인과 입력 화이트리스트를 커버하므로,
 * 이 파일은 실제 데이터 계산(rate, percent, 404 등)에 집중한다.
 *
 * WpdbStub을 확장해 RestApi가 사용하는 복잡한 쿼리 패턴을 처리한다.
 */

/**
 * RestApi 전용 WpdbStub 확장:
 * - get_results: WHERE id IN / newsletter_id 기반 이벤트 집계
 * - get_row:     SUM(success_count) 집계 / 복잡 JOIN 패턴
 * - get_var:     COUNT(*) 집계
 */
class RestApiWpdbStub extends WpdbStub {

    /** getProgress: SELECT ... WHERE id IN (1,2,3) */
    public function get_results(string $sql, string $output = 'OBJECT'): array {
        if (preg_match("/crmbiz_newsletters WHERE id IN \(([0-9, ]+)\)/i", $sql, $m)) {
            $ids  = array_map('intval', explode(',', $m[1]));
            $rows = array_values(array_filter(
                $GLOBALS['_wpdb_newsletters'],
                fn($r) => in_array((int) ($r['id'] ?? 0), $ids, true)
            ));
            return array_map(fn($r) => (object) $r, $rows);
        }

        // getNewsletterDetail: recipient 이벤트 집계 (GROUP BY email)
        if (preg_match("/crmbiz_nl_events.*WHERE newsletter_id = (\d+)/is", $sql, $m)) {
            $nlId  = (int) $m[1];
            $events = array_filter(
                $GLOBALS['_wpdb_events'],
                fn($r) => (int) ($r['newsletter_id'] ?? 0) === $nlId
            );
            // email별로 그루핑
            $byEmail = [];
            foreach ($events as $e) {
                $em = $e['email'] ?? '';
                $byEmail[$em][] = $e['type'] ?? '';
            }
            $rows = [];
            foreach ($byEmail as $email => $types) {
                $rows[] = (object) [
                    'email'        => $email,
                    'opened'       => in_array('open', $types, true) || in_array('click', $types, true) ? 1 : 0,
                    'clicked'      => in_array('click', $types, true) ? 1 : 0,
                    'failed'       => in_array('fail', $types, true) ? 1 : 0,
                    'unsubscribed' => in_array('unsubscribe', $types, true) ? 1 : 0,
                    'sent_at'      => null,
                    'open_at'      => null,
                    'click_at'     => null,
                ];
            }
            return $rows;
        }

        return parent::get_results($sql, $output);
    }

    /** getDashboard: 집계 쿼리 / getNewsletterDetail: 복잡 JOIN */
    public function get_row(string $sql, string $output = 'OBJECT') {
        // getDashboard stats: SELECT COUNT(*) AS total_nl, SUM(success_count)...
        if (strpos($sql, 'SUM(success_count)') !== false) {
            $sent = array_filter(
                $GLOBALS['_wpdb_newsletters'],
                fn($r) => ($r['status'] ?? '') === 'sent'
            );
            return (object) [
                'total_nl'      => count($sent),
                'total_success' => array_sum(array_column($sent, 'success_count')),
                'total_fail'    => array_sum(array_column($sent, 'fail_count')),
            ];
        }

        // getDashboard pending: COUNT(CASE WHEN status = 'scheduled'...)
        if (strpos($sql, "status = 'scheduled'") !== false && strpos($sql, 'CASE WHEN') !== false) {
            $nls = $GLOBALS['_wpdb_newsletters'];
            return (object) [
                'scheduled' => count(array_filter($nls, fn($r) => ($r['status'] ?? '') === 'scheduled')),
                'queued'    => count(array_filter($nls, fn($r) => ($r['status'] ?? '') === 'queued')),
                'sending'   => count(array_filter($nls, fn($r) => ($r['status'] ?? '') === 'sending')),
                'draft'     => count(array_filter($nls, fn($r) => ($r['status'] ?? '') === 'draft')),
            ];
        }

        // getNewsletterDetail: 복잡 JOIN (SELECT n.*, ec.open_count...)
        if (strpos($sql, 'crmbiz_nl_events') !== false && preg_match('/WHERE n\.id = (\d+)/i', $sql, $m)) {
            $id = (int) $m[1];
            foreach ($GLOBALS['_wpdb_newsletters'] as $row) {
                if ((int) ($row['id'] ?? 0) === $id) {
                    // events 집계
                    $events = array_filter($GLOBALS['_wpdb_events'], fn($e) => (int)($e['newsletter_id'] ?? 0) === $id);
                    $opens  = count(array_unique(array_column(
                        array_filter($events, fn($e) => in_array($e['type'] ?? '', ['open', 'click'], true)),
                        'email'
                    )));
                    $clicks = count(array_unique(array_column(
                        array_filter($events, fn($e) => ($e['type'] ?? '') === 'click'),
                        'email'
                    )));
                    $r = array_merge($row, [
                        'post_title'  => $row['post_title'] ?? '(삭제된 포스트)',
                        'open_count'  => $opens,
                        'click_count' => $clicks,
                    ]);
                    return (object) $r;
                }
            }
            return null;
        }

        return parent::get_row($sql, $output);
    }

    /** getDashboard: SELECT COUNT(*) FROM newsletters WHERE status = 'sent' */
    public function get_var(string $sql): ?string {
        if (preg_match("/SELECT COUNT\(\*\) FROM \S+crmbiz_newsletters WHERE status = 'sent'/i", $sql)) {
            $count = count(array_filter(
                $GLOBALS['_wpdb_newsletters'],
                fn($r) => ($r['status'] ?? '') === 'sent'
            ));
            return (string) $count;
        }
        return parent::get_var($sql);
    }
}

// ─────────────────────────────────────────────────────────────────────────────

class RestApiBusinessLogicTest extends TestCase {

    private RestApi $api;
    private static \ReflectionMethod $formatNewsletter;

    public static function setUpBeforeClass(): void {
        self::$formatNewsletter = new \ReflectionMethod(RestApi::class, 'formatNewsletter');
    }

    protected function setUp(): void {
        $GLOBALS['wpdb']                = new RestApiWpdbStub();
        $GLOBALS['_wpdb_newsletters']   = [];
        $GLOBALS['_wpdb_events']        = [];
        $GLOBALS['_wp_options']         = [];
        $GLOBALS['_wp_posts']           = [];
        $GLOBALS['_wp_transients']      = []; // 캐시 격리
        $this->api = new RestApi(new Settings());
    }

    protected function tearDown(): void {
        $GLOBALS['wpdb']              = new WpdbStub();
        $GLOBALS['_wpdb_newsletters'] = [];
        $GLOBALS['_wpdb_events']      = [];
        $GLOBALS['_wp_options']       = [];
        $GLOBALS['_wp_posts']         = [];
        $GLOBALS['_wp_transients']    = [];
    }

    // ── formatNewsletter() — open_rate · click_rate 계산 ─────────────────────

    public function test_formatNewsletter_open_rate_calculation(): void {
        $nl = (object) [
            'id' => 1, 'post_id' => 10, 'post_title' => '제목',
            'status' => 'sent', 'send_mode' => 'immediate',
            'scheduled_at' => null, 'sent_at' => '2026-01-01 12:00:00',
            'created_at' => '2026-01-01 10:00:00',
            'recipient_count' => 100, 'success_count' => 80,
            'fail_count' => 20, 'open_count' => 40, 'click_count' => 10,
            'fail_reason' => null,
        ];

        $result = self::$formatNewsletter->invoke($this->api, $nl);

        // open_rate = round(40/80 * 100, 1) = 50.0
        $this->assertSame(50.0, $result['open_rate']);
        // click_rate = round(10/80 * 100, 1) = 12.5
        $this->assertSame(12.5, $result['click_rate']);
    }

    public function test_formatNewsletter_rates_are_zero_when_no_success(): void {
        $nl = (object) [
            'id' => 2, 'post_id' => 0, 'post_title' => '제목',
            'status' => 'failed', 'send_mode' => 'immediate',
            'scheduled_at' => null, 'sent_at' => null, 'created_at' => '2026-01-01',
            'recipient_count' => 100, 'success_count' => 0,
            'fail_count' => 100, 'open_count' => 0, 'click_count' => 0,
            'fail_reason' => null,
        ];

        $result = self::$formatNewsletter->invoke($this->api, $nl);

        $this->assertSame(0, $result['open_rate']);
        $this->assertSame(0, $result['click_rate']);
    }

    public function test_formatNewsletter_post_id_zero_yields_null_urls(): void {
        $nl = (object) [
            'id' => 3, 'post_id' => 0, 'post_title' => '제목',
            'status' => 'draft', 'send_mode' => 'immediate',
            'scheduled_at' => null, 'sent_at' => null, 'created_at' => '2026-01-01',
            'recipient_count' => 0, 'success_count' => 0,
            'fail_count' => 0, 'open_count' => 0, 'click_count' => 0,
            'fail_reason' => null,
        ];

        $result = self::$formatNewsletter->invoke($this->api, $nl);

        $this->assertNull($result['post_url']);
        $this->assertNull($result['preview_url']);
    }

    public function test_formatNewsletter_rounded_to_one_decimal(): void {
        $nl = (object) [
            'id' => 4, 'post_id' => 10, 'post_title' => '제목',
            'status' => 'sent', 'send_mode' => 'immediate',
            'scheduled_at' => null, 'sent_at' => '2026-01-01',
            'created_at' => '2026-01-01',
            'recipient_count' => 3, 'success_count' => 3,
            'fail_count' => 0, 'open_count' => 1, 'click_count' => 1,
            'fail_reason' => null,
        ];

        $result = self::$formatNewsletter->invoke($this->api, $nl);

        // open_rate = round(1/3 * 100, 1) = 33.3
        $this->assertSame(33.3, $result['open_rate']);
    }

    // ── getProgress() — percent 공식 ─────────────────────────────────────────

    public function test_getProgress_empty_ids_returns_empty_array(): void {
        $req    = new \WP_REST_Request('GET', []);
        $res    = $this->api->getProgress($req);
        $data   = $res->get_data();

        $this->assertSame([], $data);
    }

    public function test_getProgress_percent_formula(): void {
        $GLOBALS['_wpdb_newsletters'][] = [
            'id' => 1, 'status' => 'sending',
            'success_count' => 40, 'fail_count' => 10, 'recipient_count' => 100,
        ];

        $req  = new \WP_REST_Request('GET', ['ids' => [1]]);
        $res  = $this->api->getProgress($req);
        $row  = $res->get_data()[0];

        // done = 40 + 10 = 50, percent = round(50/100 * 100) = 50
        $this->assertSame(50, $row['done']);
        $this->assertEquals(50, $row['percent']); // round() returns float
        $this->assertSame('sending', $row['status']);
    }

    public function test_getProgress_percent_caps_at_100(): void {
        $GLOBALS['_wpdb_newsletters'][] = [
            'id' => 2, 'status' => 'sent',
            'success_count' => 110, 'fail_count' => 0, 'recipient_count' => 100,
        ];

        $req = new \WP_REST_Request('GET', ['ids' => [2]]);
        $row = $this->api->getProgress($req)->get_data()[0];

        // min(100, round(110/100 * 100)) = 100
        $this->assertSame(100, $row['percent']);
    }

    public function test_getProgress_percent_zero_when_no_recipients(): void {
        $GLOBALS['_wpdb_newsletters'][] = [
            'id' => 3, 'status' => 'queued',
            'success_count' => 0, 'fail_count' => 0, 'recipient_count' => 0,
        ];

        $req = new \WP_REST_Request('GET', ['ids' => [3]]);
        $row = $this->api->getProgress($req)->get_data()[0];

        $this->assertSame(0, $row['percent']);
        $this->assertSame(0, $row['done']);
    }

    public function test_getProgress_done_includes_both_success_and_fail(): void {
        $GLOBALS['_wpdb_newsletters'][] = [
            'id' => 4, 'status' => 'sending',
            'success_count' => 30, 'fail_count' => 5, 'recipient_count' => 50,
        ];

        $req = new \WP_REST_Request('GET', ['ids' => [4]]);
        $row = $this->api->getProgress($req)->get_data()[0];

        $this->assertSame(35, $row['done']); // 30 + 5
    }

    public function test_getProgress_filters_out_nonexistent_ids(): void {
        $GLOBALS['_wpdb_newsletters'][] = [
            'id' => 5, 'status' => 'sent',
            'success_count' => 10, 'fail_count' => 0, 'recipient_count' => 10,
        ];

        $req  = new \WP_REST_Request('GET', ['ids' => [5, 999]]);
        $data = $this->api->getProgress($req)->get_data();

        $this->assertCount(1, $data);
        $this->assertSame(5, $data[0]['id']);
    }

    // ── getNewsletterDetail() — 404 · stats 계산 ─────────────────────────────

    public function test_getNewsletterDetail_returns_404_for_missing_id(): void {
        $req = new \WP_REST_Request('GET', ['id' => 9999]);
        $res = $this->api->getNewsletterDetail($req);

        $this->assertSame(404, $res->get_status());
    }

    private function seedDetailNewsletter(int $id, int $sent, int $fail, int $recipients): void {
        $GLOBALS['_wpdb_newsletters'][] = [
            'id' => $id, 'post_id' => 0, 'post_title' => '테스트',
            'status' => 'sent', 'send_mode' => 'immediate',
            'scheduled_at' => null, 'sent_at' => '2026-01-01',
            'created_at' => '2026-01-01',
            'recipient_count' => $recipients,
            'success_count' => $sent,
            'fail_count' => $fail,
            'fail_reason' => null,
        ];
    }

    public function test_getNewsletterDetail_open_rate_and_click_rate(): void {
        $this->seedDetailNewsletter(10, 100, 0, 100);
        // 10명 오픈, 5명 클릭
        foreach (range(1, 10) as $i) {
            $GLOBALS['_wpdb_events'][] = ['newsletter_id' => 10, 'email' => "open{$i}@e.com", 'type' => 'open'];
        }
        foreach (range(1, 5) as $i) {
            $GLOBALS['_wpdb_events'][] = ['newsletter_id' => 10, 'email' => "click{$i}@e.com", 'type' => 'click'];
        }

        $req   = new \WP_REST_Request('GET', ['id' => 10]);
        $stats = $this->api->getNewsletterDetail($req)->get_data()['stats'];

        // open_rate = round((10+5)/100 * 100, 1) = 15.0 (open OR click)
        $this->assertSame(15.0, $stats['open_rate']);
        // click_rate = round(5/100 * 100, 1) = 5.0
        $this->assertSame(5.0, $stats['click_rate']);
    }

    public function test_getNewsletterDetail_ctr_click_through_rate(): void {
        $this->seedDetailNewsletter(20, 100, 0, 100);
        // 20명 오픈, 그 중 10명 클릭
        foreach (range(1, 20) as $i) {
            $GLOBALS['_wpdb_events'][] = ['newsletter_id' => 20, 'email' => "o{$i}@e.com", 'type' => 'open'];
        }
        foreach (range(1, 10) as $i) {
            $GLOBALS['_wpdb_events'][] = ['newsletter_id' => 20, 'email' => "c{$i}@e.com", 'type' => 'click'];
        }

        $req   = new \WP_REST_Request('GET', ['id' => 20]);
        $stats = $this->api->getNewsletterDetail($req)->get_data()['stats'];

        // opens = 20+10 = 30, clicks = 10
        // ctr = round(10/30 * 100, 1) = 33.3
        $this->assertSame(33.3, $stats['ctr']);
    }

    public function test_getNewsletterDetail_fail_rate(): void {
        $this->seedDetailNewsletter(30, 80, 20, 100);

        $req   = new \WP_REST_Request('GET', ['id' => 30]);
        $stats = $this->api->getNewsletterDetail($req)->get_data()['stats'];

        // fail_rate = round(20/100 * 100, 1) = 20.0
        $this->assertSame(20.0, $stats['fail_rate']);
    }

    public function test_getNewsletterDetail_all_rates_zero_when_no_sent(): void {
        $this->seedDetailNewsletter(40, 0, 0, 100);

        $req   = new \WP_REST_Request('GET', ['id' => 40]);
        $stats = $this->api->getNewsletterDetail($req)->get_data()['stats'];

        $this->assertSame(0, $stats['open_rate']);
        $this->assertSame(0, $stats['click_rate']);
        $this->assertSame(0, $stats['unsub_rate']);
        $this->assertSame(0, $stats['ctr']);
    }

    public function test_getNewsletterDetail_unsub_rate(): void {
        $this->seedDetailNewsletter(50, 100, 0, 100);
        foreach (range(1, 4) as $i) {
            $GLOBALS['_wpdb_events'][] = ['newsletter_id' => 50, 'email' => "u{$i}@e.com", 'type' => 'unsubscribe'];
        }

        $req   = new \WP_REST_Request('GET', ['id' => 50]);
        $stats = $this->api->getNewsletterDetail($req)->get_data()['stats'];

        // unsub_rate = round(4/100 * 100, 1) = 4.0
        $this->assertSame(4.0, $stats['unsub_rate']);
    }

    // ── getDashboard() — success_rate 계산 ───────────────────────────────────

    public function test_getDashboard_success_rate_calculation(): void {
        // sent 뉴스레터 2건: 성공 80 + 실패 20 = 100 delivered
        $GLOBALS['_wpdb_newsletters'][] = [
            'id' => 1, 'status' => 'sent',
            'success_count' => 70, 'fail_count' => 10,
            'recipient_count' => 80,
        ];
        $GLOBALS['_wpdb_newsletters'][] = [
            'id' => 2, 'status' => 'sent',
            'success_count' => 10, 'fail_count' => 10,
            'recipient_count' => 20,
        ];

        $req   = new \WP_REST_Request('GET', []);
        $stats = $this->api->getDashboard($req)->get_data()['stats'];

        // total_success=80, total_fail=20, delivered=100
        // success_rate = round(80/100 * 100, 1) = 80.0
        $this->assertSame(80, $stats['total_success']);
        $this->assertSame(20, $stats['total_fail']);
        $this->assertSame(80.0, $stats['success_rate']);
    }

    public function test_getDashboard_success_rate_zero_when_no_sent(): void {
        // 발송 완료된 뉴스레터 없음
        $req   = new \WP_REST_Request('GET', []);
        $stats = $this->api->getDashboard($req)->get_data()['stats'];

        $this->assertSame(0, $stats['success_rate']);
        $this->assertSame(0, $stats['total_nl']);
    }

    public function test_getDashboard_chart_has_correct_day_count(): void {
        $req  = new \WP_REST_Request('GET', ['days' => 7]);
        $data = $this->api->getDashboard($req)->get_data();

        $this->assertSame(7, $data['chart']['days']);
        $this->assertCount(7, $data['chart']['labels']);
        $this->assertCount(7, $data['chart']['counts']);
    }

    public function test_getDashboard_invalid_days_falls_back_to_30(): void {
        $req  = new \WP_REST_Request('GET', ['days' => 999]);
        $data = $this->api->getDashboard($req)->get_data();

        $this->assertSame(30, $data['chart']['days']);
        $this->assertCount(30, $data['chart']['labels']);
    }

    public function test_getDashboard_response_has_required_keys(): void {
        $req  = new \WP_REST_Request('GET', []);
        $data = $this->api->getDashboard($req)->get_data();

        foreach (['stats', 'pending', 'chart', 'campaign_total', 'campaign_pages', 'campaigns', 'system'] as $key) {
            $this->assertArrayHasKey($key, $data, "응답에 '{$key}' 키가 없습니다.");
        }
        foreach (['total_nl', 'total_success', 'total_fail', 'success_rate'] as $key) {
            $this->assertArrayHasKey($key, $data['stats'], "stats에 '{$key}' 키가 없습니다.");
        }
    }

    public function test_getDashboard_campaign_pages_minimum_one(): void {
        // 뉴스레터 없어도 campaign_pages는 최소 1
        $req  = new \WP_REST_Request('GET', []);
        $data = $this->api->getDashboard($req)->get_data();

        $this->assertGreaterThanOrEqual(1, $data['campaign_pages']);
    }

    // ── getDashboard() 캐시 동작 ─────────────────────────────────────────────

    public function test_getDashboard_stats_cached_on_second_call(): void {
        $this->seedForDashboard(80, 20);

        // 첫 번째 호출 → DB 조회 후 transient 저장
        $req = new \WP_REST_Request('GET', []);
        $first = $this->api->getDashboard($req)->get_data();

        // 트랜지언트가 저장됐는지 확인
        $cached = get_transient('crmbiz_nl_dash_stats');
        $this->assertNotFalse($cached, 'stats transient이 저장되어야 함');

        // DB에서 데이터를 바꿔도 캐시에서 응답
        $GLOBALS['_wpdb_newsletters'] = [];
        $second = $this->api->getDashboard($req)->get_data();

        $this->assertSame($first['stats']['total_success'], $second['stats']['total_success'],
            '두 번째 호출은 캐시에서 반환');
    }

    public function test_clearDashboardCache_removes_all_transients(): void {
        $this->seedForDashboard(10, 5);

        // 캐시 채우기
        $this->api->getDashboard(new \WP_REST_Request('GET', ['days' => 7]));
        $this->api->getDashboard(new \WP_REST_Request('GET', ['days' => 30]));

        $this->assertNotFalse(get_transient('crmbiz_nl_dash_stats'));
        $this->assertNotFalse(get_transient('crmbiz_nl_dash_chart_7'));
        $this->assertNotFalse(get_transient('crmbiz_nl_dash_chart_30'));

        // 무효화
        RestApi::clearDashboardCache();

        $this->assertFalse(get_transient('crmbiz_nl_dash_stats'), 'stats 캐시 삭제됨');
        $this->assertFalse(get_transient('crmbiz_nl_dash_chart_7'), 'chart_7 캐시 삭제됨');
        $this->assertFalse(get_transient('crmbiz_nl_dash_chart_30'), 'chart_30 캐시 삭제됨');
        $this->assertFalse(get_transient('crmbiz_nl_dash_chart_90'), 'chart_90 캐시 삭제됨');
    }

    public function test_getDashboard_cache_key_differs_by_days(): void {
        $this->seedForDashboard(50, 10);

        $this->api->getDashboard(new \WP_REST_Request('GET', ['days' => 7]));
        $this->api->getDashboard(new \WP_REST_Request('GET', ['days' => 90]));

        $this->assertNotFalse(get_transient('crmbiz_nl_dash_chart_7'));
        $this->assertNotFalse(get_transient('crmbiz_nl_dash_chart_90'));
        // 30일은 아직 조회 안 했으므로 캐시 없음
        $this->assertFalse(get_transient('crmbiz_nl_dash_chart_30'));
    }

    private function seedForDashboard(int $success, int $fail): void {
        $GLOBALS['_wpdb_newsletters'][] = [
            'id' => 1, 'status' => 'sent',
            'success_count' => $success, 'fail_count' => $fail,
            'recipient_count' => $success + $fail,
        ];
    }
}
