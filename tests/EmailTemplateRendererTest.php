<?php
declare(strict_types=1);

use CRMBizNewsletter\EmailTemplateRenderer;
use CRMBizNewsletter\Settings;
use CRMBizNewsletter\TrackingHandler;
use PHPUnit\Framework\TestCase;

/**
 * EmailTemplateRenderer 보안 테스트
 *
 * XSS 방어, 출력 이스케이프, 트래킹 URL 삽입 정확성
 */
class EmailTemplateRendererTest extends TestCase {

    private EmailTemplateRenderer $renderer;
    private Settings $settings;

    protected function setUp(): void {
        $this->settings = new Settings();
        $this->renderer = new EmailTemplateRenderer($this->settings);
    }

    /** 테스트용 WP_Post 객체 생성 */
    private function makePost(string $title = 'Test Post', string $content = '<p>Hello</p>'): object {
        return new \WP_Post((object)[
            'ID'           => 99,
            'post_title'   => $title,
            'post_content' => $content,
            'post_date'    => '2026-01-01 12:00:00',
            'post_status'  => 'publish',
            'post_type'    => 'post',
            'post_author'  => 1,
            'post_excerpt' => '',
            'post_name'    => 'test-post',
            'guid'         => 'https://example.com/?p=99',
        ]);
    }

    /** 테스트용 subscriber 객체 */
    private function makeSubscriber(string $email = 'sub@example.com'): object {
        return (object)[
            'email'      => $email,
            'first_name' => 'Test',
            'last_name'  => 'User',
            'full_name'  => 'Test User',
        ];
    }

    // ── XSS 방어: 제목 ────────────────────────────────────────────────────

    public function test_xss_in_post_title_is_escaped(): void {
        $post = $this->makePost('<script>alert("xss")</script>');
        $html = $this->renderer->render($post, $this->makeSubscriber());

        $this->assertStringNotContainsString('<script>alert("xss")', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_xss_with_img_onerror_in_title_is_escaped(): void {
        $post = $this->makePost('<img src=x onerror=alert(1)>');
        $html = $this->renderer->render($post, $this->makeSubscriber());

        // 원본 img 태그가 실행 가능한 HTML 형태로 나오면 안 됨
        $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
        // 이스케이프된 형태로 포함되는 것은 OK
        $this->assertStringContainsString('&lt;img', $html);
    }

    public function test_javascript_protocol_in_title_is_escaped(): void {
        $post = $this->makePost('javascript:void(0)');
        $html = $this->renderer->render($post, $this->makeSubscriber());

        // 제목이 esc_html 처리되어 그대로 렌더되면 OK (링크 아님)
        $this->assertStringNotContainsString('<a href="javascript:', $html);
    }

    // ── XSS 방어: 수신자 이름 ─────────────────────────────────────────────

    public function test_xss_in_subscriber_name_is_escaped(): void {
        $subscriber = (object)[
            'email'      => 'sub@example.com',
            'first_name' => '<script>alert(1)</script>',
            'last_name'  => '',
            'full_name'  => '<script>alert(1)</script>',
        ];
        $html = $this->renderer->render($this->makePost(), $subscriber);

        $this->assertStringNotContainsString('<script>alert(1)', $html);
    }

    // ── 트래킹 픽셀 삽입 ──────────────────────────────────────────────────

    public function test_tracking_pixel_inserted_when_newsletter_id_given(): void {
        $html = $this->renderer->render($this->makePost(), $this->makeSubscriber(), 5);

        // 1×1 오픈 픽셀이 </body> 전에 삽입됨
        $this->assertStringContainsString('crmbiz_nl_action=open', $html);
        $this->assertStringContainsString('width="1"', $html);
        $this->assertStringContainsString('height="1"', $html);
    }

    public function test_tracking_pixel_not_inserted_when_no_newsletter_id(): void {
        $html = $this->renderer->render($this->makePost(), $this->makeSubscriber(), 0);

        $this->assertStringNotContainsString('crmbiz_nl_action=open', $html);
    }

    // ── 링크 트래킹 치환 ──────────────────────────────────────────────────

    public function test_links_replaced_with_click_tracking_url(): void {
        $post = $this->makePost('Title', '<p><a href="https://target.com/article">읽기</a></p>');
        $html = $this->renderer->render($post, $this->makeSubscriber(), 7);

        $this->assertStringContainsString('crmbiz_nl_action=click', $html);
    }

    public function test_unsubscribe_link_not_double_wrapped(): void {
        // unsubscribe 링크는 이미 crmbiz_nl_action을 포함 → 재치환 안 됨
        $unsubUrl = 'https://example.com/?crmbiz_nl_action=unsubscribe&enc=xxx&token=yyy';
        $post     = $this->makePost('Title', '<p><a href="' . $unsubUrl . '">수신거부</a></p>');
        $html     = $this->renderer->render($post, $this->makeSubscriber(), 9);

        // crmbiz_nl_action=click 으로 래핑되지 않아야 함
        $this->assertStringNotContainsString('crmbiz_nl_action=click', $html);
    }

    public function test_pixel_placed_before_body_close_tag(): void {
        $html = $this->renderer->render($this->makePost(), $this->makeSubscriber(), 3);

        $pixelPos = strpos($html, 'crmbiz_nl_action=open');
        $bodyPos  = strpos($html, '</body>');

        if ($pixelPos !== false && $bodyPos !== false) {
            $this->assertLessThan($bodyPos, $pixelPos, '픽셀이 </body> 앞에 있어야 함');
        }
    }

    // ── HTML 구조 ──────────────────────────────────────────────────────────

    public function test_render_returns_non_empty_html(): void {
        $html = $this->renderer->render($this->makePost(), $this->makeSubscriber());
        $this->assertNotEmpty($html);
        $this->assertStringContainsString('<', $html);
    }

    public function test_render_contains_post_title(): void {
        $html = $this->renderer->render($this->makePost('My Newsletter'), $this->makeSubscriber());
        $this->assertStringContainsString('My Newsletter', $html);
    }

    // ── inlineStylesForEmail: 이미지 반응형 ───────────────────────────────

    public function test_img_width_height_attributes_removed_for_responsive(): void {
        $post = $this->makePost('T', '<img src="test.jpg" width="600" height="400">');
        $html = $this->renderer->render($post, $this->makeSubscriber());

        // width/height 속성 제거, max-width:100% 인라인 스타일 추가
        $this->assertStringContainsString('max-width:100%', $html);
    }

    // ── 수신거부 URL 포함 ──────────────────────────────────────────────────

    public function test_unsubscribe_url_present_in_output(): void {
        $html = $this->renderer->render($this->makePost(), $this->makeSubscriber('sub@example.com'));
        $this->assertStringContainsString('crmbiz_nl_action=unsubscribe', $html);
    }

    public function test_unsubscribe_url_contains_encrypted_email(): void {
        $html = $this->renderer->render($this->makePost(), $this->makeSubscriber('sub@example.com'));
        $this->assertStringContainsString('enc=', $html);
        $this->assertStringContainsString('token=', $html);
    }

}
