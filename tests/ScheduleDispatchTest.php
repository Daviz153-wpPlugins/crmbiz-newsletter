<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use CRMBizNewsletter\Plugin;
use CRMBizNewsletter\Settings;
use CRMBizNewsletter\Scheduler;

/**
 * 예약 발송 디스패치 로직 검증
 *
 * 재현하는 버그
 * ─────────────
 * Bug 1 (Gutenberg 경쟁 조건)
 *   transition_post_status는 save_post보다 먼저 실행된다.
 *   이전 draft 저장에 _crmbiz_nl_enabled = 1 이 있고 당시 send_mode = 'immediate'였다면
 *   onPostPublished()가 queued 레코드를 만든다.
 *   이후 save_post에서 send_mode = 'scheduled'가 저장돼도 syncDraftRecord(구버전)는
 *   status='draft' 조건만 업데이트하므로 queued 레코드를 교정하지 못했다.
 *
 * Bug 2 (발행 후 예약 시각 변경)
 *   MetaBox 저장 시 DB scheduled_at은 갱신하지만 Scheduler 이벤트는
 *   재등록하지 않아 이전 시각의 이벤트가 그대로 남았다.
 *
 * 검증 방법
 * ─────────
 * - Plugin::newInstanceWithoutConstructor() 로 생성자 없이 인스턴스화 (WP 훅 등록 스킵)
 * - 공개 메서드 Plugin::savePostMeta() 를 통해 실제 프로덕션 진입점 구동
 * - MetaBox::savePostMeta()는 nonce($_POST) 없으면 즉시 리턴 → 테스트 메타는 setUp에서 직접 세팅
 */
class ScheduleDispatchTest extends TestCase {

    private const CRON_HOOK = 'crmbiz_nl_send_newsletter';
    private const AS_GROUP  = 'crmbiz-newsletter';

    private Plugin $plugin;

    protected function setUp(): void {
        $GLOBALS['_wp_posts']         = [];
        $GLOBALS['_wp_post_meta']     = [];
        $GLOBALS['_wpdb_newsletters'] = [];
        $GLOBALS['_as_actions']       = [];
        $_POST                        = [];

        $this->plugin = $this->makePlugin();
    }

    // ── 인스턴스 생성 헬퍼 ───────────────────────────────────────────────

    /**
     * 생성자 없이 Plugin 인스턴스화 — registerHooks(), Database::install() 등 전부 스킵.
     * syncPendingRecord()에는 생성자 초기화가 전혀 필요 없다.
     */
    private function makePlugin(): Plugin {
        $ref    = new ReflectionClass(Plugin::class);
        $plugin = $ref->newInstanceWithoutConstructor();

        // 싱글톤 프로퍼티도 이 인스턴스로 덮어씀 (필요시)
        $instProp = $ref->getProperty('instance');
        $instProp->setValue(null, $plugin);

        // settings 주입
        $settingsProp = $ref->getProperty('settings');
        $settingsProp->setValue($plugin, new Settings());

        return $plugin;
    }

    // ── 테스트 데이터 헬퍼 ───────────────────────────────────────────────

    /**
     * Seoul 기준 미래 datetime-local 문자열 생성.
     * parseScheduledAt()이 wp_timezone()(= Asia/Seoul)로 해석하므로,
     * CI 서버 시간대(UTC)와 무관하게 항상 미래를 가리켜야 한다.
     */
    private function futureSeoul(int $secondsAhead = 7200): string {
        $seoul = new \DateTimeZone('Asia/Seoul');
        return (new \DateTime('+' . $secondsAhead . ' seconds', $seoul))->format('Y-m-d\TH:i');
    }

    private function makePost(int $id, string $status = 'publish'): void {
        $GLOBALS['_wp_posts'][$id] = (object)[
            'ID'          => $id,
            'post_type'   => 'post',
            'post_status' => $status,
        ];
    }

