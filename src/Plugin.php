<?php
namespace CRMBizNewsletter;

use CRMBizNewsletter\Admin\DiagnosticsPage;
use CRMBizNewsletter\Admin\SettingsPage;

defined('ABSPATH') || exit;

class Plugin {

    private static ?self $instance = null;

    private Settings $settings;

    public static function getInstance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {
        $this->settings = new Settings();
        $this->registerHooks();
    }

    private function registerHooks(): void {
        if (is_admin()) {
            add_action('admin_menu', [$this, 'registerAdminPages']);
        }

        add_action('wp_ajax_crmbiz_nl_test_email', [$this, 'handleTestEmail']);
    }

    public function registerAdminPages(): void {
        add_menu_page(
            'CRMBiz Newsletter',
            'CRMBiz NL',
            'manage_options',
            'crmbiz-newsletter',
            [$this, 'renderDiagnosticsPage'],
            'dashicons-email-alt',
            58
        );

        add_submenu_page(
            'crmbiz-newsletter',
            '진단 대시보드',
            '진단',
            'manage_options',
            'crmbiz-newsletter',
            [$this, 'renderDiagnosticsPage']
        );

        add_submenu_page(
            'crmbiz-newsletter',
            '설정',
            '설정',
            'manage_options',
            'crmbiz-nl-settings',
            [$this, 'renderSettingsPage']
        );
    }

    public function renderDiagnosticsPage(): void {
        (new DiagnosticsPage($this->settings))->render();
    }

    public function renderSettingsPage(): void {
        (new SettingsPage($this->settings))->render();
    }

    public function handleTestEmail(): void {
        if (!check_ajax_referer('crmbiz_nl_diagnostics', 'nonce', false)) {
            wp_send_json_error(['message' => '보안 검증 실패.'], 403);
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => '권한이 없습니다.'], 403);
        }

        $to = sanitize_email($_POST['test_email'] ?? '');
        if (!is_email($to)) {
            wp_send_json_error(['message' => '유효하지 않은 이메일 주소입니다.']);
        }

        if ($this->settings->isDryRun()) {
            FluentCRMBridge::debugLog(
                'CRMBiz Newsletter',
                'DRY-RUN: 테스트 이메일 건너뜀. To: ' . $to
            );
            wp_send_json_success(['dry_run' => true, 'to' => $to]);
        }

        $fromName  = $this->settings->getFromName();
        $fromEmail = $this->settings->getFromEmail();

        $subject = '[테스트] CRMBiz Newsletter 발송 테스트';
        $body    = sprintf(
            '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:24px">' .
            '<h2 style="color:#1a1a2e">테스트 이메일</h2>' .
            '<p>CRMBiz Newsletter 플러그인에서 발송한 테스트 이메일입니다.</p>' .
            '<table style="border-collapse:collapse;margin-top:16px">' .
            '<tr><td style="padding:4px 12px 4px 0;color:#666">발신자</td><td>%s &lt;%s&gt;</td></tr>' .
            '<tr><td style="padding:4px 12px 4px 0;color:#666">수신자</td><td>%s</td></tr>' .
            '<tr><td style="padding:4px 12px 4px 0;color:#666">시각</td><td>%s</td></tr>' .
            '</table>' .
            '<p style="margin-top:24px;color:#0f5132;background:#d1e7dd;padding:10px 14px;border-radius:4px">' .
            'FluentSMTP 연결이 정상입니다.</p>' .
            '</body></html>',
            esc_html($fromName),
            esc_html($fromEmail),
            esc_html($to),
            esc_html(current_time('Y-m-d H:i:s'))
        );

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $fromName . ' <' . $fromEmail . '>',
        ];

        $result = wp_mail($to, $subject, $body, $headers);

        if ($result) {
            wp_send_json_success(['message' => '발송 성공: ' . $to]);
        } else {
            wp_send_json_error(['message' => '발송 실패. FluentSMTP 설정을 확인하세요.']);
        }
    }
}
