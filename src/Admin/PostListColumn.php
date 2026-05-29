<?php
namespace CRMBizNewsletter\Admin;

defined('ABSPATH') || exit;

class PostListColumn {

    private static array $cache      = [];
    private static bool  $cacheReady = false;

    public function init(): void {
        add_filter('manage_post_posts_columns',       [$this, 'addColumn']);
        add_action('manage_post_posts_custom_column', [$this, 'renderColumn'], 10, 2);
        add_filter('the_posts',                       [$this, 'preloadCache'],  10, 1);
        add_action('admin_head',                      [$this, 'columnStyles']);
    }

    public function addColumn(array $columns): array {
        $new = [];
        foreach ($columns as $key => $label) {
            $new[$key] = $label;
            if ($key === 'title') {
                $new['crmbiz_newsletter'] = '뉴스레터';
            }
        }
        return $new;
    }

    /**
     * 현재 페이지의 포스트 ID를 한 번의 쿼리로 미리 로드해 N+1 방지
     */
    public function preloadCache(array $posts): array {
        if (empty($posts) || !is_admin()) {
            return $posts;
        }
        global $wpdb;
        $ids          = array_unique(array_map(fn($p) => (int) $p->ID, $posts));
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT post_id, status, sent_at, success_count
                   FROM {$wpdb->prefix}crmbiz_newsletters
                  WHERE post_id IN ($placeholders)
                  ORDER BY id DESC",
                ...$ids
            )
        );

        foreach ($rows as $row) {
            // post_id당 가장 최신 레코드(ORDER BY id DESC의 첫 번째)만 보존
            if (!isset(self::$cache[(int) $row->post_id])) {
                self::$cache[(int) $row->post_id] = $row;
            }
        }
        self::$cacheReady = true;
        return $posts;
    }

    public function renderColumn(string $column, int $postId): void {
        if ($column !== 'crmbiz_newsletter') {
            return;
        }

        $row = null;
        if (self::$cacheReady) {
            $row = self::$cache[$postId] ?? null;
        } else {
            global $wpdb;
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT status, sent_at, success_count
                   FROM {$wpdb->prefix}crmbiz_newsletters
                  WHERE post_id = %d
                  ORDER BY id DESC LIMIT 1",
                $postId
            ));
        }

        if (!$row) {
            echo '<span style="color:#d1d5db;font-size:13px">—</span>';
            return;
        }

        static $statusMap = [
            'sent'      => ['발송 완료', '#065f46', '#d1fae5'],
            'sending'   => ['발송 중',   '#1d4ed8', '#dbeafe'],
            'queued'    => ['발송 대기', '#92400e', '#fef3c7'],
            'scheduled' => ['예약 발송', '#5b21b6', '#ede9fe'],
            'draft'     => ['임시저장',  '#374151', '#f3f4f6'],
            'failed'    => ['발송 실패', '#991b1b', '#fee2e2'],
            'cancelled' => ['취소됨',    '#6b7280', '#f3f4f6'],
        ];

        [$label, $color, $bg] = $statusMap[$row->status] ?? [$row->status, '#374151', '#f3f4f6'];

        $historyUrl = admin_url('admin.php?page=crmbiz-nl-history');

        printf(
            '<a href="%s" style="text-decoration:none">'
          . '<span style="display:inline-block;padding:2px 7px;border-radius:4px;font-size:11px;font-weight:600;background:%s;color:%s">%s</span>'
          . '</a>',
            esc_url($historyUrl),
            esc_attr($bg),
            esc_attr($color),
            esc_html($label)
        );

        if ($row->status === 'sent' && $row->sent_at) {
            printf(
                '<span style="display:block;margin-top:3px;font-size:11px;color:#9ca3af">%s &middot; %d명</span>',
                esc_html(wp_date('m/d', (new \DateTime($row->sent_at, wp_timezone()))->getTimestamp())),
                (int) $row->success_count
            );
        }
    }

    public function columnStyles(): void {
        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'edit-post') {
            return;
        }
        echo '<style>.column-crmbiz_newsletter{width:88px}</style>';
    }
}
