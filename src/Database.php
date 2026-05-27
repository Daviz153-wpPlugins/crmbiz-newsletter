<?php
namespace CRMBizNewsletter;

defined('ABSPATH') || exit;

class Database {

    const DB_VERSION = '1.1.0';
    const DB_VERSION_OPTION = 'crmbiz_nl_db_version';

    public static function install(): void {
        global $wpdb;

        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

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

    public static function getVersion(): string {
        return get_option(self::DB_VERSION_OPTION, '');
    }

    public static function isInstalled(): bool {
        return self::getVersion() !== '';
    }
}
