<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// ── DB 테이블 전체 삭제 (의존성 순서: 자식 테이블 먼저) ─────────────────
$tables = [
    'crmbiz_nl_events',
    'crmbiz_nl_queue',
    'crmbiz_nl_sends',
    'crmbiz_nl_ratelimit',
    'crmbiz_nl_logs',
    'crmbiz_nl_unsubscribers',
    'crmbiz_newsletters',
];
foreach ($tables as $table) {
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}{$table}");
}

// ── WordPress 옵션 삭제 ──────────────────────────────────────────────────
$options = [
    'crmbiz_nl_settings',
    'crmbiz_nl_db_version',
    'crmbiz_nl_secret',
    'crmbiz_nl_last_cron_run',
];
foreach ($options as $option) {
    delete_option($option);
}

// ── 트랜지언트 삭제 ──────────────────────────────────────────────────────
delete_transient('crmbiz_nl_dash_stats');
delete_transient('crmbiz_nl_dash_chart_7');
delete_transient('crmbiz_nl_dash_chart_30');
delete_transient('crmbiz_nl_dash_chart_90');

// crmbiz_nl_err_{hash} 패턴 트랜지언트 일괄 삭제
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '_transient_crmbiz_nl_err_%'
        OR option_name LIKE '_transient_timeout_crmbiz_nl_err_%'"
);

// ── Action Scheduler 이벤트 제거 ─────────────────────────────────────────
$hooks = ['crmbiz_nl_send_newsletter', 'crmbiz_nl_cleanup'];
foreach ($hooks as $hook) {
    if (function_exists('as_unschedule_all_actions')) {
        as_unschedule_all_actions($hook, null, 'crmbiz-newsletter');
    }
    wp_clear_scheduled_hook($hook);
}

// ── 포스트 메타 삭제 ─────────────────────────────────────────────────────
$meta_keys = [
    '_crmbiz_nl_enabled',
    '_crmbiz_nl_tag_ids',
    '_crmbiz_nl_list_ids',
    '_crmbiz_nl_send_mode',
    '_crmbiz_nl_scheduled_at',
];
foreach ($meta_keys as $key) {
    $wpdb->delete($wpdb->postmeta, ['meta_key' => $key]);
}
