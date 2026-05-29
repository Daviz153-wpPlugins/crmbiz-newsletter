<?php
declare(strict_types=1);

use CRMBizNewsletter\Settings;
use PHPUnit\Framework\TestCase;

class SettingsTest extends TestCase {

    protected function setUp(): void {
        unset($GLOBALS['_wp_options']['crmbiz_nl_settings']);
    }

    protected function tearDown(): void {
        unset($GLOBALS['_wp_options']['crmbiz_nl_settings']);
    }

    private function make(array $data = []): Settings {
        if ($data) {
            $GLOBALS['_wp_options']['crmbiz_nl_settings'] = $data;
        }
        return new Settings();
    }

    public function test_get_returns_default_when_key_missing(): void {
        $s = $this->make();
        $this->assertSame('fallback', $s->get('nonexistent', 'fallback'));
    }

    public function test_get_returns_stored_value(): void {
        $s = $this->make(['from_name' => 'My Newsletter']);
        $this->assertSame('My Newsletter', $s->get('from_name'));
    }

    public function test_set_persists_value(): void {
        $s = $this->make();
        $s->set('foo', 'bar');
        // 새 인스턴스로 읽어도 get_option 스텁에서 저장됐으면 읽힘
        $s2 = new Settings();
        $this->assertSame('bar', $s2->get('foo'));
    }

    public function test_is_dry_run_defaults_false(): void {
        $this->assertFalse($this->make()->isDryRun());
    }

    public function test_is_dry_run_true_when_set(): void {
        $this->assertTrue($this->make(['dry_run' => 1])->isDryRun());
    }

    public function test_is_debug_mode_defaults_false(): void {
        $this->assertFalse($this->make()->isDebugMode());
    }

    public function test_save_from_post_sets_fields(): void {
        $s = $this->make();
        $s->saveFromPost([
            'from_name'  => '  테스트 발신자  ',
            'from_email' => 'sender@example.com',
            'dry_run'    => '1',
        ]);

        $s2 = new Settings();
        $this->assertSame('테스트 발신자', $s2->get('from_name'));
        $this->assertSame('sender@example.com', $s2->get('from_email'));
        $this->assertTrue($s2->isDryRun());
    }

    public function test_save_from_post_dry_run_off_when_absent(): void {
        $s = $this->make(['dry_run' => 1]);
        $s->saveFromPost(['from_name' => 'X', 'from_email' => '']); // dry_run 키 없음

        $this->assertFalse((new Settings())->isDryRun());
    }
}
