<?php
declare(strict_types=1);

use CRMBizNewsletter\Database;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Database — getSecret / getVersion / isInstalled / checkRateLimit / getClientIp
 *
 * DatabaseEncryptionTest가 암호화 라운드트립과 checkRateLimit 기본 카운팅을 커버하므로
 * 이 파일은 나머지 브랜치(비밀키 생성·재사용, 설치 상태, IP 우선순위)에 집중한다.
 */
class DatabaseTest extends TestCase {

    private static \ReflectionMethod $getClientIp;

    public static function setUpBeforeClass(): void {
        self::$getClientIp = new \ReflectionMethod(Database::class, 'getClientIp');
    }

    protected function setUp(): void {
        // 각 테스트를 완전히 격리
        unset(
            $GLOBALS['_wp_options']['crmbiz_nl_secret'],
            $GLOBALS['_wp_options'][Database::DB_VERSION_OPTION],
            $_SERVER['HTTP_CF_CONNECTING_IP'],
            $_SERVER['HTTP_X_FORWARDED_FOR'],
            $_SERVER['REMOTE_ADDR']
        );
        $GLOBALS['_wpdb_ratelimit'] = [];
    }

    protected function tearDown(): void {
        unset(
            $GLOBALS['_wp_options']['crmbiz_nl_secret'],
            $GLOBALS['_wp_options'][Database::DB_VERSION_OPTION],
            $_SERVER['HTTP_CF_CONNECTING_IP'],
            $_SERVER['HTTP_X_FORWARDED_FOR'],
            $_SERVER['REMOTE_ADDR']
        );
        $GLOBALS['_wpdb_ratelimit'] = [];
    }

    // ── getSecret ─────────────────────────────────────────────────────────────

