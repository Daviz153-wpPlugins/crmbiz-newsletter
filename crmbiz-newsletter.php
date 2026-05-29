<?php
/**
 * Plugin Name: CRMBiz Newsletter
 * Plugin URI:  https://github.com/Daviz153/crmbiz-newsletter
 * Description: FluentCRM 연락처를 기반으로 WordPress 포스트를 뉴스레터로 자동 발송
 * Version:     0.5.0
 * Author:      CRMBiz
 * License:     GPL-2.0-or-later
 * Text Domain: crmbiz-newsletter
 * Requires PHP: 8.0
 * Requires Plugins: fluentcrm
 */

defined('ABSPATH') || exit;

define('CRMBIZ_NL_VERSION', '0.5.0');
define('CRMBIZ_NL_FILE',    __FILE__);
define('CRMBIZ_NL_DIR',     plugin_dir_path(__FILE__));
define('CRMBIZ_NL_URL',     plugin_dir_url(__FILE__));

require_once CRMBIZ_NL_DIR . 'autoload.php';

register_activation_hook(__FILE__, ['CRMBizNewsletter\\Database', 'install']);
register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('crmbiz_nl_send_newsletter');
    wp_clear_scheduled_hook('crmbiz_nl_cleanup');
});

add_action('plugins_loaded', function () {
    CRMBizNewsletter\Plugin::getInstance();
});
