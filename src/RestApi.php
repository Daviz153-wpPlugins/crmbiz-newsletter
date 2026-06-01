<?php
namespace CRMBizNewsletter;

defined('ABSPATH') || exit;

class RestApi {

    private const NAMESPACE = 'crmbiz-nl/v1';

    public function init(): void {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void {
        // Dashboard
        register_rest_route(self::NAMESPACE, '/dashboard', [
            'methods'             => 'GET',
            'callback'            => [$this, 'getDashboard'],
            'permission_callback' => [$this, 'adminOnly'],
        ]);

        // Newsletter list
        register_rest_route(self::NAMESPACE, '/newsletters', [
            'methods'             => 'GET',
            'callback'            => [$this, 'getNewsletters'],
            'permission_callback' => [$this, 'adminOnly'],
        ]);

        // Newsletter detail
        register_rest_route(self::NAMESPACE, '/newsletters/(?P<id>[\d]+)', [
            ['methods' => 'GET',    'callback' => [$this, 'getNewsletterDetail'], 'permission_callback' => [$this, 'adminOnly']],
            ['methods' => 'DELETE', 'callback' => [$this, 'deleteNewsletter'],    'permission_callback' => [$this, 'adminOnly']],
        ]);

        // Actions
        foreach (['send', 'cancel', 'force-send', 'resend'] as $action) {
            $cb = lcfirst(str_replace('-', '', ucwords($action, '-'))) . 'Newsletter';
            register_rest_route(self::NAMESPACE, '/newsletters/(?P<id>[\d]+)/' . $action, [
                'methods'             => 'POST',
                'callback'            => [$this, $cb],
                'permission_callback' => [$this, 'adminOnly'],
            ]);
        }

        // Resend single recipient
        register_rest_route(self::NAMESPACE, '/newsletters/(?P<id>[\d]+)/resend-single', [
            'methods'             => 'POST',
            'callback'            => [$this, 'resendSingle'],
            'permission_callback' => [$this, 'adminOnly'],
        ]);

        // Progress polling
        register_rest_route(self::NAMESPACE, '/newsletters/progress', [
            'methods'             => 'GET',
            'callback'            => [$this, 'getProgress'],
            'permission_callback' => [$this, 'adminOnly'],
        ]);
    }

    public function adminOnly(): bool {
        return current_user_can('manage_options');
    }

    // ── Dashboard ────────────────────────────────────────────────────────────

    public function getDashboard(\WP_REST_Request $req): \WP_REST_Response {
        global $wpdb;

        $days = in_array((int) ($req->get_param('days') ?? 30), [7, 30, 90], true)
                ? (int) $req->get_param('days') : 30;

        $stats = $wpdb->get_row(
            "SELECT COUNT(*) AS total_nl,
                    COALESCE(SUM(success_count), 0) AS total_success,
                    COALESCE(SUM(fail_count), 0)    AS total_fail
             FROM {$wpdb->prefix}crmbiz_newsletters WHERE status = 'sent'"
        );

        $totalSuccess = (int) ($stats->total_success ?? 0);
        $totalFail    = (int) ($stats->total_fail ?? 0);
        $delivered    = $totalSuccess + $totalFail;

        $daily = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(sent_at) AS day, SUM(success_count) AS cnt
             FROM {$wpdb->prefix}crmbiz_newsletters
             WHERE status = 'sent' AND sent_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
             GROUP BY DATE(sent_at) ORDER BY day ASC",
            $days
        ));
        $dailyMap = [];
        foreach ($daily as $r) $dailyMap[$r->day] = (int) $r->cnt;

