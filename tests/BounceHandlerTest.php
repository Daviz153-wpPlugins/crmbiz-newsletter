<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use CRMBizNewsletter\UnsubscribeHandler;

/**
 * FluentCRM 바운스/스팸 신고 자동 수신거부 등록 테스트
 */
class BounceHandlerTest extends TestCase {

    private UnsubscribeHandler $handler;

    protected function setUp(): void {
        $GLOBALS['_wp_options']['crmbiz_nl_secret'] = bin2hex(str_repeat("\x42", 32));
        $GLOBALS['_wpdb_unsubscribers'] = [];
        $this->handler = new UnsubscribeHandler();
    }

    protected function tearDown(): void {
        unset($GLOBALS['_wp_options']['crmbiz_nl_secret']);
        $GLOBALS['_wpdb_unsubscribers'] = [];
    }

    // ── handleBounce 등록 검증 ────────────────────────────────────────────────

    public function test_handleBounce_registers_bounced_email(): void {
        $subscriber = (object) ['email' => 'bounce@example.com', 'status' => 'bounced'];
        $this->handler->handleBounce($subscriber);

        $row = $this->findUnsub('bounce@example.com');
        $this->assertNotNull($row, '바운스 이메일이 수신거부 테이블에 등록돼야 함');
        $this->assertSame('fc_bounced', $row['token_used']);
    }

    public function test_handleBounce_registers_complained_email(): void {
        $subscriber = (object) ['email' => 'spam@example.com', 'status' => 'complained'];
        $this->handler->handleBounce($subscriber);

        $row = $this->findUnsub('spam@example.com');
        $this->assertNotNull($row, '스팸 신고 이메일이 수신거부 테이블에 등록돼야 함');
        $this->assertSame('fc_complained', $row['token_used']);
    }

    public function test_handleBounce_skips_empty_email(): void {
        $subscriber = (object) ['email' => '', 'status' => 'bounced'];
        $this->handler->handleBounce($subscriber);

        $this->assertEmpty($GLOBALS['_wpdb_unsubscribers'], '이메일이 없으면 등록하지 않음');
    }

    public function test_handleBounce_token_has_fc_prefix(): void {
        $subscriber = (object) ['email' => 'test@example.com', 'status' => 'bounced'];
        $this->handler->handleBounce($subscriber);

        $row = $this->findUnsub('test@example.com');
        $this->assertNotNull($row);
        $this->assertStringStartsWith('fc_', $row['token_used'], 'token_used는 fc_ 접두사를 가져야 함');
    }

    public function test_handleBounce_updates_existing_entry(): void {
        // 이미 수동으로 수신거부된 이메일이 다시 바운스돼도 중복 없이 갱신
        $GLOBALS['_wpdb_unsubscribers'][] = [
            'email'           => 'dup@example.com',
            'unsubscribed_at' => '2024-01-01 00:00:00',
            'token_used'      => 'manual',
        ];

        $subscriber = (object) ['email' => 'dup@example.com', 'status' => 'bounced'];
        $this->handler->handleBounce($subscriber);

        $entries = array_filter(
            $GLOBALS['_wpdb_unsubscribers'],
            fn ($r) => $r['email'] === 'dup@example.com'
        );
        $this->assertCount(1, $entries, '중복 등록 없이 갱신돼야 함');
    }

    // ── removeOnResubscribe 검증 ──────────────────────────────────────────────

    public function test_removeOnResubscribe_removes_email_from_list(): void {
        $GLOBALS['_wpdb_unsubscribers'][] = [
            'email'           => 'user@example.com',
            'unsubscribed_at' => date('Y-m-d H:i:s'),
            'token_used'      => 'fc_bounced',
        ];

        $subscriber = (object) ['email' => 'user@example.com'];
        $this->handler->removeOnResubscribe($subscriber, 'bounced');

        $this->assertNull($this->findUnsub('user@example.com'), '재구독 시 수신거부 목록에서 제거돼야 함');
    }

    public function test_removeOnResubscribe_only_removes_matching_email(): void {
        $GLOBALS['_wpdb_unsubscribers'][] = ['email' => 'a@example.com', 'unsubscribed_at' => date('Y-m-d H:i:s'), 'token_used' => 'x'];
        $GLOBALS['_wpdb_unsubscribers'][] = ['email' => 'b@example.com', 'unsubscribed_at' => date('Y-m-d H:i:s'), 'token_used' => 'x'];

        $subscriber = (object) ['email' => 'a@example.com'];
        $this->handler->removeOnResubscribe($subscriber, 'subscribed');

        $this->assertNull($this->findUnsub('a@example.com'), 'a는 제거됨');
        $this->assertNotNull($this->findUnsub('b@example.com'), 'b는 유지됨');
    }

    // ── isUnsubscribed 검증 ───────────────────────────────────────────────────

    public function test_isUnsubscribed_returns_true_when_registered(): void {
        $GLOBALS['_wpdb_unsubscribers'][] = ['email' => 'unsub@example.com', 'unsubscribed_at' => date('Y-m-d H:i:s'), 'token_used' => 'fc_bounced'];
        $this->assertTrue(UnsubscribeHandler::isUnsubscribed('unsub@example.com'));
    }

    public function test_isUnsubscribed_returns_false_when_not_registered(): void {
        $this->assertFalse(UnsubscribeHandler::isUnsubscribed('notinlist@example.com'));
    }

    // ── 헬퍼 ─────────────────────────────────────────────────────────────────

    private function findUnsub(string $email): ?array {
        foreach ($GLOBALS['_wpdb_unsubscribers'] as $row) {
            if ($row['email'] === $email) {
                return $row;
            }
        }
        return null;
    }
}