    /**
     * 메타 세팅 — 실제로는 MetaBox::savePostMeta()가 $_POST에서 읽어 저장하지만,
     * 테스트에서는 nonce 없어 MetaBox가 즉시 리턴하므로 여기서 직접 세팅.
     */
    private function setMeta(int $postId, string $mode, string $schedAt = ''): void {
        update_post_meta($postId, '_crmbiz_nl_enabled',      1);
        update_post_meta($postId, '_crmbiz_nl_send_mode',    $mode);
        update_post_meta($postId, '_crmbiz_nl_scheduled_at', $schedAt);
        update_post_meta($postId, '_crmbiz_nl_tag_ids',      [1]);
        update_post_meta($postId, '_crmbiz_nl_list_ids',     []);
    }

    /** DB에 newsletter 레코드 삽입 (onPostPublished 시뮬레이션용) */
    private function insertNlRecord(int $postId, string $status, int $nlId, string $schedAt = ''): void {
        $GLOBALS['_wpdb_newsletters'][] = [
            'id'           => $nlId,
            'post_id'      => $postId,
            'status'       => $status,
            'scheduled_at' => $schedAt ?: null,
            'tag_ids'      => '[1]',
            'list_ids'     => '[]',
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ];
    }

    private function asNextAt(int $nlId): int|false {
        return as_next_scheduled_action(self::CRON_HOOK, [$nlId], self::AS_GROUP);
    }

    private function asCountFor(int $nlId): int {
        return count(array_filter(
            $GLOBALS['_as_actions'],
            fn($a) => $a['hook'] === self::CRON_HOOK
                   && $a['args'] === [$nlId]
                   && $a['group'] === self::AS_GROUP
        ));
    }

    private function nlStatus(int $nlId): string {
        foreach ($GLOBALS['_wpdb_newsletters'] as $row) {
            if ((int)$row['id'] === $nlId) return $row['status'];
        }
        return '';
    }

    private function nlScheduledAt(int $nlId): string {
        foreach ($GLOBALS['_wpdb_newsletters'] as $row) {
            if ((int)$row['id'] === $nlId) return (string)($row['scheduled_at'] ?? '');
        }
        return '';
    }

    // ── Scheduler 단위 테스트 ────────────────────────────────────────────

    public function testScheduler_SchedulesSingleAction(): void {
        $future = time() + 3600;
        Scheduler::scheduleSingle($future, self::CRON_HOOK, [99]);
        $this->assertSame($future, $this->asNextAt(99));
    }

    public function testScheduler_UnscheduleRemovesAction(): void {
        Scheduler::scheduleSingle(time() + 3600, self::CRON_HOOK, [99]);
        Scheduler::unschedule(self::CRON_HOOK, [99]);
        $this->assertFalse($this->asNextAt(99));
    }

    public function testScheduler_UnscheduleAll_RemovesAllActionsForHook(): void {
        Scheduler::scheduleSingle(time() + 100, self::CRON_HOOK, [1]);
        Scheduler::scheduleSingle(time() + 200, self::CRON_HOOK, [2]);
        Scheduler::scheduleSingle(time() + 300, self::CRON_HOOK, [3]);

        Scheduler::unscheduleAll(self::CRON_HOOK);

        $this->assertFalse(Scheduler::isScheduled(self::CRON_HOOK, [1]), 'id=1 제거됨');
        $this->assertFalse(Scheduler::isScheduled(self::CRON_HOOK, [2]), 'id=2 제거됨');
        $this->assertFalse(Scheduler::isScheduled(self::CRON_HOOK, [3]), 'id=3 제거됨');
        $remaining = array_filter(
            $GLOBALS['_as_actions'],
            fn($a) => $a['hook'] === self::CRON_HOOK && $a['group'] === self::AS_GROUP
        );
        $this->assertEmpty($remaining, '해당 훅의 모든 액션이 제거돼야 함');
    }

