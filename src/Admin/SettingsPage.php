<?php
namespace CRMBizNewsletter\Admin;

use CRMBizNewsletter\Settings;
use CRMBizNewsletter\FluentCRMBridge;
use CRMBizNewsletter\Database;

defined('ABSPATH') || exit;

class SettingsPage {

    private Settings $settings;

    public function __construct(Settings $settings) {
        $this->settings = $settings;
    }

    public function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die('권한이 없습니다.');
        }

        $activeTab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'general';
        $saved = false;

        if (isset($_POST['crmbiz_nl_settings_nonce']) &&
            wp_verify_nonce($_POST['crmbiz_nl_settings_nonce'], 'crmbiz_nl_settings_save')) {
            $this->settings->saveFromPost($_POST);
            $saved     = true;
            $activeTab = sanitize_key($_POST['crmbiz_tab'] ?? 'general');
        }

        $tabUrl = admin_url('admin.php?page=crmbiz-nl-settings&tab=');
        ?>
        <div class="wrap">
            <h1>CRMBiz Newsletter — 설정</h1>

            <?php if ($saved): ?>
                <div class="notice notice-success is-dismissible"><p>설정이 저장되었습니다.</p></div>
            <?php endif; ?>

            <h2 class="nav-tab-wrapper">
                <a href="<?php echo esc_url($tabUrl . 'general'); ?>"
                   class="nav-tab <?php echo $activeTab === 'general' ? 'nav-tab-active' : ''; ?>">기본 설정</a>
                <a href="<?php echo esc_url($tabUrl . 'template'); ?>"
                   class="nav-tab <?php echo $activeTab === 'template' ? 'nav-tab-active' : ''; ?>">이메일 템플릿</a>
            </h2>

            <?php if ($activeTab === 'general'): ?>
                <?php $this->renderGeneralTab(); ?>
            <?php elseif ($activeTab === 'template'): ?>
                <?php $this->renderTemplateTab(); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // 기본 설정 탭
    // -------------------------------------------------------------------------

    private function renderGeneralTab(): void {
        $fromName     = esc_attr($this->settings->get('from_name', ''));
        $fromEmail    = esc_attr($this->settings->get('from_email', ''));
        $isDryRun     = $this->settings->isDryRun();
        $isDebug      = $this->settings->isDebugMode();
        $defaultEmail = esc_attr(wp_get_current_user()->user_email ?? '');
        ?>
        <form method="post">
            <?php wp_nonce_field('crmbiz_nl_settings_save', 'crmbiz_nl_settings_nonce'); ?>
            <input type="hidden" name="crmbiz_tab" value="general">

            <table class="form-table" role="presentation">
                <tr>
                    <th><label for="from_name">발신자 이름</label></th>
                    <td>
                        <input type="text" id="from_name" name="from_name"
                               value="<?php echo $fromName; ?>" class="regular-text"
                               placeholder="<?php echo esc_attr($this->settings->getFromName()); ?>">
                        <p class="description">비워두면 FluentCRM 전역 설정 또는 사이트 이름을 사용합니다.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="from_email">발신자 이메일</label></th>
                    <td>
                        <input type="email" id="from_email" name="from_email"
                               value="<?php echo $fromEmail; ?>" class="regular-text"
                               placeholder="<?php echo esc_attr($this->settings->getFromEmail()); ?>">
                        <p class="description">비워두면 FluentCRM 전역 설정 또는 관리자 이메일을 사용합니다.</p>
                    </td>
                </tr>
                <tr>
                    <th>테스트 모드</th>
                    <td>
                        <fieldset>
                            <label>
                                <input type="checkbox" name="dry_run" value="1" <?php checked($isDryRun); ?>>
                                테스트 모드 활성화 (실제 발송 안 함)
                                <span class="description">— 이메일을 실제로 발송하지 않고 로그만 기록합니다.</span>
                            </label>
                            <br>
                            <label>
                                <input type="checkbox" name="debug_mode" value="1" <?php checked($isDebug); ?>>
                                디버그 모드 활성화
                                <span class="description">— FluentCRM 시스템 로그에 상세 정보를 기록합니다.</span>
                            </label>
                        </fieldset>
                    </td>
                </tr>
                <tr>
                    <th><label for="notify_email">알림 이메일</label></th>
                    <td>
                        <input type="email" id="notify_email" name="notify_email"
                               value="<?php echo esc_attr($this->settings->get('notify_email', '')); ?>"
                               class="regular-text"
                               placeholder="<?php echo esc_attr((string) get_option('admin_email')); ?>">
                        <p class="description">뉴스레터 발송 완료/실패 시 결과를 받을 이메일. 비워두면 WordPress 관리자 이메일로 발송됩니다.</p>
                    </td>
                </tr>
            </table>

            <?php submit_button('설정 저장'); ?>
        </form>

        <hr class="crmbiz-settings-hr">

        <h2>테스트 이메일 발송</h2>
        <p>실제 이메일을 발송하여 SMTP 연결을 확인합니다.</p>
        <table class="form-table" role="presentation">
            <tr>
                <th><label for="crmbiz-test-email">수신 이메일</label></th>
                <td>
                    <input type="email" id="crmbiz-test-email" value="<?php echo $defaultEmail; ?>"
                           class="regular-text" placeholder="test@example.com">
                    <button type="button" id="crmbiz-send-test" class="button button-primary" style="margin-left:8px;flex-shrink:0">
                        테스트 발송
                    </button>
                    <p class="description">
                        발신자: <?php echo esc_html($this->settings->getFromName()); ?>
                        &lt;<?php echo esc_html($this->settings->getFromEmail()); ?>&gt;
                    </p>
                </td>
            </tr>
        </table>
        <div id="crmbiz-test-result" class="crmbiz-test-result"></div>
        <?php
    }

    // -------------------------------------------------------------------------
    // RGBA 컬러 피커 위젯
    // -------------------------------------------------------------------------

    private static function hexToRgba(string $color, int $opacity): string {
        $hex = ltrim($color, '#');
        $r   = hexdec(substr($hex, 0, 2));
        $g   = hexdec(substr($hex, 2, 2));
        $b   = hexdec(substr($hex, 4, 2));
        $a   = round($opacity / 100, 2);
        return "rgba($r,$g,$b,$a)";
    }

    private function renderRgbaPicker(string $colorName, string $opacityName, string $color, int $opacity, string $id): void {
        $rgba = self::hexToRgba($color, $opacity);
        ?>
        <div class="crmbiz-rgba-picker" id="<?php echo esc_attr($id); ?>">
            <input type="color" class="crmbiz-color-input" name="<?php echo esc_attr($colorName); ?>"
                   value="<?php echo esc_attr($color); ?>">
            <input type="range" class="crmbiz-opacity-input" name="<?php echo esc_attr($opacityName); ?>"
                   min="0" max="100" step="1" value="<?php echo esc_attr($opacity); ?>">
            <span class="crmbiz-opacity-val"><?php echo $opacity; ?>%</span>
            <div class="crmbiz-swatch-fill" style="background:<?php echo esc_attr($rgba); ?>"></div>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // 이메일 템플릿 탭
    // -------------------------------------------------------------------------

    private function renderTemplateTab(): void {
        $sig = $this->settings->getSignature();
        ?>
        <form method="post">
            <?php wp_nonce_field('crmbiz_nl_settings_save', 'crmbiz_nl_settings_nonce'); ?>
            <input type="hidden" name="crmbiz_tab" value="template">

            <h2 class="crmbiz-sig-section-title">시그니처</h2>
            <p class="description crmbiz-sig-desc">
                이메일 본문 하단, 최근 뉴스레터 목록 위에 표시됩니다.
            </p>

            <table class="form-table" role="presentation">
                <tr>
                    <th>시그니처 사용</th>
                    <td>
                        <label>
                            <input type="checkbox" id="sig_enabled" name="sig_enabled" value="1"
                                   <?php checked($sig['enabled']); ?>>
                            시그니처 표시 활성화
                        </label>
                    </td>
                </tr>
                <tr>
                    <th><label for="sig_photo_url">프로필 사진</label></th>
                    <td>
                        <div class="crmbiz-sig-photo-row">
                            <div id="crmbiz-sig-photo-wrap" <?php echo $sig['photo_url'] ? '' : 'style="display:none"'; ?>>
                                <img id="crmbiz-sig-photo-preview"
                                     src="<?php echo esc_url($sig['photo_url']); ?>"
                                     class="crmbiz-sig-preview"
                                     style="border-color:<?php echo esc_attr($sig['border_color']); ?>">
                            </div>
                            <div>
                                <input type="text" id="sig_photo_url" name="sig_photo_url"
                                       value="<?php echo esc_attr($sig['photo_url']); ?>"
                                       class="regular-text" placeholder="https://">
                                <button type="button" id="crmbiz-upload-sig-photo" class="button" style="margin-left:6px">
                                    사진 선택
                                </button>
                                <p class="description">WordPress 미디어 라이브러리에서 선택하거나 URL을 직접 입력하세요.</p>
                            </div>
                        </div>
                    </td>
                </tr>
                <tr>
                    <th>이름 / 직함</th>
                    <td>
                        <label class="crmbiz-sig-toggle">
                            <input type="checkbox" id="sig_show_name" name="sig_show_name" value="1"
                                   <?php checked($sig['show_name']); ?>>
                            표시
                        </label>
                        <input type="text" id="sig_name" name="sig_name"
                               value="<?php echo esc_attr($sig['name']); ?>"
                               class="regular-text" placeholder="예: 당신의 재무 파트너 신 팀장입니다."
                               <?php echo $sig['show_name'] ? '' : 'style="opacity:0.4"'; ?>>
                    </td>
                </tr>
                <tr>
                    <th>소개 문구</th>
                    <td>
                        <label class="crmbiz-sig-toggle">
                            <input type="checkbox" id="sig_show_bio" name="sig_show_bio" value="1"
                                   <?php checked($sig['show_bio']); ?>>
                            표시
                        </label>
                        <textarea id="sig_bio" name="sig_bio"
                                  class="large-text" rows="3"
                                  placeholder="예: 재무상담 17년 차이며, &lt;strong&gt;'현실적인 돈 만드는 방법'&lt;/strong&gt;을 제안합니다."
                                  <?php echo $sig['show_bio'] ? '' : 'style="opacity:0.4"'; ?>><?php echo esc_textarea($sig['bio']); ?></textarea>
                        <p class="description">
                            HTML 사용 가능:
                            <code>&lt;strong&gt;</code>,
                            <code>&lt;em&gt;</code>,
                            <code>&lt;span style="color:#e91e63"&gt;...&lt;/span&gt;</code>,
                            <code>&lt;a href="..."&gt;</code>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th>사진 테두리 색상</th>
                    <td>
                        <?php $this->renderRgbaPicker('sig_border_color', 'sig_border_opacity', $sig['border_color'], $sig['border_opacity'], 'crmbiz-border-picker'); ?>
                        <p class="description">투명도 0% = 테두리 없음</p>
                    </td>
                </tr>
                <tr>
                    <th>배경 색상</th>
                    <td>
                        <?php $this->renderRgbaPicker('sig_bg_color', 'sig_bg_opacity', $sig['bg_color'], $sig['bg_opacity'], 'crmbiz-bg-picker'); ?>
                        <p class="description">투명도 0% = 배경 없음</p>
                    </td>
                </tr>
                <tr>
                    <th>사진 ↔ 텍스트 간격</th>
                    <td>
                        <div class="crmbiz-sig-range-row">
                            <input type="range" id="sig_photo_gap" name="sig_photo_gap"
                                   class="crmbiz-sig-range" min="0" max="80" step="2"
                                   value="<?php echo esc_attr($sig['photo_gap']); ?>">
                            <span id="crmbiz-photo-gap-val" class="crmbiz-sig-range-val"><?php echo $sig['photo_gap']; ?>px</span>
                        </div>
                        <p class="description">사진과 이름/소개 사이 간격 (기본 16px)</p>
                    </td>
                </tr>
                <tr>
                    <th>이름 ↔ 소개 간격</th>
                    <td>
                        <div class="crmbiz-sig-range-row">
                            <input type="range" id="sig_text_gap" name="sig_text_gap"
                                   class="crmbiz-sig-range" min="0" max="40" step="2"
                                   value="<?php echo esc_attr($sig['text_gap']); ?>">
                            <span id="crmbiz-text-gap-val" class="crmbiz-sig-range-val"><?php echo $sig['text_gap']; ?>px</span>
                        </div>
                        <p class="description">이름과 소개 문구 사이 간격 (기본 8px)</p>
                    </td>
                </tr>
                <tr>
                    <th>사진 위치</th>
                    <td>
                        <fieldset>
                            <?php
                            $positions = ['left' => '왼쪽', 'top' => '위', 'right' => '오른쪽'];
                            foreach ($positions as $val => $label):
                            ?>
                            <label class="crmbiz-sig-radio">
                                <input type="radio" name="sig_photo_position" value="<?php echo $val; ?>"
                                       <?php checked($sig['photo_position'], $val); ?>>
                                <?php echo $label; ?>
                            </label>
                            <?php endforeach; ?>
                        </fieldset>
                    </td>
                </tr>
            </table>

            <?php submit_button('템플릿 설정 저장'); ?>
        </form>

        <!-- 미리보기 -->
        <style>
        #crmbiz-preview-viewport { transition: max-width 0.3s ease; overflow:hidden; }
        #crmbiz-sig-preview-box { border-radius:12px; padding:20px 24px; display:flex; flex-wrap:wrap; align-items:center; gap:20px; border:none !important; box-shadow:none !important; outline:none !important; }
        #crmbiz-sig-preview-box > div { border:none !important; box-shadow:none !important; outline:none !important; }
        #crmbiz-preview-img { border-radius:50% !important; }
        #crmbiz-sig-preview-box.pos-top { flex-direction:column; align-items:center; text-align:center; }
        #crmbiz-sig-preview-box.pos-right { flex-direction:row-reverse; }
        #crmbiz-preview-viewport.vp-tablet  #crmbiz-sig-preview-box,
        #crmbiz-preview-viewport.vp-mobile  #crmbiz-sig-preview-box { flex-direction:column !important; align-items:center !important; text-align:center !important; }
        .crmbiz-vp-btn { cursor:pointer; padding:4px 12px; border-radius:4px; font-size:13px; border:1px solid #c3c4c7; background:#fff; }
        .crmbiz-vp-btn.active { background:#2271b1; color:#fff; border-color:#2271b1; }
        </style>
        <hr style="margin:8px 0 16px">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">
            <h3 style="margin:0">미리보기</h3>
            <button type="button" class="crmbiz-vp-btn active" data-vp="laptop">💻 랩탑</button>
            <button type="button" class="crmbiz-vp-btn" data-vp="tablet">📱 태블릿</button>
            <button type="button" class="crmbiz-vp-btn" data-vp="mobile">📲 모바일</button>
        </div>
        <div id="crmbiz-preview-viewport" class="vp-laptop" style="max-width:640px;border:none !important;box-shadow:none !important;outline:none !important;background:transparent !important;padding:0 !important">
        <?php
            $initBg          = self::hexToRgba($sig['bg_color'],     $sig['bg_opacity']);
            $initBorderColor = self::hexToRgba($sig['border_color'], $sig['border_opacity']);
        ?>
        <div id="crmbiz-sig-preview-box"
             class="pos-<?php echo esc_attr($sig['photo_position']); ?>"
             style="background:<?php echo esc_attr($initBg); ?>">
            <?php // border color computed above ?>
            <div id="crmbiz-preview-photo-wrap" style="<?php echo $sig['photo_url'] ? '' : 'display:none'; ?>;flex-shrink:0">
                <img id="crmbiz-preview-img"
                     src="<?php echo esc_url($sig['photo_url']); ?>"
                     style="width:64px;height:64px;border-radius:50%;border:3px solid <?php echo esc_attr($initBorderColor); ?>;object-fit:cover;display:block">
            </div>
            <div id="crmbiz-preview-text">
                <p id="crmbiz-preview-name" style="margin:0 0 6px;font-size:15px;font-weight:700;color:#111827;<?php echo $sig['show_name'] ? '' : 'display:none'; ?>"><?php echo esc_html($sig['name']); ?></p>
                <p id="crmbiz-preview-bio" style="margin:0;font-size:14px;color:#374151;line-height:1.75;<?php echo $sig['show_bio'] ? '' : 'display:none'; ?>"><?php echo $sig['bio']; // phpcs:ignore ?></p>
            </div>
        </div>
        </div>

        <script>
        jQuery(function($){
            var vpWidths = { laptop: '640px', tablet: '480px', mobile: '320px' };

            // 뷰포트 전환
            $('.crmbiz-vp-btn').on('click', function(){
                var vp = $(this).data('vp');
                $('.crmbiz-vp-btn').removeClass('active');
                $(this).addClass('active');
                $('#crmbiz-preview-viewport')
                    .removeClass('vp-laptop vp-tablet vp-mobile')
                    .addClass('vp-' + vp)
                    .css('max-width', vpWidths[vp]);
            });

            // 미디어 업로더
            $('#crmbiz-upload-sig-photo').on('click', function(e){
                e.preventDefault();
                var frame = wp.media({ title: '프로필 사진 선택', button: { text: '선택' }, multiple: false });
                frame.on('select', function(){
                    var url = frame.state().get('selection').first().toJSON().url;
                    $('#sig_photo_url').val(url);
                    $('#crmbiz-sig-photo-preview').attr('src', url);
                    $('#crmbiz-sig-photo-wrap').show();
                    $('#crmbiz-preview-img').attr('src', url);
                    $('#crmbiz-preview-photo-wrap').show();
                });
                frame.open();
            });

            // URL 직접 입력
            $('#sig_photo_url').on('input', function(){
                var url = $(this).val();
                if (url) {
                    $('#crmbiz-sig-photo-preview').attr('src', url);
                    $('#crmbiz-sig-photo-wrap').show();
                    $('#crmbiz-preview-img').attr('src', url);
                    $('#crmbiz-preview-photo-wrap').show();
                } else {
                    $('#crmbiz-sig-photo-wrap, #crmbiz-preview-photo-wrap').hide();
                }
            });

            // 이름 표시 토글
            $('#sig_show_name').on('change', function(){
                var show = $(this).is(':checked');
                $('#sig_name').css('opacity', show ? 1 : 0.4);
                $('#crmbiz-preview-name').toggle(show);
            });

            // 이름
            $('#sig_name').on('input', function(){ $('#crmbiz-preview-name').text($(this).val()); });

            // 소개 표시 토글
            $('#sig_show_bio').on('change', function(){
                var show = $(this).is(':checked');
                $('#sig_bio').css('opacity', show ? 1 : 0.4);
                $('#crmbiz-preview-bio').toggle(show);
            });

            // 소개
            $('#sig_bio').on('input', function(){ $('#crmbiz-preview-bio').html($(this).val()); });

            // RGBA 피커 공통 로직
            function hexToRgba(hex, opacity) {
                var r = parseInt(hex.slice(1,3), 16);
                var g = parseInt(hex.slice(3,5), 16);
                var b = parseInt(hex.slice(5,7), 16);
                return 'rgba('+r+','+g+','+b+','+(opacity/100)+')';
            }

            $(document).on('input', '.crmbiz-rgba-picker .crmbiz-color-input, .crmbiz-rgba-picker .crmbiz-opacity-input', function(){
                var $picker = $(this).closest('.crmbiz-rgba-picker');
                var hex     = $picker.find('.crmbiz-color-input').val();
                var opacity = parseInt($picker.find('.crmbiz-opacity-input').val());
                var rgba    = hexToRgba(hex, opacity);
                $picker.find('.crmbiz-opacity-val').text(opacity + '%');
                $picker.find('.crmbiz-swatch-fill').css('background', rgba);
                if ($picker.attr('id') === 'crmbiz-border-picker') {
                    $('#crmbiz-sig-photo-preview, #crmbiz-preview-img').css('border-color', rgba);
                } else if ($picker.attr('id') === 'crmbiz-bg-picker') {
                    $('#crmbiz-sig-preview-box').css('background', rgba);
                }
            });

            // 사진 위치
            $('input[name="sig_photo_position"]').on('change', function(){
                $('#crmbiz-sig-preview-box').removeClass('pos-left pos-top pos-right').addClass('pos-' + $(this).val());
                updateGapPreview();
            });

            // 간격 슬라이더
            function updateGapPreview() {
                var gap = $('#sig_photo_gap').val() + 'px';
                var pos = $('input[name="sig_photo_position"]:checked').val() || 'top';
                var $wrap = $('#crmbiz-preview-photo-wrap');
                $wrap.css({ 'padding-bottom':'', 'padding-right':'', 'padding-left':'' });
                if (pos === 'top')        $wrap.css('padding-bottom', gap);
                else if (pos === 'left')  $wrap.css('padding-right',  gap);
                else if (pos === 'right') $wrap.css('padding-left',   gap);
            }
            $('#sig_photo_gap').on('input', function(){
                $('#crmbiz-photo-gap-val').text($(this).val() + 'px');
                updateGapPreview();
            });
            $('#sig_text_gap').on('input', function(){
                var gap = $(this).val() + 'px';
                $('#crmbiz-text-gap-val').text(gap);
                $('#crmbiz-preview-name').css('margin-bottom', gap);
            });
            updateGapPreview();
        });
        </script>
        <?php
    }
}
