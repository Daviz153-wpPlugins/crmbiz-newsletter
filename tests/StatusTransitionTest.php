<?php
declare(strict_types=1);

use CRMBizNewsletter\RestApi;
use CRMBizNewsletter\Settings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 뉴스레터 상태 전환 완전성 테스트
 *
 * 7개 상태(draft, queued, scheduled, sending, sent, failed, cancelled)의
 * 허용/차단 전환을 RestApi 메서드 레벨에서 전수 검증.
 *
 * 비즈니스 규칙:
 *   send        : draft        → queued    (다른 상태에서는 400)
 *   cancel      : queued/sending/scheduled → cancelled (나머지는 400)
 *   force-send  : queued/sending만 허용 (나머지는 400)
 *   delete      : sending이면 400, 나머지는 삭제 가능
 *   resend      : 레코드 없으면 404, 포스트 없으면 400
 *   resend-single: 이메일 형식 검증
 */
class StatusTransitionTest extends TestCase {

    private RestApi $api;

    protected function setUp(): void {
        $GLOBALS['_wp_user_can']      = true;
        $GLOBALS['_wpdb_newsletters'] = [];
        $GLOBALS['_as_actions']       = [];
        $GLOBALS['_wp_posts']         = [];
        $this->api = new RestApi(new Settings());
    }

    /** 테스트용 뉴스레터 레코드 삽입 후 id 반환 */
    private function seedNewsletter(string $status, int $postId = 1): int {
        $id = count($GLOBALS['_wpdb_newsletters']) + 1;
        $GLOBALS['_wpdb_newsletters'][] = [
            'id'              => $id,
            'post_id'         => $postId,
            'status'          => $status,
            'send_mode'       => 'immediate',
            'success_count'   => 0,
            'fail_count'      => 0,
            'recipient_count' => 0,
            'tag_ids'         => '[]',
            'list_ids'        => '[]',
        ];
        return $id;
    }

    private function req(array $params): WP_REST_Request {
        return new WP_REST_Request('POST', $params);
    }

    // ── send: draft → queued ──────────────────────────────────────────────────

    public function test_send_draft_returns_queued(): void {
        $id = $this->seedNewsletter('draft');
        $res = $this->api->sendNewsletter($this->req(['id' => $id]));

        $this->assertSame(200, $res->get_status());
        $this->assertSame('queued', $res->get_data()['status']);
        // DB 상태도 변경됐는지
        $this->assertSame('queued', $GLOBALS['_wpdb_newsletters'][0]['status']);
    }

    #[DataProvider('nonDraftStatuses')]
    public function test_send_non_draft_returns_400(string $status): void {
        $id  = $this->seedNewsletter($status);
        $res = $this->api->sendNewsletter($this->req(['id' => $id]));
        $this->assertSame(400, $res->get_status());
        // 상태가 변경되지 않았는지
        $this->assertSame($status, $GLOBALS['_wpdb_newsletters'][0]['status']);
    }

    public static function nonDraftStatuses(): array {
        return [
            ['queued'], ['scheduled'], ['sending'], ['sent'], ['failed'], ['cancelled'],
        ];
    }

    public function test_send_nonexistent_id_returns_400(): void {
        $res = $this->api->sendNewsletter($this->req(['id' => 9999]));
        $this->assertSame(400, $res->get_status());
    }

    // ── cancel: queued/sending/scheduled → cancelled ──────────────────────────

    #[DataProvider('cancellableStatuses')]
    public function test_cancel_valid_status_returns_cancelled(string $status): void {
        $id  = $this->seedNewsletter($status);
        $res = $this->api->cancelNewsletter($this->req(['id' => $id]));

        $this->assertSame(200, $res->get_status());
        $this->assertSame('cancelled', $res->get_data()['status']);
        $this->assertSame('cancelled', $GLOBALS['_wpdb_newsletters'][0]['status']);
    }

    public static function cancellableStatuses(): array {
        return [['queued'], ['sending'], ['scheduled']];
    }

    #[DataProvider('nonCancellableStatuses')]
    public function test_cancel_invalid_status_returns_400(string $status): void {
        $id  = $this->seedNewsletter($status);
        $res = $this->api->cancelNewsletter($this->req(['id' => $id]));
        $this->assertSame(400, $res->get_status());
        $this->assertSame($status, $GLOBALS['_wpdb_newsletters'][0]['status']);
    }

    public static function nonCancellableStatuses(): array {
        return [['draft'], ['sent'], ['failed'], ['cancelled']];
    }

    public function test_cancel_nonexistent_id_returns_400(): void {
        $res = $this->api->cancelNewsletter($this->req(['id' => 9999]));
        $this->assertSame(400, $res->get_status());
    }

    // ── force-send: queued/sending만 허용 ─────────────────────────────────────

    #[DataProvider('nonForceSendableStatuses')]
    public function test_force_send_invalid_status_returns_400(string $status): void {
        $id  = $this->seedNewsletter($status);
        $res = $this->api->forceSendNewsletter($this->req(['id' => $id]));
        $this->assertSame(400, $res->get_status());
    }

