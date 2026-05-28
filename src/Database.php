<?php
namespace CRMBizNewsletter;

defined('ABSPATH') || exit;

class Database {

    const DB_VERSION = '1.2.0';
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
  error_log TEXT,
  subscriber_emails MEDIUMTEXT NULL,
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

        update_option(self::DB_VERSION_OPTION, self::DB_VERSION);
    }

    /**
     * 고정 윈도우 레이트 리밋.
     * $window 초 안에 같은 IP + action이 $limit 회를 초과하면 false 반환.
     */
    public static function checkRateLimit(string $action, int $limit, int $window): bool {
        $ip  = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $win = (int) floor(time() / $window);
        $key = 'crmbiz_rl_' . substr(md5($action . $ip . $win), 0, 20);

        $count = (int) get_transient($key);
        if ($count >= $limit) {
            return false;
        }
        set_transient($key, $count + 1, $window + 60);
        return true;
    }

    public static function getVersion(): string {
        return get_option(self::DB_VERSION_OPTION, '');
    }

    public static function isInstalled(): bool {
        return self::getVersion() !== '';
    }
}
