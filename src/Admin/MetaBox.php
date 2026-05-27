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
        ?>
        <div id="crmbiz-nl-metabox">

            <?php if (!$fcAvailable): ?>
                <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:4px;padding:8px 10px;margin-bottom:12px;font-size:12px;color:#856404">
                    FluentCRM이 활성화되지 않았습니다.
                </div>
            <?php endif; ?>

            <?php if ($isDryRun): ?>
                <div style="background:#cff4fc;border:1px solid #0dcaf0;border-radius:4px;padding:6px 10px;margin-bottom:12px;font-size:12px;color:#055160">
                    Dry-run 모드 — 실제 발송 안 됨
                </div>
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
                    <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;color:#555">수신 태그</label>
                    <select name="crmbiz_nl_tag_ids[]"
                            id="crmbiz_nl_tag_ids"
                            multiple
                            style="width:100%;min-height:80px;font-size:12px"
                            class="crmbiz-recipient-select">
                        <?php foreach ($tags as $tag): ?>
                            <option value="<?php echo esc_attr($tag['id']); ?>"
                                    <?php echo in_array((string) $tag['id'], array_map('strval', $tagIds), true) ? 'selected' : ''; ?>>
                                <?php echo esc_html($tag['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p style="font-size:11px;color:#888;margin:2px 0 0">Ctrl+클릭으로 복수 선택</p>
                </div>
                <?php endif; ?>

                <?php if (!empty($lists)): ?>
                <div style="margin-bottom:12px">
                    <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;color:#555">수신 리스트</label>
                    <select name="crmbiz_nl_list_ids[]"
                            id="crmbiz_nl_list_ids"
                            multiple
                            style="width:100%;min-height:80px;font-size:12px"
                            class="crmbiz-recipient-select">
                        <?php foreach ($lists as $list): ?>
                            <option value="<?php echo esc_attr($list['id']); ?>"
                                    <?php echo in_array((string) $list['id'], array_map('strval', $listIds), true) ? 'selected' : ''; ?>>
                                <?php echo esc_html($list['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
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
                        수동 발송 (이력 페이지에서)
                    </label>
                    <label style="display:flex;align-items:center;gap:5px;font-size:12px;cursor:pointer">
                        <input type="radio" name="crmbiz_nl_send_mode" value="scheduled" <?php checked($sendMode, 'scheduled'); ?>>
                        예약 발송
                    </label>
                    <div id="crmbiz-scheduled-at" style="margin-top:6px;<?php echo $sendMode === 'scheduled' ? '' : 'display:none'; ?>">
                        <input type="datetime-local"
                               name="crmbiz_nl_scheduled_at"
                               value="<?php echo esc_attr($schedAt); ?>"
                               style="font-size:12px;width:100%">
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
        (function() {
            var ajaxUrl  = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            var nonce    = <?php echo wp_json_encode(wp_create_nonce('crmbiz_nl_metabox')); ?>;
            var timer    = null;

            // 옵션 토글
            document.getElementById('crmbiz_nl_enabled').addEventListener('change', function() {
                document.getElementById('crmbiz-nl-options').style.display = this.checked ? '' : 'none';
            });

            // 예약 발송 시각 필드 토글
            document.querySelectorAll('[name="crmbiz_nl_send_mode"]').forEach(function(r) {
                r.addEventListener('change', function() {
                    document.getElementById('crmbiz-scheduled-at').style.display =
                        this.value === 'scheduled' ? '' : 'none';
                });
            });

            // 수신자 수 조회
            function countRecipients() {
                var tagEl  = document.getElementById('crmbiz_nl_tag_ids');
                var listEl = document.getElementById('crmbiz_nl_list_ids');
                var tagIds  = tagEl  ? Array.from(tagEl.selectedOptions).map(function(o){ return o.value; })  : [];
                var listIds = listEl ? Array.from(listEl.selectedOptions).map(function(o){ return o.value; }) : [];

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

            document.querySelectorAll('.crmbiz-recipient-select').forEach(function(el) {
                el.addEventListener('change', function() {
                    clearTimeout(timer);
                    timer = setTimeout(countRecipients, 300);
                });
            });

            // 초기 로딩 시 기존 선택값이 있으면 카운트 조회
            <?php if (!empty($tagIds) || !empty($listIds)): ?>
            countRecipients();
            <?php endif; ?>
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
}
