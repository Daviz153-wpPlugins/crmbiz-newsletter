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

        // ── 상단 통계 카드
        $stats = $wpdb->get_row(
            "SELECT COUNT(*) AS total_nl,
                    COALESCE(SUM(success_count), 0) AS total_success,
                    COALESCE(SUM(fail_count), 0)    AS total_fail,
                    COALESCE(SUM(recipient_count), 0) AS total_recipients
             FROM {$wpdb->prefix}crmbiz_newsletters
             WHERE status = 'sent'"
        );

        $totalNl      = (int) ($stats->total_nl ?? 0);
        $totalSuccess = (int) ($stats->total_success ?? 0);
        $totalFail    = (int) ($stats->total_fail ?? 0);
        $delivered    = $totalSuccess + $totalFail;
        $successRate  = $delivered > 0 ? round($totalSuccess / $delivered * 100, 1) : 0;

        // ── 최근 30일 일별 발송량 (라인 차트)
        $dailySends = $wpdb->get_results(
            "SELECT DATE(sent_at) AS day, SUM(success_count) AS cnt
             FROM {$wpdb->prefix}crmbiz_newsletters
             WHERE status = 'sent'
               AND sent_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             GROUP BY DATE(sent_at)
             ORDER BY day ASC"
        );

        // 30일 전체 날짜 배열 채우기 (빈 날은 0)
        $dailyMap = [];
        foreach ($dailySends as $row) {
            $dailyMap[$row->day] = (int) $row->cnt;
        }
        $labels30 = [];
        $data30   = [];
        for ($i = 29; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-{$i} days"));
            $labels30[] = date('m/d', strtotime($d));
            $data30[]   = $dailyMap[$d] ?? 0;
        }

        // ── 최근 8개 캠페인 오픈율/클릭율 (바 차트)
        $campaigns = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT n.id,
                        COALESCE(p.post_title, '(삭제된 포스트)') AS title,
                        n.success_count,
                        COUNT(DISTINCT CASE WHEN e.type IN ('open','click') THEN e.email END) AS opens,
                        COUNT(DISTINCT CASE WHEN e.type = 'click'           THEN e.email END) AS clicks
                 FROM {$wpdb->prefix}crmbiz_newsletters n
                 LEFT JOIN {$wpdb->posts} p ON p.ID = n.post_id
                 LEFT JOIN {$wpdb->prefix}crmbiz_nl_events e ON e.newsletter_id = n.id
                 WHERE n.status = 'sent'
                 GROUP BY n.id, p.post_title, n.success_count
                 ORDER BY n.sent_at DESC
                 LIMIT %d",
                8
            )
        );

        $campLabels    = [];
        $campOpenRates = [];
        $campClickRates = [];
        foreach (array_reverse($campaigns) as $c) {
            $sent = (int) $c->success_count;
            $campLabels[]     = mb_strimwidth($c->title, 0, 18, '…');
            $campOpenRates[]  = $sent > 0 ? round((int)$c->opens  / $sent * 100, 1) : 0;
            $campClickRates[] = $sent > 0 ? round((int)$c->clicks / $sent * 100, 1) : 0;
        }

        // ── 시스템 상태
        $fcAvailable   = FluentCRMBridge::isAvailable();
        $smtpAvailable = FluentCRMBridge::isFluentSMTPAvailable();
        $dbInstalled   = Database::isInstalled();
        $contactCount  = $fcAvailable ? FluentCRMBridge::getContactCount() : 0;
        ?>
        <div class="wrap crmbiz-wrap-md">
            <h1 style="margin-bottom:24px">뉴스레터 대시보드
                <span class="crmbiz-version-chip">v<?php echo esc_html(CRMBIZ_NL_VERSION); ?></span>
            </h1>

            <!-- ── 통계 카드 ── -->
            <div class="crmbiz-stat-grid">
                <?php
                $this->statCard('발송 캠페인', $totalNl . '회',                     '#1d4ed8');
                $this->statCard('발송 성공',   number_format($totalSuccess) . '건', '#065f46');
                $this->statCard('발송 실패',   number_format($totalFail)    . '건', '#991b1b');
                $this->statCard('성공률',      $successRate . '%',                  '#92400e');
                ?>
            </div>

            <!-- ── 분석 차트 ── -->
            <?php if ($totalNl > 0): ?>
            <div class="crmbiz-chart-grid">

                <!-- 30일 발송 추이 -->
                <div class="crmbiz-chart-card">
                    <div class="crmbiz-chart-title">최근 30일 발송 추이</div>
                    <div class="crmbiz-chart-wrap">
                        <canvas id="crmbiz-chart-daily"></canvas>
                    </div>
                </div>

                <!-- 캠페인 오픈율/클릭율 -->
                <div class="crmbiz-chart-card">
                    <div class="crmbiz-chart-title">최근 캠페인 성과</div>
                    <div class="crmbiz-chart-wrap">
                        <canvas id="crmbiz-chart-campaigns"></canvas>
                    </div>
                </div>

            </div>
            <?php endif; ?>

            <!-- ── 시스템 상태 ── -->
            <h2>시스템 상태</h2>
            <table class="widefat fixed striped" style="max-width:700px;margin-bottom:32px">
                <thead>
                    <tr><th>항목</th><th style="width:120px">상태</th><th>정보</th></tr>
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
        </div>

        <?php if ($totalNl > 0): ?>
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"
                integrity="sha384-CjneFnXqMFkCBfGKMbFGGNuO0+7r3j5+7S0X1mHpVUzPefzPurlRsEsCoU8K0J5"
                crossorigin="anonymous"></script>
        <script>
        (function() {
            var labels30     = <?php echo wp_json_encode($labels30); ?>;
            var data30       = <?php echo wp_json_encode($data30); ?>;
            var campLabels   = <?php echo wp_json_encode($campLabels); ?>;
            var campOpen     = <?php echo wp_json_encode($campOpenRates); ?>;
            var campClick    = <?php echo wp_json_encode($campClickRates); ?>;

            Chart.defaults.font.family = '-apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
            Chart.defaults.font.size   = 12;

            // ── 30일 라인 차트
            new Chart(document.getElementById('crmbiz-chart-daily'), {
                type: 'line',
                data: {
                    labels: labels30,
                    datasets: [{
                        label: '발송 수',
                        data: data30,
                        borderColor: '#2563eb',
                        backgroundColor: 'rgba(37,99,235,.08)',
                        borderWidth: 2,
                        pointRadius: 3,
                        pointBackgroundColor: '#2563eb',
                        fill: true,
                        tension: 0.35
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { grid: { display: false }, ticks: { maxTicksLimit: 10 } },
                        y: { beginAtZero: true, ticks: { precision: 0 } }
                    }
                }
            });

            // ── 캠페인 바 차트
            new Chart(document.getElementById('crmbiz-chart-campaigns'), {
                type: 'bar',
                data: {
                    labels: campLabels,
                    datasets: [
                        {
                            label: '오픈율 %',
                            data: campOpen,
                            backgroundColor: 'rgba(16,185,129,.75)',
                            borderRadius: 4
                        },
                        {
                            label: '클릭율 %',
                            data: campClick,
                            backgroundColor: 'rgba(99,102,241,.75)',
                            borderRadius: 4
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: { boxWidth: 12, padding: 12 }
                        }
                    },
                    scales: {
                        x: { grid: { display: false } },
                        y: {
                            beginAtZero: true,
                            max: 100,
                            ticks: { callback: function(v) { return v + '%'; } }
                        }
                    }
                }
            });
        })();
        </script>
        <?php endif; ?>
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
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        printf(
            '<tr><td>%s</td><td>%s</td><td>%s</td></tr>',
            esc_html($label),
            $badge,
            esc_html($info)
        );
    }
}
