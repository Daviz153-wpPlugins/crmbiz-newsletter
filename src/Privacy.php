<?php
namespace CRMBizNewsletter;

defined('ABSPATH') || exit;

/**
 * GDPR / 개인정보보호법 대응
 *
 * WordPress 개인정보 도구(관리자 → 도구 → 개인정보 삭제/내보내기)와 연동.
 * 수신거부 이력, 발송 기록, 오픈/클릭 이벤트를 내보내거나 삭제.
 */
class Privacy {

    public static function register(): void {
        add_filter('wp_privacy_personal_data_exporters', [self::class, 'registerExporter']);
        add_filter('wp_privacy_personal_data_erasers',  [self::class, 'registerEraser']);
    }

    public static function registerExporter(array $exporters): array {
        $exporters['crmbiz-newsletter'] = [
            'exporter_friendly_name' => 'CRMBiz Newsletter',
            'callback'               => [self::class, 'export'],
        ];
        return $exporters;
    }

    public static function registerEraser(array $erasers): array {
        $erasers['crmbiz-newsletter'] = [
            'eraser_friendly_name' => 'CRMBiz Newsletter',
            'callback'             => [self::class, 'erase'],
        ];
        return $erasers;
    }

    /**
     * 개인정보 내보내기 콜백.
     *
     * @return array{ data: list<array>, done: bool }
     */
    public static function export(string $email, int $page = 1): array {
        global $wpdb;
        $items = [];

        // ── 수신거부 기록 ──────────────────────────────────────────────────
        $unsub = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT email, unsubscribed_at
                 FROM {$wpdb->prefix}crmbiz_nl_unsubscribers
                 WHERE email = %s",
                $email
            ),
            ARRAY_A
        );

        if ($unsub) {
            $items[] = [
                'group_id'    => 'crmbiz-newsletter-unsub',
                'group_label' => __('뉴스레터 수신거부', 'crmbiz-newsletter'),
                'item_id'     => 'unsub-' . md5($email),
                'data'        => [
                    ['name' => __('이메일', 'crmbiz-newsletter'),        'value' => esc_html($unsub['email'])],
                    ['name' => __('수신거부 일시', 'crmbiz-newsletter'), 'value' => esc_html($unsub['unsubscribed_at'])],
                ],
            ];
        }

        // ── 발송 기록 ──────────────────────────────────────────────────────
        $sends = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT newsletter_id, status, sent_at
                 FROM {$wpdb->prefix}crmbiz_nl_sends
                 WHERE email = %s
                 ORDER BY sent_at DESC LIMIT 100",
                $email
            ),
            ARRAY_A
        ) ?: [];

        foreach ($sends as $i => $send) {
            $items[] = [
                'group_id'    => 'crmbiz-newsletter-send',
                'group_label' => __('뉴스레터 발송 기록', 'crmbiz-newsletter'),
                'item_id'     => 'send-' . (int) $send['newsletter_id'],
                'data'        => [
                    ['name' => __('이메일', 'crmbiz-newsletter'),    'value' => esc_html($email)],
                    ['name' => __('상태', 'crmbiz-newsletter'),      'value' => esc_html($send['status'])],
                    ['name' => __('발송 일시', 'crmbiz-newsletter'), 'value' => esc_html($send['sent_at'])],
                ],
            ];
        }

        // ── 오픈/클릭/수신거부 이벤트 ─────────────────────────────────────
        $events = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT type, occurred_at
                 FROM {$wpdb->prefix}crmbiz_nl_events
                 WHERE email = %s
                 ORDER BY occurred_at DESC LIMIT 100",
                $email
            ),
            ARRAY_A
        ) ?: [];

        foreach ($events as $i => $event) {
            $items[] = [
                'group_id'    => 'crmbiz-newsletter-event',
                'group_label' => __('뉴스레터 활동 기록', 'crmbiz-newsletter'),
                'item_id'     => 'event-' . $i . '-' . md5($email),
                'data'        => [
                    ['name' => __('이메일', 'crmbiz-newsletter'),    'value' => esc_html($email)],
                    ['name' => __('유형', 'crmbiz-newsletter'),      'value' => esc_html($event['type'])],
                    ['name' => __('발생 일시', 'crmbiz-newsletter'), 'value' => esc_html($event['occurred_at'])],
                ],
            ];
        }

        return [
            'data' => $items,
            'done' => true,
        ];
    }

    /**
     * 개인정보 삭제 콜백.
     *
     * 발송 관련 모든 테이블에서 해당 이메일 데이터를 완전 삭제.
     * crmbiz_newsletters (발송 설정)은 이메일을 저장하지 않으므로 대상 제외.
     *
     * @return array{ items_removed: int, items_retained: int, messages: list<string>, done: bool }
     */
    public static function erase(string $email, int $page = 1): array {
        global $wpdb;
        $removed = 0;

        // 자식 테이블(외래키 의존) 먼저 삭제
        foreach ([
            $wpdb->prefix . 'crmbiz_nl_queue',
            $wpdb->prefix . 'crmbiz_nl_events',
            $wpdb->prefix . 'crmbiz_nl_sends',
            $wpdb->prefix . 'crmbiz_nl_unsubscribers',
        ] as $table) {
            $deleted  = $wpdb->delete($table, ['email' => $email], ['%s']);
            $removed += (int) $deleted;
        }

        return [
            'items_removed'  => $removed,
            'items_retained' => 0,
            'messages'       => [],
            'done'           => true,
        ];
    }
}
