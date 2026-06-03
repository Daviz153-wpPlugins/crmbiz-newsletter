<?php
namespace CRMBizNewsletter\Admin;

use CRMBizNewsletter\EmailTemplateRenderer;
use CRMBizNewsletter\FluentCRMBridge;
use CRMBizNewsletter\Logger;
use CRMBizNewsletter\Settings;

defined('ABSPATH') || exit;

class AjaxHandlers {

    private Settings $settings;
    private string $cronHook;

    public function __construct(Settings $settings, string $cronHook) {
        $this->settings = $settings;
        $this->cronHook = $cronHook;
    }

    private function requireAuth(string $nonce, string $cap = 'manage_options'): void {
        if (!check_ajax_referer($nonce, 'nonce', false)) {
            wp_send_json_error(['message' => '보안 검증 실패.'], 403);
        }
        if (!current_user_can($cap)) {
            wp_send_json_error(['message' => '권한이 없습니다.'], 403);
        }
    }

    public function handleTestEmail(): void {
        $this->requireAuth('crmbiz_nl_diagnostics');

        $to = sanitize_email($_POST['test_email'] ?? '');
        if (!is_email($to)) {
            wp_send_json_error(['message' => '유효하지 않은 이메일 주소입니다.']);
        }

        if ($this->settings->isDryRun()) {
            FluentCRMBridge::debugLog('CRMBiz Newsletter', 'DRY-RUN: 테스트 이메일 건너뜀. To: ' . $to);
            wp_send_json_success(['dry_run' => true, 'to' => $to]);
        }

        $fromName  = str_replace(["\r", "\n"], '', $this->settings->getFromName());
        $fromEmail = str_replace(["\r", "\n"], '', $this->settings->getFromEmail());
        $result = wp_mail(
            $to,
            '[테스트] CRMBiz Newsletter 발송 테스트',
            $this->buildTestEmailBody($to),
            ['Content-Type: text/html; charset=UTF-8', 'From: ' . $fromName . ' <' . $fromEmail . '>']
        );

        $result
            ? wp_send_json_success(['message' => '발송 성공: ' . $to])
            : wp_send_json_error(['message' => '발송 실패. FluentSMTP 설정을 확인하세요.']);
    }

    public function handleCountRecipients(): void {
        $this->requireAuth('crmbiz_nl_metabox', 'edit_posts');

        if (!FluentCRMBridge::isAvailable()) {
            wp_send_json_success(['count' => 0]);
        }

        $tagIds  = array_filter(array_map('intval', (array) ($_POST['tag_ids']  ?? [])));
        $listIds = array_filter(array_map('intval', (array) ($_POST['list_ids'] ?? [])));

        if (empty($tagIds) && empty($listIds)) {
            wp_send_json_success(['count' => 0]);
        }

        try {
            $query = new \FluentCrm\App\Services\ContactsQuery([
                'tags'     => $tagIds,
                'lists'    => $listIds,
                'statuses' => ['subscribed'],
            ]);
            wp_send_json_success(['count' => $query->getModel()->count()]);
        } catch (\Throwable $e) {
            Logger::error('수신자 수 조회 실패', ['error' => $e->getMessage()]);
            wp_send_json_success(['count' => 0]);
        }
    }

    public function handleSettingsPreview(): void {
        $nonce = $_GET['nonce'] ?? '';
        if (!wp_verify_nonce($nonce, 'crmbiz_nl_settings_preview')) {
            wp_die('보안 검증 실패.', '오류', ['response' => 403]);
        }
        if (!current_user_can('manage_options')) {
            wp_die('권한이 없습니다.', '오류', ['response' => 403]);
        }

        // 가장 최근 발행된 포스트 사용, 없으면 더미 포스트 생성
        $post = null;
        $recent = get_posts(['numberposts' => 1, 'post_status' => 'publish', 'post_type' => 'post']);
        if (!empty($recent)) {
            $post = $recent[0];
        } else {
            $post = new \WP_Post((object) [
                'ID'           => 0,
                'post_title'   => '뉴스레터 미리보기',
                'post_content' => '<p>이 미리보기는 실제 발행된 포스트가 없어 샘플 콘텐츠를 사용합니다.</p><p>포스트를 발행하면 실제 내용으로 미리보기할 수 있습니다.</p>',
                'post_date'    => current_time('mysql'),
                'post_status'  => 'publish',
                'post_type'    => 'post',
                'post_author'  => get_current_user_id(),
                'post_excerpt' => '',
                'post_name'    => 'preview',
                'guid'         => home_url('/preview'),
            ]);
        }

        $currentUser = wp_get_current_user();
        $subscriber  = (object) [
            'email'      => $currentUser->user_email,
            'first_name' => $currentUser->display_name,
            'last_name'  => '',
            'full_name'  => $currentUser->display_name,
        ];

        $html = (new EmailTemplateRenderer($this->settings))->render($post, $subscriber);

        header('Content-Type: text/html; charset=UTF-8');
        // EmailTemplateRenderer가 생성한 완전한 HTML 문서 — 이미 내부적으로 이스케이프 처리됨.
        // echo esc_html()로 재이스케이프하면 HTML 태그가 깨져 미리보기 불가.
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $html;
        exit;
    }

