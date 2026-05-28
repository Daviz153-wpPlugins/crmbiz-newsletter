<?php
// WordPress가 직접 호출할 때만 실행
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// DB 테이블 삭제
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}crmbiz_nl_events");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}crmbiz_nl_unsubscribers");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}crmbiz_newsletters");

// 옵션 삭제
delete_option('crmbiz_nl_settings');
delete_option('crmbiz_nl_db_version');
delete_option('crmbiz_nl_secret');

// 예약된 WP Cron 이벤트 제거
$timestamp = wp_next_scheduled('crmbiz_nl_send_newsletter');
while ($timestamp) {
    wp_unschedule_event($timestamp, 'crmbiz_nl_send_newsletter');
    $timestamp = wp_next_scheduled('crmbiz_nl_send_newsletter');
}
wp_clear_scheduled_hook('crmbiz_nl_send_newsletter');

// 포스트 메타 삭제
$wpdb->delete($wpdb->postmeta, ['meta_key' => '_crmbiz_nl_enabled']);
$wpdb->delete($wpdb->postmeta, ['meta_key' => '_crmbiz_nl_tag_ids']);
$wpdb->delete($wpdb->postmeta, ['meta_key' => '_crmbiz_nl_list_ids']);
$wpdb->delete($wpdb->postmeta, ['meta_key' => '_crmbiz_nl_send_mode']);
$wpdb->delete($wpdb->postmeta, ['meta_key' => '_crmbiz_nl_scheduled_at']);