        $labels = $counts = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d        = gmdate('Y-m-d', strtotime("-{$i} days"));
            $labels[] = gmdate('m/d', strtotime($d));
            $counts[] = $dailyMap[$d] ?? 0;
        }

        $campaigns = $wpdb->get_results($wpdb->prepare(
            "SELECT n.id, COALESCE(p.post_title, '(삭제된 포스트)') AS title,
                    n.success_count, n.sent_at,
                    COUNT(DISTINCT CASE WHEN e.type IN ('open','click') THEN e.email END) AS opens,
                    COUNT(DISTINCT CASE WHEN e.type = 'click' THEN e.email END) AS clicks
             FROM {$wpdb->prefix}crmbiz_newsletters n
             LEFT JOIN {$wpdb->posts} p ON p.ID = n.post_id
             LEFT JOIN {$wpdb->prefix}crmbiz_nl_events e ON e.newsletter_id = n.id
             WHERE n.status = 'sent'
             GROUP BY n.id, p.post_title, n.success_count, n.sent_at
             ORDER BY n.sent_at DESC LIMIT %d", 8
        ));

        $fcAvailable = FluentCRMBridge::isAvailable();

        return rest_ensure_response([
            'stats'     => [
                'total_nl'      => (int) ($stats->total_nl ?? 0),
                'total_success' => $totalSuccess,
                'total_fail'    => $totalFail,
                'success_rate'  => $delivered > 0 ? round($totalSuccess / $delivered * 100, 1) : 0,
            ],
            'chart'     => ['labels' => $labels, 'counts' => $counts, 'days' => $days],
            'campaigns' => array_map(fn($c) => [
                'id'         => (int) $c->id,
                'title'      => $c->title,
                'sent'       => (int) $c->success_count,
                'open_rate'  => (int) $c->success_count > 0 ? round((int)$c->opens  / (int)$c->success_count * 100, 1) : 0,
                'click_rate' => (int) $c->success_count > 0 ? round((int)$c->clicks / (int)$c->success_count * 100, 1) : 0,
                'sent_at'    => $c->sent_at,
            ], array_reverse($campaigns)),
            'system' => [
                'version'       => CRMBIZ_NL_VERSION,
                'db_version'    => Database::getVersion(),
                'fluent_crm'    => $fcAvailable,
                'fluent_smtp'   => FluentCRMBridge::isFluentSMTPAvailable(),
                'contact_count' => $fcAvailable ? FluentCRMBridge::getContactCount() : 0,
            ],
        ]);
    }

    // ── Newsletter List ───────────────────────────────────────────────────────

    public function getNewsletters(\WP_REST_Request $req): \WP_REST_Response {
        global $wpdb;

        $search  = sanitize_text_field($req->get_param('search') ?? '');
        $page    = max(1, (int) ($req->get_param('page') ?? 1));
        $perPage = in_array((int) ($req->get_param('per_page') ?? 20), [20, 50, 100], true)
                   ? (int) $req->get_param('per_page') : 20;
        $offset  = ($page - 1) * $perPage;

        // 상태 필터
        $allowedStatuses = ['draft', 'queued', 'scheduled', 'sending', 'sent', 'failed', 'cancelled'];
        $statusRaw       = sanitize_key($req->get_param('status') ?? '');
        $statusFilter    = in_array($statusRaw, $allowedStatuses, true) ? $statusRaw : '';

        // 날짜 필터 (YYYY-MM-DD)
        $dateFrom = sanitize_text_field($req->get_param('date_from') ?? '');
        $dateTo   = sanitize_text_field($req->get_param('date_to')   ?? '');
        $dateFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) ? $dateFrom : '';
        $dateTo   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)   ? $dateTo   : '';

        // 정렬
        $sortDir = strtolower(sanitize_key($req->get_param('sort_dir') ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
        $sortMap = [
            'date'       => 'COALESCE(n.sent_at, n.scheduled_at, n.created_at)',
            'title'      => 'p.post_title',
            'status'     => 'n.status',
            'recipients' => 'n.recipient_count',
            'open_rate'  => 'open_count',
            'click_rate' => 'click_count',
        ];
        $sortKey = sanitize_key($req->get_param('sort_by') ?? 'date');
        $orderBy = ($sortMap[$sortKey] ?? $sortMap['date']) . ' ' . $sortDir;

        $select = "SELECT n.*, COALESCE(p.post_title, '(삭제된 포스트)') AS post_title,
                          COUNT(DISTINCT CASE WHEN e.type IN ('open','click') THEN e.email END) AS open_count,
                          COUNT(DISTINCT CASE WHEN e.type = 'click' THEN e.email END) AS click_count";
        $from   = "FROM {$wpdb->prefix}crmbiz_newsletters n
                   LEFT JOIN {$wpdb->posts} p ON p.ID = n.post_id
                   LEFT JOIN {$wpdb->prefix}crmbiz_nl_events e ON e.newsletter_id = n.id";

        // WHERE 조건 동적 조합
        $wheres = [];
        $params = [];
        if ($search !== '') {
            $wheres[] = 'p.post_title LIKE %s';
            $params[] = '%' . $wpdb->esc_like($search) . '%';
        }
        if ($statusFilter !== '') {
            $wheres[] = 'n.status = %s';
            $params[] = $statusFilter;
        }
        if ($dateFrom !== '') {
            $wheres[] = 'COALESCE(n.sent_at, n.created_at) >= %s';
            $params[] = $dateFrom . ' 00:00:00';
        }
        if ($dateTo !== '') {
            $wheres[] = 'COALESCE(n.sent_at, n.created_at) <= %s';
            $params[] = $dateTo . ' 23:59:59';
        }
        $where = $wheres ? 'WHERE ' . implode(' AND ', $wheres) : '';
        $group = "GROUP BY n.id ORDER BY $orderBy";

        if ($params) {
            $total = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}crmbiz_newsletters n
                 LEFT JOIN {$wpdb->posts} p ON p.ID = n.post_id $where",
                ...$params
            ));
            $rows = $wpdb->get_results($wpdb->prepare(
                "$select $from $where $group LIMIT %d OFFSET %d",
                ...[...$params, $perPage, $offset]
            ));
        } else {
            $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}crmbiz_newsletters");
            $rows  = $wpdb->get_results($wpdb->prepare(
                "$select $from $group LIMIT %d OFFSET %d",
                $perPage, $offset
            ));
        }

        return rest_ensure_response([
            'items' => array_map([$this, 'formatNewsletter'], $rows),
            'total' => $total,
            'pages' => max(1, (int) ceil($total / $perPage)),
        ]);
    }

    // ── Newsletter Detail ─────────────────────────────────────────────────────

    public function getNewsletterDetail(\WP_REST_Request $req): \WP_REST_Response {
        global $wpdb;
        $id = (int) $req->get_param('id');

        $nl = $wpdb->get_row($wpdb->prepare(
            "SELECT n.*, COALESCE(p.post_title, '(삭제된 포스트)') AS post_title,
                    COALESCE(ec.open_count, 0)  AS open_count,
                    COALESCE(ec.click_count, 0) AS click_count
             FROM {$wpdb->prefix}crmbiz_newsletters n
             LEFT JOIN {$wpdb->posts} p ON p.ID = n.post_id
             LEFT JOIN (
                 SELECT newsletter_id,
                        COUNT(DISTINCT CASE WHEN type = 'open'  THEN email END) AS open_count,
                        COUNT(DISTINCT CASE WHEN type = 'click' THEN email END) AS click_count
                 FROM {$wpdb->prefix}crmbiz_nl_events WHERE type IN ('open','click')
                 GROUP BY newsletter_id
             ) ec ON ec.newsletter_id = n.id
             WHERE n.id = %d", $id
        ));
        if (!$nl) return new \WP_REST_Response(['message' => '없는 항목입니다.'], 404);

        // 수신자별 이벤트 집계
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT email,
                    MAX(CASE WHEN type IN ('open','click') THEN 1 ELSE 0 END) AS opened,
                    MAX(CASE WHEN type = 'click'       THEN 1 ELSE 0 END) AS clicked,
                    MAX(CASE WHEN type = 'fail'        THEN 1 ELSE 0 END) AS failed,
                    MAX(CASE WHEN type = 'unsubscribe' THEN 1 ELSE 0 END) AS unsubscribed,
                    MAX(CASE WHEN type = 'send'        THEN occurred_at END) AS sent_at,
                    MAX(CASE WHEN type IN ('open','click') THEN occurred_at END) AS open_at,
                    MAX(CASE WHEN type = 'click'       THEN occurred_at END) AS click_at
             FROM {$wpdb->prefix}crmbiz_nl_events
             WHERE newsletter_id = %d GROUP BY email", $id
        ));

        // FluentCRM 이름 조회
        $nameMap = [];
        if (FluentCRMBridge::isAvailable() && !empty($rows)) {
            $emails = array_column($rows, 'email');
            $phs    = implode(',', array_fill(0, count($emails), '%s'));
            $fc     = $wpdb->get_results($wpdb->prepare(
                "SELECT email, first_name, last_name FROM {$wpdb->prefix}fc_subscribers WHERE email IN ($phs)",
                ...$emails
            ));
            foreach ($fc as $f) {
                $name = trim($f->first_name . ' ' . $f->last_name);
                if ($name) $nameMap[$f->email] = $name;
            }
        }

        $recipients = array_map(fn($r) => [
            'email'        => $r->email,
            'name'         => $nameMap[$r->email] ?? null,
            'opened'       => (bool) $r->opened,
            'clicked'      => (bool) $r->clicked,
            'failed'       => (bool) $r->failed,
            'unsubscribed' => (bool) $r->unsubscribed,
            'sent_at'      => $r->sent_at,
            'open_at'      => $r->open_at,
            'click_at'     => $r->click_at,
        ], $rows);

        $sent   = (int) $nl->success_count;
        $opens  = count(array_filter($recipients, fn($r) => $r['opened'] || $r['clicked']));
        $clicks = count(array_filter($recipients, fn($r) => $r['clicked']));
        $unsubs = count(array_filter($recipients, fn($r) => $r['unsubscribed']));

        // 설정에서 발신자 정보
        $settings  = (array) get_option('crmbiz_nl_settings', []);

        return rest_ensure_response([
            'newsletter'   => $this->formatNewsletter($nl),
            'from_name'    => $settings['from_name']  ?? get_bloginfo('name'),
            'from_email'   => $settings['from_email'] ?? (string) get_option('admin_email'),
            'preview_url'  => add_query_arg([
                'action'  => 'crmbiz_nl_preview_email',
                'post_id' => $nl->post_id,
                'nonce'   => wp_create_nonce('crmbiz_nl_preview_' . $nl->post_id),
            ], admin_url('admin-ajax.php')),
            'stats' => [
                'sent'       => $sent,
                'total'      => (int) $nl->recipient_count,
                'opens'      => $opens,
                'clicks'     => $clicks,
                'fails'      => (int) $nl->fail_count,
                'unsubs'     => $unsubs,
                'open_rate'  => $sent > 0 ? round($opens  / $sent * 100, 1) : 0,
                'click_rate' => $sent > 0 ? round($clicks / $sent * 100, 1) : 0,
                'fail_rate'  => (int)$nl->recipient_count > 0 ? round((int)$nl->fail_count / (int)$nl->recipient_count * 100, 1) : 0,
                'unsub_rate' => $sent > 0 ? round($unsubs / $sent * 100, 1) : 0,
                'ctr'        => $opens > 0 ? round($clicks / $opens * 100, 1) : 0,
            ],
            'recipients' => $recipients,
        ]);
    }

    // ── Actions ───────────────────────────────────────────────────────────────

    public function sendNewsletter(\WP_REST_Request $req): \WP_REST_Response {
        global $wpdb;
        $id = (int) $req->get_param('id');

        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}crmbiz_newsletters SET status = 'queued'
             WHERE id = %d AND status = 'draft'", $id
        ));
        if (!$updated) return new \WP_REST_Response(['message' => '발송 가능한 상태가 아닙니다.'], 400);

        Scheduler::scheduleSingle(time(), 'crmbiz_nl_send_newsletter', [$id]);
        return rest_ensure_response(['status' => 'queued']);
    }

    public function cancelNewsletter(\WP_REST_Request $req): \WP_REST_Response {
        global $wpdb;
        $id      = (int) $req->get_param('id');
        $cronHook = 'crmbiz_nl_send_newsletter';

        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}crmbiz_newsletters SET status = 'cancelled'
             WHERE id = %d AND status IN ('queued','sending','scheduled')", $id
        ));
        if (!$updated) return new \WP_REST_Response(['message' => '취소할 수 없는 상태입니다.'], 400);

        Scheduler::unschedule($cronHook, [$id]);
        $wpdb->delete($wpdb->prefix . 'crmbiz_nl_queue', ['newsletter_id' => $id], ['%d']);
        return rest_ensure_response(['status' => 'cancelled']);
    }

    public function forceSendNewsletter(\WP_REST_Request $req): \WP_REST_Response {
        $id = (int) $req->get_param('id');
        global $wpdb;

        $status = $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$wpdb->prefix}crmbiz_newsletters WHERE id = %d", $id
        ));
        if (!in_array($status, ['queued', 'sending'], true)) {
            return new \WP_REST_Response(['message' => '발송 불가 상태입니다.'], 400);
        }

        $settings = new Settings();
        $hasMore  = (new NewsletterSender($settings))->sendFromRecord($id);
        $cronHook = 'crmbiz_nl_send_newsletter';
        if ($hasMore && !Scheduler::isScheduled($cronHook, [$id])) {
            Scheduler::scheduleSingle(time() + 60, $cronHook, [$id]);
        }

        $nl = $wpdb->get_row($wpdb->prepare(
            "SELECT status, success_count, fail_count, recipient_count FROM {$wpdb->prefix}crmbiz_newsletters WHERE id = %d",
            $id
        ));

        return rest_ensure_response([
            'has_more'        => $hasMore,
            'status'          => $nl->status ?? 'sending',
            'success_count'   => (int) ($nl->success_count ?? 0),
            'fail_count'      => (int) ($nl->fail_count    ?? 0),
            'recipient_count' => (int) ($nl->recipient_count ?? 0),
        ]);
    }

    public function resendNewsletter(\WP_REST_Request $req): \WP_REST_Response {
        $id       = (int) $req->get_param('id');
        $settings = new Settings();
        global $wpdb;

        $record = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}crmbiz_newsletters WHERE id = %d", $id
        ));
        if (!$record) return new \WP_REST_Response(['message' => '없는 항목입니다.'], 404);
        if (!get_post((int) $record->post_id)) return new \WP_REST_Response(['message' => '포스트를 찾을 수 없습니다.'], 400);

        $newId    = (new NewsletterSender($settings))->createQueuedRecord((int) $record->post_id);
        $cronHook = 'crmbiz_nl_send_newsletter';
        Scheduler::scheduleSingle(time(), $cronHook, [$newId]);

        return rest_ensure_response(['new_id' => $newId]);
    }

    public function deleteNewsletter(\WP_REST_Request $req): \WP_REST_Response {
        global $wpdb;
        $id       = (int) $req->get_param('id');
        $cronHook = 'crmbiz_nl_send_newsletter';

        $status = $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$wpdb->prefix}crmbiz_newsletters WHERE id = %d", $id
        ));
        if ($status === 'sending') {
            return new \WP_REST_Response(['message' => '발송 중입니다. 취소 후 삭제하세요.'], 400);
        }

        Scheduler::unschedule($cronHook, [$id]);
        $wpdb->delete($wpdb->prefix . 'crmbiz_nl_queue',  ['newsletter_id' => $id], ['%d']);
        $wpdb->delete($wpdb->prefix . 'crmbiz_nl_events', ['newsletter_id' => $id], ['%d']);
        $deleted = (bool) $wpdb->delete($wpdb->prefix . 'crmbiz_newsletters', ['id' => $id], ['%d']);

        return $deleted
            ? rest_ensure_response(['deleted' => true])
            : new \WP_REST_Response(['message' => '삭제 실패.'], 500);
    }

    public function resendSingle(\WP_REST_Request $req): \WP_REST_Response {
        $id       = (int) $req->get_param('id');
        $email    = sanitize_email($req->get_param('email') ?? '');
        if (!is_email($email)) return new \WP_REST_Response(['message' => '유효하지 않은 이메일.'], 400);

        $settings = new Settings();
        $sent     = (new NewsletterSender($settings))->sendToEmail($id, $email);
        return $sent
            ? rest_ensure_response(['sent' => true])
            : new \WP_REST_Response(['message' => '발송 실패 (수신거부 또는 오류).'], 400);
    }

    public function getProgress(\WP_REST_Request $req): \WP_REST_Response {
        global $wpdb;
        $ids = array_values(array_filter(array_map('intval', (array) ($req->get_param('ids') ?? []))));
        if (empty($ids)) return rest_ensure_response([]);

        $phs  = implode(',', array_fill(0, count($ids), '%d'));
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, status, success_count, fail_count, recipient_count
                 FROM {$wpdb->prefix}crmbiz_newsletters WHERE id IN ($phs)",
                ...$ids
            )
        );

        return rest_ensure_response(array_map(fn($r) => [
            'id'              => (int) $r->id,
            'status'          => $r->status,
            'done'            => (int)$r->success_count + (int)$r->fail_count,
            'recipient_count' => (int) $r->recipient_count,
            'percent'         => (int)$r->recipient_count > 0
                                 ? min(100, round(((int)$r->success_count + (int)$r->fail_count) / (int)$r->recipient_count * 100))
                                 : 0,
        ], $rows));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function formatNewsletter(object $nl): array {
        $sent       = (int) $nl->success_count;
        $openCount  = (int) ($nl->open_count ?? 0);
        $clickCount = (int) ($nl->click_count ?? 0);
        $postId     = (int) $nl->post_id;
        return [
            'id'              => (int) $nl->id,
            'post_id'         => $postId,
            'post_title'      => $nl->post_title ?? '(삭제된 포스트)',
            'post_url'        => $postId > 0 ? (get_permalink($postId) ?: null) : null,
            'preview_url'     => $postId > 0 ? add_query_arg([
                'action'  => 'crmbiz_nl_preview_email',
                'post_id' => $postId,
                'nonce'   => wp_create_nonce('crmbiz_nl_preview_' . $postId),
            ], admin_url('admin-ajax.php')) : null,
            'status'          => $nl->status,
            'send_mode'       => $nl->send_mode,
            'scheduled_at'    => $nl->scheduled_at,
            'sent_at'         => $nl->sent_at,
            'created_at'      => $nl->created_at,
            'recipient_count' => (int) $nl->recipient_count,
            'success_count'   => $sent,
            'fail_count'      => (int) $nl->fail_count,
            'open_count'      => $openCount,
            'click_count'     => $clickCount,
            'open_rate'       => $sent > 0 ? round($openCount  / $sent * 100, 1) : 0,
            'click_rate'      => $sent > 0 ? round($clickCount / $sent * 100, 1) : 0,
            'fail_reason'     => $nl->fail_reason ?? null,
        ];
    }
}
