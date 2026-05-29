<?php
namespace CRMBizNewsletter\Admin;

use CRMBizNewsletter\FluentCRMBridge;
use CRMBizNewsletter\Settings;

defined('ABSPATH') || exit;

class MetaBox {

    private Settings $settings;

    public function __construct(Settings $settings) {
        $this->settings = $settings;
    }

    public function register(): void {
        add_meta_box(
            'crmbiz_nl_metabox',
            '뉴스레터 발송 설정',
            [$this, 'render'],
            'post',
            'side',
            'high'
        );
    }

    public function render(\WP_Post $post): void {
        try {
            $this->renderInner($post);
        } catch (\Throwable $e) {
            echo '<p style="color:#b91c1c;font-size:12px">뉴스레터 메타박스 오류: ' . esc_html($e->getMessage()) . '</p>';
        }
    }

    private function renderInner(\WP_Post $post): void {
        wp_nonce_field('crmbiz_nl_metabox', 'crmbiz_nl_nonce');

        $enabled  = (bool) get_post_meta($post->ID, '_crmbiz_nl_enabled',      true);
        $tagIds   = (array) get_post_meta($post->ID, '_crmbiz_nl_tag_ids',     true);
        $listIds  = (array) get_post_meta($post->ID, '_crmbiz_nl_list_ids',    true);
        $sendMode = (string) get_post_meta($post->ID, '_crmbiz_nl_send_mode',  true) ?: 'immediate';
        $schedAt  = (string) get_post_meta($post->ID, '_crmbiz_nl_scheduled_at', true);

        $fcAvailable = FluentCRMBridge::isAvailable();
        $tags        = $fcAvailable ? FluentCRMBridge::getTagsForSelect()  : [];
        $lists       = $fcAvailable ? FluentCRMBridge::getListsForSelect() : [];

        $isDryRun = $this->settings->isDryRun();

        global $wpdb;
        $nlRecord = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}crmbiz_newsletters WHERE post_id = %d ORDER BY created_at DESC LIMIT 1",
            $post->ID
        ));
        ?>
        <div id="crmbiz-nl-metabox">

            <?php if (!$fcAvailable): ?>
                <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:4px;padding:8px 10px;margin-bottom:12px;font-size:12px;color:#856404">
                    FluentCRM이 활성화되지 않았습니다.
                </div>
            <?php endif; ?>

            <?php if ($isDryRun): ?>
                <div style="background:#cff4fc;border:1px solid #0dcaf0;border-radius:4px;padding:6px 10px;margin-bottom:12px;font-size:12px;color:#055160">
                    테스트 모드 — 실제 발송 안 됨
                </div>
            <?php endif; ?>

            <?php if ($nlRecord): ?>
                <?php echo $this->renderStatusCard($nlRecord); ?>
            <?php endif; ?>

            <label style="display:flex;align-items:center;gap:6px;font-weight:600;margin-bottom:14px;cursor:pointer">
                <input type="checkbox"
                       id="crmbiz_nl_enabled"
                       name="crmbiz_nl_enabled"
                       value="1"
                       <?php checked($enabled); ?>>
                이 포스트를 뉴스레터로 발송
            </label>

            <div id="crmbiz-nl-options" style="<?php echo $enabled ? '' : 'display:none'; ?>">

                <?php if (!empty($tags)): ?>
                <div style="margin-bottom:12px">
                    <label style="display:block;font-size:12px;font-weight:600;margin-bottom:6px;color:#555">수신 태그</label>
                    <div style="max-height:120px;overflow-y:auto;border:1px solid #ddd;border-radius:4px;padding:6px 8px;background:#fafafa">
                        <?php foreach ($tags as $tag): ?>
                        <label style="display:flex;align-items:center;gap:6px;font-size:12px;padding:2px 0;cursor:pointer">
                            <input type="checkbox"
                                   name="crmbiz_nl_tag_ids[]"
                                   value="<?php echo esc_attr($tag['id']); ?>"
                                   class="crmbiz-recipient-check"
                                   <?php checked(in_array((string) $tag['id'], array_map('strval', $tagIds), true)); ?>>
                            <?php echo esc_html($tag['label']); ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($lists)): ?>
                <div style="margin-bottom:12px">
                    <label style="display:block;font-size:12px;font-weight:600;margin-bottom:6px;color:#555">수신 리스트</label>
                    <div style="max-height:120px;overflow-y:auto;border:1px solid #ddd;border-radius:4px;padding:6px 8px;background:#fafafa">
                        <?php foreach ($lists as $list): ?>
                        <label style="display:flex;align-items:center;gap:6px;font-size:12px;padding:2px 0;cursor:pointer">
                            <input type="checkbox"
                                   name="crmbiz_nl_list_ids[]"
                                   value="<?php echo esc_attr($list['id']); ?>"
                                   class="crmbiz-recipient-check"
                                   <?php checked(in_array((string) $list['id'], array_map('strval', $listIds), true)); ?>>
                            <?php echo esc_html($list['label']); ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (empty($tags) && empty($lists) && $fcAvailable): ?>
                    <p style="font-size:12px;color:#888">FluentCRM에 태그/리스트가 없습니다.</p>
                <?php endif; ?>

                <!-- 예상 수신자 수 -->
                <div id="crmbiz-recipient-count"
                     style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:4px;padding:6px 10px;margin-bottom:12px;font-size:12px;color:#0c4a6e;display:none">
                    예상 수신자: <strong id="crmbiz-count-value">0</strong>명
                    <span style="color:#94a3b8">(중복·수신거부 제외는 발송 시 처리)</span>
                </div>

                <!-- 발송 시점 -->
                <div style="margin-bottom:12px">
                    <label style="display:block;font-size:12px;font-weight:600;margin-bottom:6px;color:#555">발송 시점</label>
                    <label style="display:flex;align-items:center;gap:5px;margin-bottom:4px;font-size:12px;cursor:pointer">
                        <input type="radio" name="crmbiz_nl_send_mode" value="immediate" <?php checked($sendMode, 'immediate'); ?>>
                        즉시 발송
                    </label>
                    <label style="display:flex;align-items:center;gap:5px;margin-bottom:4px;font-size:12px;cursor:pointer">
                        <input type="radio" name="crmbiz_nl_send_mode" value="manual" <?php checked($sendMode, 'manual'); ?>>
                        수동 발송
                    </label>
                    <div id="crmbiz-manual-hint" style="margin:4px 0 4px 16px;<?php echo $sendMode === 'manual' ? '' : 'display:none'; ?>">
                        <div style="background:#fefce8;border:1px solid #fde68a;border-radius:4px;padding:7px 10px;font-size:11px;color:#854d0e;line-height:1.6">
                            발행해도 자동으로 발송되지 않습니다.<br>
                            발행 후 <a href="<?php echo esc_url(admin_url('admin.php?page=crmbiz-nl-history')); ?>"
                                      style="color:#854d0e;font-weight:600">발송 이력</a> 페이지에서
                            <strong>▶ 발송</strong> 버튼을 눌러 직접 발송하세요.
                        </div>
                    </div>
                    <label style="display:flex;align-items:center;gap:5px;font-size:12px;cursor:pointer">
                        <input type="radio" name="crmbiz_nl_send_mode" value="scheduled" <?php checked($sendMode, 'scheduled'); ?>>
                        예약 발송
                    </label>
                    <div id="crmbiz-scheduled-at" style="margin-top:6px;<?php echo $sendMode === 'scheduled' ? '' : 'display:none'; ?>">
                        <input type="datetime-local"
                               name="crmbiz_nl_scheduled_at"
                               value="<?php echo esc_attr($schedAt); ?>"
                               style="font-size:12px;width:100%">
                        <p style="margin:4px 0 0;font-size:11px;color:#888">
                            사이트 시간대: <?php echo esc_html(wp_timezone_string()); ?>
                        </p>
                    </div>
                </div>

                <!-- HTML 미리보기 -->
                <a href="<?php echo esc_url(add_query_arg(['action' => 'crmbiz_nl_preview_email', 'post_id' => $post->ID, 'nonce' => wp_create_nonce('crmbiz_nl_preview_' . $post->ID)], admin_url('admin-ajax.php'))); ?>"
                   target="_blank"
                   style="display:inline-block;font-size:12px;color:#1a56db;text-decoration:none;border:1px solid #1a56db;border-radius:4px;padding:4px 10px">
                    HTML 미리보기 ↗
                </a>

            </div><!-- /crmbiz-nl-options -->
        </div>

        <script>
        (function init() {
            var el = document.getElementById('crmbiz_nl_enabled');
            if (!el) { setTimeout(init, 50); return; }
            var ajaxUrl  = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            var nonce    = <?php echo wp_json_encode(wp_create_nonce('crmbiz_nl_metabox')); ?>;
            var timer    = null;

            // 옵션 토글
            document.getElementById('crmbiz_nl_enabled').addEventListener('change', function() {
                document.getElementById('crmbiz-nl-options').style.display = this.checked ? '' : 'none';
            });

            // 발송 모드별 힌트 토글
            document.querySelectorAll('[name="crmbiz_nl_send_mode"]').forEach(function(r) {
                r.addEventListener('change', function() {
                    document.getElementById('crmbiz-scheduled-at').style.display =
                        this.value === 'scheduled' ? '' : 'none';
                    document.getElementById('crmbiz-manual-hint').style.display =
                        this.value === 'manual' ? '' : 'none';
                });
            });

            // 수신자 수 조회
            function countRecipients() {
                var tagIds  = Array.from(document.querySelectorAll('[name="crmbiz_nl_tag_ids[]"]:checked')).map(function(o){ return o.value; });
                var listIds = Array.from(document.querySelectorAll('[name="crmbiz_nl_list_ids[]"]:checked')).map(function(o){ return o.value; });

                if (tagIds.length === 0 && listIds.length === 0) {
                    document.getElementById('crmbiz-recipient-count').style.display = 'none';
                    return;
                }

                var data = new URLSearchParams({
                    action: 'crmbiz_nl_count_recipients',
                    nonce:  nonce
                });
                tagIds.forEach(function(id)  { data.append('tag_ids[]',  id); });
                listIds.forEach(function(id) { data.append('list_ids[]', id); });

                fetch(ajaxUrl, { method: 'POST', body: data })
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        if (res.success) {
                            document.getElementById('crmbiz-count-value').textContent =
                                Number(res.data.count).toLocaleString();
                            document.getElementById('crmbiz-recipient-count').style.display = '';
                        }
                    });
            }

            document.querySelectorAll('.crmbiz-recipient-check').forEach(function(el) {
                el.addEventListener('change', function() {
                    clearTimeout(timer);
                    timer = setTimeout(countRecipients, 300);
                });
            });

            // 초기 로딩 시 기존 선택값이 있으면 카운트 조회
            <?php if (!empty($tagIds) || !empty($listIds)): ?>
            countRecipients();
            <?php endif; ?>

            // Classic editor: 발행 버튼 클릭 시 즉시 발송 confirm
            var publishBtn = document.getElementById('publish');
            if (publishBtn) {
                publishBtn.addEventListener('click', function(e) {
                    var enabled = document.getElementById('crmbiz_nl_enabled').checked;
                    var modeEl  = document.querySelector('[name="crmbiz_nl_send_mode"]:checked');
                    if (!enabled || !modeEl || modeEl.value !== 'immediate') return;
                    var count = document.getElementById('crmbiz-count-value').textContent || '?';
                    if (!confirm('발행 즉시 ' + count + '명에게 뉴스레터가 발송됩니다.\n계속하시겠습니까?')) {
                        e.preventDefault();
                        e.stopImmediatePropagation();
                    }
                }, true);
            }
        })();
        </script>
        <?php
    }

    public function savePostMeta(int $postId): void {
        if (!isset($_POST['crmbiz_nl_nonce'])) {
            return;
        }
        if (!wp_verify_nonce($_POST['crmbiz_nl_nonce'], 'crmbiz_nl_metabox')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!current_user_can('edit_post', $postId)) {
            return;
        }

        $enabled  = isset($_POST['crmbiz_nl_enabled']) ? 1 : 0;
        $tagIds   = array_filter(array_map('intval', (array) ($_POST['crmbiz_nl_tag_ids']  ?? [])));
        $listIds  = array_filter(array_map('intval', (array) ($_POST['crmbiz_nl_list_ids'] ?? [])));
        $sendMode = in_array($_POST['crmbiz_nl_send_mode'] ?? '', ['immediate', 'manual', 'scheduled'], true)
                    ? $_POST['crmbiz_nl_send_mode']
                    : 'immediate';
        $schedAt  = sanitize_text_field($_POST['crmbiz_nl_scheduled_at'] ?? '');

        update_post_meta($postId, '_crmbiz_nl_enabled',      $enabled);
        update_post_meta($postId, '_crmbiz_nl_tag_ids',      array_values($tagIds));
        update_post_meta($postId, '_crmbiz_nl_list_ids',     array_values($listIds));
        update_post_meta($postId, '_crmbiz_nl_send_mode',    $sendMode);
        update_post_meta($postId, '_crmbiz_nl_scheduled_at', $schedAt);
    }

    private function renderStatusCard(object $nl): string {
        $status = $nl->status;

        $configs = [
            'sent'      => ['bg' => '#d1e7dd', 'border' => '#0f5132', 'color' => '#0f5132', 'label' => '발송 완료'],
            'sending'   => ['bg' => '#cfe2ff', 'border' => '#084298', 'color' => '#084298', 'label' => '발송 중'],
            'queued'    => ['bg' => '#cfe2ff', 'border' => '#084298', 'color' => '#084298', 'label' => '발송 대기 중'],
            'scheduled' => ['bg' => '#fff3cd', 'border' => '#997404', 'color' => '#664d03', 'label' => '예약됨'],
            'draft'     => ['bg' => '#f3f4f6', 'border' => '#9ca3af', 'color' => '#374151', 'label' => '수동 발송 대기'],
            'failed'    => ['bg' => '#f8d7da', 'border' => '#842029', 'color' => '#842029', 'label' => '발송 실패'],
            'cancelled' => ['bg' => '#f3f4f6', 'border' => '#9ca3af', 'color' => '#374151', 'label' => '취소됨'],
        ];
        $cfg = $configs[$status] ?? $configs['draft'];

        // 날짜 문자열
        if ($status === 'scheduled' && $nl->scheduled_at) {
            $dateLabel = '예약 시각: ' . $this->formatLocalDate($nl->scheduled_at);
        } elseif ($nl->sent_at) {
            $dateLabel = '발송일: ' . $this->formatLocalDate($nl->sent_at);
        } elseif ($nl->created_at) {
            $dateLabel = '생성일: ' . $this->formatLocalDate($nl->created_at);
        } else {
            $dateLabel = '';
        }

        // 수신자 정보
        $counts = '';
        if ($status === 'sent') {
            $counts = '수신자 ' . number_format((int) $nl->success_count) . '명 발송됨';
            if ((int) $nl->fail_count > 0) {
                $counts .= ' · 실패 ' . number_format((int) $nl->fail_count) . '명';
            }
        } elseif (in_array($status, ['sending', 'queued'], true) && (int) $nl->recipient_count > 0) {
            $counts = '총 수신자 ' . number_format((int) $nl->recipient_count) . '명';
        }

        $historyUrl = admin_url('admin.php?page=crmbiz-nl-history');

        $notice = in_array($status, ['sent', 'sending', 'queued'], true)
            ? '<div style="font-size:11px;color:' . esc_attr($cfg['color']) . ';margin-top:5px;opacity:.85">포스트를 저장해도 재발송되지 않습니다.</div>'
            : '';

        ob_start();
        ?>
        <div style="background:<?php echo esc_attr($cfg['bg']); ?>;border:1px solid <?php echo esc_attr($cfg['border']); ?>;border-radius:6px;padding:10px 12px;margin-bottom:14px;font-size:12px">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px">
                <strong style="color:<?php echo esc_attr($cfg['color']); ?>;font-size:12px">
                    <?php echo esc_html($cfg['label']); ?>
                </strong>
                <a href="<?php echo esc_url($historyUrl); ?>"
                   style="font-size:11px;color:<?php echo esc_attr($cfg['color']); ?>;text-decoration:underline;opacity:.75">
                    이력 보기 ↗
                </a>
            </div>
            <?php if ($dateLabel): ?>
                <div style="color:<?php echo esc_attr($cfg['color']); ?>;opacity:.9"><?php echo esc_html($dateLabel); ?></div>
            <?php endif; ?>
            <?php if ($counts): ?>
                <div style="color:<?php echo esc_attr($cfg['color']); ?>;opacity:.9"><?php echo esc_html($counts); ?></div>
            <?php endif; ?>
            <?php
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $notice is internally built HTML with esc_attr
            echo $notice;
            ?>
        </div>
        <?php
        return ob_get_clean();
    }

    private function formatLocalDate(string $dt): string {
        try {
            $d = new \DateTime($dt, wp_timezone());
            return $d->format('Y년 m월 d일 G:i');
        } catch (\Throwable $e) {
            return $dt;
        }
    }
}
