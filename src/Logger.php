<?php
namespace CRMBizNewsletter;

defined('ABSPATH') || exit;

class Logger {

    private const PREFIX = '[CRMBiz NL]';

    /** 항상 기록 — 운영 중 반드시 알아야 할 오류 */
    public static function error(string $message, array $context = []): void {
        self::write('ERROR', $message, $context);
    }

    /** 항상 기록 — 비정상이지만 치명적이지 않은 상황 */
    public static function warning(string $message, array $context = []): void {
        self::write('WARN', $message, $context);
    }

    /** WP_DEBUG 활성화 시에만 기록 — 정상 흐름 추적용 */
    public static function info(string $message, array $context = []): void {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            self::write('INFO', $message, $context);
        }
    }

    private static function write(string $level, string $message, array $context): void {
        $entry = self::PREFIX . '[' . $level . '] ' . $message;
        if (!empty($context)) {
            $entry .= ' ' . (function_exists('wp_json_encode')
                ? wp_json_encode($context, JSON_UNESCAPED_UNICODE)
                : json_encode($context, JSON_UNESCAPED_UNICODE));
        }
        error_log($entry);
    }
}