    public static function nonForceSendableStatuses(): array {
        return [['draft'], ['scheduled'], ['sent'], ['failed'], ['cancelled']];
    }

    public function test_force_send_nonexistent_id_returns_400(): void {
        $res = $this->api->forceSendNewsletter($this->req(['id' => 9999]));
        $this->assertSame(400, $res->get_status());
    }

    // ── delete: sending이면 차단, 나머지 허용 ─────────────────────────────────

    public function test_delete_sending_returns_400(): void {
        $id  = $this->seedNewsletter('sending');
        $res = $this->api->deleteNewsletter($this->req(['id' => $id]));
        $this->assertSame(400, $res->get_status());
        // 삭제되지 않았는지
        $this->assertCount(1, $GLOBALS['_wpdb_newsletters']);
    }

    #[DataProvider('deletableStatuses')]
    public function test_delete_non_sending_returns_200(string $status): void {
        $id  = $this->seedNewsletter($status);
        $res = $this->api->deleteNewsletter($this->req(['id' => $id]));
        $this->assertSame(200, $res->get_status());
        $this->assertTrue($res->get_data()['deleted']);
        $this->assertEmpty($GLOBALS['_wpdb_newsletters']);
    }

    public static function deletableStatuses(): array {
        return [['draft'], ['queued'], ['scheduled'], ['sent'], ['failed'], ['cancelled']];
    }

    public function test_delete_nonexistent_id_returns_500(): void {
        $res = $this->api->deleteNewsletter($this->req(['id' => 9999]));
        // 레코드 없음 → delete 반환 0 → 500
        $this->assertSame(500, $res->get_status());
    }

    // ── resend: 레코드/포스트 존재 여부 ──────────────────────────────────────

    public function test_resend_nonexistent_record_returns_404(): void {
        $res = $this->api->resendNewsletter($this->req(['id' => 9999]));
        $this->assertSame(404, $res->get_status());
    }

    public function test_resend_existing_record_but_no_post_returns_400(): void {
        $id = $this->seedNewsletter('sent', 42);
        // post_id=42 는 _wp_posts에 없음 → get_post(42) = null
        $res = $this->api->resendNewsletter($this->req(['id' => $id]));
        $this->assertSame(400, $res->get_status());
    }

    public function test_resend_valid_record_and_post_returns_new_id(): void {
        $postId = 7;
        $GLOBALS['_wp_posts'][$postId] = (object)[
            'ID' => $postId, 'post_title' => 'Test', 'post_status' => 'publish',
        ];
        $id  = $this->seedNewsletter('sent', $postId);
        $res = $this->api->resendNewsletter($this->req(['id' => $id]));

        $this->assertSame(200, $res->get_status());
        $this->assertArrayHasKey('new_id', $res->get_data());
        $this->assertGreaterThan(0, $res->get_data()['new_id']);
    }

    // ── resend-single: 이메일 형식 검증 ───────────────────────────────────────

    public function test_resend_single_invalid_email_returns_400(): void {
        $res = $this->api->resendSingle($this->req(['id' => 1, 'email' => 'not-an-email']));
        $this->assertSame(400, $res->get_status());
    }

    public function test_resend_single_empty_email_returns_400(): void {
        $res = $this->api->resendSingle($this->req(['id' => 1, 'email' => '']));
        $this->assertSame(400, $res->get_status());
    }

    // ── 연속 전환 시나리오 ────────────────────────────────────────────────────

    public function test_draft_send_then_cancel_full_path(): void {
        $id = $this->seedNewsletter('draft');

        // 1. draft → queued
        $sendRes = $this->api->sendNewsletter($this->req(['id' => $id]));
        $this->assertSame(200, $sendRes->get_status());
        $this->assertSame('queued', $GLOBALS['_wpdb_newsletters'][0]['status']);

        // 2. queued → cancelled
        $cancelRes = $this->api->cancelNewsletter($this->req(['id' => $id]));
        $this->assertSame(200, $cancelRes->get_status());
        $this->assertSame('cancelled', $GLOBALS['_wpdb_newsletters'][0]['status']);
    }

    public function test_cannot_send_already_queued_newsletter(): void {
        $id = $this->seedNewsletter('draft');

        // 첫 번째 발송 요청
        $this->api->sendNewsletter($this->req(['id' => $id]));
        $this->assertSame('queued', $GLOBALS['_wpdb_newsletters'][0]['status']);

        // 두 번째 발송 요청 — 이미 queued 이므로 차단
        $res2 = $this->api->sendNewsletter($this->req(['id' => $id]));
        $this->assertSame(400, $res2->get_status());
    }

    public function test_cannot_delete_while_sending(): void {
        $id = $this->seedNewsletter('queued');

        // queued → (sending 으로 직접 전환)
        $GLOBALS['_wpdb_newsletters'][0]['status'] = 'sending';

        $res = $this->api->deleteNewsletter($this->req(['id' => $id]));
        $this->assertSame(400, $res->get_status());
        $this->assertCount(1, $GLOBALS['_wpdb_newsletters']); // 삭제 안 됨
    }

}
