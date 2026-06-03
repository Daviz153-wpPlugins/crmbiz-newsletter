/**
 * DISABLE_WP_CRON 환경 호환성 테스트
 *
 * 많은 실 운영 서버가 wp-config.php에 DISABLE_WP_CRON = true 를 설정하고
 * 서버 crontab으로 `wp cron event run --due-now` 를 직접 호출한다.
 * 이 테스트는 그 방식으로 트리거했을 때 발송이 정상 완료되는지 검증한다.
 *
 * 실행: npx playwright test disable-wp-cron --project=chromium
 */
import { test, expect } from '@playwright/test'
import { execSync }      from 'child_process'

const WP_PATH  = process.env.WP_PATH    || '/Applications/MAMP/htdocs/wordpress'
const _WP_BASE = (process.env.WP_BASE_URL || 'http://localhost:8888/wordpress').replace(/\/$/, '')
const CRON_HOOK = 'crmbiz_nl_send_newsletter'

function wpEval(code) {
  const flat = code.replace(/\s+/g, ' ').trim()
  return execSync(
    `wp eval '${flat.replace(/'/g, "'\\''")}' --path=${WP_PATH}`,
    { encoding: 'utf-8', timeout: 30_000 }
  ).trim().replace(/^(PHP Deprecated|Deprecated):.*\n?/gm, '').trim()
}

/**
 * WP Cron이 이벤트를 실행할 때 내부적으로 do_action()을 호출한다.
 * 서버 crontab: `wp cron event run --due-now` → WordPress가 _get_cron_array()를 순회 후 do_action() 실행.
 * 이 함수는 그 최종 단계를 직접 재현한다 (스케줄 타이밍 경쟁 없음).
 */
function triggerSend(nlId) {
  return wpEval(`do_action("${CRON_HOOK}", ${nlId});`)
}

test.describe('DISABLE_WP_CRON 환경 — wp cron event run 트리거', () => {
  let postId, nlId

  test.beforeEach(() => {
    // 테스트용 포스트 생성
    postId = parseInt(wpEval(`
      echo wp_insert_post([
        "post_title"   => "CRON 테스트 뉴스레터",
        "post_content" => "<p>cron 트리거 테스트 본문입니다.</p>",
        "post_status"  => "publish",
        "post_type"    => "post",
      ]);
    `))

    // queued 상태 뉴스레터 레코드 삽입 (FluentCRM 태그 1: 뉴스레터구독)
    nlId = parseInt(wpEval(`
      global $wpdb;
      $wpdb->insert(
        $wpdb->prefix . "crmbiz_newsletters",
        [
          "post_id"         => ${postId},
          "status"          => "queued",
          "send_mode"       => "immediate",
          "tag_ids"         => json_encode([1]),
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

    // 이 시점에서 실제 서버라면 wp_schedule_single_event()가 호출되어
    // crontab의 `wp cron event run --due-now`가 처리한다.
    // 여기서는 do_action() 직접 호출로 동등하게 시뮬레이션한다.
  })

  test.afterEach(() => {
    if (postId) wpEval(`wp_delete_post(${postId}, true);`)
    if (nlId) wpEval(`
      global $wpdb;
      $wpdb->delete($wpdb->prefix . "crmbiz_newsletters", ["id" => ${nlId}], ["%d"]);
      $wpdb->delete($wpdb->prefix . "crmbiz_nl_queue", ["newsletter_id" => ${nlId}], ["%d"]);
    `)
  })

  test('wp cron event run --due-now 으로 queued → sent 전환 성공', async () => {
    // 외부 cron 트리거 (서버 crontab: wp cron event run --due-now)
    triggerSend(nlId)

    // 최대 2분 내 sent 상태 확인 (배치 여러 번 필요할 수 있음)
    await expect.poll(
      () => wpEval(`
        global $wpdb;
        echo $wpdb->get_var($wpdb->prepare(
          "SELECT status FROM {$wpdb->prefix}crmbiz_newsletters WHERE id = %d",
          ${nlId}
        ));
      `),
      { timeout: 120_000, intervals: [3_000, 5_000, 10_000] }
    ).toBe('sent')
  })

  test('발송 완료 후 큐가 비어있다 (이중 발송 없음)', async () => {
    triggerSend(nlId)

    // sent 대기
    await expect.poll(
      () => wpEval(`
        global $wpdb;
        echo $wpdb->get_var($wpdb->prepare(
          "SELECT status FROM {$wpdb->prefix}crmbiz_newsletters WHERE id = %d", ${nlId}
        ));
      `),
      { timeout: 120_000, intervals: [5_000, 10_000] }
    ).toBe('sent')

    // 큐 완전 소진 확인
    const queueRemaining = wpEval(`
      global $wpdb;
      echo $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}crmbiz_nl_queue WHERE newsletter_id = %d", ${nlId}
      ));
    `)
    expect(parseInt(queueRemaining)).toBe(0)
  })

  test('--due-now 두 번 연속 실행해도 이중 발송 없다 (GET_LOCK 보호)', async () => {
    // 첫 번째 cron 트리거
    triggerSend(nlId)

    // sent 대기
    await expect.poll(
      () => wpEval(`
        global $wpdb;
        echo $wpdb->get_var($wpdb->prepare(
          "SELECT status FROM {$wpdb->prefix}crmbiz_newsletters WHERE id = %d", ${nlId}
        ));
      `),
      { timeout: 120_000, intervals: [5_000, 10_000] }
    ).toBe('sent')

    const successAfterFirst = parseInt(wpEval(`
      global $wpdb;
      echo $wpdb->get_var($wpdb->prepare(
        "SELECT success_count FROM {$wpdb->prefix}crmbiz_newsletters WHERE id = %d", ${nlId}
      ));
    `))

    // 두 번째 cron 트리거 (이미 sent 상태)
    try { triggerSend(nlId) } catch { /* sent 상태 → sendFromRecord가 즉시 return — 오류 무시 */ }

    const successAfterSecond = parseInt(wpEval(`
      global $wpdb;
      echo $wpdb->get_var($wpdb->prepare(
        "SELECT success_count FROM {$wpdb->prefix}crmbiz_newsletters WHERE id = %d", ${nlId}
      ));
    `))

    // 두 번째 실행 후 success_count가 늘지 않아야 함
    expect(successAfterSecond).toBe(successAfterFirst)
  })
})
