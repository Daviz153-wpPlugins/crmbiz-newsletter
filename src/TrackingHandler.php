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
        } elseif ($action === 'web_view') {
            $this->handleWebView();
        }
    }

    private function handleOpen(): void {
        $newsletterId = (int) ($_GET['nl'] ?? 0);
        $email        = sanitize_email(Database::decryptEmail(sanitize_text_field($_GET['e'] ?? '')));
        $token        = sanitize_text_field($_GET['t'] ?? '');

        if ($newsletterId && $email && $this->verifyOpenToken($newsletterId, $email, $token)
            && Database::checkRateLimit('open', 30, 3600)) {
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
        $email        = sanitize_email(Database::decryptEmail(sanitize_text_field($_GET['e'] ?? '')));
        $token        = sanitize_text_field($_GET['t'] ?? '');
        $url          = $_GET['url'] ?? '';

        if ($newsletterId && $email && $url && $this->verifyClickToken($newsletterId, $email, $url, $token)) {
            if (Database::checkRateLimit('click', 30, 3600)) {
                $this->recordEvent($newsletterId, $email, 'click', $url);
            }
            wp_redirect(esc_url_raw($url));
        } else {
            wp_redirect(home_url('/'));
        }
        exit;
    }

    private function handleWebView(): void {
        global $wpdb;
        $newsletterId = (int) ($_GET['nl'] ?? 0);
        $email        = sanitize_email(Database::decryptEmail(sanitize_text_field($_GET['e'] ?? '')));
        $token        = sanitize_text_field($_GET['t'] ?? '');

        $expected = hash_hmac('sha256', "web_view:{$newsletterId}|{$email}", Database::getSecret());
        if ($newsletterId && $email && hash_equals($expected, $token)) {
            if (Database::checkRateLimit('click', 30, 3600)) {
                $this->recordEvent($newsletterId, $email, 'click', null);
            }
            $postId = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->prefix}crmbiz_newsletters WHERE id = %d",
                $newsletterId
            ));
            if ($postId) {
                $permalink = get_permalink($postId);
                if ($permalink) {
                    wp_redirect(esc_url_raw($permalink));
                    exit;
                }
            }
        }
        wp_redirect(home_url('/'));
        exit;
    }

    private function verifyOpenToken(int $newsletterId, string $email, string $token): bool {
        $expected = hash_hmac('sha256', "open:{$newsletterId}|{$email}", Database::getSecret());
        return hash_equals($expected, $token);
    }

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
            'e'                => Database::encryptEmail($email),
            't'                => $token,
        ], home_url('/'));
    }

    public static function buildClickUrl(int $newsletterId, string $email, string $targetUrl): string {
        $token = hash_hmac('sha256', "click:{$newsletterId}|{$email}|{$targetUrl}", Database::getSecret());
        return add_query_arg([
            'crmbiz_nl_action' => 'click',
            'nl'               => $newsletterId,
            'e'                => Database::encryptEmail($email),
            't'                => $token,
            'url'              => $targetUrl,
        ], home_url('/'));
    }

    public static function buildWebViewUrl(int $newsletterId, string $email): string {
        $token = hash_hmac('sha256', "web_view:{$newsletterId}|{$email}", Database::getSecret());
        return add_query_arg([
            'crmbiz_nl_action' => 'web_view',
            'nl'               => $newsletterId,
            'e'                => Database::encryptEmail($email),
            't'                => $token,
        ], home_url('/'));
    }

}
