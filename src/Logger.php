<?php
namespace CRMBizNewsletter;

defined('ABSPATH') || exit;

class Logger {

    private const PREFIX    = '[CRMBiz NL]';
    private const RETAIN_DB = 7; // DB 로그 보관 일수

    // ── 공개 로깅 메서드 ──────────────────────────────────────────────────────

    /** 항상 기록 + DB 저장 + 관리자 이메일 알림 (rate limit: 1시간/유형) */
    public static function error(string $message, array $context = []): void {
        self::write('ERROR', $message, $context);
        self::writeDb('ERROR', $message, $context);
        self::maybeSendEmail($message, $context);
    }

    /** 항상 기록 + DB 저장 */
    public static function warning(string $message, array $context = []): void {
        self::write('WARN', $message, $context);
        self::writeDb('WARN', $message, $context);
    }

    /** WP_DEBUG 활성화 시에만 기록 */
    public static function info(string $message, array $context = []): void {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            self::write('INFO', $message, $context);
        }
    }

    // ── DB 로그 조회 (설정 페이지용) ─────────────────────────────────────────

    public static function getLogs(string $level = '', int $limit = 100): array {
        global $wpdb;
        $table = $wpdb->prefix . 'crmbiz_nl_logs';

        if ($level) {
            return $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table} WHERE level = %s ORDER BY occurred_at DESC LIMIT %d",
                $level, $limit
            ), ARRAY_A) ?: [];
        }

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} ORDER BY occurred_at DESC LIMIT %d",
            $limit
        ), ARRAY_A) ?: [];
    }

    public static function clearLogs(): void {
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}crmbiz_nl_logs");
    }

    /** handleCleanup()에서 호출 — 보관 기간 초과 로그 삭제 */
    public static function cleanup(): void {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}crmbiz_nl_logs WHERE occurred_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            self::RETAIN_DB
        ));
    }

    // ── 내부 메서드 ───────────────────────────────────────────────────────────

    private static function write(string $level, string $message, array $context): void {
        $entry = self::PREFIX . '[' . $level . '] ' . $message;
        if (!empty($context)) {
            $entry .= ' ' . (function_exists('wp_json_encode')
                ? wp_json_encode($context, JSON_UNESCAPED_UNICODE)
                : json_encode($context, JSON_UNESCAPED_UNICODE));
        }
        error_log($entry);
    }

    private static function writeDb(string $level, string $message, array $context): void {
        global $wpdb;
        // DB가 초기화되지 않았거나 테이블 없으면 조용히 무시
        if (!$wpdb || !Database::isInstalled()) {
            return;
        }
        try {
            $wpdb->insert(
                $wpdb->prefix . 'crmbiz_nl_logs',
                [
                    'level'       => $level,
                    'message'     => mb_substr($message, 0, 500),
                    'context'     => empty($context) ? null : wp_json_encode($context, JSON_UNESCAPED_UNICODE),
                    'occurred_at' => current_time('mysql'),
                ],
                ['%s', '%s', '%s', '%s']
            );
        } catch (\Throwable $e) {
            // DB 쓰기 실패 — 메인 발송 흐름에 영향 없도록 무시
        }
    }

    /**
     * 오류 이메일 알림 — 같은 유형 오류는 1시간에 1회만 발송 (transient rate limit).
     */
    private static function maybeSendEmail(string $message, array $context): void {
        if (!function_exists('wp_mail') || !function_exists('get_transient')) {
            return;
        }

        // 관리자 이메일 알림 비활성화 옵션 확인
        $settings = (array) get_option('crmbiz_nl_settings', []);
        if (!empty($settings['disable_error_email'])) {
            return;
        }

        // Rate limit: 같은 메시지 유형 1시간에 1회
        $key = 'crmbiz_nl_err_' . substr(md5($message), 0, 16);
        if (get_transient($key)) {
            return;
        }
        set_transient($key, 1, HOUR_IN_SECONDS);

        $adminEmail = $settings['notify_email'] ?? get_option('admin_email');
        if (!is_email($adminEmail)) {
            return;
        }

        $ctx     = empty($context) ? '' : '<pre style="background:#f3f4f6;padding:10px;border-radius:4px;font-size:12px;overflow:auto">' . esc_html(wp_json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) . '</pre>';
        $siteUrl = admin_url('admin.php?page=crmbiz-nl-settings&tab=logs');
        $body    = '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:24px;background:#f9fafb">'
            . '<div style="max-width:520px;margin:0 auto;background:#fff;padding:28px;border-radius:8px;border-left:4px solid #ef4444">'
            . '<h2 style="margin:0 0 12px;color:#111827;font-size:18px">⚠️ CRMBiz Newsletter 오류 감지</h2>'
            . '<p style="margin:0 0 8px;font-size:14px;color:#374151"><strong>오류:</strong> ' . esc_html($message) . '</p>'
            . '<p style="margin:0 0 16px;font-size:12px;color:#9ca3af">' . current_time('Y-m-d H:i:s') . '</p>'
            . $ctx
            . '<p style="margin:16px 0 0"><a href="' . esc_url($siteUrl) . '" style="background:#111827;color:#fff;padding:8px 16px;border-radius:6px;text-decoration:none;font-size:13px">시스템 로그 보기</a></p>'
            . '</div></body></html>';

        wp_mail(
            $adminEmail,
            '[CRMBiz Newsletter] 오류 발생: ' . mb_substr($message, 0, 60),
            $body,
            ['Content-Type: text/html; charset=UTF-8']
        );
    }
}
