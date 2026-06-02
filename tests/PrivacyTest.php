<?php
declare(strict_types=1);

use CRMBizNewsletter\Privacy;
use PHPUnit\Framework\TestCase;

class PrivacyTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['_wpdb_unsubscribers'] = [];
        $GLOBALS['_wpdb_sends']         = [];
        $GLOBALS['_wpdb_events']        = [];
        $GLOBALS['_wpdb_queue']         = [];
    }

    // ── 훅 등록 ───────────────────────────────────────────────────────────────

    public function test_registerExporter_adds_plugin_to_exporters(): void {
        $result = Privacy::registerExporter([]);

        $this->assertArrayHasKey('crmbiz-newsletter', $result);
        $this->assertSame('CRMBiz Newsletter', $result['crmbiz-newsletter']['exporter_friendly_name']);
        $this->assertIsCallable($result['crmbiz-newsletter']['callback']);
    }

    public function test_registerExporter_preserves_existing_exporters(): void {
        $existing = ['other-plugin' => ['exporter_friendly_name' => 'Other']];
        $result   = Privacy::registerExporter($existing);

        $this->assertArrayHasKey('other-plugin',       $result);
        $this->assertArrayHasKey('crmbiz-newsletter',  $result);
        $this->assertCount(2, $result);
    }

    public function test_registerEraser_adds_plugin_to_erasers(): void {
        $result = Privacy::registerEraser([]);

        $this->assertArrayHasKey('crmbiz-newsletter', $result);
        $this->assertSame('CRMBiz Newsletter', $result['crmbiz-newsletter']['eraser_friendly_name']);
        $this->assertIsCallable($result['crmbiz-newsletter']['callback']);
    }

    // ── export() — 데이터 없음 ─────────────────────────────────────────────────

    public function test_export_returns_empty_data_when_no_records(): void {
        $result = Privacy::export('ghost@example.com');

        $this->assertSame([], $result['data']);
        $this->assertTrue($result['done']);
    }

    public function test_export_always_returns_done_true(): void {
        $result = Privacy::export('any@example.com');
        $this->assertTrue($result['done']);
    }

    // ── export() — 수신거부 기록 ────────────────────────────────────────────────

    public function test_export_includes_unsubscribe_record(): void {
        $GLOBALS['_wpdb_unsubscribers'][] = [
            'id'              => 1,
            'email'           => 'sub@example.com',
            'unsubscribed_at' => '2026-01-15 10:00:00',
        ];

        $result = Privacy::export('sub@example.com');
        $groups = array_column($result['data'], 'group_id');

        $this->assertContains('crmbiz-newsletter-unsub', $groups);
    }

    public function test_export_unsub_contains_email_and_date(): void {
        $GLOBALS['_wpdb_unsubscribers'][] = [
            'id'              => 1,
            'email'           => 'sub@example.com',
            'unsubscribed_at' => '2026-06-01 09:00:00',
        ];

        $result   = Privacy::export('sub@example.com');
        $unsubRow = array_values(array_filter($result['data'], fn($r) => $r['group_id'] === 'crmbiz-newsletter-unsub'))[0];
        $dataMap  = array_column($unsubRow['data'], 'value', 'name');

        $this->assertSame('sub@example.com',    $dataMap['이메일']);
        $this->assertSame('2026-06-01 09:00:00', $dataMap['수신거부 일시']);
    }

    public function test_export_no_unsub_record_for_unknown_email(): void {
        $GLOBALS['_wpdb_unsubscribers'][] = [
            'id'    => 1,
            'email' => 'other@example.com',
            'unsubscribed_at' => '2026-01-01',
        ];

        $result = Privacy::export('unknown@example.com');
        $groups = array_column($result['data'], 'group_id');

        $this->assertNotContains('crmbiz-newsletter-unsub', $groups);
    }

    // ── export() — 발송 기록 ───────────────────────────────────────────────────

    public function test_export_includes_send_records(): void {
        $GLOBALS['_wpdb_sends'][] = [
            'id'            => 1,
            'newsletter_id' => 10,
            'email'         => 'reader@example.com',
            'status'        => 'sent',
            'sent_at'       => '2026-05-01 12:00:00',
        ];

        $result = Privacy::export('reader@example.com');
        $groups = array_column($result['data'], 'group_id');

        $this->assertContains('crmbiz-newsletter-send', $groups);
    }

    public function test_export_send_record_contains_status_and_date(): void {
        $GLOBALS['_wpdb_sends'][] = [
            'id'            => 1,
            'newsletter_id' => 5,
            'email'         => 'reader@example.com',
            'status'        => 'sent',
            'sent_at'       => '2026-05-01 12:00:00',
        ];

        $result   = Privacy::export('reader@example.com');
        $sendRow  = array_values(array_filter($result['data'], fn($r) => $r['group_id'] === 'crmbiz-newsletter-send'))[0];
        $dataMap  = array_column($sendRow['data'], 'value', 'name');

        $this->assertSame('sent',                $dataMap['상태']);
        $this->assertSame('2026-05-01 12:00:00', $dataMap['발송 일시']);
    }

    public function test_export_multiple_sends_all_included(): void {
        $email = 'multi@example.com';
        $GLOBALS['_wpdb_sends'][] = ['id' => 1, 'newsletter_id' => 1, 'email' => $email, 'status' => 'sent', 'sent_at' => '2026-01-01'];
        $GLOBALS['_wpdb_sends'][] = ['id' => 2, 'newsletter_id' => 2, 'email' => $email, 'status' => 'sent', 'sent_at' => '2026-02-01'];
        $GLOBALS['_wpdb_sends'][] = ['id' => 3, 'newsletter_id' => 3, 'email' => 'other@x.com', 'status' => 'sent', 'sent_at' => '2026-03-01'];

        $result    = Privacy::export($email);
        $sendItems = array_filter($result['data'], fn($r) => $r['group_id'] === 'crmbiz-newsletter-send');

        $this->assertCount(2, $sendItems);
    }

    // ── export() — 이벤트 기록 ─────────────────────────────────────────────────

    public function test_export_includes_event_records(): void {
        $GLOBALS['_wpdb_events'][] = [
            'id'            => 1,
            'newsletter_id' => 10,
            'email'         => 'clicker@example.com',
            'type'          => 'open',
            'occurred_at'   => '2026-05-01 14:00:00',
        ];

        $result = Privacy::export('clicker@example.com');
        $groups = array_column($result['data'], 'group_id');

        $this->assertContains('crmbiz-newsletter-event', $groups);
    }

    public function test_export_event_contains_type_and_date(): void {
        $GLOBALS['_wpdb_events'][] = [
            'id'            => 1,
            'newsletter_id' => 3,
            'email'         => 'user@example.com',
            'type'          => 'click',
            'occurred_at'   => '2026-06-01 08:00:00',
        ];

        $result   = Privacy::export('user@example.com');
        $evRow    = array_values(array_filter($result['data'], fn($r) => $r['group_id'] === 'crmbiz-newsletter-event'))[0];
        $dataMap  = array_column($evRow['data'], 'value', 'name');

        $this->assertSame('click',               $dataMap['유형']);
        $this->assertSame('2026-06-01 08:00:00', $dataMap['발생 일시']);
    }

    // ── export() — 전체 통합 ──────────────────────────────────────────────────

    public function test_export_all_three_groups_when_full_history(): void {
        $email = 'full@example.com';
        $GLOBALS['_wpdb_unsubscribers'][] = ['id' => 1, 'email' => $email, 'unsubscribed_at' => '2026-06-01'];
        $GLOBALS['_wpdb_sends'][]         = ['id' => 1, 'newsletter_id' => 1, 'email' => $email, 'status' => 'sent', 'sent_at' => '2026-05-01'];
        $GLOBALS['_wpdb_events'][]        = ['id' => 1, 'newsletter_id' => 1, 'email' => $email, 'type' => 'open', 'occurred_at' => '2026-05-01'];

        $result = Privacy::export($email);
        $groups = array_unique(array_column($result['data'], 'group_id'));

        $this->assertContains('crmbiz-newsletter-unsub',  $groups);
        $this->assertContains('crmbiz-newsletter-send',   $groups);
        $this->assertContains('crmbiz-newsletter-event',  $groups);
    }

    // ── erase() ───────────────────────────────────────────────────────────────

    public function test_erase_returns_done_true(): void {
        $result = Privacy::erase('nobody@example.com');
        $this->assertTrue($result['done']);
    }

    public function test_erase_returns_zero_removed_when_no_data(): void {
        $result = Privacy::erase('nobody@example.com');
        $this->assertSame(0, $result['items_removed']);
        $this->assertSame(0, $result['items_retained']);
        $this->assertSame([], $result['messages']);
    }

    public function test_erase_removes_unsubscriber_record(): void {
        $email = 'del@example.com';
        $GLOBALS['_wpdb_unsubscribers'][] = ['id' => 1, 'email' => $email, 'unsubscribed_at' => '2026-01-01'];
        $GLOBALS['_wpdb_unsubscribers'][] = ['id' => 2, 'email' => 'keep@example.com', 'unsubscribed_at' => '2026-01-01'];

        Privacy::erase($email);

        $remaining = array_column($GLOBALS['_wpdb_unsubscribers'], 'email');
        $this->assertNotContains($email, $remaining);
        $this->assertContains('keep@example.com', $remaining);
    }

    public function test_erase_removes_event_records(): void {
        $email = 'del@example.com';
        $GLOBALS['_wpdb_events'][] = ['id' => 1, 'email' => $email,          'type' => 'open'];
        $GLOBALS['_wpdb_events'][] = ['id' => 2, 'email' => 'keep@x.com', 'type' => 'click'];

        Privacy::erase($email);

        $remaining = array_column($GLOBALS['_wpdb_events'], 'email');
        $this->assertNotContains($email, $remaining);
        $this->assertContains('keep@x.com', $remaining);
    }

    public function test_erase_removes_send_records(): void {
        $email = 'del@example.com';
        $GLOBALS['_wpdb_sends'][] = ['id' => 1, 'email' => $email,       'status' => 'sent'];
        $GLOBALS['_wpdb_sends'][] = ['id' => 2, 'email' => 'keep@x.com', 'status' => 'sent'];

        Privacy::erase($email);

        $remaining = array_column($GLOBALS['_wpdb_sends'], 'email');
        $this->assertNotContains($email, $remaining);
    }

    public function test_erase_removes_queue_records(): void {
        $email = 'del@example.com';
        $GLOBALS['_wpdb_queue'][] = ['id' => 1, 'newsletter_id' => 1, 'email' => $email];
        $GLOBALS['_wpdb_queue'][] = ['id' => 2, 'newsletter_id' => 1, 'email' => 'keep@x.com'];

        Privacy::erase($email);

        $remaining = array_column($GLOBALS['_wpdb_queue'], 'email');
        $this->assertNotContains($email, $remaining);
    }

    public function test_erase_counts_all_removed_records(): void {
        $email = 'full@example.com';
        $GLOBALS['_wpdb_unsubscribers'][] = ['id' => 1, 'email' => $email, 'unsubscribed_at' => '2026-01-01'];
        $GLOBALS['_wpdb_sends'][]         = ['id' => 1, 'email' => $email, 'status' => 'sent'];
        $GLOBALS['_wpdb_sends'][]         = ['id' => 2, 'email' => $email, 'status' => 'sent'];
        $GLOBALS['_wpdb_events'][]        = ['id' => 1, 'email' => $email, 'type' => 'open'];
        $GLOBALS['_wpdb_queue'][]         = ['id' => 1, 'email' => $email];

        $result = Privacy::erase($email);

        // 4개 테이블에서 총 5건 삭제
        $this->assertSame(5, $result['items_removed']);
    }

    public function test_erase_does_not_touch_other_users_data(): void {
        $email = 'del@example.com';
        $keep  = 'keep@example.com';
        $GLOBALS['_wpdb_unsubscribers'][] = ['id' => 1, 'email' => $email, 'unsubscribed_at' => '2026-01-01'];
        $GLOBALS['_wpdb_unsubscribers'][] = ['id' => 2, 'email' => $keep,  'unsubscribed_at' => '2026-01-01'];
        $GLOBALS['_wpdb_events'][]        = ['id' => 1, 'email' => $email, 'type' => 'open'];
        $GLOBALS['_wpdb_events'][]        = ['id' => 2, 'email' => $keep,  'type' => 'open'];

        Privacy::erase($email);

        $this->assertCount(1, $GLOBALS['_wpdb_unsubscribers']);
        $this->assertSame($keep, $GLOBALS['_wpdb_unsubscribers'][0]['email']);
        $this->assertCount(1, $GLOBALS['_wpdb_events']);
        $this->assertSame($keep, $GLOBALS['_wpdb_events'][0]['email']);
    }

    // ── XSS 방어 ──────────────────────────────────────────────────────────────

    public function test_export_escapes_xss_in_email(): void {
        $xssEmail = 'user@example.com';
        $GLOBALS['_wpdb_unsubscribers'][] = [
            'id'              => 1,
            'email'           => '<script>alert(1)</script>@x.com',
            'unsubscribed_at' => '2026-01-01',
        ];

        // XSS 포함 이메일로 내보내기
        $result  = Privacy::export('<script>alert(1)</script>@x.com');
        $allData = array_merge(...array_column($result['data'], 'data'));
        $values  = array_column($allData, 'value');

        foreach ($values as $value) {
            $this->assertStringNotContainsString('<script>', $value);
        }
    }

}
