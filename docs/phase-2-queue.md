# Phase 2: 큐 + 배치 발송 안정화 (v2.0)

> 100~1,000명 발송 시 브라우저 타임아웃 없이 처리

---

## 목표

1. WP Cron 기반 비동기 배치 발송
2. 브라우저 응답 2초 이내 (즉시 반환)
3. SMTP 레이트 리밋 자동 준수
4. 실패 이메일 재시도 (최대 3회)
5. 예약 발송 지원

---

## 아키텍처 변경: 즉시 발송 → 큐 기반

### Phase 1 (즉시 발송)
```
발행 → 수신자 조회 → wp_mail() × N → 완료
(N=100이면 수십 초 소요 → 타임아웃 위험)
```

### Phase 2 (큐 기반)
```
발행 → DB 큐 등록 (2초) → HTTP 응답
              ↓
       WP Cron 스케줄
              ↓
       배치 처리 (14~50명씩) → sleep(1초) → 다음 배치
```

---

## 추가 테이블: wp_crmbiz_nl_queue

```sql
CREATE TABLE wp_crmbiz_nl_queue (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    newsletter_id    BIGINT UNSIGNED NOT NULL,
    subscriber_email VARCHAR(191) NOT NULL,
    subscriber_name  VARCHAR(191),
    status           ENUM('pending','sent','failed','skipped') DEFAULT 'pending',
    attempts         TINYINT UNSIGNED DEFAULT 0,
    error_message    TEXT,
    scheduled_at     DATETIME NULL,
    processed_at     DATETIME NULL,
    INDEX idx_newsletter_pending (newsletter_id, status),
    INDEX idx_scheduled          (scheduled_at, status)
);
```

---

## QueueManager.php — 큐 등록 & 스케줄링

```php
namespace CRMBizNewsletter\Queue;

use FluentCrm\App\Services\ContactsQuery;
use CRMBizNewsletter\FluentCRMBridge;

class QueueManager {

    public function enqueue(int $postId, int $newsletterId): void {
        $tagIds  = (array) get_post_meta($postId, '_crmbiz_nl_tag_ids',  true);
        $listIds = (array) get_post_meta($postId, '_crmbiz_nl_list_ids', true);

        // ContactsQuery로 수신자 조회 (offset 방식으로 청크 처리)
        $offset    = 0;
        $chunkSize = 500; // 한 번에 500명씩 DB 삽입
        $total     = 0;

        do {
            $query = new ContactsQuery([
                'tags'     => array_filter($tagIds),
                'lists'    => array_filter($listIds),
                'statuses' => ['subscribed'],
                'limit'    => $chunkSize,
                'offset'   => $offset,
            ]);

            $subscribers = $query->get();
            if ($subscribers->isEmpty()) break;

            $this->bulkInsertQueue($newsletterId, $subscribers);
            $total  += $subscribers->count();
            $offset += $chunkSize;

        } while ($subscribers->count() === $chunkSize);

        // DB 레코드 업데이트
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'crmbiz_newsletters',
            ['recipient_count' => $total, 'status' => 'pending'],
            ['id' => $newsletterId],
            ['%d', '%s'],
            ['%d']
        );

        // 즉시 WP Cron 실행 예약
        $schedAt = get_post_meta($postId, '_crmbiz_nl_scheduled_at', true);
        $runAt   = $schedAt ? strtotime($schedAt) : time();

        wp_schedule_single_event($runAt, 'crmbiz_nl_process_batch', [$newsletterId]);
    }

    private function bulkInsertQueue(int $newsletterId, $subscribers): void {
        global $wpdb;

        // 수신거부 목록 미리 로드 (N+1 방지)
        $emails     = $subscribers->pluck('email')->toArray();
        $unsubEmails = $this->getUnsubscribedEmails($emails);

        $rows = [];
        foreach ($subscribers as $s) {
            if (in_array($s->email, $unsubEmails, true)) {
                continue; // 수신거부자 건너뜀
            }
            $rows[] = $wpdb->prepare(
                '(%d, %s, %s, %s)',
                $newsletterId,
                $s->email,
                $s->full_name,
                'pending'
            );
        }

        if (empty($rows)) return;

        // 배치 INSERT (성능)
        $wpdb->query(
            "INSERT INTO {$wpdb->prefix}crmbiz_nl_queue
             (newsletter_id, subscriber_email, subscriber_name, status)
             VALUES " . implode(',', $rows)
        );
    }

    private function getUnsubscribedEmails(array $emails): array {
        global $wpdb;
        if (empty($emails)) return [];

        $placeholders = implode(',', array_fill(0, count($emails), '%s'));
        return $wpdb->get_col(
            $wpdb->prepare(
                "SELECT email FROM {$wpdb->prefix}crmbiz_nl_unsubscribers WHERE email IN ($placeholders)",
                ...$emails
            )
        );
    }
}
```

---

## BatchProcessor.php — 배치 실행

