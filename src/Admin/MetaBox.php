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
                <div class="crmbiz-mb-notice crmbiz-mb-notice--warn">
                    FluentCRM이 활성화되지 않았습니다.
                </div>
            <?php endif; ?>

            <?php if ($isDryRun): ?>
                <div class="crmbiz-mb-notice crmbiz-mb-notice--info">
                    테스트 모드 — 실제 발송 안 됨
                </div>
            <?php endif; ?>

            <?php if ($nlRecord): ?>
                <?php echo $this->renderStatusCard($nlRecord); ?>
            <?php endif; ?>

            <label class="crmbiz-mb-enabled-label <?php echo $enabled ? 'is-enabled' : ''; ?>">
                <input type="checkbox"
                       id="crmbiz_nl_enabled"
                       name="crmbiz_nl_enabled"
                       value="1"
                       <?php checked($enabled); ?>>
                <span class="dashicons <?php echo $enabled ? 'dashicons-email-alt' : 'dashicons-email'; ?> crmbiz-mb-icon"></span>
                뉴스레터로 발송
            </label>

            <div id="crmbiz-nl-options" class="crmbiz-mb-options" <?php echo $enabled ? '' : 'hidden'; ?>>

                <?php if (!empty($tags)): ?>
                <div class="crmbiz-mb-section">
                    <label class="crmbiz-mb-label">수신 태그</label>
                    <div class="crmbiz-mb-scroll">
                        <?php foreach ($tags as $tag): ?>
                        <label class="crmbiz-mb-check-label">
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
                <div class="crmbiz-mb-section">
                    <label class="crmbiz-mb-label">수신 리스트</label>
                    <div class="crmbiz-mb-scroll">
                        <?php foreach ($lists as $list): ?>
                        <label class="crmbiz-mb-check-label">
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

                <div id="crmbiz-recipient-count" class="crmbiz-mb-count" style="display:none">
                    예상 수신자: <strong id="crmbiz-count-value">0</strong>명
                    <span class="crmbiz-mb-count-note">(중복·수신거부 제외는 발송 시 처리)</span>
                </div>

                <div class="crmbiz-mb-section">
                    <label class="crmbiz-mb-label">발송 시점</label>
                    <label class="crmbiz-mb-mode-label">
                        <input type="radio" name="crmbiz_nl_send_mode" value="immediate" <?php checked($sendMode, 'immediate'); ?>>
                        즉시 발송
                    </label>
                    <label class="crmbiz-mb-mode-label">
                        <input type="radio" name="crmbiz_nl_send_mode" value="manual" <?php checked($sendMode, 'manual'); ?>>
                        수동 발송
                    </label>
                    <div id="crmbiz-manual-hint" class="crmbiz-mb-hint" <?php echo $sendMode === 'manual' ? '' : 'style="display:none"'; ?>>
                        <div class="crmbiz-mb-hint-box">
                            발행해도 자동으로 발송되지 않습니다.<br>
                            발행 후 <a href="<?php echo esc_url(admin_url('admin.php?page=crmbiz-nl-history')); ?>">발송 이력</a> 페이지에서
                            <strong>▶ 발송</strong> 버튼을 눌러 직접 발송하세요.
                        </div>
                    </div>
                    <label class="crmbiz-mb-mode-label">
                        <input type="radio" name="crmbiz_nl_send_mode" value="scheduled" <?php checked($sendMode, 'scheduled'); ?>>
                        예약 발송
                    </label>
                    <div id="crmbiz-scheduled-at" style="margin-top:6px;<?php echo $sendMode === 'scheduled' ? '' : 'display:none'; ?>">
                        <input type="datetime-local"
                               name="crmbiz_nl_scheduled_at"
                               value="<?php echo esc_attr($schedAt); ?>"
                               class="crmbiz-mb-datetime">
                        <p class="crmbiz-mb-tz-note">
                            사이트 시간대: <?php echo esc_html(wp_timezone_string()); ?>
                        </p>
                    </div>
                </div>

                <a href="<?php echo esc_url(add_query_arg(['action' => 'crmbiz_nl_preview_email', 'post_id' => $post->ID, 'nonce' => wp_create_nonce('crmbiz_nl_preview_' . $post->ID)], admin_url('admin-ajax.php'))); ?>"
                   target="_blank"
                   class="crmbiz-mb-preview-link">
                    HTML 미리보기 ↗
                </a>

                <div class="crmbiz-mb-divider">
                    <label class="crmbiz-mb-label">테스트 발송</label>
                    <input type="text"
                           id="crmbiz-nl-test-email"
                           placeholder="test@example.com"
                           value="<?php echo esc_attr(wp_get_current_user()->user_email); ?>"
                           class="crmbiz-mb-input">
                    <button type="button"
                            id="crmbiz-nl-send-test"
                            data-post-id="<?php echo esc_attr($post->ID); ?>"
                            class="crmbiz-mb-btn">
                        테스트 발송
                    </button>
                    <div id="crmbiz-nl-test-result" class="crmbiz-mb-test-result" style="display:none"></div>
                </div>

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
                var opts  = document.getElementById('crmbiz-nl-options');
                var label = document.querySelector('.crmbiz-mb-enabled-label');
                opts.hidden = !this.checked;
                label.classList.toggle('is-enabled', this.checked);
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
            // 테스트 발송
            var testBtn = document.getElementById('crmbiz-nl-send-test');
            if (testBtn) {
                testBtn.addEventListener('click', function() {
                    var email  = document.getElementById('crmbiz-nl-test-email').value.trim();
                    var postId = this.dataset.postId;
                    var result = document.getElementById('crmbiz-nl-test-result');
                    if (!email) { return; }

                    var btn = this;
                    btn.disabled = true;
                    btn.textContent = '발송 중...';

                    fetch(ajaxUrl, {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: new URLSearchParams({
                            action:     'crmbiz_nl_test_newsletter',
                            nonce:      nonce,
                            post_id:    postId,
                            test_email: email
                        })
                    })
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        result.style.display  = '';
                        result.style.background = res.success ? '#d1e7dd' : '#f8d7da';
                        result.style.color      = res.success ? '#0f5132' : '#842029';
                        result.textContent      = res.data.message;
                        btn.disabled    = false;
                        btn.textContent = '테스트 발송';
                    });
                });
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

        // 발행된 포스트에 대기 중인 레코드가 있으면 스케줄 변경 사항 즉시 반영
        if (get_post_status($postId) === 'publish') {
            global $wpdb;
            $table  = $wpdb->prefix . 'crmbiz_newsletters';
            $record = $wpdb->get_row($wpdb->prepare(
                "SELECT id, status FROM {$table} WHERE post_id = %d AND status IN ('queued','scheduled') ORDER BY created_at DESC LIMIT 1",
                $postId
            ));
            if ($record) {
                $recipientData = [
                    'tag_ids'  => wp_json_encode(array_values($tagIds)),
                    'list_ids' => wp_json_encode(array_values($listIds)),
                ];
                if ($sendMode === 'scheduled' && $schedAt) {
                    // 로컬 시간(서울) 그대로 저장 — sent_at/created_at과 동일하게 current_time('mysql') 기준
                    $wpdb->update($table, array_merge($recipientData, ['status' => 'scheduled', 'scheduled_at' => $schedAt]), ['id' => $record->id]);
                } elseif ($sendMode !== 'scheduled' && $record->status === 'scheduled') {
                    $wpdb->update($table, array_merge($recipientData, ['status' => 'queued', 'scheduled_at' => null]), ['id' => $record->id]);
                } else {
                    $wpdb->update($table, $recipientData, ['id' => $record->id]);
                }
            }
        }
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

        $remainingLabel = '';
        if ($status === 'scheduled' && $nl->scheduled_at) {
            try {
                $schedDt = new \DateTime($nl->scheduled_at, wp_timezone());
                $nowDt   = new \DateTime('now', wp_timezone());
                $diff    = $nowDt->diff($schedDt);

                if ($schedDt > $nowDt) {
                    if ($diff->days >= 1) {
                        $remainingLabel = $diff->days . '일 후 발송 예정';
                    } elseif ($diff->h >= 1) {
                        $remainingLabel = $diff->h . '시간 후 발송 예정';
                    } else {
                        $remainingLabel = $diff->i . '분 후 발송 예정';
                    }
                } else {
                    $remainingLabel = '발송 대기 중 (처리 예정)';
                }
            } catch (\Throwable $e) {
                // 무시
            }
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
            ? '<div class="crmbiz-mb-status-note" style="color:' . esc_attr($cfg['color']) . '">포스트를 저장해도 재발송되지 않습니다.</div>'
            : '';

        ob_start();
        ?>
        <div class="crmbiz-mb-status" style="background:<?php echo esc_attr($cfg['bg']); ?>;border:1px solid <?php echo esc_attr($cfg['border']); ?>">
            <div class="crmbiz-mb-status-header">
                <strong class="crmbiz-mb-status-label" style="color:<?php echo esc_attr($cfg['color']); ?>">
                    <?php echo esc_html($cfg['label']); ?>
                </strong>
                <a href="<?php echo esc_url($historyUrl); ?>"
                   class="crmbiz-mb-status-link" style="color:<?php echo esc_attr($cfg['color']); ?>">
                    이력 보기 ↗
                </a>
            </div>
            <?php if ($dateLabel): ?>
                <div class="crmbiz-mb-status-row" style="color:<?php echo esc_attr($cfg['color']); ?>"><?php echo esc_html($dateLabel); ?></div>
            <?php endif; ?>
            <?php if ($remainingLabel): ?>
                <div class="crmbiz-mb-status-row" style="color:<?php echo esc_attr($cfg['color']); ?>;font-size:11px;opacity:.8">
                    <?php echo esc_html($remainingLabel); ?> · <?php echo esc_html(wp_timezone_string()); ?>
                </div>
            <?php endif; ?>
            <?php if ($counts): ?>
                <div class="crmbiz-mb-status-row" style="color:<?php echo esc_attr($cfg['color']); ?>"><?php echo esc_html($counts); ?></div>
            <?php endif; ?>
            <?php
            // $notice는 esc_attr()로 동적 값을 처리한 정적 HTML 문자열.
            // esc_html()로 재이스케이프하면 HTML 태그가 깨지므로 그대로 출력.
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
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
