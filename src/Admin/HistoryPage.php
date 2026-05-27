<?php
namespace CRMBizNewsletter\Admin;

defined('ABSPATH') || exit;

class HistoryPage {

    public function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die('권한이 없습니다.');
        }

        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT n.*, p.post_title,
                (SELECT COUNT(DISTINCT email) FROM {$wpdb->prefix}crmbiz_nl_events WHERE newsletter_id = n.id AND type = 'open') AS open_count,
                (SELECT COUNT(DISTINCT email) FROM {$wpdb->prefix}crmbiz_nl_events WHERE newsletter_id = n.id AND type = 'click') AS click_count
             FROM {$wpdb->prefix}crmbiz_newsletters n
             LEFT JOIN {$wpdb->posts} p ON p.ID = n.post_id
             ORDER BY n.created_at DESC
             LIMIT 100"
        );
        ?>
        <div class="wrap">
            <h1>CRMBiz Newsletter — 발송 이력</h1>

            <?php if (empty($rows)): ?>
                <p style="color:#666;margin-top:20px">발송 이력이 없습니다. 포스트를 발행하면 여기에 기록됩니다.</p>
            <?php else: ?>

            <table class="widefat fixed striped" style="margin-top:16px">
                <thead>
                    <tr>
                        <th style="width:30%">포스트</th>
                        <th style="width:100px">상태</th>
                        <th style="width:80px">수신자</th>
                        <th style="width:80px">성공</th>
                        <th style="width:80px">실패</th>
                        <th style="width:70px">오픈</th>
                        <th style="width:70px">클릭</th>
                        <th style="width:80px">발송 방식</th>
                        <th>발송 일시</th>
                        <th style="width:200px">액션</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                    <tr>
                        <td>
                            <a href="<?php echo esc_url(get_permalink((int) $row->post_id)); ?>" target="_blank">
                                <?php echo esc_html($row->post_title ?: '(삭제된 포스트)'); ?>
                            </a>
                        </td>
                        <td><?php echo $this->statusBadge($row->status); ?></td>
                        <td><?php echo esc_html(number_format((int) $row->recipient_count)); ?></td>
                        <td style="color:#0f5132"><?php echo esc_html(number_format((int) $row->success_count)); ?></td>
                        <td style="color:<?php echo (int) $row->fail_count > 0 ? '#842029' : '#666'; ?>">
                            <?php echo esc_html(number_format((int) $row->fail_count)); ?>
                        </td>
                        <td style="color:#0f5132">
                            <?php
                            $opens   = (int) $row->open_count;
                            $success = (int) $row->success_count;
                            $rate    = $success > 0 ? round($opens / $success * 100) : 0;
                            echo esc_html($opens);
                            if ($success > 0) echo '<br><span style="font-size:11px;color:#888">' . esc_html($rate) . '%</span>';
                            ?>
                        </td>
                        <td style="color:#1d4ed8"><?php echo esc_html((int) $row->click_count); ?></td>
                        <td><?php echo esc_html($this->sendModeLabel($row->send_mode)); ?></td>
                        <td style="font-size:12px;color:#555">
                            <?php echo esc_html($row->sent_at ?? $row->created_at); ?>
                        </td>
                        <td>
                            <!-- 미리보기 -->
                            <a href="<?php echo esc_url(add_query_arg([
                                'action'  => 'crmbiz_nl_preview_email',
                                'post_id' => $row->post_id,
                                'nonce'   => wp_create_nonce('crmbiz_nl_preview_' . $row->post_id),
                            ], admin_url('admin-ajax.php'))); ?>"
                               target="_blank"
                               class="button button-small">미리보기</a>

                            <!-- 수동 발송 버튼 (draft 상태만) -->
                            <?php if ($row->status === 'draft'): ?>
                            <button type="button"
                                    class="button button-small button-primary crmbiz-manual-send"
                                    data-id="<?php echo esc_attr($row->id); ?>"
                                    style="margin-left:4px">
                                발송
                            </button>
                            <?php endif; ?>

                            <!-- 재발송 버튼 (sent/failed 상태) -->
                            <?php if (in_array($row->status, ['sent', 'failed'], true)): ?>
                            <button type="button"
                                    class="button button-small crmbiz-resend"
                                    data-id="<?php echo esc_attr($row->id); ?>"
                                    style="margin-left:4px">
                                재발송
                            </button>
                            <?php endif; ?>

                            <!-- 발송 로그 (AJAX 로드) -->
                            <button type="button"
                                    class="button button-small crmbiz-show-log"
                                    data-id="<?php echo esc_attr($row->id); ?>"
                                    style="margin-left:4px">
                                로그
                            </button>
                            <div id="crmbiz-log-<?php echo esc_attr($row->id); ?>"
                                 style="display:none;margin-top:8px;font-size:12px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:4px;padding:8px;max-height:200px;overflow-y:auto">
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php endif; ?>
        </div>

        <script>
        (function($) {
            var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            var nonce   = <?php echo wp_json_encode(wp_create_nonce('crmbiz_nl_manual_send')); ?>;

            var logNonce = <?php echo wp_json_encode(wp_create_nonce('crmbiz_nl_get_log')); ?>;

            $(document).on('click', '.crmbiz-show-log', function() {
                var $btn = $(this);
                var id   = $btn.data('id');
                var $log = $('#crmbiz-log-' + id);

                if ($log.is(':visible')) {
                    $log.hide();
                    $btn.text('로그');
                    return;
                }

                // 이미 로드된 경우 그냥 표시
                if ($log.data('loaded')) {
                    $log.show();
                    $btn.text('닫기');
                    return;
                }

                $btn.prop('disabled', true).text('로딩...');
                $.post(ajaxUrl, {
                    action:        'crmbiz_nl_get_log',
                    nonce:         logNonce,
                    newsletter_id: id
                }, function(res) {
                    $btn.prop('disabled', false);
                    if (res.success) {
                        $log.html(res.data.html).data('loaded', true).show();
                        $btn.text('닫기');
                    } else {
                        $log.html('<p style="margin:0;color:#842029">로그 로드 실패</p>').show();
                        $btn.text('닫기');
                    }
                }).fail(function() {
                    $btn.prop('disabled', false).text('로그');
                });
            });

            $(document).on('click', '.crmbiz-resend', function() {
                var $btn = $(this);
                var id   = $btn.data('id');

                if (!confirm('같은 수신자에게 다시 발송합니다. 계속하시겠습니까?')) return;

                $btn.prop('disabled', true).text('발송 중...');

                $.post(ajaxUrl, {
                    action:        'crmbiz_nl_resend',
                    nonce:         nonce,
                    newsletter_id: id
                }, function(res) {
                    if (res.success) {
                        alert(res.data.message || '재발송 완료');
                        location.reload();
                    } else {
                        alert('오류: ' + (res.data && res.data.message ? res.data.message : '재발송 실패'));
                        $btn.prop('disabled', false).text('재발송');
                    }
                }).fail(function() {
                    alert('서버 오류가 발생했습니다.');
                    $btn.prop('disabled', false).text('재발송');
                });
            });

            $(document).on('click', '.crmbiz-manual-send', function() {
                var $btn = $(this);
                var id   = $btn.data('id');

                if (!confirm('이 뉴스레터를 지금 발송하시겠습니까?')) return;

                $btn.prop('disabled', true).text('발송 중...');

                $.post(ajaxUrl, {
                    action:         'crmbiz_nl_manual_send',
                    nonce:          nonce,
                    newsletter_id:  id
                }, function(res) {
                    if (res.success) {
                        alert(res.data.message || '발송 완료');
                        location.reload();
                    } else {
                        alert('오류: ' + (res.data && res.data.message ? res.data.message : '발송 실패'));
                        $btn.prop('disabled', false).text('발송');
                    }
                }).fail(function() {
                    alert('서버 오류가 발생했습니다.');
                    $btn.prop('disabled', false).text('발송');
                });
            });
        })(jQuery);
        </script>
        <?php
    }

    public function renderLogPublic(int $newsletterId): string {
        return $this->renderLog($newsletterId);
    }

    private function renderLog(int $newsletterId): string {
        global $wpdb;
        $events = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT email, type, url, occurred_at
                 FROM {$wpdb->prefix}crmbiz_nl_events
                 WHERE newsletter_id = %d
                 ORDER BY occurred_at ASC",
                $newsletterId
            )
        );

        if (empty($events)) {
            return '<p style="margin:0;color:#888">이벤트 없음</p>';
        }

        $typeLabel = ['send' => '✉ 발송', 'fail' => '✗ 실패', 'open' => '👁 오픈', 'click' => '🔗 클릭'];
        $typeColor = ['send' => '#374151', 'fail' => '#842029', 'open' => '#0f5132', 'click' => '#1d4ed8'];

        $html = '<table style="width:100%;border-collapse:collapse">';
        foreach ($events as $e) {
            $label = $typeLabel[$e->type] ?? $e->type;
            $color = $typeColor[$e->type] ?? '#374151';
            $url   = $e->url ? ' — <a href="' . esc_url($e->url) . '" target="_blank" style="color:#6b7280">' . esc_html(substr($e->url, 0, 50)) . '…</a>' : '';
            $html .= sprintf(
                '<tr><td style="padding:2px 8px 2px 0;color:%s;white-space:nowrap">%s</td><td style="padding:2px 8px 2px 0;color:#374151">%s</td><td style="padding:2px 0;color:#9ca3af;font-size:11px">%s%s</td></tr>',
                esc_attr($color),
                esc_html($label),
                esc_html($e->email),
                esc_html($e->occurred_at),
                $url
            );
        }
        $html .= '</table>';
        return $html;
    }

    private function statusBadge(string $status): string {
        $map = [
            'draft'    => ['#6b7280', '#f3f4f6', '대기'],
            'sending'  => ['#1d4ed8', '#dbeafe', '발송 중'],
            'sent'     => ['#0f5132', '#d1e7dd', '완료'],
            'failed'   => ['#842029', '#f8d7da', '실패'],
            'scheduled'=> ['#7c3aed', '#ede9fe', '예약'],
        ];
        [$color, $bg, $label] = $map[$status] ?? ['#6b7280', '#f3f4f6', $status];
        return sprintf(
            '<span style="color:%s;background:%s;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:600">%s</span>',
            esc_attr($color), esc_attr($bg), esc_html($label)
        );
    }

    private function sendModeLabel(string $mode): string {
        return ['immediate' => '즉시', 'manual' => '수동', 'scheduled' => '예약'][$mode] ?? $mode;
    }
}
