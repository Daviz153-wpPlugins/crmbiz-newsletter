<?php
namespace CRMBizNewsletter\Admin;

defined('ABSPATH') || exit;

class HistoryPage {

    public function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die('권한이 없습니다.');
        }
        ?>
        <div class="wrap">
        <div id="crmbiz-history-app"></div>
        </div>
        <?php
    }
}
