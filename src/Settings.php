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
        $this->data['from_name']   = sanitize_text_field($post['from_name']   ?? '');
        $this->data['from_email']  = sanitize_email($post['from_email']       ?? '');
        $this->data['dry_run']     = isset($post['dry_run'])    ? 1 : 0;
        $this->data['debug_mode']  = isset($post['debug_mode']) ? 1 : 0;
        $this->data['notify_email'] = sanitize_email($post['notify_email'] ?? '');
        update_option(self::OPTION_KEY, $this->data);
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