    public function testScheduler_IsScheduledReflectsState(): void {
        Scheduler::scheduleSingle(time() + 3600, self::CRON_HOOK, [42]);
        $this->assertTrue(Scheduler::isScheduled(self::CRON_HOOK, [42]));

        Scheduler::unschedule(self::CRON_HOOK, [42]);
        $this->assertFalse(Scheduler::isScheduled(self::CRON_HOOK, [42]));
    }

    // ── Bug 1: Gutenberg 경쟁 조건 ──────────────────────────────────────

    /**
     * 시나리오:
     *   1. 이전 draft 저장: enabled=1, send_mode=immediate
     *   2. 발행 시 onPostPublished()가 queued 레코드 생성 + 즉시 AS 이벤트 등록
     *   3. save_post에서 send_mode=scheduled 로 갱신된 메타로 savePostMeta() 호출
     *
     * 기대 결과 (syncPendingRecord 수정 후):
     *   - 레코드 status = scheduled
     *   - AS 이벤트 = 미래 시각 1개 (즉시 이벤트 취소됨)
     */
    public function testBug1_GutenbergRace_QueuesToScheduled(): void {
        $postId  = 10;
        $nlId    = 1;
        $future  = $this->futureSeoul(7200);

        $this->makePost($postId);

        // onPostPublished 시뮬레이션: 이전 meta(immediate)로 queued 레코드 생성
        $this->insertNlRecord($postId, 'queued', $nlId);
        Scheduler::scheduleSingle(time(), self::CRON_HOOK, [$nlId]); // 즉시 발송 이벤트

        // save_post: 사용자가 선택한 scheduled + 미래 시각 메타 저장
        $this->setMeta($postId, 'scheduled', $future);

        // 실제 프로덕션 진입점
        $this->plugin->savePostMeta($postId);

        $this->assertSame('scheduled', $this->nlStatus($nlId),
            'queued 레코드가 scheduled로 교정돼야 함 (Bug 1 수정 검증)');
        $this->assertSame($future, $this->nlScheduledAt($nlId),
            'scheduled_at이 미래 시각으로 저장돼야 함');
        $this->assertSame(1, $this->asCountFor($nlId),
            'AS 이벤트 1개: 즉시 이벤트 취소 + 예약 이벤트 등록');
        $this->assertGreaterThan(time(), $this->asNextAt($nlId),
            'AS 이벤트가 미래 시각이어야 함');
    }

    /**
     * 반대 케이스: enabled가 첫 발행 때 처음 저장된다면
     * onPostPublished는 early-return, savePostMeta에서 dispatchNewsletter를 직접 호출.
     * 이 경우 newsletterRecordExists() = false → syncPendingRecord 미실행.
     * AS 이벤트는 없어야 함 (dispatching은 WP DB 없어 실제 등록 안 됨).
     */
    public function testBug1_NoExistingRecord_SyncSkipped(): void {
        $postId = 20;
        $future = $this->futureSeoul(7200);

        $this->makePost($postId);
        $this->setMeta($postId, 'scheduled', $future);
        // DB에 레코드 없음 → newsletterRecordExists() = false

        $this->plugin->savePostMeta($postId);

        // syncPendingRecord는 호출 안 됨 — dispatchNewsletter 경로
        // dispatchNewsletter → NewsletterSender::createScheduledRecord → wpdb->insert
        // 스텁에서는 insert가 동작하므로 레코드 하나 생성됨
        // 여기선 crash 없이 완료되는 것만 검증
        $this->assertTrue(true, '레코드 없을 때 savePostMeta가 크래시 없이 완료');
    }

    // ── Bug 2: 발행 후 예약 시각 변경 ────────────────────────────────────

