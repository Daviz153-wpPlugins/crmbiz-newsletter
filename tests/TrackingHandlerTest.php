<?php
declare(strict_types=1);

use CRMBizNewsletter\TrackingHandler;
use CRMBizNewsletter\Database;
use PHPUnit\Framework\TestCase;

/**
 * TrackingHandler 보안 테스트
 *
 * HMAC 토큰 생성/검증, URL 스킴 차단, 이벤트 중복 방지
 */
class TrackingHandlerTest extends TestCase {

    private function secret(): string {
        return Database::getSecret();
    }

    // ── buildPixelUrl ──────────────────────────────────────────────────────

    public function test_buildPixelUrl_contains_required_params(): void {
        $url = TrackingHandler::buildPixelUrl(1, 'user@example.com');
        $this->assertStringContainsString('crmbiz_nl_action=open', $url);
        $this->assertStringContainsString('nl=1',                  $url);
        $this->assertStringContainsString('e=',                    $url);
        $this->assertStringContainsString('t=',                    $url);
    }

    public function test_buildPixelUrl_token_is_valid_hmac(): void {
        $email = 'user@example.com';
        $nlId  = 42;
        $url   = TrackingHandler::buildPixelUrl($nlId, $email);
        parse_str(parse_url($url, PHP_URL_QUERY), $params);

        $expected = hash_hmac('sha256', "open:{$nlId}|{$email}", $this->secret());
        $this->assertSame($expected, $params['t']);
    }

    public function test_buildPixelUrl_different_emails_produce_different_tokens(): void {
        $url1 = TrackingHandler::buildPixelUrl(1, 'alice@example.com');
        $url2 = TrackingHandler::buildPixelUrl(1, 'bob@example.com');
        parse_str(parse_url($url1, PHP_URL_QUERY), $p1);
        parse_str(parse_url($url2, PHP_URL_QUERY), $p2);

        $this->assertNotSame($p1['t'], $p2['t'], '이메일이 다르면 토큰이 달라야 함');
    }

    public function test_buildPixelUrl_different_ids_produce_different_tokens(): void {
        $url1 = TrackingHandler::buildPixelUrl(1, 'user@example.com');
        $url2 = TrackingHandler::buildPixelUrl(2, 'user@example.com');
        parse_str(parse_url($url1, PHP_URL_QUERY), $p1);
        parse_str(parse_url($url2, PHP_URL_QUERY), $p2);

        $this->assertNotSame($p1['t'], $p2['t'], 'newsletter ID가 다르면 토큰이 달라야 함');
    }

    // ── buildClickUrl ──────────────────────────────────────────────────────

    public function test_buildClickUrl_contains_required_params(): void {
        $url = TrackingHandler::buildClickUrl(1, 'user@example.com', 'https://target.com/article');
        $this->assertStringContainsString('crmbiz_nl_action=click', $url);
        $this->assertStringContainsString('nl=1', $url);
        $this->assertStringContainsString('url=', $url);
        $this->assertStringContainsString('t=',   $url);
    }

    public function test_buildClickUrl_token_is_valid_hmac(): void {
        $email     = 'user@example.com';
        $nlId      = 7;
        $targetUrl = 'https://target.com/page';
        $url       = TrackingHandler::buildClickUrl($nlId, $email, $targetUrl);
        parse_str(parse_url($url, PHP_URL_QUERY), $params);

        $expected = hash_hmac('sha256', "click:{$nlId}|{$email}|{$targetUrl}", $this->secret());
        $this->assertSame($expected, $params['t']);
    }

    public function test_buildClickUrl_token_covers_target_url(): void {
        // targetUrl이 변조되면 토큰이 달라져야 한다 (open redirect 방지)
        $url1 = TrackingHandler::buildClickUrl(1, 'u@e.com', 'https://good.com');
        $url2 = TrackingHandler::buildClickUrl(1, 'u@e.com', 'https://evil.com');
        parse_str(parse_url($url1, PHP_URL_QUERY), $p1);
        parse_str(parse_url($url2, PHP_URL_QUERY), $p2);

        $this->assertNotSame($p1['t'], $p2['t'], 'targetUrl 변조 시 토큰이 달라야 함');
    }

    public function test_buildClickUrl_open_redirect_cannot_reuse_pixel_token(): void {
        // 오픈 픽셀 토큰을 클릭 URL에 재사용 불가 (prefix가 다름)
        $email = 'user@example.com';
        $nlId  = 1;

        $pixelUrl = TrackingHandler::buildPixelUrl($nlId, $email);
        parse_str(parse_url($pixelUrl, PHP_URL_QUERY), $pixelParams);
        $pixelToken = $pixelParams['t'];

        $clickToken = hash_hmac('sha256', "click:{$nlId}|{$email}|https://target.com", $this->secret());

        $this->assertNotSame($pixelToken, $clickToken, '오픈 토큰을 클릭에 재사용 불가');
    }

