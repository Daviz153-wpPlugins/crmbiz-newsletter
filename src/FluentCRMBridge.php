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
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public static function getTagsForSelect(): array {
        if (!self::isAvailable()) {
            return [];
        }
        try {
            $result = [];
            foreach (FluentCrmApi('tags')->get() as $tag) {
                try {
                    $count = $tag->countByStatus('subscribed');
                } catch (\Throwable $e) {
                    $count = '?';
                }
                $result[] = [
                    'id'    => (int) $tag->id,
                    'label' => $tag->title . ' (' . $count . '명)',
                ];
            }
            return $result;
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function getListsForSelect(): array {
        if (!self::isAvailable()) {
            return [];
        }
        try {
            $result = [];
            foreach (FluentCrmApi('lists')->get() as $list) {
                try {
                    $count = $list->countByStatus('subscribed');
                } catch (\Throwable $e) {
                    $count = '?';
                }
                $result[] = [
                    'id'    => (int) $list->id,
                    'label' => $list->title . ' (' . $count . '명)',
                ];
            }
            return $result;
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function getGlobalEmailSettings(): array {
        if (!self::isAvailable()) {
            return [];
        }
        try {
            return \FluentCrm\App\Services\Helper::getGlobalEmailSettings();
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function debugLog(string $title, string $description): void {
        if (!self::isAvailable()) {
            return;
        }
        try {
            \FluentCrm\App\Services\Helper::debugLog($title, $description);
        } catch (\Throwable $e) {
            // FluentCRM 로그 불가 시 무시
        }
    }
}
