<?php
namespace CRMBizNewsletter\Admin;

use CRMBizNewsletter\FluentCRMBridge;
use CRMBizNewsletter\Settings;
use CRMBizNewsletter\Database;

defined('ABSPATH') || exit;

class DiagnosticsPage {

    private Settings $settings;

    public function __construct(Settings $settings) {
        $this->settings = $settings;
    }

    public function enqueueScripts(string $hookSuffix): void {
        // 인라인 스크립트 사용 — 외부 파일 불필요
    }

    public function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die('권한이 없습니다.');
        }

        $fcAvailable    = FluentCRMBridge::isAvailable();
        $smtpAvailable  = FluentCRMBridge::isFluentSMTPAvailable();
        $dbInstalled    = Database::isInstalled();
        $contactCount   = $fcAvailable ? FluentCRMBridge::getContactCount() : 0;
        $tags           = $fcAvailable ? FluentCRMBridge::getTagsForSelect()  : [];
        $lists          = $fcAvailable ? FluentCRMBridge::getListsForSelect() : [];
        $isDryRun       = $this->settings->isDryRun();
        $defaultEmail   = esc_attr(wp_get_current_user()->user_email ?? '');
        ?>
        <div class="wrap">
            <h1>CRMBiz Newsletter — 진단
                <span style="font-size:13px;font-weight:400;color:#6b7280;background:#f3f4f6;border:1px solid #e5e7eb;border-radius:4px;padding:2px 8px;margin-left:8px;vertical-align:middle">
                    v<?php echo esc_html(CRMBIZ_NL_VERSION); ?>
                </span>
            </h1>

            <?php if ($isDryRun): ?>
                <div class="notice notice-warning">
                    <p><strong>Dry-run 모드 활성화</strong> — 테스트 이메일도 실제 발송되지 않습니다.
                    <a href="<?php echo esc_url(admin_url('admin.php?page=crmbiz-nl-settings')); ?>">설정에서 해제</a></p>
                </div>
            <?php endif; ?>

            <h2>시스템 상태</h2>
            <table class="widefat fixed striped" style="max-width:700px">
                <thead>
                    <tr>
                        <th>항목</th>
                        <th style="width:120px">상태</th>
                        <th>정보</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $this->statusRow(
                        '플러그인 버전',
                        true,
                        'v' . CRMBIZ_NL_VERSION . ' (DB v' . Database::getVersion() . ')'
                    );
                    $this->statusRow(
                        'FluentCRM',
                        $fcAvailable,
                        $fcAvailable ? '활성화됨' : '비활성 또는 미설치'
                    );
                    $this->statusRow(
                        'FluentSMTP',
                        $smtpAvailable,
                        $smtpAvailable ? '활성화됨' : '비활성 (wp_mail 기본값 사용)'
                    );
                    $this->statusRow(
                        'DB 테이블',
                        $dbInstalled,
                        $dbInstalled ? 'v' . Database::getVersion() : '미설치 (플러그인 재활성화 필요)'
                    );
                    $this->statusRow(
                        '연락처 수',
                        $fcAvailable,
                        $fcAvailable ? number_format($contactCount) . '명' : '-'
                    );
                    $this->statusRow(
                        '태그 수',
                        $fcAvailable,
                        $fcAvailable ? count($tags) . '개' : '-'
                    );
                    $this->statusRow(
                        '리스트 수',
                        $fcAvailable,
                        $fcAvailable ? count($lists) . '개' : '-'
                    );
                    ?>
                </tbody>
            </table>

            <?php if ($fcAvailable && (count($tags) > 0 || count($lists) > 0)): ?>
                <h2 style="margin-top:24px">태그 / 리스트 목록</h2>
                <div style="display:flex;gap:32px;max-width:700px">
                    <?php if (!empty($tags)): ?>
                        <div style="flex:1">
                            <h3>태그 (<?php echo count($tags); ?>)</h3>
                            <ul style="margin:0;padding-left:16px">
                                <?php foreach ($tags as $tag): ?>
                                    <li><?php echo esc_html($tag['label']); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($lists)): ?>
                        <div style="flex:1">
                            <h3>리스트 (<?php echo count($lists); ?>)</h3>
                            <ul style="margin:0;padding-left:16px">
                                <?php foreach ($lists as $list): ?>
                                    <li><?php echo esc_html($list['label']); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <h2 style="margin-top:32px">테스트 이메일 발송</h2>
            <p>실제 이메일을 발송하여 SMTP 연결을 확인합니다.</p>
            <table class="form-table" role="presentation" style="max-width:700px">
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
                    </td>
                </tr>
            </table>
            <div id="crmbiz-test-result" style="margin-top:12px;display:none;padding:10px 14px;border-radius:4px;max-width:600px"></div>

            <h2 style="margin-top:32px">발신자 정보</h2>
            <table class="form-table" role="presentation" style="max-width:700px">
                <tr>
                    <th>발신자 이름</th>
                    <td><?php echo esc_html($this->settings->getFromName()); ?></td>
                </tr>
                <tr>
                    <th>발신자 이메일</th>
                    <td><?php echo esc_html($this->settings->getFromEmail()); ?></td>
                </tr>
            </table>
        </div>

        <script>
        (function($) {
            var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            var nonce   = <?php echo wp_json_encode(wp_create_nonce('crmbiz_nl_diagnostics')); ?>;

            $('#crmbiz-send-test').on('click', function() {
                var email   = $('#crmbiz-test-email').val().trim();
                var $result = $('#crmbiz-test-result');

                if (!email) {
                    $result.css({'background':'#fff3cd','border':'1px solid #ffc107','color':'#856404'})
                           .text('이메일 주소를 입력하세요.')
                           .show();
                    return;
                }

                var $btn = $(this).prop('disabled', true).text('발송 중...');

                $.post(ajaxUrl, {
                    action:     'crmbiz_nl_test_email',
                    nonce:      nonce,
                    test_email: email
                }, function(res) {
                    $btn.prop('disabled', false).text('테스트 발송');

                    if (res.success) {
                        var msg = (res.data && res.data.dry_run)
                            ? 'Dry-run: 실제 발송 건너뜀 (' + res.data.to + ')'
                            : (res.data && res.data.message ? res.data.message : '발송 성공');

                        $result.css({'background':'#d1e7dd','border':'1px solid #0f5132','color':'#0f5132'})
                               .text(msg).show();
                    } else {
                        var errMsg = (res.data && res.data.message) ? res.data.message : '발송 실패';
                        $result.css({'background':'#f8d7da','border':'1px solid #842029','color':'#842029'})
                               .text(errMsg).show();
                    }
                }).fail(function() {
                    $btn.prop('disabled', false).text('테스트 발송');
                    $result.css({'background':'#f8d7da','border':'1px solid #842029','color':'#842029'})
                           .text('AJAX 요청 실패. 서버 오류를 확인하세요.').show();
                });
            });
        })(jQuery);
        </script>
        <?php
    }

    private function statusRow(string $label, bool $ok, string $info): void {
        $badge = $ok
            ? '<span style="color:#0f5132;background:#d1e7dd;padding:2px 8px;border-radius:3px;font-size:12px">정상</span>'
            : '<span style="color:#842029;background:#f8d7da;padding:2px 8px;border-radius:3px;font-size:12px">확인 필요</span>';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $badge는 내부 생성 HTML, 값은 esc_attr 처리됨
        printf(
            '<tr><td>%s</td><td>%s</td><td>%s</td></tr>',
            esc_html($label),
            $badge,
            esc_html($info)
        );
    }
}