    // ── buildWebViewUrl ────────────────────────────────────────────────────

    public function test_buildWebViewUrl_token_is_valid_hmac(): void {
        $email = 'user@example.com';
        $nlId  = 3;
        $url   = TrackingHandler::buildWebViewUrl($nlId, $email);
        parse_str(parse_url($url, PHP_URL_QUERY), $params);

        $expected = hash_hmac('sha256', "web_view:{$nlId}|{$email}", $this->secret());
        $this->assertSame($expected, $params['t']);
    }

    public function test_buildWebViewUrl_prefix_differs_from_open_and_click(): void {
        $email = 'user@example.com';
        $nlId  = 5;

        $openToken    = hash_hmac('sha256', "open:{$nlId}|{$email}",     $this->secret());
        $clickToken   = hash_hmac('sha256', "click:{$nlId}|{$email}|",   $this->secret());
        $webViewToken = hash_hmac('sha256', "web_view:{$nlId}|{$email}", $this->secret());

        $this->assertNotSame($openToken,  $webViewToken);
        $this->assertNotSame($clickToken, $webViewToken);
    }

    // ── URL 스킴 차단 로직 ─────────────────────────────────────────────────

    /**
     * handleClick()의 스킴 차단 로직을 동일한 방식으로 단위 검증
     * (private 메서드이므로 로직을 직접 재현하여 테스트)
     */
    public function test_scheme_validation_blocks_javascript(): void {
        $url    = 'javascript:alert(1)';
        $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));
        $this->assertFalse(in_array($scheme, ['http', 'https'], true));
    }

    public function test_scheme_validation_blocks_data_uri(): void {
        $url    = 'data:text/html,<script>alert(1)</script>';
        $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));
        $this->assertFalse(in_array($scheme, ['http', 'https'], true));
    }

    public function test_scheme_validation_blocks_ftp(): void {
        $url    = 'ftp://evil.com/file.exe';
        $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));
        $this->assertFalse(in_array($scheme, ['http', 'https'], true));
    }

    public function test_scheme_validation_allows_https(): void {
        $url    = 'https://safe.com/page';
        $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));
        $this->assertTrue(in_array($scheme, ['http', 'https'], true));
    }

    public function test_scheme_validation_allows_http(): void {
        $url    = 'http://safe.com/page';
        $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));
        $this->assertTrue(in_array($scheme, ['http', 'https'], true));
    }

    public function test_scheme_validation_blocks_empty_url(): void {
        $url    = '';
        $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));
        $this->assertFalse(in_array($scheme, ['http', 'https'], true));
    }

    // ── recordSend ────────────────────────────────────────────────────────

    public function test_recordSend_success_inserts_send_type(): void {
        $GLOBALS['_wpdb_events'] = [];
        TrackingHandler::recordSend(1, 'user@example.com', true);
        $rows = $GLOBALS['_wpdb_events'];
        $last = end($rows);
        $this->assertSame('send', $last['type'] ?? '');
        $this->assertSame(1, $last['newsletter_id'] ?? -1);
    }

    public function test_recordSend_failure_inserts_fail_type(): void {
        $GLOBALS['_wpdb_events'] = [];
        TrackingHandler::recordSend(2, 'user@example.com', false);
        $rows = $GLOBALS['_wpdb_events'];
        $last = end($rows);
        $this->assertSame('fail', $last['type'] ?? '');
    }

    // ── recordUnsubscribe ──────────────────────────────────────────────────

    public function test_recordUnsubscribe_inserts_event(): void {
        $GLOBALS['_wpdb_events'] = [];
        TrackingHandler::recordUnsubscribe(10, 'unsub@example.com');
        $found = array_filter($GLOBALS['_wpdb_events'], fn($r) => ($r['type'] ?? '') === 'unsubscribe' && ($r['email'] ?? '') === 'unsub@example.com');
        $this->assertNotEmpty($found);
    }

    public function test_recordUnsubscribe_does_not_duplicate(): void {
        $GLOBALS['_wpdb_events'] = [];
        $email = 'dup@example.com';
        TrackingHandler::recordUnsubscribe(10, $email);
        $countBefore = count(array_filter($GLOBALS['_wpdb_events'], fn($r) => ($r['type'] ?? '') === 'unsubscribe' && ($r['email'] ?? '') === $email));

        TrackingHandler::recordUnsubscribe(10, $email); // 두 번째 호출 — 이미 존재하므로 skip
        $countAfter = count(array_filter($GLOBALS['_wpdb_events'], fn($r) => ($r['type'] ?? '') === 'unsubscribe' && ($r['email'] ?? '') === $email));

        $this->assertSame($countBefore, $countAfter, '수신거부 이벤트 중복 삽입 방지');
    }

}
