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
delete_option('crmbiz_nl_settings');
delete_option('crmbiz_nl_db_version');
delete_option('crmbiz_nl_secret');

// ── 예약 Cron 이벤트 전체 제거 ──────────────────────────────────────────
wp_clear_scheduled_hook('crmbiz_nl_send_newsletter');
wp_clear_scheduled_hook('crmbiz_nl_cleanup');

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
