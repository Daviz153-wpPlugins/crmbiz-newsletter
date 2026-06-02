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

function esc_html(string $text): string   { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
function esc_attr(string $text): string   { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
function esc_url(string $url): string     { return htmlspecialchars($url,  ENT_QUOTES, 'UTF-8'); }
function esc_html__(string $text, string $domain = 'default'): string { return esc_html($text); }

function is_email(string $email): bool {
    return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
}

function wp_mail($to, string $subject, string $message, $headers = '', $attachments = []): bool {
    $GLOBALS['_wp_mail_calls'][] = compact('to', 'subject', 'message', 'headers');
    return $GLOBALS['_wp_mail_result'] ?? true;
}
$GLOBALS['_wp_mail_calls']  = [];
$GLOBALS['_wp_mail_result'] = true;

/**
 * wp_send_json_* 스텁 — exit 대신 예외를 던져 테스트에서 캡처 가능하게 함
 * try { $handler->handle(); } catch (WpJsonResponse $e) { $e->getPayload() }
 */
class WpJsonResponse extends \RuntimeException {
    public function __construct(private bool $success, private mixed $data, private int $statusCode) {
        parent::__construct($success ? 'success' : 'error');
    }
    public function isSuccess(): bool  { return $this->success; }
    public function getData(): mixed   { return $this->data; }
    public function getStatus(): int   { return $this->statusCode; }
}

function wp_send_json_success($data = null, int $statusCode = 200): void {
    throw new WpJsonResponse(true, $data, $statusCode);
}
function wp_send_json_error($data = null, int $statusCode = 200): void {
    throw new WpJsonResponse(false, $data, $statusCode);
}
function wp_send_json($data, int $statusCode = 200): void {
    throw new WpJsonResponse(true, $data, $statusCode);
}

class WpDiedException extends \RuntimeException {
    public function __construct(public readonly string $wpMessage, public readonly int $statusCode = 500) {
        parent::__construct($wpMessage);
    }
}
function wp_die($message = '', $title = '', $args = []): void {
    $code = is_array($args) ? (int) ($args['response'] ?? 500) : 500;
    throw new WpDiedException((string) $message, $code);
}

function wp_redirect(string $location, int $status = 302): bool {
    $GLOBALS['_wp_redirect'] = ['location' => $location, 'status' => $status];
    return true;
}
$GLOBALS['_wp_redirect'] = null;

// WP_Post 스텁
if (!class_exists('WP_Post')) {
    class WP_Post {
        public int    $ID           = 0;
        public string $post_title   = '';
        public string $post_content = '';
        public string $post_date    = '';
        public string $post_status  = 'publish';
        public string $post_type    = 'post';
        public int    $post_author  = 0;
        public string $post_excerpt = '';
        public string $post_name    = '';
        public string $guid         = '';

        public function __construct(object $data) {
            foreach ((array) $data as $key => $value) {
                $this->$key = $value;
            }
        }
    }
}

function get_current_user_id(): int { return 1; }
function wp_get_current_user(): object {
    return (object)[
        'ID'           => 1,
        'user_email'   => 'admin@example.com',
        'display_name' => 'Admin',
    ];
}
function get_the_title($post): string {
    if (is_object($post)) return $post->post_title ?? '';
    return $GLOBALS['_wp_posts'][(int)$post]->post_title ?? '';
}
function get_permalink($post): string { return 'https://example.com/post/1'; }
function get_the_date(string $format = '', $post = null): string { return '2026-01-01'; }
function get_theme_mod(string $name, $default = false) { return $default; }
function wp_get_attachment_image_src(int $attachmentId, $size = 'thumbnail'): false { return false; }
function get_post_thumbnail_id($post = null): int { return 0; }
function get_posts(array $args = []): array { return []; }
function wp_kses_post(string $content): string { return $content; }

function add_action(string $hook, $callback, int $priority = 10, int $args = 1): void {}
function add_filter(string $hook, $callback, int $priority = 10, int $args = 1): void {}
function is_admin(): bool { return false; }
function wp_next_scheduled(string $hook, array $args = []): false { return false; }
function wp_schedule_event(): void {}
function wp_schedule_single_event(): void {}
function wp_clear_scheduled_hook(string $hook, array $args = []): void {}
function wp_timezone_string(): string { return 'Asia/Seoul'; }
function wp_json_encode($data, int $flags = 0): string { return (string) json_encode($data, $flags); }
function wp_is_post_revision(int $postId): false { return false; }
function wp_verify_nonce($nonce, $action = -1): int { return 1; }
function current_user_can(string $capability): bool {
    return (bool) ($GLOBALS['_wp_user_can'] ?? true);
}
$GLOBALS['_wp_user_can'] = true;
function check_ajax_referer(string $action, $query_arg = false, bool $die = true): int { return 1; }

function get_post(int $postId): ?object {
    return $GLOBALS['_wp_posts'][$postId] ?? null;
}
function get_post_status(int $postId): string {
    return $GLOBALS['_wp_posts'][$postId]->post_status ?? 'draft';
}

$GLOBALS['_wp_post_meta'] = [];
function get_post_meta(int $postId, string $key, bool $single = false) {
    $val = $GLOBALS['_wp_post_meta'][$postId][$key] ?? null;
    if ($single) {
        return $val ?? '';
    }
    return $val !== null ? [$val] : [];
}
function update_post_meta(int $postId, string $key, $value): void {
    $GLOBALS['_wp_post_meta'][$postId][$key] = $value;
}
function delete_post_meta(int $postId, string $key): void {
    unset($GLOBALS['_wp_post_meta'][$postId][$key]);
}

// Action Scheduler 스텁 — ScheduleDispatchTest에서 예약 흐름 검증용
$GLOBALS['_as_actions'] = [];

function as_schedule_single_action(int $ts, string $hook, array $args = [], string $group = ''): int {
    $id = count($GLOBALS['_as_actions']) + 1;
    $GLOBALS['_as_actions'][] = ['id' => $id, 'ts' => $ts, 'hook' => $hook, 'args' => $args, 'group' => $group];
    return $id;
}

function as_unschedule_all_actions(string $hook, $args = [], string $group = ''): void {
    $GLOBALS['_as_actions'] = array_values(array_filter(
        $GLOBALS['_as_actions'],
        function ($a) use ($hook, $args, $group) {
            if ($a['hook'] !== $hook || $a['group'] !== $group) {
                return true; // 다른 훅/그룹은 유지
            }
            if ($args === null) {
                return false; // null = args 무관하게 모두 제거
            }
            return $a['args'] !== $args;
        }
    ));
}

function as_next_scheduled_action(string $hook, array $args = [], string $group = ''): int|false {
    foreach ($GLOBALS['_as_actions'] as $a) {
        if ($a['hook'] === $hook && $a['args'] === $args && $a['group'] === $group) {
            return $a['ts'];
        }
    }
    return false;
}

// -----------------------------------------------------------------------
// $wpdb 인메모리 스텁
// -----------------------------------------------------------------------
$GLOBALS['_wpdb_ratelimit']      = [];
$GLOBALS['_wpdb_newsletters']    = [];
$GLOBALS['_wpdb_unsubscribers']  = [];
$GLOBALS['_wpdb_events']         = [];

class WpdbStub {
    public string $prefix = 'wp_';
    public string $options = 'wp_options';
    public int $rows_affected = 0;
    public int $insert_id = 0;
    private int $last_insert_id = 0;

    public function prepare(string $sql, ...$args): string {
        $i = 0;
        return preg_replace_callback('/%([sd])/', function($m) use (&$i, $args) {
            $val = (string) ($args[$i++] ?? '');
            return $m[1] === 's' ? "'" . addslashes($val) . "'" : (int) $val;
        }, $sql);
    }

    public function get_var(string $sql): ?string {
        if (strpos($sql, 'LAST_INSERT_ID()') !== false) {
            return (string) $this->last_insert_id;
        }
        // SELECT id FROM wp_crmbiz_newsletters WHERE post_id = N LIMIT 1
        if (preg_match('/SELECT id FROM \S+crmbiz_newsletters WHERE post_id = (\d+)/i', $sql, $m)) {
            foreach ($GLOBALS['_wpdb_newsletters'] as $row) {
                if ((int)$row['post_id'] === (int)$m[1]) {
                    return (string) $row['id'];
                }
            }
            return null;
        }
        // SELECT id FROM wp_crmbiz_nl_unsubscribers WHERE email = 'x' LIMIT 1
        if (preg_match("/SELECT id FROM \S+crmbiz_nl_unsubscribers WHERE email = '([^']+)'/i", $sql, $m)) {
            foreach ($GLOBALS['_wpdb_unsubscribers'] as $row) {
                if ($row['email'] === $m[1]) {
                    return '1';
                }
            }
            return null;
        }
        // SELECT id FROM wp_crmbiz_nl_events WHERE newsletter_id = N AND email = 'x' AND type = 'unsubscribe' LIMIT 1
        if (preg_match("/SELECT id FROM \S+crmbiz_nl_events\s+WHERE newsletter_id = (\d+) AND email = '([^']+)' AND type = 'unsubscribe'/is", $sql, $m)) {
            $nlId  = (int) $m[1];
            $email = $m[2];
            foreach ($GLOBALS['_wpdb_events'] as $row) {
                if ((int)($row['newsletter_id'] ?? 0) === $nlId && ($row['email'] ?? '') === $email && ($row['type'] ?? '') === 'unsubscribe') {
                    return (string) $row['id'];
                }
            }
            return null;
        }
        return null;
    }

    public function replace(string $table, array $data, array $formats = []): int {
        if (strpos($table, 'unsubscribers') !== false) {
            foreach ($GLOBALS['_wpdb_unsubscribers'] as $key => $row) {
                if ($row['email'] === ($data['email'] ?? '')) {
                    $GLOBALS['_wpdb_unsubscribers'][$key] = array_merge($row, $data);
                    return 1;
                }
            }
            $GLOBALS['_wpdb_unsubscribers'][] = $data;
        }
        return 1;
    }

    public function delete(string $table, array $where, array $formats = []): int {
        if (strpos($table, 'unsubscribers') !== false) {
            $before = count($GLOBALS['_wpdb_unsubscribers']);
            $GLOBALS['_wpdb_unsubscribers'] = array_values(array_filter(
                $GLOBALS['_wpdb_unsubscribers'],
                function ($row) use ($where) {
                    foreach ($where as $col => $val) {
                        if (($row[$col] ?? null) == $val) {
                            return false;
                        }
                    }
                    return true;
                }
            ));
            return $before - count($GLOBALS['_wpdb_unsubscribers']);
        }
        return 1;
    }

    public function get_row(string $sql): ?object {
        // SELECT id, status FROM wp_crmbiz_newsletters WHERE post_id = N AND status IN (...)
        if (preg_match("/post_id = (\d+) AND status IN \(([^)]+)\)/i", $sql, $m)) {
            $postId   = (int) $m[1];
            $statuses = array_map(fn($s) => trim($s, " '\""), explode(',', $m[2]));
            foreach (array_reverse($GLOBALS['_wpdb_newsletters']) as $row) {
                if ((int)$row['post_id'] === $postId && in_array($row['status'], $statuses, true)) {
                    return (object) $row;
                }
            }
        }
        return null;
    }

    public function update(string $table, array $data, array $where, array $dataFormats = [], array $whereFormats = []): int {
        foreach ($GLOBALS['_wpdb_newsletters'] as &$row) {
            $match = true;
            foreach ($where as $col => $val) {
                if (($row[$col] ?? null) != $val) { $match = false; break; }
            }
            if ($match) {
                foreach ($data as $col => $val) {
                    $row[$col] = $val;
                }
            }
        }
        unset($row);
        return 1;
    }

    public function insert(string $table, array $data, array $formats = []): int {
        if (strpos($table, 'crmbiz_nl_events') !== false) {
            $id = count($GLOBALS['_wpdb_events']) + 1;
            $data['id'] = $id;
            $GLOBALS['_wpdb_events'][] = $data;
            $this->last_insert_id = $id;
            $this->insert_id      = $id;
            return 1;
        }
        $id = count($GLOBALS['_wpdb_newsletters']) + 1;
        $data['id'] = $id;
        $GLOBALS['_wpdb_newsletters'][] = $data;
        $this->last_insert_id = $id;
        $this->insert_id      = $id;
        return 1;
    }

    public function get_col(string $sql): array {
        // SELECT post_id FROM wp_crmbiz_newsletters WHERE status = 'sent' ...
        if (preg_match("/SELECT post_id FROM \S+crmbiz_newsletters WHERE status = 'sent'/i", $sql)) {
            return array_map(fn($r) => (string)($r['post_id'] ?? 0),
                array_filter($GLOBALS['_wpdb_newsletters'], fn($r) => ($r['status'] ?? '') === 'sent')
            );
        }
        return [];
    }

    public function query(string $sql): int {
        // DELETE FROM wp_crmbiz_nl_unsubscribers WHERE id IN (...)
        if (preg_match('/DELETE FROM \S+crmbiz_nl_unsubscribers WHERE id IN \(([^)]+)\)/i', $sql, $m)) {
            $ids    = array_map('intval', explode(',', $m[1]));
            $before = count($GLOBALS['_wpdb_unsubscribers']);
            $GLOBALS['_wpdb_unsubscribers'] = array_values(array_filter(
                $GLOBALS['_wpdb_unsubscribers'],
                fn($r) => !in_array((int)($r['id'] ?? 0), $ids, true)
            ));
            return $before - count($GLOBALS['_wpdb_unsubscribers']);
        }
        if (preg_match('/INSERT INTO \S+crmbiz_nl_ratelimit.*VALUES \(\'([^\']+)\'/s', $sql, $m)) {
            $key   = $m[1];
            $store = &$GLOBALS['_wpdb_ratelimit'];
            $now   = time();
            if (!isset($store[$key]) || $store[$key]['expires_at'] < $now) {
                $store[$key] = ['count' => 1, 'expires_at' => $now + 3660];
                $this->last_insert_id = 1;
            } else {
                $store[$key]['count']++;
                $this->last_insert_id = $store[$key]['count'];
            }
            return (int) $this->last_insert_id;
        }
        return 0;
    }
}

$GLOBALS['wpdb'] = new WpdbStub();

// -----------------------------------------------------------------------
// Composer 자동로더 (src/ 네임스페이스)
// -----------------------------------------------------------------------
require_once dirname(__DIR__) . '/vendor/autoload.php';
