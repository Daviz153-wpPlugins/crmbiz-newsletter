<?php
declare(strict_types=1);

use CRMBizNewsletter\Logger;
use PHPUnit\Framework\TestCase;

class LoggerTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['_wpdb_logs']     = [];
        $GLOBALS['_wp_mail_calls'] = [];
        $GLOBALS['_wp_transients'] = [];
        // Database::isInstalled() = true
        $GLOBALS['_wp_options']['crmbiz_nl_db_version'] = '2.0.0';
        // 기본 관리자 이메일
        $GLOBALS['_wp_options']['admin_email'] = 'admin@example.com';
        // 이메일 알림 기본 활성
        unset($GLOBALS['_wp_options']['crmbiz_nl_settings']);
    }

    // ── error() ───────────────────────────────────────────────────────────────

    public function test_error_writes_to_db(): void {
        Logger::error('something broke');

        $this->assertCount(1, $GLOBALS['_wpdb_logs']);
        $this->assertSame('ERROR', $GLOBALS['_wpdb_logs'][0]['level']);
        $this->assertSame('something broke', $GLOBALS['_wpdb_logs'][0]['message']);
    }

    public function test_error_stores_context_as_json(): void {
        Logger::error('fail', ['key' => 'value', 'code' => 500]);

        $ctx = $GLOBALS['_wpdb_logs'][0]['context'];
        $this->assertNotNull($ctx);
        $decoded = json_decode($ctx, true);
        $this->assertSame('value', $decoded['key']);
        $this->assertSame(500, $decoded['code']);
    }

    public function test_error_stores_null_context_when_empty(): void {
        Logger::error('no context');

        $this->assertNull($GLOBALS['_wpdb_logs'][0]['context']);
    }

    public function test_error_sends_email_to_admin(): void {
        Logger::error('disk full');

        $this->assertCount(1, $GLOBALS['_wp_mail_calls']);
        $this->assertSame('admin@example.com', $GLOBALS['_wp_mail_calls'][0]['to']);
        $this->assertStringContainsString('disk full', $GLOBALS['_wp_mail_calls'][0]['subject']);
    }

    public function test_error_email_uses_notify_email_when_set(): void {
        $GLOBALS['_wp_options']['crmbiz_nl_settings'] = ['notify_email' => 'ops@company.com'];

        Logger::error('cpu spike');

        $this->assertSame('ops@company.com', $GLOBALS['_wp_mail_calls'][0]['to']);
    }

    public function test_error_skips_email_when_invalid_notify_email(): void {
        $GLOBALS['_wp_options']['crmbiz_nl_settings'] = ['notify_email' => 'NOT_AN_EMAIL'];

        Logger::error('test');

        $this->assertEmpty($GLOBALS['_wp_mail_calls']);
    }

    public function test_error_skips_email_when_disable_error_email_set(): void {
        $GLOBALS['_wp_options']['crmbiz_nl_settings'] = [
            'disable_error_email' => 1,
            'notify_email'        => 'admin@example.com',
        ];

        Logger::error('should not email');

        $this->assertEmpty($GLOBALS['_wp_mail_calls']);
    }

    public function test_error_rate_limits_email_per_message(): void {
        Logger::error('repeated error');   // 1회 — 이메일 발송
        Logger::error('repeated error');   // 2회 — transient 있으므로 발송 안 됨

        $this->assertCount(1, $GLOBALS['_wp_mail_calls']);
    }

    public function test_error_different_messages_each_send_email(): void {
        Logger::error('error A');
        Logger::error('error B');

        // 서로 다른 메시지 → 서로 다른 transient 키 → 각각 1회 발송
        $this->assertCount(2, $GLOBALS['_wp_mail_calls']);
    }

    // ── warning() ─────────────────────────────────────────────────────────────

    public function test_warning_writes_to_db_with_warn_level(): void {
        Logger::warning('slow query detected');

        $this->assertCount(1, $GLOBALS['_wpdb_logs']);
        $this->assertSame('WARN', $GLOBALS['_wpdb_logs'][0]['level']);
    }

    public function test_warning_does_not_send_email(): void {
        Logger::warning('something odd');

        $this->assertEmpty($GLOBALS['_wp_mail_calls']);
    }

    public function test_warning_stores_context(): void {
        Logger::warning('retry exhausted', ['attempt' => 3, 'id' => 99]);

        $ctx = json_decode($GLOBALS['_wpdb_logs'][0]['context'], true);
        $this->assertSame(3, $ctx['attempt']);
    }

    // ── info() ────────────────────────────────────────────────────────────────

    public function test_info_does_not_write_to_db(): void {
        // info()는 writeDb()를 호출하지 않음
        Logger::info('debug detail');

        $this->assertEmpty($GLOBALS['_wpdb_logs']);
    }

    public function test_info_does_not_send_email(): void {
        Logger::info('just info');

        $this->assertEmpty($GLOBALS['_wp_mail_calls']);
    }

    // ── writeDb() 엣지케이스 ───────────────────────────────────────────────────

    public function test_message_over_500_chars_is_truncated_to_500(): void {
        $longMsg = str_repeat('x', 600);
        Logger::error($longMsg);

        $stored = $GLOBALS['_wpdb_logs'][0]['message'];
        $this->assertSame(500, mb_strlen($stored));
    }

    public function test_db_not_installed_skips_write(): void {
        // isInstalled() = false
        $GLOBALS['_wp_options']['crmbiz_nl_db_version'] = '';

        Logger::warning('should not store');

        $this->assertEmpty($GLOBALS['_wpdb_logs']);
    }

    public function test_multiple_errors_all_written_to_db(): void {
        Logger::error('err1');
        Logger::warning('warn1');
        Logger::error('err2');

        $this->assertCount(3, $GLOBALS['_wpdb_logs']);
    }

    // ── getLogs() ─────────────────────────────────────────────────────────────

    public function test_getLogs_returns_empty_when_no_logs(): void {
        $logs = Logger::getLogs();
        $this->assertSame([], $logs);
    }

    public function test_getLogs_returns_all_logs_when_no_level(): void {
        Logger::error('e1');
        Logger::warning('w1');
        Logger::error('e2');

        $logs = Logger::getLogs();
        $this->assertCount(3, $logs);
    }

    public function test_getLogs_filters_by_error_level(): void {
        Logger::error('e1');
        Logger::warning('w1');
        Logger::error('e2');

        $logs = Logger::getLogs('ERROR');
        $this->assertCount(2, $logs);
        foreach ($logs as $log) {
            $this->assertSame('ERROR', $log['level']);
        }
    }

    public function test_getLogs_filters_by_warn_level(): void {
        Logger::error('e1');
        Logger::warning('w1');
        Logger::warning('w2');

        $logs = Logger::getLogs('WARN');
        $this->assertCount(2, $logs);
    }

    public function test_getLogs_respects_limit(): void {
        for ($i = 0; $i < 10; $i++) {
            Logger::error("error {$i}");
        }

        $logs = Logger::getLogs('', 3);
        $this->assertCount(3, $logs);
    }

    public function test_getLogs_returns_most_recent_first(): void {
        Logger::error('first');
        Logger::error('second');
        Logger::error('third');

        $logs = Logger::getLogs();
        // DESC 정렬 — 마지막에 쓴 것이 첫 번째
        $this->assertSame('third', $logs[0]['message']);
        $this->assertSame('first', $logs[2]['message']);
    }

    public function test_getLogs_returns_array_of_arrays(): void {
        Logger::warning('check format');

        $logs = Logger::getLogs();
        $this->assertIsArray($logs[0]);
        $this->assertArrayHasKey('level',   $logs[0]);
        $this->assertArrayHasKey('message', $logs[0]);
    }

    // ── clearLogs() ───────────────────────────────────────────────────────────

    public function test_clearLogs_empties_all_logs(): void {
        Logger::error('e1');
        Logger::warning('w1');
        $this->assertCount(2, $GLOBALS['_wpdb_logs']);

        Logger::clearLogs();

        $this->assertEmpty($GLOBALS['_wpdb_logs']);
        $this->assertSame([], Logger::getLogs());
    }

    public function test_clearLogs_on_empty_table_is_safe(): void {
        Logger::clearLogs(); // 예외 없이 실행돼야 함
        $this->assertEmpty($GLOBALS['_wpdb_logs']);
    }

    // ── cleanup() ─────────────────────────────────────────────────────────────

    public function test_cleanup_removes_old_logs(): void {
        Logger::error('old log');
        Logger::warning('another old');
        $this->assertCount(2, $GLOBALS['_wpdb_logs']);

        Logger::cleanup();

        // WpdbStub은 DELETE FROM logs를 전체 삭제로 처리
        $this->assertEmpty($GLOBALS['_wpdb_logs']);
    }

    public function test_cleanup_on_empty_table_is_safe(): void {
        Logger::cleanup();
        $this->assertEmpty($GLOBALS['_wpdb_logs']);
    }

}
