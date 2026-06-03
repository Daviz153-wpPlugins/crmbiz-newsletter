<?php
namespace CRMBizNewsletter\Admin;

defined('ABSPATH') || exit;

/**
 * 시그니처 필드 렌더링 — SettingsPage에서 use하는 trait.
 * 시그니처 관련 메서드만 모아 SettingsPage.php 파일 크기를 줄인다.
 */
trait SettingsSignatureTrait {

    // -------------------------------------------------------------------------
    // 시그니처 필드 폼
    // -------------------------------------------------------------------------

    private function renderSignatureFields(array $sig): void { ?>
        <div class="crmbiz-settings-field crmbiz-settings-field--flush">
            <label class="crmbiz-settings-field-label" for="sig_photo_url"><?php esc_html_e('프로필 사진', 'crmbiz-newsletter'); ?></label>
            <div class="crmbiz-settings-field-body">
                <div class="crmbiz-sig-photo-row">
                    <div id="crmbiz-sig-photo-wrap" <?php echo $sig['photo_url'] ? '' : 'style="display:none"'; ?>>
                        <img id="crmbiz-sig-photo-preview" src="<?php echo esc_url($sig['photo_url']); ?>"
                             class="crmbiz-sig-preview" style="border-color:<?php echo esc_attr($sig['border_color']); ?>">
                    </div>
                    <div class="crmbiz-sig-photo-actions">
                        <input type="text" id="sig_photo_url" name="sig_photo_url"
                               value="<?php echo esc_attr($sig['photo_url']); ?>"
                               class="crmbiz-settings-input crmbiz-sig-input--narrow" placeholder="https://">
                        <button type="button" id="crmbiz-upload-sig-photo"
                                class="crmbiz-btn crmbiz-btn--secondary crmbiz-btn--sm">
                            <?php esc_html_e('사진 선택', 'crmbiz-newsletter'); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="crmbiz-settings-field crmbiz-settings-field--flush">
            <div class="crmbiz-settings-field-label crmbiz-settings-field-label--top">
                <label class="crmbiz-settings-checkbox-label">
                    <input type="checkbox" id="sig_show_name" name="sig_show_name" value="1" <?php checked($sig['show_name']); ?>>
                    <?php esc_html_e('이름 / 직함', 'crmbiz-newsletter'); ?>
                </label>
            </div>
            <div class="crmbiz-settings-field-body">
                <input type="text" id="sig_name" name="sig_name"
                       value="<?php echo esc_attr($sig['name']); ?>"
                       class="crmbiz-settings-input<?php echo $sig['show_name'] ? '' : ' crmbiz-input--inactive'; ?>"
                       placeholder="<?php esc_attr_e('예: 당신의 재무 파트너 신 팀장입니다.', 'crmbiz-newsletter'); ?>">
            </div>
        </div>

        <div class="crmbiz-settings-field crmbiz-settings-field--flush">
            <div class="crmbiz-settings-field-label crmbiz-settings-field-label--top">
                <label class="crmbiz-settings-checkbox-label">
                    <input type="checkbox" id="sig_show_bio" name="sig_show_bio" value="1" <?php checked($sig['show_bio']); ?>>
                    <?php esc_html_e('소개 문구', 'crmbiz-newsletter'); ?>
                </label>
            </div>
            <div class="crmbiz-settings-field-body">
                <textarea id="sig_bio" name="sig_bio" rows="3"
                          class="crmbiz-settings-input crmbiz-sig-textarea<?php echo $sig['show_bio'] ? '' : ' crmbiz-input--inactive'; ?>"
                          placeholder="<?php esc_attr_e('예: 재무상담 17년 차이며...', 'crmbiz-newsletter'); ?>"><?php echo esc_textarea($sig['bio']); ?></textarea>
                <p class="crmbiz-settings-hint"><?php esc_html_e('HTML 사용 가능:', 'crmbiz-newsletter'); ?> <code>&lt;strong&gt;</code>, <code>&lt;em&gt;</code>, <code>&lt;a href="..."&gt;</code></p>
            </div>
        </div>

        <div class="crmbiz-settings-field crmbiz-settings-field--flush">
            <label class="crmbiz-settings-field-label"><?php esc_html_e('사진 테두리', 'crmbiz-newsletter'); ?></label>
            <div class="crmbiz-settings-field-body">
                <?php $this->renderRgbaPicker('sig_border_color', 'sig_border_opacity', $sig['border_color'], $sig['border_opacity'], 'crmbiz-border-picker'); ?>
                <p class="crmbiz-settings-hint"><?php esc_html_e('투명도 0% = 테두리 없음', 'crmbiz-newsletter'); ?></p>
            </div>
        </div>

        <div class="crmbiz-settings-field crmbiz-settings-field--flush">
            <label class="crmbiz-settings-field-label"><?php esc_html_e('배경 색상', 'crmbiz-newsletter'); ?></label>
            <div class="crmbiz-settings-field-body">
                <?php $this->renderRgbaPicker('sig_bg_color', 'sig_bg_opacity', $sig['bg_color'], $sig['bg_opacity'], 'crmbiz-bg-picker'); ?>
                <p class="crmbiz-settings-hint"><?php esc_html_e('투명도 0% = 배경 없음', 'crmbiz-newsletter'); ?></p>
            </div>
        </div>

        <div class="crmbiz-settings-field crmbiz-settings-field--flush">
            <label class="crmbiz-settings-field-label"><?php esc_html_e('간격', 'crmbiz-newsletter'); ?></label>
            <div class="crmbiz-settings-field-body crmbiz-sig-gap-col">
                <div>
                    <p class="crmbiz-settings-hint crmbiz-sig-hint-label"><?php esc_html_e('사진 ↔ 텍스트', 'crmbiz-newsletter'); ?></p>
                    <div class="crmbiz-sig-range-row">
                        <input type="range" id="sig_photo_gap" name="sig_photo_gap" class="crmbiz-sig-range" min="0" max="80" step="2" value="<?php echo esc_attr($sig['photo_gap']); ?>">
                        <span id="crmbiz-photo-gap-val" class="crmbiz-sig-range-val"><?php echo $sig['photo_gap']; ?>px</span>
                    </div>
                </div>
                <div>
                    <p class="crmbiz-settings-hint crmbiz-sig-hint-label"><?php esc_html_e('이름 ↔ 소개', 'crmbiz-newsletter'); ?></p>
                    <div class="crmbiz-sig-range-row">
                        <input type="range" id="sig_text_gap" name="sig_text_gap" class="crmbiz-sig-range" min="0" max="40" step="2" value="<?php echo esc_attr($sig['text_gap']); ?>">
                        <span id="crmbiz-text-gap-val" class="crmbiz-sig-range-val"><?php echo $sig['text_gap']; ?>px</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="crmbiz-settings-field crmbiz-settings-field--flush">
            <label class="crmbiz-settings-field-label"><?php esc_html_e('사진 위치', 'crmbiz-newsletter'); ?></label>
            <div class="crmbiz-settings-field-body crmbiz-sig-position-row">
                <?php foreach (['left' => __('왼쪽', 'crmbiz-newsletter'), 'top' => __('위', 'crmbiz-newsletter'), 'right' => __('오른쪽', 'crmbiz-newsletter')] as $val => $lbl): ?>
                <label class="crmbiz-sig-position-label">
                    <input type="radio" name="sig_photo_position" value="<?php echo $val; ?>" <?php checked($sig['photo_position'], $val); ?>>
                    <?php echo $lbl; ?>
                </label>
                <?php endforeach; ?>
            </div>
        </div>
    <?php }

