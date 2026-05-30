<?php
namespace CRMBizNewsletter\Admin;

defined('ABSPATH') || exit;

class DashboardPage {

    public function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die('권한이 없습니다.');
        }
        ?>
        <div id="crmbiz-dashboard-app"></div>
        <?php
    }
}
