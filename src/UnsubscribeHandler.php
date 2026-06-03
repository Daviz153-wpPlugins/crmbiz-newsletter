<?php
namespace CRMBizNewsletter;

defined('ABSPATH') || exit;

class UnsubscribeHandler {

    public function init(): void {
        add_action('template_redirect', [$this, 'handleUnsubscribeRequest']);
        add_action('fluentcrm_subscriber_status_to_subscribed',  [$this, 'removeOnResubscribe'], 10, 2);
        add_action('fluentcrm_subscriber_status_to_bounced',     [$this, 'handleBounce']);
        add_action('fluentcrm_subscriber_status_to_complained',  [$this, 'handleBounce']);
    }

    public function handleUnsubscribeRequest(): void {
        if (($_GET['crmbiz_nl_action'] ?? '') !== 'unsubscribe') {
            return;
        }

        $enc          = sanitize_text_field($_GET['enc'] ?? '');
        $email        = sanitize_email(Database::decryptEmail($enc));
        $token        = sanitize_text_field($_GET['token'] ?? '');
        $newsletterId = (int) ($_GET['nl'] ?? 0);
        $exp          = (int) ($_GET['exp'] ?? 0);

        // 레이트 리밋: IP당 10분 10회
        if (!Database::checkRateLimit('unsub', 10, 600)) {
            wp_die(__('요청이 너무 많습니다. 잠시 후 다시 시도해 주세요.', 'crmbiz-newsletter'), __('요청 제한', 'crmbiz-newsletter'), ['response' => 429]);
        }

        if (!$email || !$this->verifyToken($email, $token, $exp)) {
            $msg = ($exp > 0 && time() > $exp)
                ? __('수신거부 링크가 만료되었습니다. 최신 뉴스레터의 수신거부 링크를 사용해 주세요.', 'crmbiz-newsletter')
                : __('유효하지 않은 수신거부 링크입니다.', 'crmbiz-newsletter');
            wp_die($msg, __('수신거부 오류', 'crmbiz-newsletter'), ['response' => 403]);
        }

        $this->processUnsubscribe($email, $token);

        // 이벤트 테이블에 수신거부 기록 (newsletter_id가 있는 경우만)
        if ($newsletterId > 0) {
            TrackingHandler::recordUnsubscribe($newsletterId, $email);
        }

        // FluentCRM 연락처 상태도 unsubscribed로 동기화
        $api = FluentCRMBridge::getContactsApi();
        if ($api) {
            $contact = $api->getContact($email);
            if ($contact) {
                $contact->updateStatus('unsubscribed');
            }
        }

        $siteName = esc_html(get_bloginfo('name'));
        $homeUrl  = esc_url(home_url('/'));
        $maskedEmail = $this->maskEmail($email);

        wp_die(
            '<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>' . esc_html__('수신거부 완료', 'crmbiz-newsletter') . ' — ' . $siteName . '</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{background:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}
  .card{background:#fff;border-radius:12px;padding:48px 40px;max-width:440px;width:100%;text-align:center;box-shadow:0 1px 3px rgba(0,0,0,.08)}
  .icon{font-size:48px;margin-bottom:20px}
  h1{font-size:20px;font-weight:700;color:#111827;margin-bottom:10px}
  p{font-size:14px;color:#6b7280;line-height:1.6;margin-bottom:8px}
  .email{font-size:13px;color:#374151;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:8px 14px;display:inline-block;margin:12px 0 24px}
  a{display:inline-block;padding:10px 24px;background:#1a1a2e;color:#fff;border-radius:6px;font-size:14px;text-decoration:none;font-weight:500}
  a:hover{opacity:.9}
</style>
</head>
<body>
  <div class="card">
    <div class="icon">✅</div>
    <h1>' . esc_html__('수신거부가 완료되었습니다', 'crmbiz-newsletter') . '</h1>
    <p>' . esc_html__('아래 이메일 주소로 더 이상 뉴스레터가 발송되지 않습니다.', 'crmbiz-newsletter') . '</p>
    <div class="email">' . esc_html($maskedEmail) . '</div>
    <p style="margin-bottom:24px">' . sprintf(esc_html__('다시 구독을 원하시면 %s에서 신청하세요.', 'crmbiz-newsletter'), $siteName) . '</p>
    <a href="' . $homeUrl . '">' . esc_html__('홈으로 돌아가기', 'crmbiz-newsletter') . '</a>
  </div>
</body>
</html>',
            '',
            ['response' => 200]
        );
    }

    private function maskEmail(string $email): string {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $masked = mb_substr($local, 0, 2) . str_repeat('*', max(1, mb_strlen($local) - 2));
        return $masked . '@' . $domain;
    }

    private function verifyToken(string $email, string $token, int $exp): bool {
        if ($exp === 0 || time() > $exp) {
            return false;
        }
        $expected = hash_hmac('sha256', $email . '|' . $exp, Database::getSecret());
        return hash_equals($expected, $token);
    }

    private function processUnsubscribe(string $email, string $token): void {
        global $wpdb;
        $wpdb->replace(
            $wpdb->prefix . 'crmbiz_nl_unsubscribers',
            [
                'email'               => $email,
                'unsubscribed_at'     => current_time('mysql'),
                'unsubscribed_at_gmt' => current_time('mysql', true),
                'token_used'          => substr($token, 0, 64),
            ],
            ['%s', '%s', '%s', '%s']
        );
    }

    // FluentCRM 바운스/스팸 신고 시 수신거부 테이블에 자동 등록
    public function handleBounce($subscriber): void {
        if (empty($subscriber->email)) {
            return;
        }
        $reason = $subscriber->status ?? 'bounced';
        global $wpdb;
        $wpdb->replace(
            $wpdb->prefix . 'crmbiz_nl_unsubscribers',
            [
                'email'               => $subscriber->email,
                'unsubscribed_at'     => current_time('mysql'),
                'unsubscribed_at_gmt' => current_time('mysql', true),
                'token_used'          => 'fc_' . $reason,
            ],
            ['%s', '%s', '%s', '%s']
        );
        Logger::info('바운스 수신거부 자동 등록', ['email' => $subscriber->email, 'reason' => $reason]);
    }

    // FluentCRM 재구독 시 우리 수신거부 테이블에서 제거
    public function removeOnResubscribe($subscriber, string $previousStatus): void {
        global $wpdb;
        $wpdb->delete(
            $wpdb->prefix . 'crmbiz_nl_unsubscribers',
            ['email' => $subscriber->email],
            ['%s']
        );
    }

    public static function isUnsubscribed(string $email): bool {
        global $wpdb;
        return (bool) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}crmbiz_nl_unsubscribers WHERE email = %s LIMIT 1",
                $email
            )
        );
    }

    public static function buildUnsubscribeUrl(string $email, int $newsletterId = 0): string {
        $exp   = time() + (365 * DAY_IN_SECONDS); // 1년 유효
        $token = hash_hmac('sha256', $email . '|' . $exp, Database::getSecret());
        return add_query_arg([
            'crmbiz_nl_action' => 'unsubscribe',
            'enc'              => Database::encryptEmail($email), // 평문 이메일 대신 암호화값
            'token'            => $token,
            'nl'               => $newsletterId,
            'exp'              => $exp,
        ], home_url('/'));
    }
}
