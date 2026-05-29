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

function apply_filters(string $hook, $value, ...$args) {
    return $value;
}

function do_action(string $hook, ...$args): void {}

function esc_url_raw(string $url): string {
    return $url;
}

function wp_parse_url(string $url, int $component = -1) {
    return parse_url($url, $component);
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
// $wpdb 인메모리 스텁 (rate limit 등 DB 직접 접근용)
// -----------------------------------------------------------------------
$GLOBALS['_wpdb_ratelimit'] = []; // rl_key => ['count'=>int, 'expires_at'=>int]

class WpdbStub {
    public string $prefix = 'wp_';
    public string $options = 'wp_options';
    public int $rows_affected = 0;
    private int $last_insert_id = 0;

    public function prepare(string $sql, ...$args): string {
        $i = 0;
        return preg_replace_callback('/%([sd])/', function($m) use (&$i, $args) {
            $val = (string) ($args[$i++] ?? '');
            // %s → 'value' (문자열), %d → 숫자 (따옴표 없음)
            return $m[1] === 's' ? "'" . addslashes($val) . "'" : (int) $val;
        }, $sql);
    }

    public function query(string $sql): void {
        // INSERT ... ON DUPLICATE KEY UPDATE 패턴 파싱 (rate limit용)
        if (preg_match('/INSERT INTO \S+crmbiz_nl_ratelimit.*VALUES \(\'([^\']+)\'/s', $sql, $m)) {
            $key = $m[1];
            $store = &$GLOBALS['_wpdb_ratelimit'];
            $now   = time();
            if (!isset($store[$key]) || $store[$key]['expires_at'] < $now) {
                $store[$key] = ['count' => 1, 'expires_at' => $now + 3660];
                $this->last_insert_id = 1;
            } else {
                $store[$key]['count']++;
                $this->last_insert_id = $store[$key]['count'];
            }
            $this->rows_affected = 1;
        }
    }

    public function get_var(string $sql): ?string {
        if (strpos($sql, 'LAST_INSERT_ID()') !== false) {
            return (string) $this->last_insert_id;
        }
        return null;
    }
}

$GLOBALS['wpdb'] = new WpdbStub();

// -----------------------------------------------------------------------
// Composer 자동로더 (src/ 네임스페이스)
// -----------------------------------------------------------------------
require_once dirname(__DIR__) . '/vendor/autoload.php';