    public function test_getSecret_generates_256bit_hex_on_first_call(): void {
        // 옵션이 없는 상태에서 호출하면 새 시크릿이 생성되어야 함
        $secret = Database::getSecret();

        // 256-bit → 64자 hex 문자열
        $this->assertSame(64, strlen($secret));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $secret);
    }

    public function test_getSecret_persists_after_first_call(): void {
        $first  = Database::getSecret();
        $second = Database::getSecret();

        // 두 번째 호출은 재생성하지 않고 저장된 값을 반환
        $this->assertSame($first, $second);
    }

    public function test_getSecret_returns_existing_option_without_overwriting(): void {
        $fixed = str_repeat('ab', 32); // 64자 hex
        $GLOBALS['_wp_options']['crmbiz_nl_secret'] = $fixed;

        $this->assertSame($fixed, Database::getSecret());
    }

    // ── getVersion / isInstalled ───────────────────────────────────────────────

    public function test_getVersion_returns_empty_when_not_installed(): void {
        $this->assertSame('', Database::getVersion());
    }

    public function test_getVersion_returns_stored_version(): void {
        $GLOBALS['_wp_options'][Database::DB_VERSION_OPTION] = '2.0.0';

        $this->assertSame('2.0.0', Database::getVersion());
    }

    public function test_isInstalled_false_when_no_version(): void {
        $this->assertFalse(Database::isInstalled());
    }

    public function test_isInstalled_true_when_version_set(): void {
        $GLOBALS['_wp_options'][Database::DB_VERSION_OPTION] = '1.0.0';

        $this->assertTrue(Database::isInstalled());
    }

    // ── checkRateLimit — 추가 케이스 ──────────────────────────────────────────

    public function test_checkRateLimit_limit_zero_always_blocks(): void {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        // limit=0이면 첫 번째 요청도 차단
        $this->assertFalse(Database::checkRateLimit('zero_action', 0, 60));
    }

    public function test_checkRateLimit_different_actions_are_isolated(): void {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        Database::checkRateLimit('action_a', 1, 60);
        // action_a는 한도 소진됐지만 action_b는 독립적
        $this->assertFalse(Database::checkRateLimit('action_a', 1, 60));
        $this->assertTrue(Database::checkRateLimit('action_b', 1, 60));
    }

    // ── getClientIp — Reflection으로 직접 검증 ────────────────────────────────

    public function test_getClientIp_uses_cf_connecting_ip_first(): void {
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '1.2.3.4';
        $_SERVER['REMOTE_ADDR']           = '10.0.0.1';

        $ip = self::$getClientIp->invoke(null);

        $this->assertSame('1.2.3.4', $ip);
    }

    public function test_getClientIp_skips_invalid_cf_ip_and_falls_through(): void {
        $_SERVER['HTTP_CF_CONNECTING_IP'] = 'not-an-ip';
        $_SERVER['REMOTE_ADDR']           = '203.0.113.5'; // 공인 IP

        $ip = self::$getClientIp->invoke(null);

        // CF 헤더가 유효하지 않으므로 REMOTE_ADDR 사용
        $this->assertSame('203.0.113.5', $ip);
    }

    public function test_getClientIp_uses_x_forwarded_for_behind_proxy(): void {
        // REMOTE_ADDR가 사설 IP(프록시)이면 X-Forwarded-For 신뢰
        unset($_SERVER['HTTP_CF_CONNECTING_IP']);
        $_SERVER['REMOTE_ADDR']          = '10.0.0.2'; // 사설 IP → behindProxy=true
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.7, 10.0.0.2';

        $ip = self::$getClientIp->invoke(null);

        // 첫 번째 공인 IP가 사용되어야 함
        $this->assertSame('198.51.100.7', $ip);
    }

    public function test_getClientIp_ignores_x_forwarded_for_on_direct_connection(): void {
        // REMOTE_ADDR가 공인 IP(직접 접속)이면 X-Forwarded-For 무시(위조 방지)
        unset($_SERVER['HTTP_CF_CONNECTING_IP']);
        $_SERVER['REMOTE_ADDR']          = '203.0.113.1'; // 공인 IP → behindProxy=false
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';

        $ip = self::$getClientIp->invoke(null);

        // X-Forwarded-For가 아닌 REMOTE_ADDR 반환
        $this->assertSame('203.0.113.1', $ip);
    }

    public function test_getClientIp_falls_back_to_remote_addr(): void {
        unset($_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_X_FORWARDED_FOR']);
        $_SERVER['REMOTE_ADDR'] = '192.0.2.99';

        $ip = self::$getClientIp->invoke(null);

        $this->assertSame('192.0.2.99', $ip);
    }

    public function test_getClientIp_returns_unknown_for_invalid_remote_addr(): void {
        unset($_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_X_FORWARDED_FOR']);
        $_SERVER['REMOTE_ADDR'] = 'not-valid';

        $ip = self::$getClientIp->invoke(null);

        $this->assertSame('unknown', $ip);
    }

    public function test_getClientIp_xff_skips_private_ips_in_list(): void {
        // XFF 목록에 사설 IP → 공인 IP 찾을 때까지 건너뜀
        unset($_SERVER['HTTP_CF_CONNECTING_IP']);
        $_SERVER['REMOTE_ADDR']          = '172.16.0.1'; // 사설 → behindProxy=true
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '10.0.0.5, 198.51.100.20, 172.16.0.1';

        $ip = self::$getClientIp->invoke(null);

        $this->assertSame('198.51.100.20', $ip);
    }

    public function test_getClientIp_with_rate_limit_same_ip_counted_once(): void {
        // getClientIp가 실제로 checkRateLimit의 키에 반영되는지 통합 확인
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '5.6.7.8';
        $_SERVER['REMOTE_ADDR']           = '10.0.0.1';

        Database::checkRateLimit('cf_test', 1, 60);
        $result = Database::checkRateLimit('cf_test', 1, 60);

        // CF IP로 카운트되어 두 번째에서 차단
        $this->assertFalse($result);
    }
}