    // -------------------------------------------------------------------------
    // 시그니처 미리보기
    // -------------------------------------------------------------------------

    private function renderSignaturePreview(array $sig): void {
        $initBg          = self::hexToRgba($sig['bg_color'],     $sig['bg_opacity']);
        $initBorderColor = self::hexToRgba($sig['border_color'], $sig['border_opacity']);
        ?>
        <style>
        #crmbiz-preview-viewport { overflow:hidden; width:100%; }
        #crmbiz-sig-preview-box { border-radius:12px; padding:20px 24px; display:flex; flex-wrap:wrap; align-items:center; gap:20px; border:none !important; box-shadow:none !important; outline:none !important; }
        #crmbiz-sig-preview-box > div { border:none !important; box-shadow:none !important; outline:none !important; }
        #crmbiz-preview-img { border-radius:50% !important; }
        #crmbiz-sig-preview-box.pos-top { flex-direction:column; align-items:center; text-align:center; }
        #crmbiz-sig-preview-box.pos-right { flex-direction:row-reverse; }
        </style>

        <hr class="crmbiz-settings-hr">
        <div class="crmbiz-preview-header">
            <h3><?php esc_html_e('시그니처 미리보기', 'crmbiz-newsletter'); ?></h3>
        </div>

        <div id="crmbiz-preview-viewport" class="crmbiz-preview-viewport">
            <div id="crmbiz-sig-preview-box"
                 class="pos-<?php echo esc_attr($sig['photo_position']); ?>"
                 style="background:<?php echo esc_attr($initBg); ?>">
                <div id="crmbiz-preview-photo-wrap" style="<?php echo $sig['photo_url'] ? '' : 'display:none'; ?>;flex-shrink:0">
                    <img id="crmbiz-preview-img"
                         src="<?php echo esc_url($sig['photo_url']); ?>"
                         style="width:64px;height:64px;border-radius:50%;border:3px solid <?php echo esc_attr($initBorderColor); ?>;object-fit:cover;display:block">
                </div>
                <div id="crmbiz-preview-text">
                    <p id="crmbiz-preview-name" style="margin:0 0 6px;font-size:15px;font-weight:700;color:#111827;<?php echo $sig['show_name'] ? '' : 'display:none'; ?>"><?php echo esc_html($sig['name']); ?></p>
                    <p id="crmbiz-preview-bio" style="margin:0;font-size:14px;color:#374151;line-height:1.75;<?php echo $sig['show_bio'] ? '' : 'display:none'; ?>"><?php echo wp_kses($sig['bio'], ['strong' => [], 'em' => [], 'a' => ['href' => [], 'target' => []]]); ?></p>
                </div>
            </div>
        </div>

        <script>
        jQuery(function($){

            // ── 사진 업로드 ───────────────────────────────────────────────────
            $(document).on('click', '#crmbiz-upload-sig-photo', function(e){
                e.preventDefault();
                var frame = wp.media({ title: '프로필 사진 선택', button: { text: '선택' }, multiple: false });
                frame.on('select', function(){
                    var url = frame.state().get('selection').first().toJSON().url;
                    $('#sig_photo_url').val(url).trigger('input');
                });
                frame.open();
            });

            $(document).on('input', '#sig_photo_url', function(){
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

            // ── 시그니처 전체 활성화 토글 ────────────────────────────────────
            $(document).on('change', '#sig_enabled', function(){
                $('#crmbiz-sig-preview-box').css('opacity', $(this).is(':checked') ? 1 : 0.35);
            });

            // ── 이름 표시 ─────────────────────────────────────────────────────
            $(document).on('change', '#sig_show_name', function(){
                var show = $(this).is(':checked');
                $('#sig_name').css('opacity', show ? 1 : 0.4);
                $('#crmbiz-preview-name').toggle(show);
            });
            $(document).on('input', '#sig_name', function(){
                $('#crmbiz-preview-name').text($(this).val());
            });

            // ── 소개 문구 ─────────────────────────────────────────────────────
            $(document).on('change', '#sig_show_bio', function(){
                var show = $(this).is(':checked');
                $('#sig_bio').css('opacity', show ? 1 : 0.4);
                $('#crmbiz-preview-bio').toggle(show);
            });
            $(document).on('input', '#sig_bio', function(){
                $('#crmbiz-preview-bio').html($(this).val());
            });

            // ── 색상 피커 (테두리 / 배경) ─────────────────────────────────────
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

            // ── 사진 위치 ─────────────────────────────────────────────────────
            $(document).on('change', 'input[name="sig_photo_position"]', function(){
                $('#crmbiz-sig-preview-box').removeClass('pos-left pos-top pos-right').addClass('pos-' + $(this).val());
                updateGapPreview();
            });

            // ── 간격 슬라이더 ─────────────────────────────────────────────────
            function updateGapPreview() {
                var gap = $('#sig_photo_gap').val() + 'px';
                var pos = $('input[name="sig_photo_position"]:checked').val() || 'top';
                var $wrap = $('#crmbiz-preview-photo-wrap');
                $wrap.css({ 'padding-bottom':'', 'padding-right':'', 'padding-left':'' });
                if (pos === 'top')        $wrap.css('padding-bottom', gap);
                else if (pos === 'left')  $wrap.css('padding-right',  gap);
                else if (pos === 'right') $wrap.css('padding-left',   gap);
            }
            $(document).on('input', '#sig_photo_gap', function(){
                $('#crmbiz-photo-gap-val').text($(this).val() + 'px');
                updateGapPreview();
            });
            $(document).on('input', '#sig_text_gap', function(){
                $('#crmbiz-text-gap-val').text($(this).val() + 'px');
                $('#crmbiz-preview-name').css('margin-bottom', $(this).val() + 'px');
            });
            updateGapPreview();
        });
        </script>
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
}
