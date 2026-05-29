<?php
namespace CRMBizNewsletter;

defined('ABSPATH') || exit;

class Database {

    const DB_VERSION = '1.5.0';
    const DB_VERSION_OPTION = 'crmbiz_nl_db_version';

    /**
     * 플러그인 전용 HMAC 시크릿 — wp-config.php와 독립적으로 wp_options에 저장.
     * 없으면 즉시 생성 후 저장.
     */
    public static function getSecret(): string {
        $secret = (string) get_option('crmbiz_nl_secret', '');
        if ($secret === '') {
            $secret = bin2hex(random_bytes(32)); // 256-bit
            update_option('crmbiz_nl_secret', $secret, false);
        }
        return $secret;
    }

    public static function install(): void {
        global $wpdb;

        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // 시크릿 초기 생성
        if (!get_option('crmbiz_nl_secret')) {
            update_option('crmbiz_nl_secret', bin2hex(random_bytes(32)), false);
        }

        dbDelta("CREATE TABLE {$wpdb->prefix}crmbiz_newsletters (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  post_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'draft',
  send_mode VARCHAR(20) NOT NULL DEFAULT 'immediate',
  scheduled_at DATETIME NULL,
  sent_at DATETIME NULL,
  recipient_count INT UNSIGNED NOT NULL DEFAULT 0,
  success_count INT UNSIGNED NOT NULL DEFAULT 0,
  fail_count INT UNSIGNED NOT NULL DEFAULT 0,
  tag_ids TEXT,
  list_ids TEXT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_post_id (post_id),
  KEY idx_status (status)
) $charset;");

        dbDelta("CREATE TABLE {$wpdb->prefix}crmbiz_nl_unsubscribers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email VARCHAR(191) NOT NULL,
  unsubscribed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  token_used VARCHAR(64) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_email (email)
) $charset;");

        dbDelta("CREATE TABLE {$wpdb->prefix}crmbiz_nl_queue (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  newsletter_id BIGINT UNSIGNED NOT NULL,
  email VARCHAR(191) NOT NULL,
  retry_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_nl_email (newsletter_id, email),
  KEY idx_newsletter_id (newsletter_id)
) $charset;");

        dbDelta("CREATE TABLE {$wpdb->prefix}crmbiz_nl_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  newsletter_id BIGINT UNSIGNED NOT NULL,
  email VARCHAR(191) NOT NULL,
  type VARCHAR(10) NOT NULL,
  url VARCHAR(2083) DEFAULT NULL,
  occurred_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_newsletter (newsletter_id),
  KEY idx_email (email),
  KEY idx_type (type)
) $charset;");

        // 1.3.0 마이그레이션: 미사용 error_log 컬럼 제거
        if (version_compare(self::getVersion(), '1.3.0', '<')) {
            $cols = $wpdb->get_col("SHOW COLUMNS FROM {$wpdb->prefix}crmbiz_newsletters");
            if (in_array('error_log', $cols, true)) {
                $wpdb->query("ALTER TABLE {$wpdb->prefix}crmbiz_newsletters DROP COLUMN error_log");
            }
        }

        // 1.4.0: crmbiz_nl_queue 테이블 추가 (dbDelta가 이미 처리), subscriber_emails 컬럼 잔존 (하위호환)

        // 1.5.0 마이그레이션
        if (version_compare(self::getVersion(), '1.5.0', '<')) {
            // crmbiz_nl_queue 에 retry_count 컬럼 추가
            $qCols = $wpdb->get_col("SHOW COLUMNS FROM {$wpdb->prefix}crmbiz_nl_queue");
            if (is_array($qCols) && !in_array('retry_count', $qCols, true)) {
                $wpdb->query("ALTER TABLE {$wpdb->prefix}crmbiz_nl_queue ADD COLUMN retry_count TINYINT UNSIGNED NOT NULL DEFAULT 0");
            }
            // crmbiz_newsletters 에서 미사용 subscriber_emails 컬럼 제거
            $nCols = $wpdb->get_col("SHOW COLUMNS FROM {$wpdb->prefix}crmbiz_newsletters");
            if (is_array($nCols) && in_array('subscriber_emails', $nCols, true)) {
                $wpdb->query("ALTER TABLE {$wpdb->prefix}crmbiz_newsletters DROP COLUMN subscriber_emails");
            }
        }

        update_option(self::DB_VERSION_OPTION, self::DB_VERSION);
    }

    /**
     * 이메일 주소를 AES-256-CBC로 암호화 — URL 파라미터용 URL-safe Base64 반환.
     */
    public static function encryptEmail(string $email): string {
        $key = hex2bin(self::getSecret());
        $iv  = random_bytes(16);
        $ct  = openssl_encrypt($email, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        return rtrim(strtr(base64_encode($iv . $ct), '+/', '-_'), '=');
    }

    /**
     * encryptEmail() 역연산 — 복호화 실패 시 빈 문자열 반환.
     */
    public static function decryptEmail(string $encoded): string {
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
        $key    = hex2bin(self::getSecret());
        $result = openssl_decrypt(substr($raw, 16), 'AES-256-CBC', $key, OPENSSL_RAW_DATA, substr($raw, 0, 16));
        return $result !== false ? $result : '';
    }

    /**
     * 고정 윈도우 레이트 리밋.
     * $window 초 안에 같은 IP + action이 $limit 회를 초과하면 false 반환.
     */
    public static function checkRateLimit(string $action, int $limit, int $window): bool {
        $ip  = self::getClientIp();
        $win = (int) floor(time() / $window);
        $key = 'crmbiz_rl_' . substr(md5($action . $ip . $win), 0, 20);

        $count = (int) get_transient($key);
        if ($count >= $limit) {
            return false;
        }
        set_transient($key, $count + 1, $window + 60);
        return true;
    }

    /**
     * 클라이언트 실제 IP 주소 반환.
     * 우선순위: CF-Connecting-IP → X-Forwarded-For(프록시 뒤일 때만) → REMOTE_ADDR
     */
    private static function getClientIp(): string {
        // 1. Cloudflare — Cloudflare 인프라가 주입하므로 클라이언트 위조 불가
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $ip = trim((string) $_SERVER['HTTP_CF_CONNECTING_IP']);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return (string) apply_filters('crmbiz_nl_client_ip', $ip);
            }
        }

        $remoteAddr  = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        $behindProxy = !filter_var(
            $remoteAddr, FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );

        // 2. X-Forwarded-For — REMOTE_ADDR가 사설 IP(프록시 뒤)일 때만 신뢰
        //    직접 접속이면 클라이언트가 헤더를 위조할 수 있으므로 무시
        if ($behindProxy && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            foreach (array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])) as $ip) {
                if (filter_var($ip, FILTER_VALIDATE_IP,
                               FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return (string) apply_filters('crmbiz_nl_client_ip', $ip);
                }
            }
        }

        // 3. REMOTE_ADDR 폴백
        $ip = filter_var($remoteAddr, FILTER_VALIDATE_IP) ? $remoteAddr : 'unknown';
        return (string) apply_filters('crmbiz_nl_client_ip', $ip);
    }

    public static function getVersion(): string {
        return get_option(self::DB_VERSION_OPTION, '');
    }

    public static function isInstalled(): bool {
        return self::getVersion() !== '';
    }
}
