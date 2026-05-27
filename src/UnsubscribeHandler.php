<?php
namespace CRMBizNewsletter;

defined('ABSPATH') || exit;

class UnsubscribeHandler {

    public function init(): void {
        add_action('template_redirect', [$this, 'handleUnsubscribeRequest']);
        add_action('fluentcrm_after_subscribers_deleted',        [$this, 'cleanupOnDelete']);
        add_action('fluentcrm_subscriber_status_to_subscribed',  [$this, 'removeOnResubscribe'], 10, 2);
    }

    public function handleUnsubscribeRequest(): void {
        if (($_GET['crmbiz_nl_action'] ?? '') !== 'unsubscribe') {
            return;
        }

        $email = sanitize_email($_GET['email'] ?? '');
        $token = sanitize_text_field($_GET['token'] ?? '');

        if (!$email || !$this->verifyToken($email, $token)) {
            wp_die('유효하지 않은 수신거부 링크입니다.', '수신거부 오류', ['response' => 403]);
        }

        $this->processUnsubscribe($email, $token);

        // FluentCRM 연락처 상태도 unsubscribed로 동기화
        $api = FluentCRMBridge::getContactsApi();
        if ($api) {
            $contact = $api->getContact($email);
            if ($contact) {
                $contact->updateStatus('unsubscribed');
            }
        }

        wp_redirect(add_query_arg('crmbiz_nl_unsub', '1', home_url('/')));
        exit;
    }

    private function verifyToken(string $email, string $token): bool {
        $expected = hash_hmac('sha256', $email, wp_salt('auth'));
        return hash_equals($expected, $token);
    }

    private function processUnsubscribe(string $email, string $token): void {
        global $wpdb;
        $wpdb->replace(
            $wpdb->prefix . 'crmbiz_nl_unsubscribers',
            [
                'email'           => $email,
                'unsubscribed_at' => current_time('mysql'),
                'token_used'      => substr($token, 0, 64),
            ],
            ['%s', '%s', '%s']
        );
    }

    // FluentCRM 연락처 삭제 시 — 수신거부 레코드는 스팸 재구독 방지용으로 유지
    public function cleanupOnDelete(array $subscriberIds): void {}

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

    public static function buildUnsubscribeUrl(string $email): string {
        $token = hash_hmac('sha256', $email, wp_salt('auth'));
        return add_query_arg([
            'crmbiz_nl_action' => 'unsubscribe',
            'email'            => $email,
            'token'            => $token,
        ], home_url('/'));
    }
}
