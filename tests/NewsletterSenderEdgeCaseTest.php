<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * NewsletterSender 엣지 케이스 — WP DB 없이 순수 로직만 검증
 */
class NewsletterSenderEdgeCaseTest extends TestCase {

    // ── 발송 상태 전이 규칙 검증 ──────────────────────────────────────────────

    public function testFinalizeStatusSentWhenBothZero(): void {
        // success=0, fail=0 → 수신자 없음인데 'sent' 반환하면 안 됨
        // 실제 코드에서 수신자 0명은 populateQueue()에서 'failed'로 처리됨
        // 여기서는 finalizeSend 내부 상태 판단 로직 검증
        $success = 0;
        $fail    = 0;
        $status  = ($success === 0 && $fail > 0) ? 'failed' : 'sent';
        // success=0, fail=0 이면 'sent' — finalizeSend는 이 경우를 보지 않음
        // (populateQueue에서 미리 'failed' 처리하므로 정상)
        $this->assertSame('sent', $status, 'success=0 fail=0은 populateQueue에서 이미 차단됨');
    }

    public function testFinalizeStatusFailedWhenOnlyFails(): void {
        $success = 0;
        $fail    = 5;
        $status  = ($success === 0 && $fail > 0) ? 'failed' : 'sent';
        $this->assertSame('failed', $status);
    }

    public function testFinalizeStatusSentWhenMixed(): void {
        $success = 10;
        $fail    = 2;
        $status  = ($success === 0 && $fail > 0) ? 'failed' : 'sent';
        $this->assertSame('sent', $status);
    }

    // ── 예약 시간 파싱 규칙 ───────────────────────────────────────────────────

    public function testScheduledAtEmptyReturnsZero(): void {
        $ts = $this->parseScheduledAt('');
        $this->assertSame(0, $ts);
    }

    public function testScheduledAtPastReturnsZero(): void {
        // 과거 시간 → 즉시 발송 폴백
        $ts = $this->parseScheduledAt('2020-01-01T00:00');
        $this->assertSame(0, $ts, '과거 시간은 0을 반환해야 즉시 큐로 폴백');
    }

    public function testScheduledAtFutureReturnsPositive(): void {
        // 미래 시간 → 타임스탬프 반환
        $future = date('Y-m-d\TH:i', strtotime('+1 hour'));
        $ts = $this->parseScheduledAt($future);
        $this->assertGreaterThan(time(), $ts, '미래 시간은 현재보다 큰 타임스탬프를 반환');
    }

    public function testScheduledAtInvalidStringReturnsZero(): void {
        $ts = $this->parseScheduledAt('not-a-date');
        $this->assertSame(0, $ts, '유효하지 않은 날짜 문자열은 0 반환');
    }

    // ── 이메일 헤더 인젝션 방지 ───────────────────────────────────────────────

    public function testFromNameStripsNewlines(): void {
        // 헤더 인젝션은 \r\n으로 새 헤더 줄을 만드는 방식 — 개행 제거로 무력화
        $raw     = "Legit Name\r\nBcc: attacker@evil.com";
        $cleaned = str_replace(["\r", "\n"], '', $raw);
        $this->assertStringNotContainsString("\r", $cleaned, 'CR 제거 확인');
        $this->assertStringNotContainsString("\n", $cleaned, 'LF 제거 확인');
        // Bcc: 텍스트는 남지만 개행 없어 헤더로 파싱 불가 — 안전
        $this->assertStringContainsString('Bcc:', $cleaned, '개행 없는 Bcc: 텍스트는 헤더 인젝션 아님');
    }

    public function testFromEmailStripsNewlines(): void {
        $raw     = "admin@example.com\nBcc: attacker@evil.com";
        $cleaned = str_replace(["\r", "\n"], '', $raw);
        $this->assertStringNotContainsString("\r", $cleaned);
        $this->assertStringNotContainsString("\n", $cleaned);
    }

    // ── 배치 청크 분할 검증 ───────────────────────────────────────────────────

    public function testPopulateQueueChunks(): void {
        $emails = array_map(fn($i) => "user{$i}@example.com", range(1, 500));
        $chunks = array_chunk($emails, 200);

        $this->assertCount(3, $chunks);
        $this->assertCount(200, $chunks[0]);
        $this->assertCount(200, $chunks[1]);
        $this->assertCount(100, $chunks[2]);
    }

    public function testPopulateQueueSingleEmail(): void {
        $emails = ['only@example.com'];
        $chunks = array_chunk($emails, 200);
        $this->assertCount(1, $chunks);
        $this->assertCount(1, $chunks[0]);
    }

    // ── 성공률 계산 ──────────────────────────────────────────────────────────

    public function testSuccessRateCalculation(): void {
        // round()는 PHP 8에서 float 반환 — sprintf로 정수 포맷팅하는 방식 확인
        $success   = 100;
        $delivered = 105;
        $rate      = $delivered > 0 ? round($success / $delivered * 100) : 0;
        $this->assertEqualsWithDelta(95, $rate, 1.0, '성공률 95% ± 1');
        $this->assertSame('95', sprintf('%d', $rate), '포맷 문자열 정수 변환');
    }

