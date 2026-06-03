<?php
namespace CRMBizNewsletter\Admin;

defined('ABSPATH') || exit;

class DashboardPage {

    public function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('권한이 없습니다.', 'crmbiz-newsletter'));
        }
        ?>
        <div class="wrap">
        <div id="crmbiz-dashboard-app"></div>
        </div>
        <?php
    }
}
