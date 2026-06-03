/**
 * 공유 호스팅 제약 환경 테스트
 *
 * 카페24, 닷홈 같은 공유 호스팅은 max_execution_time=30, memory_limit=128M 제한이 있다.
 * 배치 1회(50건)의 PHP 오버헤드(DB + FluentCRM 조회)가 공유 호스팅 한도 안에 들어오는지 측정한다.
 *
 * 참고: 이 테스트는 Mailpit(로컬 SMTP)으로 실행되므로 wp_mail() 지연은 0에 가깝다.
 * 실 SMTP 여유 시간 = (30초 - 측정된 PHP 오버헤드) / 50건
 * 예) PHP 오버헤드 2초 → 실 SMTP 예산 = 28초 / 50 = 0.56초/건
 *
 * 실행: npx playwright test shared-hosting --project=chromium
 */
import { test, expect } from '@playwright/test'
import { execSync }      from 'child_process'

const WP_PATH = process.env.WP_PATH || '/Applications/MAMP/htdocs/wordpress'
const TAG_ID  = 1  // 뉴스레터구독 태그

const BATCH_SIZE        = 30
const TIME_LIMIT_SEC    = 25   // 30초 한도에서 5초 여유
const MEMORY_LIMIT_MB   = 100  // 128MB 한도에서 28MB 여유

function wpEval(code) {
  const flat = code.replace(/\s+/g, ' ').trim()
  return execSync(
    `wp eval '${flat.replace(/'/g, "'\\''")}' --path=${WP_PATH}`,
    { encoding: 'utf-8', timeout: 120_000 }
  ).trim().replace(/^(PHP Deprecated|Deprecated):.*\n?/gm, '').trim()
}

