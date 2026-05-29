<?php
declare(strict_types=1);

use CRMBizNewsletter\Database;
use CRMBizNewsletter\UnsubscribeHandler;
use PHPUnit\Framework\TestCase;

class UnsubscribeHandlerTest extends TestCase {

    private \ReflectionClass $ref;
    private UnsubscribeHandler $handler;

    protected function setUp(): void {
        $GLOBALS['_wp_options']['crmbiz_nl_secret'] = bin2hex(str_repeat("\xAB", 32));
        $this->ref     = new \ReflectionClass(UnsubscribeHandler::class);
        $this->handler = $this->ref->newInstanceWithoutConstructor();
    }

    protected function tearDown(): void {
        unset($GLOBALS['_wp_options']['crmbiz_nl_secret']);
    }

    // ------------------------------------------------------------------
    // maskEmail
    // ------------------------------------------------------------------

    private function callMaskEmail(string $email): string {
        $m = $this->ref->getMethod('maskEmail');
        return $m->invoke($this->handler, $email);
    }

    public function test_mask_email_hides_middle_chars(): void {
        $masked = $this->callMaskEmail('hello@example.com');
        $this->assertStringStartsWith('he', $masked);
        $this->assertStringContainsString('@example.com', $masked);
        $this->assertStringContainsString('*', $masked);
    }

    public function test_mask_email_short_local_part(): void {
        // 2자 이하는 첫 두 글자 + 최소 별표 1개
        $masked = $this->callMaskEmail('a@b.com');
        $this->assertStringStartsWith('a', $masked);
        $this->assertStringContainsString('*', $masked);
        $this->assertStringEndsWith('@b.com', $masked);
    }

    public function test_mask_email_preserves_domain(): void {
        $masked = $this->callMaskEmail('newsletter@crmbiz.io');
        $this->assertStringEndsWith('@crmbiz.io', $masked);
    }

    // ------------------------------------------------------------------
    // verifyToken
    // ------------------------------------------------------------------

    private function callVerifyToken(string $email, string $token, int $exp): bool {
        $m = $this->ref->getMethod('verifyToken');
        return $m->invoke($this->handler, $email, $token, $exp);
    }

    private function makeToken(string $email, int $exp): string {
        return hash_hmac('sha256', $email . '|' . $exp, Database::getSecret());
    }

    public function test_verify_token_valid(): void {
        $email = 'valid@example.com';
        $exp   = time() + 3600;
        $token = $this->makeToken($email, $exp);

        $this->assertTrue($this->callVerifyToken($email, $token, $exp));
    }

    public function test_verify_token_expired(): void {
        $email = 'expired@example.com';
        $exp   = time() - 1; // 이미 만료
        $token = $this->makeToken($email, $exp);

        $this->assertFalse($this->callVerifyToken($email, $token, $exp));
    }

    public function test_verify_token_zero_exp(): void {
        $email = 'zero@example.com';
        $token = $this->makeToken($email, 0);

        $this->assertFalse($this->callVerifyToken($email, $token, 0));
    }

    public function test_verify_token_wrong_hmac(): void {
        $exp = time() + 3600;
        $this->assertFalse(
            $this->callVerifyToken('user@example.com', 'deadbeefdeadbeef', $exp)
        );
    }

    public function test_verify_token_email_mismatch(): void {
        $exp   = time() + 3600;
        $token = $this->makeToken('real@example.com', $exp);

        $this->assertFalse($this->callVerifyToken('fake@example.com', $token, $exp));
    }

    // ------------------------------------------------------------------
    // buildUnsubscribeUrl
    // ------------------------------------------------------------------

    public function test_build_unsubscribe_url_contains_required_params(): void {
        $url = UnsubscribeHandler::buildUnsubscribeUrl('sub@example.com', 7);

        $this->assertStringContainsString('crmbiz_nl_action=unsubscribe', $url);
        $this->assertStringContainsString('token=',   $url);
        $this->assertStringContainsString('enc=',     $url);
        $this->assertStringContainsString('exp=',     $url);
        $this->assertStringContainsString('nl=7',     $url);
    }

    public function test_build_unsubscribe_url_enc_is_decryptable(): void {
        $email = 'roundtrip@example.com';
        $url   = UnsubscribeHandler::buildUnsubscribeUrl($email);

        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $params);
        $decoded = Database::decryptEmail($params['enc'] ?? '');

        $this->assertSame($email, $decoded);
    }

    public function test_build_unsubscribe_url_token_verifies(): void {
        $email = 'check@example.com';
        $url   = UnsubscribeHandler::buildUnsubscribeUrl($email, 0);

        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $params);
        $exp      = (int) ($params['exp'] ?? 0);
        $token    = $params['token'] ?? '';
        $expected = hash_hmac('sha256', $email . '|' . $exp, Database::getSecret());

        $this->assertTrue(hash_equals($expected, $token));
    }
}
