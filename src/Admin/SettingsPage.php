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
        <div class="wrap crmbiz-settings-wrap">
        <div class="crmbiz-admin-page">

            <div class="crmbiz-settings-header">
                <h1 class="crmbiz-settings-title">설정</h1>
                <p class="crmbiz-settings-subtitle">CRMBiz Newsletter</p>
            </div>

            <?php if ($saved): ?>
                <div class="crmbiz-settings-notice crmbiz-settings-notice--success">
                    ✓ 설정이 저장되었습니다.
                </div>
            <?php endif; ?>

            <div class="crmbiz-settings-tabs">
                <a href="<?php echo esc_url($tabUrl . 'general'); ?>"
                   class="crmbiz-settings-tab <?php echo $activeTab === 'general'   ? 'is-active' : ''; ?>">기본 설정</a>
                <a href="<?php echo esc_url($tabUrl . 'customize'); ?>"
                   class="crmbiz-settings-tab <?php echo $activeTab === 'customize' ? 'is-active' : ''; ?>">이메일 커스터마이징</a>
            </div>

            <?php if ($activeTab === 'general'): ?>
                <?php $this->renderGeneralTab(); ?>
            <?php elseif ($activeTab === 'customize'): ?>
                <?php $this->renderCustomizeTab(); ?>
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

            <!-- 발신자 설정 -->
            <div class="crmbiz-settings-section">
                <div class="crmbiz-settings-section-head">
                    <h3>발신자 설정</h3>
                    <p>수신자에게 표시되는 이름과 이메일 주소입니다.</p>
                </div>
                <div class="crmbiz-settings-section-body">
                    <div class="crmbiz-settings-field">
                        <label class="crmbiz-settings-field-label" for="from_name">발신자 이름</label>
                        <div class="crmbiz-settings-field-body">
                            <input type="text" id="from_name" name="from_name"
                                   value="<?php echo $fromName; ?>"
                                   class="crmbiz-settings-input"
                                   placeholder="<?php echo esc_attr($this->settings->getFromName()); ?>">
                            <p class="crmbiz-settings-hint">비워두면 FluentCRM 전역 설정 또는 사이트 이름을 사용합니다.</p>
                        </div>
                    </div>
                    <div class="crmbiz-settings-field">
                        <label class="crmbiz-settings-field-label" for="from_email">발신자 이메일</label>
                        <div class="crmbiz-settings-field-body">
                            <input type="email" id="from_email" name="from_email"
                                   value="<?php echo $fromEmail; ?>"
                                   class="crmbiz-settings-input"
                                   placeholder="<?php echo esc_attr($this->settings->getFromEmail()); ?>">
                            <p class="crmbiz-settings-hint">비워두면 FluentCRM 전역 설정 또는 관리자 이메일을 사용합니다.</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 발송 모드 -->
            <div class="crmbiz-settings-section">
                <div class="crmbiz-settings-section-head">
                    <h3>발송 모드</h3>
                    <p>개발 및 테스트 환경에서 사용합니다.</p>
                </div>
                <div class="crmbiz-settings-section-body">
                    <div class="crmbiz-toggle-row">
                        <div class="crmbiz-toggle-info">
                            <h4>테스트 모드</h4>
                            <p>이메일을 실제로 발송하지 않고 로그만 기록합니다.</p>
                        </div>
                        <label class="crmbiz-toggle-switch">
                            <input type="checkbox" name="dry_run" value="1" <?php checked($isDryRun); ?>>
                            <span class="crmbiz-toggle-track"></span>
                        </label>
                    </div>
                    <div class="crmbiz-toggle-row">
                        <div class="crmbiz-toggle-info">
                            <h4>디버그 모드</h4>
                            <p>FluentCRM 시스템 로그에 상세 정보를 기록합니다.</p>
                        </div>
                        <label class="crmbiz-toggle-switch">
                            <input type="checkbox" name="debug_mode" value="1" <?php checked($isDebug); ?>>
                            <span class="crmbiz-toggle-track"></span>
                        </label>
                    </div>
                </div>
            </div>

            <!-- 알림 설정 -->
            <div class="crmbiz-settings-section">
                <div class="crmbiz-settings-section-head">
                    <h3>알림 설정</h3>
                    <p>발송 완료 또는 실패 시 결과를 받을 이메일입니다.</p>
                </div>
                <div class="crmbiz-settings-section-body">
                    <div class="crmbiz-settings-field">
                        <label class="crmbiz-settings-field-label" for="notify_email">알림 이메일</label>
                        <div class="crmbiz-settings-field-body">
                            <input type="email" id="notify_email" name="notify_email"
                                   value="<?php echo esc_attr($this->settings->get('notify_email', '')); ?>"
                                   class="crmbiz-settings-input"
                                   placeholder="<?php echo esc_attr((string) get_option('admin_email')); ?>">
                            <p class="crmbiz-settings-hint">비워두면 WordPress 관리자 이메일로 발송됩니다.</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="crmbiz-settings-footer">
                <button type="submit" class="crmbiz-btn crmbiz-btn--primary crmbiz-btn--form">
                    설정 저장
                </button>
            </div>
        </form>

        <!-- 테스트 이메일 -->
        <div class="crmbiz-settings-section crmbiz-settings-section--accent">
            <div class="crmbiz-settings-section-head">
                <h3>테스트 이메일 발송</h3>
                <p>실제 이메일을 발송하여 SMTP 연결을 확인합니다. 발신자: <strong><?php echo esc_html($this->settings->getFromName()); ?></strong> &lt;<?php echo esc_html($this->settings->getFromEmail()); ?>&gt;</p>
            </div>
            <div class="crmbiz-settings-section-body">
                <div class="crmbiz-settings-field">
                    <label class="crmbiz-settings-field-label" for="crmbiz-test-email">수신 이메일</label>
                    <div class="crmbiz-settings-field-body crmbiz-test-email-row">
                        <input type="email" id="crmbiz-test-email" value="<?php echo $defaultEmail; ?>"
                               class="crmbiz-settings-input crmbiz-test-email-input" placeholder="test@example.com">
                        <button type="button" id="crmbiz-send-test"
                                class="crmbiz-btn crmbiz-btn--primary crmbiz-btn--form crmbiz-flex-shrink-0">
                            테스트 발송
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <div id="crmbiz-test-result" class="crmbiz-test-result"></div>
        <?php
    }

    // -------------------------------------------------------------------------
    // 이메일 커스터마이징 탭 (스타일 + 시그니처 통합)
    // -------------------------------------------------------------------------

    private function renderCustomizeTab(): void {
        $s   = $this->settings->getEmailStyle();
        $sig = $this->settings->getSignature();

        $presets = [
            'modern'  => ['label' => '모던',   'outer_bg' => '#f3f4f6', 'header_bg' => '#ffffff', 'header_color' => '#111827', 'accent_color' => '#1a56db'],
            'dark'    => ['label' => '다크',   'outer_bg' => '#111827', 'header_bg' => '#1e293b', 'header_color' => '#f9fafb', 'accent_color' => '#60a5fa'],
            'minimal' => ['label' => '미니멀', 'outer_bg' => '#ffffff', 'header_bg' => '#ffffff', 'header_color' => '#111827', 'accent_color' => '#111827'],
        ];
        ?>
        <form method="post">
            <?php wp_nonce_field('crmbiz_nl_settings_save', 'crmbiz_nl_settings_nonce'); ?>
            <input type="hidden" name="crmbiz_tab" value="customize">

            <!-- 색상 & 레이아웃 -->
            <div class="crmbiz-settings-section">
                <div class="crmbiz-settings-section-head">
                    <h3>색상 &amp; 레이아웃</h3>
                    <p>프리셋을 선택하거나 색상을 직접 조정하세요.</p>
                </div>
                <div class="crmbiz-settings-section-body">

                    <!-- 프리셋 -->
                    <div class="crmbiz-preset-row">
                        <?php foreach ($presets as $key => $preset): ?>
                        <button type="button" class="crmbiz-preset-btn"
                                data-preset="<?php echo esc_attr(wp_json_encode($preset)); ?>">
                            <div style="height:28px;background:<?php echo esc_attr($preset['header_bg']); ?>;border-bottom:3px solid <?php echo esc_attr($preset['accent_color']); ?>"></div>
                            <div style="height:20px;background:<?php echo esc_attr($preset['outer_bg']); ?>"></div>
                            <div class="crmbiz-preset-label"><?php echo esc_html($preset['label']); ?></div>
                        </button>
                        <?php endforeach; ?>
                    </div>

                    <!-- 미리보기 -->
                    <div class="crmbiz-preview-block">

                        <!-- 색상 미리보기 (레이아웃 모형) -->
                        <div id="crmbiz-preset-preview"
                             style="display:none;max-width:560px;width:100%;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.1);margin-bottom:12px">
                            <div id="crmbiz-preset-header" style="padding:14px 18px;border-bottom:3px solid #1a56db;background:#fff">
                                <div id="crmbiz-preset-title" style="font-size:14px;font-weight:700;color:#111827;margin-bottom:3px">뉴스레터 제목 미리보기</div>
                                <span id="crmbiz-preset-link" style="font-size:11px;color:#1a56db;text-decoration:underline">웹에서 보기</span>
                            </div>
                            <div id="crmbiz-preset-body" style="background:#fff;padding:14px 18px">
                                <div style="height:7px;background:#e5e7eb;border-radius:4px;margin-bottom:7px"></div>
                                <div style="height:7px;background:#e5e7eb;border-radius:4px;width:80%;margin-bottom:7px"></div>
                                <div style="height:7px;background:#e5e7eb;border-radius:4px;width:60%"></div>
                            </div>
                        </div>

                        <!-- 실제 이메일 전체보기 -->
                        <a id="crmbiz-settings-full-preview"
                           href="<?php echo esc_url(add_query_arg([
                               'action' => 'crmbiz_nl_settings_preview',
                               'nonce'  => wp_create_nonce('crmbiz_nl_settings_preview'),
                           ], admin_url('admin-ajax.php'))); ?>"
                           target="_blank"
                           class="crmbiz-btn crmbiz-btn--secondary crmbiz-btn--inline">
                            <span class="dashicons dashicons-visibility"></span>
                            실제 이메일 전체보기 ↗
                        </a>
                        <p class="crmbiz-settings-hint">저장된 설정 기준으로 렌더링됩니다. 색상 변경 후에는 저장하고 확인하세요.</p>
                    </div>

                    <div class="crmbiz-settings-field">
                        <label class="crmbiz-settings-field-label" for="style_outer_bg">외부 배경</label>
                        <div class="crmbiz-settings-field-body crmbiz-color-field-body">
                            <input type="color" id="style_outer_bg" name="style_outer_bg" value="<?php echo esc_attr($s['outer_bg']); ?>" class="crmbiz-color-input">
                            <span class="crmbiz-settings-hint--inline">이메일 바깥 여백 색상</span>
                        </div>
                    </div>
                    <div class="crmbiz-settings-field">
                        <label class="crmbiz-settings-field-label" for="style_header_bg">헤더 배경</label>
                        <div class="crmbiz-settings-field-body crmbiz-color-field-body">
                            <input type="color" id="style_header_bg" name="style_header_bg" value="<?php echo esc_attr($s['header_bg']); ?>" class="crmbiz-color-input">
                            <span class="crmbiz-settings-hint--inline">제목 영역 배경</span>
                        </div>
                    </div>
                    <div class="crmbiz-settings-field">
                        <label class="crmbiz-settings-field-label" for="style_header_color">헤더 텍스트</label>
                        <div class="crmbiz-settings-field-body crmbiz-color-field-body">
                            <input type="color" id="style_header_color" name="style_header_color" value="<?php echo esc_attr($s['header_color']); ?>" class="crmbiz-color-input">
                            <span class="crmbiz-settings-hint--inline">제목/날짜 글자 색상</span>
                        </div>
                    </div>
                    <div class="crmbiz-settings-field">
                        <label class="crmbiz-settings-field-label" for="style_accent_color">강조 색상</label>
                        <div class="crmbiz-settings-field-body crmbiz-color-field-body">
                            <input type="color" id="style_accent_color" name="style_accent_color" value="<?php echo esc_attr($s['accent_color']); ?>" class="crmbiz-color-input">
                            <span class="crmbiz-settings-hint--inline">링크, 수신거부 버튼</span>
                        </div>
                    </div>
                    <div class="crmbiz-settings-field">
                        <label class="crmbiz-settings-field-label" for="style_content_width">콘텐츠 너비</label>
                        <div class="crmbiz-settings-field-body">
                            <div class="crmbiz-sig-range-row">
                                <input type="range" id="style_content_width" name="style_content_width"
                                       class="crmbiz-sig-range" min="480" max="800" step="20"
                                       value="<?php echo esc_attr($s['content_width']); ?>">
                                <span id="crmbiz-width-val" class="crmbiz-sig-range-val"><?php echo $s['content_width']; ?>px</span>
                            </div>
                            <p class="crmbiz-settings-hint">이메일 본문 최대 너비 (480–800px)</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 섹션 표시 -->
            <div class="crmbiz-settings-section">
                <div class="crmbiz-settings-section-head">
                    <h3>섹션 표시</h3>
                    <p>이메일에 포함할 요소를 선택합니다.</p>
                </div>
                <div class="crmbiz-settings-section-body">
                    <div class="crmbiz-toggle-row">
                        <div class="crmbiz-toggle-info">
                            <h4>대표 이미지</h4>
                            <p>포스트 대표 이미지를 이메일 상단에 표시합니다.</p>
                        </div>
                        <label class="crmbiz-toggle-switch">
                            <input type="checkbox" name="style_show_featured" value="1" <?php checked($s['show_featured']); ?>>
                            <span class="crmbiz-toggle-track"></span>
                        </label>
                    </div>
                    <div class="crmbiz-toggle-row">
                        <div class="crmbiz-toggle-info">
                            <h4>최근 뉴스레터</h4>
                            <p>본문 하단에 최근 3개 뉴스레터 링크를 표시합니다.</p>
                        </div>
                        <label class="crmbiz-toggle-switch">
                            <input type="checkbox" name="style_show_recent" value="1" <?php checked($s['show_recent']); ?>>
                            <span class="crmbiz-toggle-track"></span>
                        </label>
                    </div>
                    <div class="crmbiz-toggle-row">
                        <div class="crmbiz-toggle-info">
                            <h4>웹에서 보기</h4>
                            <p>헤더에 "웹에서 보기" 링크를 표시합니다.</p>
                        </div>
                        <label class="crmbiz-toggle-switch">
                            <input type="checkbox" name="style_show_web_view" value="1" <?php checked($s['show_web_view']); ?>>
                            <span class="crmbiz-toggle-track"></span>
                        </label>
                    </div>
                    <div class="crmbiz-toggle-row">
                        <div class="crmbiz-toggle-info">
                            <h4>발송 날짜</h4>
                            <p>제목 아래 발송 날짜를 표시합니다.</p>
                        </div>
                        <label class="crmbiz-toggle-switch">
                            <input type="checkbox" name="style_show_date" value="1" <?php checked($s['show_date']); ?>>
                            <span class="crmbiz-toggle-track"></span>
                        </label>
                    </div>
                </div>
            </div>

            <!-- 시그니처 -->
            <div class="crmbiz-settings-section">
                <div class="crmbiz-settings-section-head crmbiz-section-head--toggle">
                    <div>
                        <h3>시그니처</h3>
                        <p>이메일 본문 하단에 표시됩니다.</p>
                    </div>
                    <label class="crmbiz-toggle-switch">
                        <input type="checkbox" id="sig_enabled" name="sig_enabled" value="1" <?php checked($sig['enabled']); ?>>
                        <span class="crmbiz-toggle-track"></span>
                    </label>
                </div>
                <div class="crmbiz-settings-section-body crmbiz-settings-section-body--padded">
                    <?php $this->renderSignatureFields($sig); ?>
                </div>
            </div>

            <div class="crmbiz-settings-footer">
                <button type="submit" class="crmbiz-btn crmbiz-btn--primary crmbiz-btn--form">
                    커스터마이징 저장
                </button>
            </div>
        </form>

        <script>
        jQuery(function($) {
            // 프리셋 — 색상 반영 + 이메일 레이아웃 미리보기
            $('.crmbiz-preset-btn').on('click', function() {
                var p = $(this).data('preset');
                if (typeof p === 'string') p = JSON.parse(p);
                $('#style_outer_bg').val(p.outer_bg);
                $('#style_header_bg').val(p.header_bg);
                $('#style_header_color').val(p.header_color);
                $('#style_accent_color').val(p.accent_color);
                $('.crmbiz-preset-btn').css('border-color', '#e5e7eb');
                $(this).css('border-color', '#2563eb');
                // 이메일 미리보기 갱신
                $('#crmbiz-preset-preview').css({
                    background: p.outer_bg,
                    display: 'block'
                });
                $('#crmbiz-preset-header').css({
                    background: p.header_bg,
                    borderBottomColor: p.accent_color
                });
                $('#crmbiz-preset-title').css('color', p.header_color);
                $('#crmbiz-preset-link').css('color', p.accent_color);
            });
            // 너비 슬라이더
            $('#style_content_width').on('input', function() {
                $('#crmbiz-width-val').text($(this).val() + 'px');
            });

            // 색상 피커 변경 시 미리보기 실시간 갱신
            function syncPreview() {
                var outerBg  = $('#style_outer_bg').val();
                var headerBg = $('#style_header_bg').val();
                var hColor   = $('#style_header_color').val();
                var accent   = $('#style_accent_color').val();
                $('#crmbiz-preset-preview').css('background', outerBg).show();
                $('#crmbiz-preset-header').css({ background: headerBg, borderBottomColor: accent });
                $('#crmbiz-preset-title').css('color', hColor);
                $('#crmbiz-preset-link').css('color', accent);
            }
            $('#style_outer_bg, #style_header_bg, #style_header_color, #style_accent_color').on('input', syncPreview);

            // "실제 이메일 전체보기" — 클릭 시 현재 설정을 먼저 저장한 뒤 미리보기 열기
            $('#crmbiz-settings-full-preview').on('click', function(e) {
                e.preventDefault();
                var previewUrl = $(this).attr('href');
                var $btn = $(this);
                var $form = $btn.closest('form');
                $btn.text('저장 중...').css('pointer-events', 'none');
                $.post(window.location.href, $form.serialize())
                    .always(function() {
                        $btn.html('<span class="dashicons dashicons-visibility"></span> 실제 이메일 전체보기 ↗')
                            .css('pointer-events', '');
                        window.open(previewUrl, '_blank');
                    });
            });
        });
        </script>

        <?php $this->renderSignaturePreview($sig); ?>
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
    // 시그니처 헬퍼 메서드 (renderCustomizeTab에서 재사용)
    // -------------------------------------------------------------------------

    private function renderSignatureFields(array $sig): void { ?>
        <div class="crmbiz-settings-field crmbiz-settings-field--flush">
            <label class="crmbiz-settings-field-label" for="sig_photo_url">프로필 사진</label>
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
                            사진 선택
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="crmbiz-settings-field crmbiz-settings-field--flush">
            <div class="crmbiz-settings-field-label crmbiz-settings-field-label--top">
                <label class="crmbiz-settings-checkbox-label">
                    <input type="checkbox" id="sig_show_name" name="sig_show_name" value="1" <?php checked($sig['show_name']); ?>>
                    이름 / 직함
                </label>
            </div>
            <div class="crmbiz-settings-field-body">
                <input type="text" id="sig_name" name="sig_name"
                       value="<?php echo esc_attr($sig['name']); ?>"
                       class="crmbiz-settings-input<?php echo $sig['show_name'] ? '' : ' crmbiz-input--inactive'; ?>"
                       placeholder="예: 당신의 재무 파트너 신 팀장입니다.">
            </div>
        </div>

        <div class="crmbiz-settings-field crmbiz-settings-field--flush">
            <div class="crmbiz-settings-field-label crmbiz-settings-field-label--top">
                <label class="crmbiz-settings-checkbox-label">
                    <input type="checkbox" id="sig_show_bio" name="sig_show_bio" value="1" <?php checked($sig['show_bio']); ?>>
                    소개 문구
                </label>
            </div>
            <div class="crmbiz-settings-field-body">
                <textarea id="sig_bio" name="sig_bio" rows="3"
                          class="crmbiz-settings-input crmbiz-sig-textarea<?php echo $sig['show_bio'] ? '' : ' crmbiz-input--inactive'; ?>"
                          placeholder="예: 재무상담 17년 차이며..."><?php echo esc_textarea($sig['bio']); ?></textarea>
                <p class="crmbiz-settings-hint">HTML 사용 가능: <code>&lt;strong&gt;</code>, <code>&lt;em&gt;</code>, <code>&lt;a href="..."&gt;</code></p>
            </div>
        </div>

        <div class="crmbiz-settings-field crmbiz-settings-field--flush">
            <label class="crmbiz-settings-field-label">사진 테두리</label>
            <div class="crmbiz-settings-field-body">
                <?php $this->renderRgbaPicker('sig_border_color', 'sig_border_opacity', $sig['border_color'], $sig['border_opacity'], 'crmbiz-border-picker'); ?>
                <p class="crmbiz-settings-hint">투명도 0% = 테두리 없음</p>
            </div>
        </div>

        <div class="crmbiz-settings-field crmbiz-settings-field--flush">
            <label class="crmbiz-settings-field-label">배경 색상</label>
            <div class="crmbiz-settings-field-body">
                <?php $this->renderRgbaPicker('sig_bg_color', 'sig_bg_opacity', $sig['bg_color'], $sig['bg_opacity'], 'crmbiz-bg-picker'); ?>
                <p class="crmbiz-settings-hint">투명도 0% = 배경 없음</p>
            </div>
        </div>

        <div class="crmbiz-settings-field crmbiz-settings-field--flush">
            <label class="crmbiz-settings-field-label">간격</label>
            <div class="crmbiz-settings-field-body crmbiz-sig-gap-col">
                <div>
                    <p class="crmbiz-settings-hint crmbiz-sig-hint-label">사진 ↔ 텍스트</p>
                    <div class="crmbiz-sig-range-row">
                        <input type="range" id="sig_photo_gap" name="sig_photo_gap" class="crmbiz-sig-range" min="0" max="80" step="2" value="<?php echo esc_attr($sig['photo_gap']); ?>">
                        <span id="crmbiz-photo-gap-val" class="crmbiz-sig-range-val"><?php echo $sig['photo_gap']; ?>px</span>
                    </div>
                </div>
                <div>
                    <p class="crmbiz-settings-hint crmbiz-sig-hint-label">이름 ↔ 소개</p>
                    <div class="crmbiz-sig-range-row">
                        <input type="range" id="sig_text_gap" name="sig_text_gap" class="crmbiz-sig-range" min="0" max="40" step="2" value="<?php echo esc_attr($sig['text_gap']); ?>">
                        <span id="crmbiz-text-gap-val" class="crmbiz-sig-range-val"><?php echo $sig['text_gap']; ?>px</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="crmbiz-settings-field crmbiz-settings-field--flush">
            <label class="crmbiz-settings-field-label">사진 위치</label>
            <div class="crmbiz-settings-field-body crmbiz-sig-position-row">
                <?php foreach (['left' => '왼쪽', 'top' => '위', 'right' => '오른쪽'] as $val => $lbl): ?>
                <label class="crmbiz-sig-position-label">
                    <input type="radio" name="sig_photo_position" value="<?php echo $val; ?>" <?php checked($sig['photo_position'], $val); ?>>
                    <?php echo $lbl; ?>
                </label>
                <?php endforeach; ?>
            </div>
        </div>
    <?php }

    private function renderSignaturePreview(array $sig): void { ?>
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
        <hr class="crmbiz-settings-hr">
        <div class="crmbiz-preview-header">
            <h3>미리보기</h3>
            <button type="button" class="crmbiz-vp-btn active" data-vp="laptop">💻 랩탑</button>
            <button type="button" class="crmbiz-vp-btn" data-vp="tablet">📱 태블릿</button>
            <button type="button" class="crmbiz-vp-btn" data-vp="mobile">📲 모바일</button>
        </div>
        <div id="crmbiz-preview-viewport" class="crmbiz-preview-viewport vp-laptop">
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
                <p id="crmbiz-preview-bio" style="margin:0;font-size:14px;color:#374151;line-height:1.75;<?php echo $sig['show_bio'] ? '' : 'display:none'; ?>"><?php echo wp_kses($sig['bio'], ['strong' => [], 'em' => [], 'a' => ['href' => [], 'target' => []]]); ?></p>
            </div>
        </div>
        </div><!-- /crmbiz-admin-page -->
        </div><!-- /wrap -->

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