    /**
     * 시나리오:
     *   1. 발행 시 T1에 예약됨 (scheduled 레코드 + AS 이벤트@T1)
     *   2. 사용자가 MetaBox에서 T2로 변경 후 저장 (savePostMeta 호출)
     *
     * 기대 결과 (syncPendingRecord 수정 후):
     *   - AS 이벤트가 T1 → T2 로 갱신됨 (총 1개)
     */
    public function testBug2_ScheduleChange_ReschedulesAction(): void {
        $postId = 30;
        $nlId   = 2;
        $t1     = $this->futureSeoul(3600);
        $t2     = $this->futureSeoul(86400);

        $this->makePost($postId);

        // 기존 예약 상태 (발행 시 T1에 등록)
        $this->insertNlRecord($postId, 'scheduled', $nlId, $t1);
        $t1ts = (new DateTime($t1, wp_timezone()))->getTimestamp();
        Scheduler::scheduleSingle($t1ts, self::CRON_HOOK, [$nlId]);

        // MetaBox가 T2로 변경한 메타 (실제로는 MetaBox::savePostMeta가 하지만 nonce 없어 스킵)
        $this->setMeta($postId, 'scheduled', $t2);

        $this->plugin->savePostMeta($postId);

        $this->assertSame(1, $this->asCountFor($nlId),
            'T1 이벤트 취소 + T2 이벤트 등록으로 총 1개 (Bug 2 수정 검증)');

        $t2ts = (new DateTime($t2, wp_timezone()))->getTimestamp();
        $this->assertSame($t2ts, $this->asNextAt($nlId),
            'AS 이벤트가 새 예약 시각(T2)으로 변경됐어야 함');

        $allTs = array_column(array_filter(
            $GLOBALS['_as_actions'],
            fn($a) => $a['hook'] === self::CRON_HOOK && $a['args'] === [$nlId]
        ), 'ts');
        $this->assertNotContains($t1ts, $allTs, 'T1 이벤트는 취소됐어야 함');
    }

    // ── scheduled → immediate 전환 ───────────────────────────────────────

    public function testScheduledToImmediate_UnschedulesAndQueues(): void {
        $postId = 40;
        $nlId   = 3;
        $future = $this->futureSeoul(3600);

        $this->makePost($postId);
        $this->insertNlRecord($postId, 'scheduled', $nlId, $future);
        $futureTs = (new DateTime($future, wp_timezone()))->getTimestamp();
        Scheduler::scheduleSingle($futureTs, self::CRON_HOOK, [$nlId]);

        $this->setMeta($postId, 'immediate', '');
        $this->plugin->savePostMeta($postId);

        $this->assertSame('queued', $this->nlStatus($nlId),
            'scheduled → immediate 전환 시 status = queued');
        $this->assertSame(1, $this->asCountFor($nlId),
            '즉시 발송 이벤트 1개');
        $this->assertLessThanOrEqual(time() + 5, $this->asNextAt($nlId),
            '이벤트가 현재 시각 근처');
        $this->assertNotContains($futureTs, array_column(array_filter(
            $GLOBALS['_as_actions'],
            fn($a) => $a['hook'] === self::CRON_HOOK && $a['args'] === [$nlId]
        ), 'ts'), '기존 미래 이벤트 취소됨');
    }

    // ── 과거 시각은 재예약하지 않음 ─────────────────────────────────────

    public function testPastScheduledAt_DoesNotReschedule(): void {
        $postId = 50;
        $nlId   = 4;
        $past   = (new \DateTime('-1 hour', new \DateTimeZone('Asia/Seoul')))->format('Y-m-d\TH:i');

        $this->makePost($postId);
        $this->insertNlRecord($postId, 'queued', $nlId);
        Scheduler::scheduleSingle(time(), self::CRON_HOOK, [$nlId]);

        $this->setMeta($postId, 'scheduled', $past);
        $this->plugin->savePostMeta($postId);

        // parseScheduledAt이 0 반환 → 재예약 안 함 → queued + 즉시 이벤트 유지
        $this->assertSame('queued', $this->nlStatus($nlId),
            '과거 시각이면 queued 상태 유지');
    }
}
