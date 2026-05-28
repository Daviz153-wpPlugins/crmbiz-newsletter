<?php
namespace CRMBizNewsletter;

defined('ABSPATH') || exit;

class TrackingHandler {

    public function init(): void {
        add_action('template_redirect', [$this, 'handleRequest']);
    }

    public function handleRequest(): void {
        $action = $_GET['crmbiz_nl_action'] ?? '';

        if ($action === 'open') {
            $this->handleOpen();
        } elseif ($action === 'click') {
            $this->handleClick();
        }
    }

    private function handleOpen(): void {
        $newsletterId = (int) ($_GET['nl'] ?? 0);
        $email        = sanitize_email(self::decryptEmail(sanitize_text_field($_GET['e'] ?? '')));
        $token        = sanitize_text_field($_GET['t'] ?? '');

        if ($newsletterId && $email && $this->verifyOpenToken($newsletterId, $email, $token)) {
            $this->recordEvent($newsletterId, $email, 'open', null);
        }

        header('Content-Type: image/gif');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
        exit;
    }

    private function handleClick(): void {
        $newsletterId = (int) ($_GET['nl'] ?? 0);
        $email        = sanitize_email(self::decryptEmail(sanitize_text_field($_GET['e'] ?? '')));
        $token        = sanitize_text_field($_GET['t'] ?? '');
        $url          = $_GET['url'] ?? '';

        // 토큰 검증 성공 시에만 $url로 리디렉트 — 오픈 리디렉트 방지
        if ($newsletterId && $email && $url && $this->verifyClickToken($newsletterId, $email, $url, $token)) {
            $this->recordEvent($newsletterId, $email, 'click', $url);
            wp_redirect(esc_url_raw($url));
        } else {
            wp_redirect(home_url('/'));
        }
        exit;
    }

    // 오픈 토큰: newsletter_id + email 기반
    private function verifyOpenToken(int $newsletterId, string $email, string $token): bool {
        $expected = hash_hmac('sha256', "open:{$newsletterId}|{$email}", Database::getSecret());
        return hash_equals($expected, $token);
    }

    // 클릭 토큰: newsletter_id + email + 목적지 URL 포함 — URL 변조 방지
    private function verifyClickToken(int $newsletterId, string $email, string $url, string $token): bool {
        $expected = hash_hmac('sha256', "click:{$newsletterId}|{$email}|{$url}", Database::getSecret());
        return hash_equals($expected, $token);
    }

    private function recordEvent(int $newsletterId, string $email, string $type, ?string $url): void {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'crmbiz_nl_events',
            [
                'newsletter_id' => $newsletterId,
                'email'         => $email,
                'type'          => $type,
                'url'           => $url ? substr($url, 0, 2083) : null,
                'occurred_at'   => current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%s', '%s']
        );
    }

    public static function recordSend(int $newsletterId, string $email, bool $success): void {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'crmbiz_nl_events',
            [
                'newsletter_id' => $newsletterId,
                'email'         => $email,
                'type'          => $success ? 'send' : 'fail',
                'url'           => null,
                'occurred_at'   => current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%s', '%s']
        );
    }

    public static function recordUnsubscribe(int $newsletterId, string $email): void {
        global $wpdb;
        // 같은 뉴스레터에 이미 수신거부 이벤트가 있으면 중복 기록 방지
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}crmbiz_nl_events
             WHERE newsletter_id = %d AND email = %s AND type = 'unsubscribe' LIMIT 1",
            $newsletterId,
            $email
        ));
        if ($exists) {
            return;
        }
        $wpdb->insert(
            $wpdb->prefix . 'crmbiz_nl_events',
            [
                'newsletter_id' => $newsletterId,
                'email'         => $email,
                'type'          => 'unsubscribe',
                'url'           => null,
                'occurred_at'   => current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%s', '%s']
        );
    }

    public static function buildPixelUrl(int $newsletterId, string $email): string {
        $token = hash_hmac('sha256', "open:{$newsletterId}|{$email}", Database::getSecret());
        return add_query_arg([
            'crmbiz_nl_action' => 'open',
            'nl'               => $newsletterId,
            'e'                => self::encryptEmail($email),
            't'                => $token,
        ], home_url('/'));
    }

    public static function buildClickUrl(int $newsletterId, string $email, string $targetUrl): string {
        $token = hash_hmac('sha256', "click:{$newsletterId}|{$email}|{$targetUrl}", Database::getSecret());
        return add_query_arg([
            'crmbiz_nl_action' => 'click',
            'nl'               => $newsletterId,
            'e'                => self::encryptEmail($email),
            't'                => $token,
            'url'              => $targetUrl,
        ], home_url('/'));
    }

    // AES-256-CBC로 이메일 암호화 — 트래킹 URL에서 평문 이메일 노출 방지
    private static function encryptEmail(string $email): string {
        $key = hex2bin(Database::getSecret());
        $iv  = random_bytes(16);
        $ct  = openssl_encrypt($email, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        return rtrim(strtr(base64_encode($iv . $ct), '+/', '-_'), '=');
    }

    private static function decryptEmail(string $encoded): string {
        if ($encoded === '') {
            return '';
        }
        $b64 = strtr($encoded, '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad) {
            $b64 .= str_repeat('=', 4 - $pad);
        }
        $raw = base64_decode($b64, true);
        if ($raw === false || strlen($raw) < 17) {
            return '';
        }
        $key    = hex2bin(Database::getSecret());
        $result = openssl_decrypt(substr($raw, 16), 'AES-256-CBC', $key, OPENSSL_RAW_DATA, substr($raw, 0, 16));
        return $result !== false ? $result : '';
    }
}
