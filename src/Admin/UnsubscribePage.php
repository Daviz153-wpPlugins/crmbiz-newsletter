<?php
namespace CRMBizNewsletter\Admin;

defined('ABSPATH') || exit;

class UnsubscribePage {

    private const PER_PAGE = 50;

    public function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die('권한이 없습니다.');
        }

        // CSV 내보내기
        if (isset($_GET['crmbiz_export']) && $_GET['crmbiz_export'] === 'unsub') {
            if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'crmbiz_export_unsub')) {
                wp_die('보안 검증 실패.');
            }
            $this->exportCsv();
        }

        global $wpdb;

        $search = sanitize_text_field($_GET['s'] ?? '');
        $paged  = max(1, (int) ($_GET['paged'] ?? 1));
        $offset = ($paged - 1) * self::PER_PAGE;

        $where  = $search ? $wpdb->prepare("WHERE email LIKE %s", '%' . $wpdb->esc_like($search) . '%') : '';
        $total  = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}crmbiz_nl_unsubscribers $where");
        $rows   = $wpdb->get_results(
            "SELECT id, email, unsubscribed_at FROM {$wpdb->prefix}crmbiz_nl_unsubscribers
             $where ORDER BY unsubscribed_at DESC
             LIMIT " . self::PER_PAGE . " OFFSET $offset"
        );

        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $exportUrl  = wp_nonce_url(
            admin_url('admin.php?page=crmbiz-nl-unsubscribers&crmbiz_export=unsub'),
            'crmbiz_export_unsub'
        );
        ?>
        <div class="wrap crmbiz-wrap crmbiz-unsub-wrap">

            <div class="crmbiz-page-header">
                <h1 class="crmbiz-page-title">
                    수신거부 관리
                    <span class="crmbiz-page-title-count">(총 <?php echo esc_html(number_format($total)); ?>명)</span>
                </h1>
                <div style="display:flex;gap:8px">
                    <button type="button" id="crmbiz-unsub-add-btn" class="crmbiz-btn crmbiz-btn--secondary">
                        + 직접 추가
                    </button>
                    <a href="<?php echo esc_url($exportUrl); ?>" class="crmbiz-btn crmbiz-btn--secondary">
                        CSV 내보내기
                    </a>
                </div>
            </div>

            <!-- 검색 -->
            <form method="get" action="">
                <input type="hidden" name="page" value="crmbiz-nl-unsubscribers">
                <div class="crmbiz-search-wrap">
                    <div class="crmbiz-search-inner">
                        <input type="text" name="s" value="<?php echo esc_attr($search); ?>"
                               placeholder="이메일로 검색..."
                               class="crmbiz-search-input" autocomplete="off">
                    </div>
                    <button type="submit" class="crmbiz-btn crmbiz-btn--primary">검색</button>
                    <?php if ($search): ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=crmbiz-nl-unsubscribers')); ?>"
                       class="crmbiz-search-clear">초기화 ✕</a>
                    <?php endif; ?>
                </div>
            </form>

            <?php if (empty($rows)): ?>
                <div class="crmbiz-empty">
                    <div class="crmbiz-empty-icon">✉</div>
                    <p><?php echo $search ? '"' . esc_html($search) . '"에 해당하는 수신거부 이메일이 없습니다.' : '수신거부된 이메일이 없습니다.'; ?></p>
                </div>
            <?php else: ?>

            <!-- 테이블 -->
            <form id="crmbiz-unsub-form">
            <div class="crmbiz-card">
                <table class="crmbiz-table" id="crmbiz-unsub-table">
                    <thead>
                        <tr>
                            <th style="width:36px">
                                <input type="checkbox" id="crmbiz-unsub-all">
                            </th>
                            <th>이메일</th>
                            <th style="width:180px">수신거부 일시</th>
                            <th class="cn-right" style="width:80px">액션</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): ?>
                        <tr data-id="<?php echo esc_attr($row->id); ?>" data-email="<?php echo esc_attr($row->email); ?>">
                            <td><input type="checkbox" class="crmbiz-unsub-check" value="<?php echo esc_attr($row->id); ?>"></td>
                            <td style="font-size:13px;color:var(--cn-text)"><?php echo esc_html($row->email); ?></td>
                            <td style="font-size:12px;color:var(--cn-muted)"><?php echo esc_html($this->localDate($row->unsubscribed_at)); ?></td>
                            <td style="text-align:right">
                                <button type="button"
                                        class="crmbiz-unsub-remove crmbiz-icon-btn crmbiz-icon-btn--red"
                                        data-id="<?php echo esc_attr($row->id); ?>"
                                        title="수신거부 해제">
                                    <span class="dashicons dashicons-undo" style="font-size:14px;line-height:28px"></span>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- 일괄 액션 + 페이지네이션 -->
            <div style="display:flex;align-items:center;justify-content:space-between;margin-top:12px;flex-wrap:wrap;gap:8px">
                <button type="button" id="crmbiz-unsub-bulk-remove"
                        class="crmbiz-btn crmbiz-btn--secondary" style="font-size:12px" disabled>
                    선택 해제
                </button>
                <?php if ($totalPages > 1): ?>
                <div style="display:flex;align-items:center;gap:8px;font-size:12px;color:var(--cn-muted)">
                    <?php if ($paged > 1): ?>
                    <a href="<?php echo esc_url(add_query_arg(['paged' => $paged - 1, 's' => $search])); ?>"
                       class="crmbiz-btn crmbiz-btn--secondary" style="padding:4px 10px;font-size:12px">◀</a>
                    <?php endif; ?>
                    <span><?php echo $paged; ?> / <?php echo $totalPages; ?></span>
                    <?php if ($paged < $totalPages): ?>
                    <a href="<?php echo esc_url(add_query_arg(['paged' => $paged + 1, 's' => $search])); ?>"
                       class="crmbiz-btn crmbiz-btn--secondary" style="padding:4px 10px;font-size:12px">▶</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            </form>

            <?php endif; ?>
        </div>

        <!-- 직접 추가 모달 -->
        <div id="crmbiz-unsub-modal" class="crmbiz-modal-overlay" style="display:none">
            <div class="crmbiz-modal">
                <p class="crmbiz-modal-message" style="font-weight:600;margin-bottom:12px">수신거부 이메일 직접 추가</p>
                <input type="email" id="crmbiz-unsub-email-input"
                       placeholder="추가할 이메일 주소"
                       style="width:100%;box-sizing:border-box;padding:8px 12px;border:1px solid var(--cn-border);border-radius:var(--cn-radius);font-size:13px;margin-bottom:16px">
                <div class="crmbiz-modal-actions">
                    <button type="button" class="crmbiz-btn crmbiz-btn--secondary" id="crmbiz-unsub-modal-cancel">취소</button>
                    <button type="button" class="crmbiz-btn crmbiz-btn--primary" id="crmbiz-unsub-modal-confirm">추가</button>
                </div>
            </div>
        </div>

        <script>
        (function($) {
            var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            var nonce   = <?php echo wp_json_encode(wp_create_nonce('crmbiz_nl_unsub_manage')); ?>;

            function toast(msg, type) {
                if (!$('#crmbiz-toast-container').length) $('<div id="crmbiz-toast-container">').appendTo('body');
                var $t = $('<div>').addClass('crmbiz-toast crmbiz-toast--' + (type || 'info')).text(msg);
                $('#crmbiz-toast-container').append($t);
                setTimeout(function() { $t.addClass('crmbiz-toast--out'); setTimeout(function() { $t.remove(); }, 300); }, 3000);
            }

            // 전체 선택
            $('#crmbiz-unsub-all').on('change', function() {
                $('.crmbiz-unsub-check').prop('checked', this.checked);
                updateBulkBtn();
            });
            $(document).on('change', '.crmbiz-unsub-check', updateBulkBtn);
            function updateBulkBtn() {
                var checked = $('.crmbiz-unsub-check:checked').length;
                $('#crmbiz-unsub-bulk-remove').prop('disabled', !checked).text('선택 해제 (' + checked + ')');
            }

            // 개별 해제
            $(document).on('click', '.crmbiz-unsub-remove', function() {
                var $btn = $(this);
                var id   = $btn.data('id');
                $btn.prop('disabled', true);
                $.post(ajaxUrl, { action: 'crmbiz_nl_unsub_remove', nonce: nonce, id: id }, function(res) {
                    if (res.success) {
                        $btn.closest('tr').fadeOut(200, function() { $(this).remove(); });
                        toast('수신거부가 해제되었습니다.', 'success');
                    } else {
                        toast('오류: ' + (res.data && res.data.message ? res.data.message : '실패'), 'error');
                        $btn.prop('disabled', false);
                    }
                }).fail(function() { toast('서버 오류', 'error'); $btn.prop('disabled', false); });
            });

            // 일괄 해제
            $('#crmbiz-unsub-bulk-remove').on('click', function() {
                var ids = $('.crmbiz-unsub-check:checked').map(function() { return $(this).val(); }).get();
                if (!ids.length) return;
                $(this).prop('disabled', true).text('처리 중...');
                $.post(ajaxUrl, { action: 'crmbiz_nl_unsub_remove', nonce: nonce, ids: ids }, function(res) {
                    if (res.success) {
                        ids.forEach(function(id) {
                            $('tr[data-id="' + id + '"]').fadeOut(200, function() { $(this).remove(); });
                        });
                        toast(ids.length + '건의 수신거부가 해제되었습니다.', 'success');
                        $('#crmbiz-unsub-bulk-remove').prop('disabled', true).text('선택 해제');
                        $('#crmbiz-unsub-all').prop('checked', false);
                    } else {
                        toast('오류: ' + (res.data && res.data.message ? res.data.message : '실패'), 'error');
                        $('#crmbiz-unsub-bulk-remove').prop('disabled', false);
                    }
                }).fail(function() { toast('서버 오류', 'error'); });
            });

            // 직접 추가 모달
            $('#crmbiz-unsub-add-btn').on('click', function() {
                $('#crmbiz-unsub-email-input').val('');
                $('#crmbiz-unsub-modal').show();
                setTimeout(function() { $('#crmbiz-unsub-email-input').focus(); }, 50);
            });
            $('#crmbiz-unsub-modal-cancel').on('click', function() {
                $('#crmbiz-unsub-modal').hide();
            });
            $('#crmbiz-unsub-modal-confirm').on('click', function() {
                var email = $('#crmbiz-unsub-email-input').val().trim();
                if (!email) return;
                $(this).prop('disabled', true);
                $.post(ajaxUrl, { action: 'crmbiz_nl_unsub_add', nonce: nonce, email: email }, function(res) {
                    $('#crmbiz-unsub-modal-confirm').prop('disabled', false);
                    if (res.success) {
                        $('#crmbiz-unsub-modal').hide();
                        toast(email + ' 수신거부 추가됨', 'success');
                        setTimeout(function() { location.reload(); }, 1200);
                    } else {
                        toast('오류: ' + (res.data && res.data.message ? res.data.message : '실패'), 'error');
                    }
                }).fail(function() {
                    toast('서버 오류', 'error');
                    $('#crmbiz-unsub-modal-confirm').prop('disabled', false);
                });
            });

            // 모달 외부 클릭 닫기
            $('#crmbiz-unsub-modal').on('click', function(e) {
                if ($(e.target).is('#crmbiz-unsub-modal')) $(this).hide();
            });

        })(jQuery);
        </script>
        <?php
    }

    private function exportCsv(): void {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT email, unsubscribed_at FROM {$wpdb->prefix}crmbiz_nl_unsubscribers ORDER BY unsubscribed_at DESC",
            ARRAY_A
        );

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="unsubscribers-' . date('Y-m-d') . '.csv"');
        header('Pragma: no-cache');

        $out = fopen('php://output', 'w');
        // phpcs:ignore WordPress.WP.AlternativeFunctions
        fputs($out, "\xEF\xBB\xBF"); // UTF-8 BOM (엑셀 한글 깨짐 방지)
        fputcsv($out, ['이메일', '수신거부 일시']);
        foreach ($rows as $row) {
            fputcsv($out, [$row['email'], $row['unsubscribed_at']]);
        }
        fclose($out);
        exit;
    }

    private function localDate(string $dt): string {
        try {
            return (new \DateTime($dt, wp_timezone()))->format('Y년 m월 d일 G:i');
        } catch (\Throwable $e) {
            return $dt;
        }
    }
}
