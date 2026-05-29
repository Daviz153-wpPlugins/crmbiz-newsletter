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

        $saved = false;
        if (isset($_POST['crmbiz_nl_settings_nonce']) &&
            wp_verify_nonce($_POST['crmbiz_nl_settings_nonce'], 'crmbiz_nl_settings_save')) {

            $this->settings->saveFromPost($_POST);
            $saved = true;
        }

        $fromName     = esc_attr($this->settings->get('from_name', ''));
        $fromEmail    = esc_attr($this->settings->get('from_email', ''));
        $isDryRun     = $this->settings->isDryRun();
        $isDebug      = $this->settings->isDebugMode();
        $defaultEmail = esc_attr(wp_get_current_user()->user_email ?? '');
        ?>
        <div class="wrap">
            <h1>CRMBiz Newsletter — 설정</h1>

            <?php if ($saved): ?>
                <div class="notice notice-success is-dismissible">
                    <p>설정이 저장되었습니다.</p>
                </div>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field('crmbiz_nl_settings_save', 'crmbiz_nl_settings_nonce'); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="from_name">발신자 이름</label>
                        </th>
                        <td>
                            <input type="text"
                                   id="from_name"
                                   name="from_name"
                                   value="<?php echo $fromName; ?>"
                                   class="regular-text"
                                   placeholder="<?php echo esc_attr($this->settings->getFromName()); ?>">
                            <p class="description">비워두면 FluentCRM 전역 설정 또는 사이트 이름을 사용합니다.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="from_email">발신자 이메일</label>
                        </th>
                        <td>
                            <input type="email"
                                   id="from_email"
                                   name="from_email"
                                   value="<?php echo $fromEmail; ?>"
                                   class="regular-text"
                                   placeholder="<?php echo esc_attr($this->settings->getFromEmail()); ?>">
                            <p class="description">비워두면 FluentCRM 전역 설정 또는 관리자 이메일을 사용합니다.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">테스트 모드</th>
                        <td>
                            <fieldset>
                                <label>
                                    <input type="checkbox"
                                           name="dry_run"
                                           value="1"
                                           <?php checked($isDryRun); ?>>
                                    테스트 모드 활성화 (실제 발송 안 함)
                                    <span class="description">— 이메일을 실제로 발송하지 않고 로그만 기록합니다.</span>
                                </label>
                                <br>
                                <label>
                                    <input type="checkbox"
                                           name="debug_mode"
                                           value="1"
                                           <?php checked($isDebug); ?>>
                                    디버그 모드 활성화
                                    <span class="description">— FluentCRM 시스템 로그에 상세 정보를 기록합니다.</span>
                                </label>
                            </fieldset>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="notify_email">알림 이메일</label>
                        </th>
                        <td>
                            <input type="email"
                                   id="notify_email"
                                   name="notify_email"
                                   value="<?php echo esc_attr($this->settings->get('notify_email', '')); ?>"
                                   class="regular-text"
                                   placeholder="<?php echo esc_attr((string) get_option('admin_email')); ?>">
                            <p class="description">뉴스레터 발송 완료/실패 시 결과를 받을 이메일. 비워두면 WordPress 관리자 이메일로 발송됩니다.</p>
                        </td>
                    </tr>
                </table>

                <?php submit_button('설정 저장'); ?>
            </form>

            <hr style="margin:32px 0">

            <h2>테스트 이메일 발송</h2>
            <p>실제 이메일을 발송하여 SMTP 연결을 확인합니다.</p>
            <table class="form-table" role="presentation">
                <tr>
                    <th><label for="crmbiz-test-email">수신 이메일</label></th>
                    <td>
                        <input type="email"
                               id="crmbiz-test-email"
                               value="<?php echo $defaultEmail; ?>"
                               class="regular-text"
                               placeholder="test@example.com">
                        <button type="button"
                                id="crmbiz-send-test"
                                class="button button-primary"
                                style="margin-left:8px">
                            테스트 발송
                        </button>
                        <p class="description">
                            발신자: <?php echo esc_html($this->settings->getFromName()); ?>
                            &lt;<?php echo esc_html($this->settings->getFromEmail()); ?>&gt;
                        </p>
                    </td>
                </tr>
            </table>
            <div id="crmbiz-test-result" style="margin-top:12px;display:none;padding:10px 14px;border-radius:4px;max-width:600px"></div>
        </div>
        <?php
    }
}
