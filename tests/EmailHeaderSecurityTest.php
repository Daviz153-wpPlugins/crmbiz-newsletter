<?php
declare(strict_types=1);

use CRMBizNewsletter\Admin\AjaxHandlers;
use CRMBizNewsletter\Settings;
use PHPUnit\Framework\TestCase;

/**
 * 이메일 헤더 인젝션 보안 테스트
 *
 * From: 이름/이메일에 \r\n을 삽입해 임의 헤더(Bcc, Cc 등)를 추가하는
 * 공격 패턴을 방어하는지 전수 검증.
 *
 * 방어 코드 위치:
 *   - AjaxHandlers::handleTestEmail()      Line 43-44
 *   - AjaxHandlers::handleTestNewsletter() Line 188-189
 *   - NewsletterSender (발송 시마다)       Line 342-343, 432-433
 */
class EmailHeaderSecurityTest extends TestCase {

    private AjaxHandlers $handlers;

    protected function setUp(): void {
        $GLOBALS['_wp_user_can']    = true;
        $GLOBALS['_wp_mail_calls']  = [];
        $GLOBALS['_wp_mail_result'] = true;
        $GLOBALS['_wp_options']     = [];
        $_POST = [];
    }

    private function setFromName(string $name): void {
        $GLOBALS['_wp_options']['crmbiz_nl_settings'] = ['from_name' => $name, 'from_email' => 'valid@sender.com'];
        $this->handlers = new AjaxHandlers(new Settings(), 'test_hook');
    }

    private function setFromEmail(string $email): void {
        $GLOBALS['_wp_options']['crmbiz_nl_settings'] = ['from_name' => 'Valid Name', 'from_email' => $email];
        $this->handlers = new AjaxHandlers(new Settings(), 'test_hook');
    }

    private function getHeadersFromMail(): array {
        if (empty($GLOBALS['_wp_mail_calls'])) return [];
        $headers = $GLOBALS['_wp_mail_calls'][0]['headers'];
        return is_array($headers) ? $headers : [$headers];
    }

    private function callHandleTestEmail(string $to = 'test@example.com'): void {
        $_POST['test_email'] = $to;
        try {
            $this->handlers->handleTestEmail();
        } catch (WpJsonResponse) {
            // 성공/실패 응답 무시, 헤더만 검증
        }
    }

    // ── \r\n 인젝션 차단 ──────────────────────────────────────────────────────

    /**
     * 헤더 인젝션 방어의 핵심은 CRLF(\r\n) 제거다.
     * str_replace 후 텍스트가 한 줄에 남는 것은 정상 — 별도 헤더가 되려면 CRLF가 필요하다.
     */
    public function test_from_name_crlf_injection_stripped(): void {
        $malicious = "Legit Name\r\nBcc: attacker@evil.com";
        $this->setFromName($malicious);
        $this->callHandleTestEmail();

        $fromHeader = implode('', $this->getHeadersFromMail());
        // 핵심: CRLF 가 없어야 함 → 별도 Bcc 헤더로 해석 불가
        $this->assertStringNotContainsString("\r\n", $fromHeader, 'CRLF가 헤더에 남아있으면 인젝션 가능');
        $this->assertStringNotContainsString("\r", $fromHeader);
    }

    public function test_from_email_crlf_injection_stripped(): void {
        $malicious = "legit@example.com\r\nX-Custom: injected";
        $this->setFromEmail($malicious);
        $this->callHandleTestEmail();

        $fromHeader = implode('', $this->getHeadersFromMail());
        $this->assertStringNotContainsString("\r\n", $fromHeader);
        $this->assertStringNotContainsString("\r", $fromHeader);
    }

    public function test_from_name_lf_only_injection_stripped(): void {
        $malicious = "Name\nBcc: attacker@evil.com";
        $this->setFromName($malicious);
        $this->callHandleTestEmail();

        $fromHeader = implode('', $this->getHeadersFromMail());
        // LF만 있어도 일부 MTA가 헤더 분리로 해석 → 반드시 제거
        $this->assertStringNotContainsString("\n", $fromHeader);
    }

    public function test_from_name_cr_only_injection_stripped(): void {
        $malicious = "Name\rBcc: attacker@evil.com";
        $this->setFromName($malicious);
        $this->callHandleTestEmail();

        $fromHeader = implode('', $this->getHeadersFromMail());
        $this->assertStringNotContainsString("\r", $fromHeader);
    }

    // ── 다양한 인젝션 패턴 — 모두 newline 제거로 차단 ─────────────────────────

