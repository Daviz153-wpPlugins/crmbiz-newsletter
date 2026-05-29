<?php
namespace CRMBizNewsletter;

defined('ABSPATH') || exit;

class Settings {

    private const OPTION_KEY = 'crmbiz_nl_settings';

    private array $data;

    public function __construct() {
        $this->data = (array) get_option(self::OPTION_KEY, []);
    }

    public function get(string $key, $default = null) {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, $value): void {
        $this->data[$key] = $value;
        update_option(self::OPTION_KEY, $this->data);
    }

    public function saveFromPost(array $post): void {
        $tab = $post['crmbiz_tab'] ?? 'general';

        if ($tab === 'general') {
            $this->data['from_name']    = sanitize_text_field($post['from_name']   ?? '');
            $this->data['from_email']   = sanitize_email($post['from_email']       ?? '');
            $this->data['dry_run']      = isset($post['dry_run'])    ? 1 : 0;
            $this->data['debug_mode']   = isset($post['debug_mode']) ? 1 : 0;
            $this->data['notify_email'] = sanitize_email($post['notify_email'] ?? '');
        } elseif ($tab === 'template') {
            // style 속성 허용하되 url() 함수 제거 (CSS exfiltration 방어)
            $allowed = ['strong' => [], 'b' => [], 'em' => [], 'i' => [], 'span' => ['style' => []], 'a' => ['href' => [], 'style' => []], 'br' => []];
            $noUrlStyle = function ($styles) {
                return array_diff($styles, ['background', 'background-image']);
            };
            add_filter('safe_style_css', $noUrlStyle);
            $bio = wp_kses($post['sig_bio'] ?? '', $allowed);
            remove_filter('safe_style_css', $noUrlStyle);
            $bio = preg_replace('/\burl\s*\([^)]*\)/i', '', $bio);
            $allowed_positions = ['left', 'top', 'right'];
            $this->data['sig_enabled']        = isset($post['sig_enabled']) ? 1 : 0;
            $this->data['sig_show_name']      = isset($post['sig_show_name']) ? 1 : 0;
            $this->data['sig_show_bio']       = isset($post['sig_show_bio']) ? 1 : 0;
            $this->data['sig_photo_url']      = esc_url_raw($post['sig_photo_url'] ?? '');
            $this->data['sig_name']           = sanitize_text_field($post['sig_name'] ?? '');
            $this->data['sig_bio']            = $bio;
            $this->data['sig_border_color']   = sanitize_hex_color($post['sig_border_color'] ?? '') ?: '#f97316';
            $this->data['sig_border_opacity'] = min(100, max(0, (int) ($post['sig_border_opacity'] ?? 100)));
            $this->data['sig_bg_color']       = sanitize_hex_color($post['sig_bg_color'] ?? '') ?: '#eef2ff';
            $this->data['sig_bg_opacity']     = min(100, max(0, (int) ($post['sig_bg_opacity'] ?? 100)));
            $pos = $post['sig_photo_position'] ?? 'left';
            $this->data['sig_photo_position'] = in_array($pos, $allowed_positions, true) ? $pos : 'left';
            $this->data['sig_photo_gap']  = min(80, max(0, (int) ($post['sig_photo_gap']  ?? 16)));
            $this->data['sig_text_gap']   = min(40, max(0, (int) ($post['sig_text_gap']   ?? 8)));
        }

        update_option(self::OPTION_KEY, $this->data);
    }

    public function getSignature(): array {
        return [
            'enabled'        => (bool) $this->get('sig_enabled', true),
            'show_name'      => (bool) $this->get('sig_show_name', true),
            'show_bio'       => (bool) $this->get('sig_show_bio', true),
            'photo_url'      => (string) $this->get('sig_photo_url', ''),
            'name'           => (string) $this->get('sig_name', ''),
            'bio'            => (string) $this->get('sig_bio', ''),
            'border_color'   => (string) ($this->get('sig_border_color') ?: '#f97316'),
            'border_opacity' => (int) ($this->get('sig_border_opacity') ?? 100),
            'bg_color'       => (string) ($this->get('sig_bg_color') ?: '#eef2ff'),
            'bg_opacity'     => (int) ($this->get('sig_bg_opacity') ?? 100),
            'photo_position' => (string) ($this->get('sig_photo_position') ?: 'left'),
            'photo_gap'      => (int) ($this->get('sig_photo_gap') ?? 16),
            'text_gap'       => (int) ($this->get('sig_text_gap')  ?? 8),
        ];
    }

    public function getFromName(): string {
        $custom = $this->get('from_name');
        if ($custom) {
            return $custom;
        }
        $fcSettings = FluentCRMBridge::getGlobalEmailSettings();
        return $fcSettings['from_name'] ?? get_bloginfo('name');
    }

    public function getFromEmail(): string {
        $custom = $this->get('from_email');
        if ($custom) {
            return $custom;
        }
        $fcSettings = FluentCRMBridge::getGlobalEmailSettings();
        return $fcSettings['from_email'] ?? (string) get_option('admin_email');
    }


    public function getNotifyEmail(): string {
        $custom = $this->get('notify_email');
        if ($custom && is_email($custom)) {
            return $custom;
        }
        return (string) get_option('admin_email');
    }

    public function isDryRun(): bool {
        return (bool) $this->get('dry_run', false);
    }

    public function isDebugMode(): bool {
        return (bool) $this->get('debug_mode', false);
    }
}
