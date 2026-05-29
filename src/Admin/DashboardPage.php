<?php
namespace CRMBizNewsletter\Admin;

use CRMBizNewsletter\FluentCRMBridge;
use CRMBizNewsletter\Database;

defined('ABSPATH') || exit;

class DashboardPage {

    public function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die('권한이 없습니다.');
        }

        global $wpdb;

        $stats = $wpdb->get_row(
            "SELECT COUNT(*) AS total_nl,
                    COALESCE(SUM(success_count), 0) AS total_success,
                    COALESCE(SUM(fail_count), 0)    AS total_fail
             FROM {$wpdb->prefix}crmbiz_newsletters
             WHERE status = 'sent'"
        );

        $totalNl      = (int) ($stats->total_nl ?? 0);
        $totalSuccess = (int) ($stats->total_success ?? 0);
        $totalFail    = (int) ($stats->total_fail ?? 0);
        $delivered    = $totalSuccess + $totalFail;
        $successRate  = $delivered > 0 ? round($totalSuccess / $delivered * 100, 1) : 0;

        $fcAvailable   = FluentCRMBridge::isAvailable();
        $smtpAvailable = FluentCRMBridge::isFluentSMTPAvailable();
        $dbInstalled   = Database::isInstalled();
        $contactCount  = $fcAvailable ? FluentCRMBridge::getContactCount() : 0;
        $tags          = $fcAvailable ? FluentCRMBridge::getTagsForSelect()  : [];
        $lists         = $fcAvailable ? FluentCRMBridge::getListsForSelect() : [];
        ?>
        <div class="wrap crmbiz-wrap-md">
            <h1 style="margin-bottom:24px">뉴스레터 대시보드
                <span class="crmbiz-version-chip">v<?php echo esc_html(CRMBIZ_NL_VERSION); ?></span>
            </h1>

            <!-- 발송 통계 -->
            <div class="crmbiz-stat-grid">
                <?php
                $this->statCard('발송 캠페인', $totalNl . '회', '#1d4ed8');
                $this->statCard('발송 성공', number_format($totalSuccess) . '건', '#065f46');
                $this->statCard('발송 실패', number_format($totalFail) . '건', '#991b1b');
                $this->statCard('성공률', $successRate . '%', '#92400e');
                ?>
            </div>

            <!-- 시스템 상태 -->
            <h2>시스템 상태</h2>
            <table class="widefat fixed striped" style="max-width:700px;margin-bottom:32px">
                <thead>
                    <tr>
                        <th>항목</th>
                        <th style="width:120px">상태</th>
                        <th>정보</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $this->statusRow('플러그인 버전', true,          'v' . CRMBIZ_NL_VERSION . ' (DB v' . Database::getVersion() . ')');
                    $this->statusRow('FluentCRM',    $fcAvailable,   $fcAvailable   ? '활성화됨' : '비활성 또는 미설치');
                    $this->statusRow('FluentSMTP',   $smtpAvailable, $smtpAvailable ? '활성화됨' : '비활성 (wp_mail 기본값 사용)');
                    $this->statusRow('DB 테이블',    $dbInstalled,   $dbInstalled   ? 'v' . Database::getVersion() : '미설치 (플러그인 재활성화 필요)');
                    $this->statusRow('연락처 수',    $fcAvailable,   $fcAvailable   ? number_format($contactCount) . '명' : '-');
                    ?>
                </tbody>
            </table>

            <!-- 태그 / 리스트 -->
            <?php if ($fcAvailable && (count($tags) > 0 || count($lists) > 0)): ?>
            <h2>태그 / 리스트</h2>
            <div style="display:flex;gap:32px;max-width:700px">
                <?php if (!empty($tags)): ?>
                <div style="flex:1">
                    <h3>태그 (<?php echo count($tags); ?>)</h3>
                    <ul style="margin:0;padding-left:16px">
                        <?php foreach ($tags as $tag): ?>
                            <li><?php echo esc_html($tag['label']); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
                <?php if (!empty($lists)): ?>
                <div style="flex:1">
                    <h3>리스트 (<?php echo count($lists); ?>)</h3>
                    <ul style="margin:0;padding-left:16px">
                        <?php foreach ($lists as $list): ?>
                            <li><?php echo esc_html($list['label']); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private function statCard(string $label, string $value, string $color): void {
        printf(
            '<div class="crmbiz-stat-card">' .
            '<div class="crmbiz-stat-value" style="color:%s">%s</div>' .
            '<div class="crmbiz-stat-label">%s</div>' .
            '</div>',
            esc_attr($color),
            esc_html($value),
            esc_html($label)
        );
    }

    private function statusRow(string $label, bool $ok, string $info): void {
        $badge = $ok
            ? '<span class="crmbiz-status-ok">정상</span>'
            : '<span class="crmbiz-status-err">확인 필요</span>';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $badge는 내부 생성 HTML
        printf(
            '<tr><td>%s</td><td>%s</td><td>%s</td></tr>',
            esc_html($label),
            $badge,
            esc_html($info)
        );
    }
}
