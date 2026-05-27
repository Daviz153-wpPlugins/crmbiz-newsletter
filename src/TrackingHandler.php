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
        $email        = sanitize_email($_GET['e'] ?? '');
        $token        = sanitize_text_field($_GET['t'] ?? '');

        if ($newsletterId && $email && $this->verifyToken($newsletterId, $email, $token)) {
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
        $email        = sanitize_email($_GET['e'] ?? '');
        $token        = sanitize_text_field($_GET['t'] ?? '');
        $url          = $_GET['url'] ?? '';

        if ($newsletterId && $email && $this->verifyToken($newsletterId, $email, $token) && $url) {
            $this->recordEvent($newsletterId, $email, 'click', $url);
        }

        wp_redirect($url ? esc_url_raw($url) : home_url('/'));
        exit;
    }

    private function verifyToken(int $newsletterId, string $email, string $token): bool {
        $expected = hash_hmac('sha256', $newsletterId . '|' . $email, wp_salt('auth'));
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

    public static function buildToken(int $newsletterId, string $email): string {
        return hash_hmac('sha256', $newsletterId . '|' . $email, wp_salt('auth'));
    }

    public static function buildPixelUrl(int $newsletterId, string $email): string {
        return add_query_arg([
            'crmbiz_nl_action' => 'open',
            'nl'               => $newsletterId,
            'e'                => $email,
            't'                => self::buildToken($newsletterId, $email),
        ], home_url('/'));
    }

    public static function buildClickUrl(int $newsletterId, string $email, string $targetUrl): string {
        return add_query_arg([
            'crmbiz_nl_action' => 'click',
            'nl'               => $newsletterId,
            'e'                => $email,
            't'                => self::buildToken($newsletterId, $email),
            'url'              => $targetUrl,
        ], home_url('/'));
    }
}