    public function handlePreviewEmail(): void {
        $postId = (int) ($_GET['post_id'] ?? 0);
        $nonce  = $_GET['nonce'] ?? '';

        if (!wp_verify_nonce($nonce, 'crmbiz_nl_preview_' . $postId)) {
            wp_die('보안 검증 실패.', '오류', ['response' => 403]);
        }
        if (!current_user_can('manage_options')) {
            wp_die('권한이 없습니다.', '오류', ['response' => 403]);
        }

        $post = get_post($postId);
        if (!$post) {
            wp_die('포스트를 찾을 수 없습니다.');
        }

        $dummy = (object) [
            'email'      => wp_get_current_user()->user_email,
            'first_name' => wp_get_current_user()->display_name,
            'last_name'  => '',
            'full_name'  => wp_get_current_user()->display_name,
        ];

        $html = (new EmailTemplateRenderer($this->settings))->render($post, $dummy);

        header('Content-Type: text/html; charset=UTF-8');
        // EmailTemplateRenderer가 생성한 완전한 HTML 문서 — 위와 동일 사유.
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $html;
        exit;
    }

    public function handleTestNewsletter(): void {
        $this->requireAuth('crmbiz_nl_metabox', 'edit_posts');

        $postId = (int) ($_POST['post_id'] ?? 0);
        $to     = sanitize_email($_POST['test_email'] ?? '');

        if ($postId <= 0 || !is_email($to)) {
            wp_send_json_error(['message' => '유효하지 않은 입력입니다.']);
        }

        $post = get_post($postId);
        if (!$post) {
            wp_send_json_error(['message' => '포스트를 찾을 수 없습니다.']);
        }

        $currentUser = wp_get_current_user();
        $dummy = (object) [
            'email'      => $to,
            'first_name' => $currentUser->display_name,
            'last_name'  => '',
            'full_name'  => $currentUser->display_name,
        ];

        $html = (new EmailTemplateRenderer($this->settings))->render($post, $dummy);

        $fromName  = str_replace(["\r", "\n"], '', $this->settings->getFromName());
        $fromEmail = str_replace(["\r", "\n"], '', $this->settings->getFromEmail());
        $result    = wp_mail(
            $to,
            '[테스트] ' . get_the_title($post),
            $html,
            [
                'Content-Type: text/html; charset=UTF-8',
                'From: ' . $fromName . ' <' . $fromEmail . '>',
            ]
        );

        $result
            ? wp_send_json_success(['message' => esc_html($to) . '로 테스트 발송 완료'])
            : wp_send_json_error(['message' => '발송 실패. FluentSMTP 설정을 확인하세요.']);
    }


    public function handleUnsubRemove(): void {
        $this->requireAuth('crmbiz_nl_unsub_manage');

        global $wpdb;
        $table = $wpdb->prefix . 'crmbiz_nl_unsubscribers';

        // 단건 또는 일괄
        $ids = array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])));
        if (empty($ids) && isset($_POST['id'])) {
            $ids = [(int) $_POST['id']];
        }
        if (empty($ids)) {
            wp_send_json_error(['message' => '유효하지 않은 요청입니다.']);
        }

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $deleted = $wpdb->query(
            $wpdb->prepare("DELETE FROM {$table} WHERE id IN ($placeholders)", ...$ids)
        );

        $deleted
            ? wp_send_json_success(['deleted' => $deleted])
            : wp_send_json_error(['message' => '삭제 실패 또는 이미 삭제된 항목입니다.']);
    }

    public function handleUnsubAdd(): void {
        $this->requireAuth('crmbiz_nl_unsub_manage');

        $email = sanitize_email($_POST['email'] ?? '');
        if (!is_email($email)) {
            wp_send_json_error(['message' => '유효하지 않은 이메일 주소입니다.']);
        }

        global $wpdb;
        $result = $wpdb->replace(
            $wpdb->prefix . 'crmbiz_nl_unsubscribers',
            ['email' => $email, 'unsubscribed_at' => current_time('mysql'), 'unsubscribed_at_gmt' => current_time('mysql', true), 'token_used' => null],
            ['%s', '%s', '%s', '%s']
        );

        $result !== false
            ? wp_send_json_success(['message' => '추가되었습니다.'])
            : wp_send_json_error(['message' => '추가 실패.']);
    }

    private function buildTestEmailBody(string $to): string {
        return sprintf(
            '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:32px;background:#f3f4f6">' .
            '<div style="max-width:500px;margin:0 auto;background:#fff;padding:32px;border-radius:8px">' .
            '<h2 style="color:#1a1a2e;margin:0 0 16px">테스트 이메일</h2>' .
            '<p>CRMBiz Newsletter 플러그인에서 발송한 테스트 이메일입니다.</p>' .
            '<table style="font-size:13px;color:#555;margin-top:16px"><tr><td style="padding:3px 12px 3px 0">발신자</td><td>%s &lt;%s&gt;</td></tr>' .
            '<tr><td>수신자</td><td>%s</td></tr>' .
            '<tr><td>시각</td><td>%s</td></tr></table>' .
            '<p style="margin-top:24px;color:#0f5132;background:#d1e7dd;padding:10px 14px;border-radius:4px;font-size:13px">FluentSMTP 연결이 정상입니다.</p>' .
            '</div></body></html>',
            esc_html($this->settings->getFromName()),
            esc_html($this->settings->getFromEmail()),
            esc_html($to),
            esc_html(current_time('Y-m-d H:i:s'))
        );
    }
}
