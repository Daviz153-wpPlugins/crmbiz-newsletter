<?php
declare(strict_types=1);

use CRMBizNewsletter\Database;
use PHPUnit\Framework\TestCase;

/**
 * Database::encryptEmail / decryptEmail 테스트
 *
 * 보안에 직결되는 암호화 함수이므로 라운드트립과 오류 케이스를 모두 검증한다.
 */
class DatabaseEncryptionTest extends TestCase {

    protected function setUp(): void {
        // 테스트마다 고정 시크릿 주입 — random_bytes 의존 없이 결정론적으로 실행
        $GLOBALS['_wp_options']['crmbiz_nl_secret'] = bin2hex(str_repeat("\x42", 32));
    }

    protected function tearDown(): void {
        unset($GLOBALS['_wp_options']['crmbiz_nl_secret']);
    }

    public function test_encrypt_decrypt_roundtrip(): void {
        $email = 'user@example.com';
        $enc   = Database::encryptEmail($email);
        $this->assertSame($email, Database::decryptEmail($enc));
    }

    public function test_roundtrip_preserves_unicode_email(): void {
        $email = 'tëst+tag@subdomain.example.co.kr';
        $this->assertSame($email, Database::decryptEmail(Database::encryptEmail($email)));
    }

    public function test_encrypt_produces_different_ciphertext_each_call(): void {
        $email = 'same@example.com';
        $a     = Database::encryptEmail($email);
        $b     = Database::encryptEmail($email);
        // IV가 매번 달라야 함
        $this->assertNotSame($a, $b);
    }

    public function test_decrypt_empty_string_returns_empty(): void {
        $this->assertSame('', Database::decryptEmail(''));
    }

    public function test_decrypt_too_short_input_returns_empty(): void {
        // base64 디코드 후 16바이트 IV도 안 되는 길이
        $this->assertSame('', Database::decryptEmail(base64_encode('tooshort')));
    }

    public function test_decrypt_invalid_base64_returns_empty(): void {
        $this->assertSame('', Database::decryptEmail('!!!not-valid-base64!!!'));
    }

    public function test_decrypt_tampered_ciphertext_returns_empty(): void {
        $enc = Database::encryptEmail('legit@example.com');
        // base64 디코드 후 마지막 바이트(인증 태그) 변조
        $b64 = strtr($enc, '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad) $b64 .= str_repeat('=', 4 - $pad);
        $raw    = base64_decode($b64);
        $raw[-1] = chr(ord($raw[-1]) ^ 0xFF); // 태그 마지막 바이트 반전
        $tampered = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
        // GCM 인증 태그 불일치 → 반드시 빈 문자열
        $this->assertSame('', Database::decryptEmail($tampered));
    }

    public function test_gcm_encrypts_with_version_byte(): void {
        $enc = Database::encryptEmail('test@example.com');
        $b64 = strtr($enc, '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad) $b64 .= str_repeat('=', 4 - $pad);
        $raw = base64_decode($b64);
        // 첫 바이트가 GCM 버전 마커 0x01이어야 함
        $this->assertSame(0x01, ord($raw[0]));
    }

    public function test_legacy_cbc_still_decrypts(): void {
        // 기존 CBC 포맷으로 직접 암호화한 값이 여전히 복호화돼야 함
        $email = 'legacy@example.com';
        $key   = hex2bin(Database::getSecret());
        $iv    = str_repeat("\xAB", 16);
        $ct    = openssl_encrypt($email, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        $enc   = rtrim(strtr(base64_encode($iv . $ct), '+/', '-_'), '=');
        $this->assertSame($email, Database::decryptEmail($enc));
    }

    public function test_rate_limit_allows_within_limit(): void {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $GLOBALS['_wpdb_ratelimit'] = [];

        $this->assertTrue(Database::checkRateLimit('test_action', 3, 60));
        $this->assertTrue(Database::checkRateLimit('test_action', 3, 60));
        $this->assertTrue(Database::checkRateLimit('test_action', 3, 60));
    }

    public function test_rate_limit_blocks_after_limit_exceeded(): void {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.2';
        $GLOBALS['_wpdb_ratelimit'] = [];

        Database::checkRateLimit('block_action', 2, 60);
        Database::checkRateLimit('block_action', 2, 60);

        $this->assertFalse(Database::checkRateLimit('block_action', 2, 60));
    }

    public function test_rate_limit_different_ips_are_independent(): void {
        $GLOBALS['_wpdb_ratelimit'] = [];

        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
        Database::checkRateLimit('ip_action', 1, 60);
        $this->assertFalse(Database::checkRateLimit('ip_action', 1, 60));

        // 다른 IP는 아직 허용됨
        $_SERVER['REMOTE_ADDR'] = '10.0.0.2';
        $this->assertTrue(Database::checkRateLimit('ip_action', 1, 60));
    }
}