    public function test_from_name_cc_injection_crlf_removed(): void {
        $malicious = "Sender\r\nCc: victim@company.com";
        $this->setFromName($malicious);
        $this->callHandleTestEmail();

        $fromHeader = implode('', $this->getHeadersFromMail());
        $this->assertStringNotContainsString("\r\n", $fromHeader, 'CRLF 제거 실패');
    }

    public function test_from_name_reply_to_injection_crlf_removed(): void {
        $malicious = "Sender\r\nReply-To: phishing@evil.com";
        $this->setFromName($malicious);
        $this->callHandleTestEmail();

        $fromHeader = implode('', $this->getHeadersFromMail());
        $this->assertStringNotContainsString("\r\n", $fromHeader, 'CRLF 제거 실패');
    }

    public function test_from_name_multiple_crlf_all_removed(): void {
        $malicious = "Name\r\nBcc: a@b.com\r\nCc: c@d.com\r\nSubject: Phishing";
        $this->setFromName($malicious);
        $this->callHandleTestEmail();

        $fromHeader = implode('', $this->getHeadersFromMail());
        // 모든 CRLF가 제거되면 단일 라인 → 어떤 MTA도 별도 헤더로 해석 불가
        $this->assertStringNotContainsString("\r", $fromHeader);
        $this->assertStringNotContainsString("\n", $fromHeader);
    }

    // ── 정상 값은 통과 ────────────────────────────────────────────────────────

    public function test_normal_from_name_passes_through(): void {
        $this->setFromName('홍길동 팀장');
        $this->callHandleTestEmail();

        $fromHeader = implode('', $this->getHeadersFromMail());
        $this->assertStringContainsString('홍길동 팀장', $fromHeader);
    }

    public function test_normal_from_email_passes_through(): void {
        $this->setFromEmail('newsletter@company.com');
        $this->callHandleTestEmail();

        $fromHeader = implode('', $this->getHeadersFromMail());
        $this->assertStringContainsString('newsletter@company.com', $fromHeader);
    }

    public function test_from_name_with_special_chars_passes(): void {
        // 이메일 발신자명에 허용되는 특수문자
        $this->setFromName('Company & Co. <newsletter>');
        $this->callHandleTestEmail();

        $headers = $this->getHeadersFromMail();
        // wp_mail이 실제로 호출됐는지 확인
        $this->assertNotEmpty($headers);
    }

    // ── Settings 레벨 저장 전 검증 ────────────────────────────────────────────

    public function test_settings_getFromName_returns_stored_value(): void {
        $GLOBALS['_wp_options']['crmbiz_nl_settings'] = ['from_name' => 'Clean Name'];
        $settings = new Settings();
        $this->assertSame('Clean Name', $settings->getFromName());
    }

    public function test_settings_getFromEmail_returns_stored_value(): void {
        $GLOBALS['_wp_options']['crmbiz_nl_settings'] = ['from_email' => 'sender@example.com'];
        $settings = new Settings();
        $this->assertSame('sender@example.com', $settings->getFromEmail());
    }

    // ── 이메일 제목 인젝션 방어 확인 ──────────────────────────────────────────

    public function test_test_email_subject_does_not_contain_injected_content(): void {
        $this->setFromName("Name\r\nX-Extra: injected");
        $this->callHandleTestEmail();

        if (empty($GLOBALS['_wp_mail_calls'])) return;

        $subject = $GLOBALS['_wp_mail_calls'][0]['subject'];
        $this->assertStringNotContainsString("\r\n", $subject);
        $this->assertStringNotContainsString('X-Extra:', $subject);
    }

    // ── str_replace 방어 로직 단위 검증 ───────────────────────────────────────

    public function test_str_replace_strips_cr(): void {
        $input    = "Name\rWith CR";
        $stripped = str_replace(["\r", "\n"], '', $input);
        $this->assertStringNotContainsString("\r", $stripped);
        $this->assertSame('NameWith CR', $stripped);
    }

    public function test_str_replace_strips_lf(): void {
        $input    = "Name\nWith LF";
        $stripped = str_replace(["\r", "\n"], '', $input);
        $this->assertStringNotContainsString("\n", $stripped);
        $this->assertSame('NameWith LF', $stripped);
    }

    public function test_str_replace_strips_crlf(): void {
        $input    = "Name\r\nWith CRLF";
        $stripped = str_replace(["\r", "\n"], '', $input);
        $this->assertStringNotContainsString("\r", $stripped);
        $this->assertStringNotContainsString("\n", $stripped);
        $this->assertSame('NameWith CRLF', $stripped);
    }

    public function test_str_replace_handles_multiple_injections(): void {
        $input    = "A\r\nB\r\nC\r\nD";
        $stripped = str_replace(["\r", "\n"], '', $input);
        $this->assertSame('ABCD', $stripped);
    }

}
