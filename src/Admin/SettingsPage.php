<?php
namespace CRMBizNewsletter\Admin;

use CRMBizNewsletter\Settings;
use CRMBizNewsletter\FluentCRMBridge;
use CRMBizNewsletter\Database;
use CRMBizNewsletter\Logger;

defined('ABSPATH') || exit;

class SettingsPage {

    use SettingsSignatureTrait;

    private Settings $settings;

    public function __construct(Settings $settings) {
        $this->settings = $settings;
    }

    public function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die('권한이 없습니다.');
        }

        // 로그 지우기 처리
        if (
            isset($_POST['crmbiz_nl_clear_logs_nonce']) &&
            wp_verify_nonce($_POST['crmbiz_nl_clear_logs_nonce'], 'crmbiz_nl_clear_logs')
        ) {
            Logger::clearLogs();
            wp_redirect(admin_url('admin.php?page=crmbiz-nl-settings&tab=logs&cleared=1'));
            exit;
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
                <a href="<?php echo esc_url($tabUrl . 'logs'); ?>"
                   class="crmbiz-settings-tab <?php echo $activeTab === 'logs'      ? 'is-active' : ''; ?>">시스템 로그</a>
            </div>

            <?php if ($activeTab === 'general'): ?>
                <?php $this->renderGeneralTab(); ?>
            <?php elseif ($activeTab === 'customize'): ?>
                <?php $this->renderCustomizeTab(); ?>
            <?php elseif ($activeTab === 'logs'): ?>
                <?php $this->renderLogsTab(); ?>
            <?php endif; ?>

        </div><!-- /crmbiz-admin-page -->
        </div><!-- /wrap -->
        <?php
    }

    // -------------------------------------------------------------------------
    // 기본 설정 탭
    // -------------------------------------------------------------------------

    private function renderGeneralTab(): void {
        $fromName        = esc_attr($this->settings->get('from_name', ''));
        $fromEmail       = esc_attr($this->settings->get('from_email', ''));
        $isDryRun        = $this->settings->isDryRun();
        $isDebug         = $this->settings->isDebugMode();
        $disableErrEmail = (bool) $this->settings->get('disable_error_email', false);
        $defaultEmail    = esc_attr(wp_get_current_user()->user_email ?? '');
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
                    <div class="crmbiz-toggle-row">
                        <div class="crmbiz-toggle-info">
                            <h4>오류 이메일 알림 비활성화</h4>
                            <p>ERROR 발생 시 관리자 이메일 알림을 끕니다. (기본: 알림 활성화, 동일 오류 1시간 1회)</p>
                        </div>
                        <label class="crmbiz-toggle-switch">
                            <input type="checkbox" name="disable_error_email" value="1" <?php checked($disableErrEmail); ?>>
                            <span class="crmbiz-toggle-track"></span>
                        </label>
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
    // 시스템 로그 탭
    // -------------------------------------------------------------------------

    private function renderLogsTab(): void {
        $filterLevel = strtoupper(sanitize_key($_GET['log_level'] ?? ''));
        if (!in_array($filterLevel, ['ERROR', 'WARN'], true)) {
            $filterLevel = '';
        }

        // 테이블이 없으면 설치 실행 후 재시도
        if (Database::getVersion() !== Database::DB_VERSION) {
            Database::install();
        }

        $logs        = Logger::getLogs($filterLevel, 100);
        $cleared     = isset($_GET['cleared']);
        $levelUrl    = admin_url('admin.php?page=crmbiz-nl-settings&tab=logs');

        $levelColors = [
            'ERROR' => 'color:#dc2626;background:#fef2f2',
            'WARN'  => 'color:#d97706;background:#fffbeb',
            'INFO'  => 'color:#2563eb;background:#eff6ff',
        ];
        ?>
        <div class="crmbiz-settings-section">
            <div class="crmbiz-settings-section-head" style="display:flex;align-items:center;justify-content:space-between">
                <div>
                    <h3>시스템 로그</h3>
                    <p>최근 7일간 ERROR / WARNING 기록. 최대 100건 표시.</p>
                </div>
                <form method="post">
                    <?php wp_nonce_field('crmbiz_nl_clear_logs', 'crmbiz_nl_clear_logs_nonce'); ?>
                    <button type="submit" class="crmbiz-btn crmbiz-btn--secondary" style="font-size:12px"
                            onclick="return confirm('모든 로그를 삭제할까요?')">
                        로그 지우기
                    </button>
                </form>
            </div>

            <?php if ($cleared): ?>
                <div class="crmbiz-settings-notice crmbiz-settings-notice--success">✓ 로그가 삭제되었습니다.</div>
            <?php endif; ?>

            <!-- 레벨 필터 -->
            <div style="display:flex;gap:8px;margin:16px 0;flex-wrap:wrap;padding:0 20px">
                <?php foreach (['전체' => '', 'ERROR' => 'ERROR', 'WARN' => 'WARN'] as $label => $val): ?>
                <a href="<?php echo esc_url($levelUrl . ($val ? '&log_level=' . $val : '')); ?>"
                   style="padding:4px 14px;border-radius:20px;font-size:12px;font-weight:600;text-decoration:none;border:1px solid;
                          <?php echo $filterLevel === $val
                              ? 'background:#111827;color:#fff;border-color:#111827'
                              : 'background:#fff;color:#6b7280;border-color:#e5e7eb'; ?>">
                    <?php echo esc_html($label); ?>
                </a>
                <?php endforeach; ?>
            </div>

            <?php if (empty($logs)): ?>
                <div class="crmbiz-empty" style="padding:40px 20px;text-align:center;color:#9ca3af">
                    <p style="font-size:32px;margin-bottom:8px">✅</p>
                    <p>기록된 오류/경고가 없습니다.</p>
                </div>
            <?php else: ?>
            <div class="crmbiz-card" style="overflow:auto;margin:0 20px 20px">
                <table class="crmbiz-table" style="font-size:12px">
                    <thead>
                        <tr>
                            <th style="width:70px">레벨</th>
                            <th style="width:140px">발생 시각</th>
                            <th>메시지</th>
                            <th style="width:35%">컨텍스트</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log):
                            $lvl     = esc_html($log['level'] ?? '');
                            $style   = $levelColors[$log['level'] ?? ''] ?? '';
                            $ctx     = $log['context'] ? json_decode($log['context'], true) : [];
                            $ctxStr  = $ctx ? esc_html(wp_json_encode($ctx, JSON_UNESCAPED_UNICODE)) : '—';
                        ?>
                        <tr>
                            <td>
                                <span style="<?php echo esc_attr($style); ?>;padding:2px 8px;border-radius:4px;font-weight:700;font-size:11px">
                                    <?php echo $lvl; ?>
                                </span>
                            </td>
                            <td style="color:#6b7280;white-space:nowrap"><?php echo esc_html($log['occurred_at']); ?></td>
                            <td style="color:#111827"><?php echo esc_html($log['message']); ?></td>
                            <td style="color:#6b7280;word-break:break-all;max-width:300px"><?php echo $ctxStr; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
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

}
