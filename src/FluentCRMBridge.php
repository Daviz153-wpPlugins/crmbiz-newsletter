<?php
namespace CRMBizNewsletter;

defined('ABSPATH') || exit;

class FluentCRMBridge {

    public static function isAvailable(): bool {
        return defined('FLUENTCRM') && function_exists('FluentCrmApi');
    }

    public static function isFluentSMTPAvailable(): bool {
        return defined('FLUENTMAIL') || class_exists('FluentMail\App\App');
    }

    public static function getContactsApi() {
        return self::isAvailable() ? FluentCrmApi('contacts') : null;
    }

    public static function getTagsApi() {
        return self::isAvailable() ? FluentCrmApi('tags') : null;
    }

    public static function getListsApi() {
        return self::isAvailable() ? FluentCrmApi('lists') : null;
    }

    public static function getContactCount(): int {
        if (!self::isAvailable()) {
            return 0;
        }
        try {
            return (int) FluentCrmApi('contacts')->getInstance()->count();
        } catch (\Exception $e) {
            return 0;
        }
    }

    public static function getTagsForSelect(): array {
        if (!self::isAvailable()) {
            return [];
        }
        try {
            $tags = FluentCrmApi('tags')->all()->get();
            return array_map(fn($t) => [
                'id'    => (int) $t->id,
                'label' => $t->title . ' (' . $t->countByStatus('subscribed') . '명)',
            ], $tags->toArray());
        } catch (\Exception $e) {
            return [];
        }
    }

    public static function getListsForSelect(): array {
        if (!self::isAvailable()) {
            return [];
        }
        try {
            $lists = FluentCrmApi('lists')->all()->get();
            return array_map(fn($l) => [
                'id'    => (int) $l->id,
                'label' => $l->title . ' (' . $l->countByStatus('subscribed') . '명)',
            ], $lists->toArray());
        } catch (\Exception $e) {
            return [];
        }
    }

    public static function getGlobalEmailSettings(): array {
        if (!self::isAvailable()) {
            return [];
        }
        try {
            return \FluentCrm\App\Services\Helper::getGlobalEmailSettings();
        } catch (\Exception $e) {
            return [];
        }
    }

    public static function debugLog(string $title, string $description): void {
        if (!self::isAvailable()) {
            return;
        }
        try {
            \FluentCrm\App\Services\Helper::debugLog($title, $description);
        } catch (\Exception $e) {
            // FluentCRM 로그 불가 시 무시
        }
    }
}