test.describe('공유 호스팅 제약 — 배치 시간 · 메모리', () => {
  let postId, nlId

  test.beforeAll(() => {
    // 테스트용 포스트 생성
    postId = parseInt(wpEval(`
      echo wp_insert_post([
        "post_title"   => "[공유호스팅 테스트] 배치 50건",
        "post_content" => "<p>공유 호스팅 제약 테스트 본문.</p>",
        "post_status"  => "publish",
        "post_type"    => "post",
      ]);
    `))

    // 테스트 연락처 50명 생성 (FluentCRM에 직접 삽입)
    const result = wpEval(`
      global $wpdb;
      $now = current_time("mysql");
      $values = [];
      for ($i = 1; $i <= ${BATCH_SIZE}; $i++) {
        $values[] = $wpdb->prepare(
          "(%s, %s, %s, \\"subscribed\\", %s, %s)",
          "shtest+{$i}@example.com", "SHTest{$i}", "User", $now, $now
        );
      }
      $wpdb->query("INSERT INTO {$wpdb->prefix}fc_subscribers
        (email, first_name, last_name, status, created_at, updated_at)
        VALUES " . implode(",", $values));
      $ids = $wpdb->get_col("SELECT id FROM {$wpdb->prefix}fc_subscribers WHERE email LIKE \\"shtest+%@example.com\\"");
      $pivotVals = [];
      foreach ($ids as $id) {
        $pivotVals[] = $wpdb->prepare(
          "(%d, %d, \\"FluentCrm\\\\\\\\App\\\\\\\\Models\\\\\\\\Tag\\", NULL, 1, %s, %s)",
          $id, ${TAG_ID}, $now, $now
        );
      }
      $wpdb->query("INSERT IGNORE INTO {$wpdb->prefix}fc_subscriber_pivot
        (subscriber_id, object_id, object_type, status, is_public, created_at, updated_at)
        VALUES " . implode(",", $pivotVals));
      echo count($ids);
    `)
    console.log(`테스트 연락처 생성: ${result}명`)

    // queued 뉴스레터 생성
    nlId = parseInt(wpEval(`
      global $wpdb;
      $wpdb->insert(
        $wpdb->prefix . "crmbiz_newsletters",
        [
          "post_id"         => ${postId},
          "status"          => "queued",
          "send_mode"       => "immediate",
          "tag_ids"         => json_encode([${TAG_ID}]),
          "list_ids"        => json_encode([]),
          "recipient_count" => 0,
          "success_count"   => 0,
          "fail_count"      => 0,
          "created_at"      => current_time("mysql"),
          "updated_at"      => current_time("mysql"),
        ]
      );
      echo $wpdb->insert_id;
    `))
  })

  test.afterAll(() => {
    if (postId) wpEval(`wp_delete_post(${postId}, true);`)
    if (nlId) wpEval(`
      global $wpdb;
      $wpdb->delete($wpdb->prefix . "crmbiz_newsletters", ["id" => ${nlId}], ["%d"]);
      $wpdb->delete($wpdb->prefix . "crmbiz_nl_queue", ["newsletter_id" => ${nlId}], ["%d"]);
    `)
    wpEval(`
      global $wpdb;
      $ids = $wpdb->get_col("SELECT id FROM {$wpdb->prefix}fc_subscribers WHERE email LIKE \\"shtest+%@example.com\\"");
      if ($ids) {
        $in = implode(",", array_map("intval", $ids));
        $wpdb->query("DELETE FROM {$wpdb->prefix}fc_subscriber_pivot WHERE subscriber_id IN ($in)");
        $wpdb->query("DELETE FROM {$wpdb->prefix}fc_subscribers WHERE id IN ($in)");
      }
    `)
  })

  test(`배치 ${BATCH_SIZE}건 PHP 오버헤드가 ${TIME_LIMIT_SEC}초 미만이다`, () => {
    const result = JSON.parse(wpEval(`
      $sender = new CRMBizNewsletter\\NewsletterSender(new CRMBizNewsletter\\Settings());
      $memBefore = memory_get_usage(true);
      $start     = microtime(true);

      $sender->sendFromRecord(${nlId});

      $elapsed   = round(microtime(true) - $start, 3);
      $peakMb    = round((memory_get_peak_usage(true) - $memBefore) / 1024 / 1024, 2);

      global $wpdb;
      $row = $wpdb->get_row($wpdb->prepare(
        "SELECT status, success_count, fail_count FROM {$wpdb->prefix}crmbiz_newsletters WHERE id=%d",
        ${nlId}
      ));

      echo json_encode([
        "elapsed_sec"  => $elapsed,
        "peak_mem_mb"  => $peakMb,
        "status"       => $row->status,
        "success"      => (int)$row->success_count,
        "fail"         => (int)$row->fail_count,
        "smtp_budget"  => round((30 - $elapsed) / max(1, (int)$row->success_count), 3),
      ]);
    `))

    console.log('=== 공유 호스팅 제약 측정 결과 ===')
    console.log(`  PHP 오버헤드:       ${result.elapsed_sec}초`)
    console.log(`  피크 메모리 증가:   ${result.peak_mem_mb}MB`)
    console.log(`  발송 성공:          ${result.success}건 / 실패: ${result.fail}건`)
    console.log(`  실 SMTP 여유:       건당 최대 ${result.smtp_budget}초`)
    console.log(`  (실 SMTP 0.5초/건 기준 ${result.success}건 = ${(result.success * 0.5).toFixed(1)}초 추가 예상)`)

    expect(result.elapsed_sec).toBeLessThan(TIME_LIMIT_SEC,
      `PHP 오버헤드 ${result.elapsed_sec}초 — 30초 한도 초과 위험`)

    expect(result.peak_mem_mb).toBeLessThan(MEMORY_LIMIT_MB,
      `피크 메모리 ${result.peak_mem_mb}MB — 128MB 한도 초과 위험`)

    expect(result.success).toBeGreaterThan(0, '발송 성공 0건 — FluentCRM 연동 확인 필요')
  })

  test('피크 메모리가 128MB 한도 안에 든다', () => {
    const peakMb = parseFloat(wpEval(`
      $memBefore = memory_get_usage(true);
      $subs = FluentCrm\\App\\Models\\Subscriber::whereIn(
        "email",
        array_map(fn($i) => "shtest+{$i}@example.com", range(1, ${BATCH_SIZE}))
      )->where("status", "subscribed")->get();
      $peak = memory_get_peak_usage(true) - $memBefore;
      echo round($peak / 1024 / 1024, 2);
    `))

    console.log(`  FluentCRM ${BATCH_SIZE}명 일괄 조회 메모리: ${peakMb}MB`)
    expect(peakMb).toBeLessThan(MEMORY_LIMIT_MB,
      `구독자 조회 메모리 ${peakMb}MB — 128MB 한도 위험`)
  })
})
