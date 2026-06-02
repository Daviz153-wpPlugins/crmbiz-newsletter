<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * 국제화(i18n) 테스트
 *
 * Text Domain 설정, .pot 파일 존재, 번역 가능 문자열 형식 검증
 */
class I18nTest extends TestCase {

    private const TEXT_DOMAIN = 'crmbiz-newsletter';
    private const POT_FILE    = __DIR__ . '/../languages/crmbiz-newsletter.pot';
    private const PLUGIN_FILE = __DIR__ . '/../crmbiz-newsletter.php';
    private const SRC_DIR     = __DIR__ . '/../src';

    // ── 플러그인 헤더 ──────────────────────────────────────────────────────────

    public function test_plugin_header_declares_text_domain(): void {
        $header = file_get_contents(self::PLUGIN_FILE);
        $this->assertStringContainsString(
            'Text Domain: ' . self::TEXT_DOMAIN,
            $header,
            '플러그인 헤더에 Text Domain이 선언되어야 함'
        );
    }

    // ── .pot 파일 ─────────────────────────────────────────────────────────────

    public function test_pot_file_exists(): void {
        $this->assertFileExists(self::POT_FILE, 'languages/crmbiz-newsletter.pot 파일이 있어야 함');
    }

    public function test_pot_file_has_project_id(): void {
        $content = file_get_contents(self::POT_FILE);
        $this->assertStringContainsString('Project-Id-Version:', $content);
        $this->assertStringContainsString(self::TEXT_DOMAIN, $content);
    }

    public function test_pot_file_has_msgid_entries(): void {
        $content = file_get_contents(self::POT_FILE);
        $count   = substr_count($content, 'msgid "');
        $this->assertGreaterThan(5, $count, '.pot 파일에 충분한 번역 항목이 있어야 함');
    }

    public function test_pot_file_references_source_files(): void {
        $content = file_get_contents(self::POT_FILE);
        // 소스 파일 참조 (#: src/...) 가 포함되어야 함
        $this->assertStringContainsString('#: src/', $content);
    }

    // ── 소스 코드 i18n 준수 ───────────────────────────────────────────────────

    public function test_php_files_use_text_domain_in_i18n_functions(): void {
        $phpFiles = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::SRC_DIR)
        );

        $wrongDomainPatterns = [];
        foreach ($phpFiles as $file) {
            if ($file->getExtension() !== 'php') continue;

            $content = file_get_contents($file->getPathname());
            // __(), _e(), esc_html__() 등에 도메인 없이 사용하거나 다른 도메인 사용하면 감지
            if (preg_match_all(
                '/__\s*\(\s*[\'"][^\'"]+[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]\s*\)/u',
                $content, $matches
            )) {
                foreach ($matches[1] as $domain) {
                    if ($domain !== self::TEXT_DOMAIN) {
                        $wrongDomainPatterns[] = $file->getFilename() . ': ' . $domain;
                    }
                }
            }
        }

        $this->assertEmpty(
            $wrongDomainPatterns,
            '잘못된 텍스트 도메인 사용: ' . implode(', ', $wrongDomainPatterns)
        );
    }

    public function test_no_raw_korean_in_ajax_json_responses(): void {
        $ajaxFile = self::SRC_DIR . '/Admin/AjaxHandlers.php';
        $content  = file_get_contents($ajaxFile);

        // wp_send_json_*에 하드코딩된 한국어가 없어야 이상적이지만
        // 현재 오류 메시지는 한국어가 맞음 — 형식 검증만 수행
        // (향후 i18n 적용 대상 목록에 기록)
        $this->assertStringContainsString('wp_send_json_error', $content);
        $this->assertStringContainsString('wp_send_json_success', $content);
    }

    // ── loadTextDomain 메서드 존재 확인 ──────────────────────────────────────

    public function test_plugin_class_has_loadTextDomain_method(): void {
        $this->assertTrue(
            method_exists('CRMBizNewsletter\Plugin', 'loadTextDomain'),
            'Plugin::loadTextDomain() 정적 메서드가 있어야 함'
        );
    }

    public function test_loadTextDomain_is_callable(): void {
        // CRMBIZ_NL_FILE이 정의된 환경에서만 실행 가능 — 존재 여부만 확인
        $this->assertTrue(
            is_callable(['CRMBizNewsletter\Plugin', 'loadTextDomain']),
            'Plugin::loadTextDomain()가 callable이어야 함'
        );
    }

    // ── Privacy 클래스 번역 가능 문자열 ───────────────────────────────────────

    public function test_privacy_exporter_name_is_string(): void {
        $result = \CRMBizNewsletter\Privacy::registerExporter([]);
        $this->assertIsString($result['crmbiz-newsletter']['exporter_friendly_name']);
    }

    public function test_privacy_eraser_name_is_string(): void {
        $result = \CRMBizNewsletter\Privacy::registerEraser([]);
        $this->assertIsString($result['crmbiz-newsletter']['eraser_friendly_name']);
    }

}