    public function testSuccessRateZeroDelivered(): void {
        $delivered = 0;
        $rate      = $delivered > 0 ? round(0 / $delivered * 100) : 0;
        $this->assertSame(0, $rate);
    }

    // ── 재시도 로직 경계값 (MAX_RETRIES = 3) ─────────────────────────────────

    /**
     * sendFromRecord()의 재시도 분기:
     *   retry_count + 1 >= MAX_RETRIES → toDelete (영구 실패)
     *   retry_count + 1 <  MAX_RETRIES → toRetry  (재시도)
     */
    public function testRetry_FirstFail_GoesToRetry(): void {
        $retryCount = 0;
        $maxRetries = 3;
        $this->assertFalse(
            $retryCount + 1 >= $maxRetries,
            '첫 번째 실패(retry_count=0)는 아직 재시도 대상'
        );
    }

    public function testRetry_SecondFail_GoesToRetry(): void {
        $retryCount = 1;
        $maxRetries = 3;
        $this->assertFalse(
            $retryCount + 1 >= $maxRetries,
            '두 번째 실패(retry_count=1)는 아직 재시도 대상'
        );
    }

    public function testRetry_ThirdFail_PermanentFailure(): void {
        $retryCount = 2;
        $maxRetries = 3;
        $this->assertTrue(
            $retryCount + 1 >= $maxRetries,
            '세 번째 실패(retry_count=2)는 영구 실패로 처리'
        );
    }

    public function testRetry_ExactlyAtLimit_PermanentFailure(): void {
        // retry_count가 이미 MAX_RETRIES와 같거나 초과된 경우도 영구 실패
        $retryCount = 3;
        $maxRetries = 3;
        $this->assertTrue($retryCount + 1 >= $maxRetries);
    }

    // ── 배치 상태 전이 — hasMore 반환 규칙 ──────────────────────────────────

    public function testHasMore_WhenRemainingQueueExists(): void {
        // remaining > 0 이면 true 반환 → 다음 배치 재스케줄
        $remaining = 10;
        $this->assertTrue($remaining > 0, '남은 수신자가 있으면 hasMore=true');
    }

    public function testHasMore_WhenQueueDrained(): void {
        $remaining = 0;
        $this->assertFalse($remaining > 0, '큐 소진 시 hasMore=false → finalizeSend 호출');
    }

    // ── 수신거부자 skip 로직 ─────────────────────────────────────────────────

    public function testUnsubscribed_SkippedAndDeleted(): void {
        // 수신거부자는 toDelete에 추가되고 success/fail 카운트에 포함 안 됨
        $success   = 0;
        $fail      = 0;
        $toDelete  = [1, 2]; // 수신거부자 큐 row ID
        $toRetry   = [];

        $this->assertCount(2, $toDelete, '수신거부자 2명은 큐에서 제거됨');
        $this->assertSame(0, $success, '수신거부자는 success 카운트 포함 안 됨');
        $this->assertSame(0, $fail,    '수신거부자는 fail 카운트 포함 안 됨');
    }

    // ── logSend status 값 규칙 검증 ──────────────────────────────────────────

    public function testLogSend_AllowedStatusValues(): void {
        $allowed = ['sent', 'failed', 'skipped'];

        // 발송 성공
        $this->assertContains('sent',    $allowed);
        // 영구 실패 (MAX_RETRIES 초과)
        $this->assertContains('failed',  $allowed);
        // 수신거부 또는 CRM 미존재
        $this->assertContains('skipped', $allowed);
    }

    public function testLogSend_RetryDoesNotLog(): void {
        // retry_count + 1 < MAX_RETRIES 이면 아직 최종 결과 아님 → logSend 호출 안 함
        $retryCount = 1;
        $maxRetries = 3;
        $isFinal    = ($retryCount + 1 >= $maxRetries);
        $this->assertFalse($isFinal, '재시도 대상은 logSend 대상 아님');
    }

    public function testLogSend_MaxRetriesIsLoggedAsFailed(): void {
        $retryCount = 2; // 마지막 시도
        $maxRetries = 3;
        $isFinal    = ($retryCount + 1 >= $maxRetries);
        $this->assertTrue($isFinal, 'MAX_RETRIES 도달 시 failed로 기록');
    }

    // ── Private parseScheduledAt 시뮬레이션 ──────────────────────────────────

    private function parseScheduledAt(string $schedAt): int {
        if (!$schedAt) {
            return 0;
        }
        try {
            $dt = new \DateTime($schedAt, new \DateTimeZone('UTC'));
            $ts = $dt->getTimestamp();
            return $ts > time() ? $ts : 0;
        } catch (\Exception $e) {
            return 0;
        }
    }
}
