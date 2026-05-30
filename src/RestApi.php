<?php
namespace CRMBizNewsletter;

defined('ABSPATH') || exit;

class RestApi {

    private const NAMESPACE = 'crmbiz-nl/v1';

    public function init(): void {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void {
        register_rest_route(self::NAMESPACE, '/dashboard', [
            'methods'             => 'GET',
            'callback'            => [$this, 'getDashboard'],
            'permission_callback' => [$this, 'adminOnly'],
        ]);
    }

    public function adminOnly(): bool {
        return current_user_can('manage_options');
    }

    public function getDashboard(): \WP_REST_Response {
        global $wpdb;

        // 상단 통계
        $stats = $wpdb->get_row(
            "SELECT COUNT(*) AS total_nl,
                    COALESCE(SUM(success_count), 0) AS total_success,
                    COALESCE(SUM(fail_count), 0)    AS total_fail
             FROM {$wpdb->prefix}crmbiz_newsletters
             WHERE status = 'sent'"
        );

        $totalSuccess = (int) ($stats->total_success ?? 0);
        $totalFail    = (int) ($stats->total_fail ?? 0);
        $delivered    = $totalSuccess + $totalFail;

        // 최근 30일 일별 발송량
        $daily = $wpdb->get_results(
            "SELECT DATE(sent_at) AS day, SUM(success_count) AS cnt
             FROM {$wpdb->prefix}crmbiz_newsletters
             WHERE status = 'sent' AND sent_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             GROUP BY DATE(sent_at)
             ORDER BY day ASC"
        );

        $dailyMap = [];
        foreach ($daily as $row) {
            $dailyMap[$row->day] = (int) $row->cnt;
        }
        $labels = [];
        $counts = [];
        for ($i = 29; $i >= 0; $i--) {
            $d       = date('Y-m-d', strtotime("-{$i} days"));
            $labels[] = date('m/d', strtotime($d));
            $counts[] = $dailyMap[$d] ?? 0;
        }

        // 최근 캠페인 성과
        $campaigns = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT n.id,
                        COALESCE(p.post_title, '(삭제된 포스트)') AS title,
                        n.success_count, n.sent_at,
                        COUNT(DISTINCT CASE WHEN e.type IN ('open','click') THEN e.email END) AS opens,
                        COUNT(DISTINCT CASE WHEN e.type = 'click' THEN e.email END) AS clicks
                 FROM {$wpdb->prefix}crmbiz_newsletters n
                 LEFT JOIN {$wpdb->posts} p ON p.ID = n.post_id
                 LEFT JOIN {$wpdb->prefix}crmbiz_nl_events e ON e.newsletter_id = n.id
                 WHERE n.status = 'sent'
                 GROUP BY n.id, p.post_title, n.success_count, n.sent_at
                 ORDER BY n.sent_at DESC
                 LIMIT %d",
                8
            )
        );

        $campaignData = array_map(function ($c) {
            $sent = (int) $c->success_count;
            return [
                'id'         => (int) $c->id,
                'title'      => $c->title,
                'sent'       => $sent,
                'open_rate'  => $sent > 0 ? round((int)$c->opens  / $sent * 100, 1) : 0,
                'click_rate' => $sent > 0 ? round((int)$c->clicks / $sent * 100, 1) : 0,
                'sent_at'    => $c->sent_at,
            ];
        }, $campaigns);

        // 시스템 상태
        $fcAvailable   = FluentCRMBridge::isAvailable();
        $smtpAvailable = FluentCRMBridge::isFluentSMTPAvailable();

        return rest_ensure_response([
            'stats' => [
                'total_nl'      => (int) ($stats->total_nl ?? 0),
                'total_success' => $totalSuccess,
                'total_fail'    => $totalFail,
                'success_rate'  => $delivered > 0 ? round($totalSuccess / $delivered * 100, 1) : 0,
            ],
            'chart' => [
                'labels' => $labels,
                'counts' => $counts,
            ],
            'campaigns'  => array_reverse($campaignData),
            'system'     => [
                'version'       => CRMBIZ_NL_VERSION,
                'db_version'    => Database::getVersion(),
                'fluent_crm'    => $fcAvailable,
                'fluent_smtp'   => $smtpAvailable,
                'contact_count' => $fcAvailable ? FluentCRMBridge::getContactCount() : 0,
            ],
        ]);
    }
}
