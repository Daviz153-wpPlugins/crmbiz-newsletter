<?php
declare(strict_types=1);

// WordPress 환경 상수 정의
define('ABSPATH',         dirname(__DIR__) . '/');
define('CRMBIZ_NL_DIR',  dirname(__DIR__) . '/');
define('CRMBIZ_NL_VERSION', '0.0.0-test');
define('DAY_IN_SECONDS',    86400);

// -----------------------------------------------------------------------
// In-memory WordPress 함수 스텁
// -----------------------------------------------------------------------

/** @var array<string,mixed> */
$GLOBALS['_wp_options']    = [];
$GLOBALS['_wp_transients'] = [];

function get_option(string $key, $default = false) {
    return array_key_exists($key, $GLOBALS['_wp_options'])
        ? $GLOBALS['_wp_options'][$key]
        : $default;
}

function update_option(string $key, $value, $autoload = null): bool {
    $GLOBALS['_wp_options'][$key] = $value;
    return true;
}

function delete_option(string $key): bool {
    unset($GLOBALS['_wp_options'][$key]);
    return true;
}

function get_transient(string $key) {
    return $GLOBALS['_wp_transients'][$key] ?? false;
}

function set_transient(string $key, $value, int $expiration = 0): bool {
    $GLOBALS['_wp_transients'][$key] = $value;
    return true;
}

function delete_transient(string $key): bool {
    unset($GLOBALS['_wp_transients'][$key]);
    return true;
}

function sanitize_text_field(string $str): string {
    return trim(wp_check_invalid_utf8($str));
}

function wp_check_invalid_utf8(string $str): string {
    return $str; // 테스트에서는 그대로 반환
}

function sanitize_email(string $email): string {
    return strtolower(trim($email));
}

function get_bloginfo(string $show = ''): string {
    return 'Test Site';
}

function wp_timezone(): \DateTimeZone {
    return new \DateTimeZone('Asia/Seoul');
}

function home_url(string $path = ''): string {
    return 'https://example.com' . $path;
}

function add_query_arg($args, string $url = ''): string {
    if (is_string($args)) {
        return $url;
    }
    return $url . '?' . http_build_query($args);
}

function current_time(string $type, bool $gmt = false): string {
    return date('Y-m-d H:i:s');
}

// -----------------------------------------------------------------------
// Composer 자동로더 (src/ 네임스페이스)
// -----------------------------------------------------------------------
require_once dirname(__DIR__) . '/vendor/autoload.php';