```php
namespace CRMBizNewsletter\Queue;

class BatchProcessor {

    // SMTP 프로바이더별 배치 크기 권장값
    private const BATCH_SIZES = [
        'sendgrid' => 100,
        'mailgun'  => 100,
        'ses'      => 50,
        'gmail'    => 10,
        'default'  => 20,
    ];

    public function processBatch(int $newsletterId): void {
        $batchSize = $this->getBatchSize();
        $post      = $this->getNewsletterPost($newsletterId);

        if (!$post) return;

        // 처리할 대기 항목 가져오기
        $items = $this->getPendingItems($newsletterId, $batchSize);

        if (empty($items)) {
            $this->markNewsletterComplete($newsletterId);
            return;
        }

        foreach ($items as $item) {
            $success = $this->processItem($post, $item);

            global $wpdb;
            $wpdb->update(
                $wpdb->prefix . 'crmbiz_nl_queue',
                [
                    'status'       => $success ? 'sent' : 'failed',
                    'attempts'     => $item->attempts + 1,
                    'processed_at' => current_time('mysql'),
                ],
                ['id' => $item->id],
                ['%s', '%d', '%s'],
                ['%d']
            );
        }

        // 남은 항목이 있으면 다음 배치 예약 (1초 간격)
        $remaining = $this->countPending($newsletterId);
        if ($remaining > 0) {
            wp_schedule_single_event(
                time() + 1,
                'crmbiz_nl_process_batch',
                [$newsletterId]
            );
        } else {
            $this->markNewsletterComplete($newsletterId);

            // 실패 항목 재시도 예약 (3회 미만인 경우)
            $this->scheduleRetries($newsletterId);
        }
    }

    private function getPendingItems(int $newsletterId, int $limit): array {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}crmbiz_nl_queue
                 WHERE newsletter_id = %d
                   AND status = 'pending'
                   AND (scheduled_at IS NULL OR scheduled_at <= %s)
                 ORDER BY id ASC
                 LIMIT %d
                 FOR UPDATE",
                $newsletterId,
                current_time('mysql'),
                $limit
            )
        );
    }

    private function scheduleRetries(int $newsletterId): void {
        global $wpdb;

        // 3회 미만 실패 항목을 pending으로 되돌리고 1분 후 재시도
        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->prefix}crmbiz_nl_queue
                 SET status = 'pending', scheduled_at = DATE_ADD(NOW(), INTERVAL 60 SECOND)
                 WHERE newsletter_id = %d AND status = 'failed' AND attempts < 3",
                $newsletterId
            )
        );

        if ($updated > 0) {
            wp_schedule_single_event(
                time() + 60,
                'crmbiz_nl_process_batch',
                [$newsletterId]
            );

            // 관리자 알림 이메일
            wp_mail(
                get_option('admin_email'),
                '[CRMBiz Newsletter] 발송 실패 — 재시도 예약됨',
                "{$updated}건 발송 실패. 1분 후 자동 재시도합니다.\n뉴스레터 ID: {$newsletterId}"
            );
        }
    }

    private function getBatchSize(): int {
        $provider = get_option('crmbiz_nl_smtp_provider', 'default');
        return self::BATCH_SIZES[$provider] ?? self::BATCH_SIZES['default'];
    }
}
```

---

## Plugin.php 추가 훅 (Phase 2)

```php
// WP Cron 훅 등록
add_action('crmbiz_nl_process_batch', [new Queue\BatchProcessor(), 'processBatch']);

// transition_post_status 수정 — 즉시 발송 대신 큐 등록
public function onPostPublished(string $newStatus, string $oldStatus, \WP_Post $post): void {
    if ($newStatus !== 'publish' || $oldStatus === 'publish') return;
    if (!get_post_meta($post->ID, '_crmbiz_nl_enabled', true)) return;

    $sendMode = get_post_meta($post->ID, '_crmbiz_nl_send_mode', true) ?: 'immediate';

    if (in_array($sendMode, ['immediate', 'scheduled'], true)) {
        $newsletterId = $this->sender->createNewsletterRecord($post->ID, ...);
        (new Queue\QueueManager())->enqueue($post->ID, $newsletterId);
    }
    // manual: HistoryPage에서 수동 트리거
}
```

---

## 예약 발송 처리

```php
// 예약 발송: 저장 시 메타에 기록, 매분 체크하는 WP Cron으로 처리
add_action('crmbiz_nl_check_scheduled', function() {
    global $wpdb;

    $due = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}crmbiz_newsletters
             WHERE status = 'pending'
               AND send_mode = 'scheduled'
               AND scheduled_at <= %s",
            current_time('mysql')
        )
    );

    foreach ($due as $row) {
        wp_schedule_single_event(time(), 'crmbiz_nl_process_batch', [$row->id]);
    }
});

// 1분마다 스케줄 체크
if (!wp_next_scheduled('crmbiz_nl_check_scheduled')) {
    wp_schedule_event(time(), 'every_minute', 'crmbiz_nl_check_scheduled');
}
```

---

## 발송 설정 페이지 (Phase 2 추가)

| 설정 항목 | 설명 | 기본값 |
|---|---|---|
| SMTP 프로바이더 | sendgrid / mailgun / ses / gmail | default |
| 배치 크기 | 1회 배치당 발송 수 (자동 or 수동) | 프로바이더별 |
| 배치 간격 | 초 단위 딜레이 | 1초 |
| 최대 재시도 횟수 | 실패 시 재시도 | 3회 |
| 관리자 알림 이메일 | 실패 알림 수신 주소 | admin_email |

---

## Phase 2 완료 기준

- [ ] 포스트 발행 후 2초 이내 HTTP 응답 반환
- [ ] 100명 발송이 백그라운드에서 완료됨
- [ ] 500명 발송 성공 (배치 분할 확인)
- [ ] 예약 발송이 지정 시각에 실행됨
- [ ] 실패 이메일 자동 재시도됨 (최대 3회)
- [ ] 관리자 알림 이메일 수신
- [ ] HistoryPage에서 실시간 진행률 표시
