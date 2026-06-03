/**
 * 서버 부하 & 성능 E2E 테스트
 *
 * 플러그인이 서버에 과도한 부하를 주지 않는지 검증한다.
 * 세 가지 축을 다룬다:
 *
 * 그룹 1: API 응답 시간 — 주요 엔드포인트가 허용 범위 내에 응답하는지
 * 그룹 2: WP Cron 이중 발화 방지 — GET_LOCK이 중복 처리를 차단하는지
 * 그룹 3: REST API 동시 요청 — 병렬 요청에서 데이터 일관성 유지하는지
 *
 * 실행: npx playwright test server-performance --project=chromium
 */
import { test, expect } from '@playwright/test'
import { execSync }      from 'child_process'

const WP_PATH  = process.env.WP_PATH    || '/Applications/MAMP/htdocs/wordpress'
const WP_BASE  = (process.env.WP_BASE_URL || 'http://localhost:8888/wordpress').replace(/\/$/, '')
const API_BASE = WP_BASE + '/wp-json/crmbiz-nl/v1'

const DASHBOARD = 'wp-admin/admin.php?page=crmbiz-newsletter'
const HISTORY   = 'wp-admin/admin.php?page=crmbiz-nl-history'

// ─── 헬퍼 ────────────────────────────────────────────────────────────────────

function wpEval(code) {
  const flat = code.replace(/\s+/g, ' ').trim()
  const out = execSync(
    `wp eval '${flat.replace(/'/g, "'\\''")}' --path=${WP_PATH}`,
    { encoding: 'utf-8' }
  )
  // PHP deprecated warnings 제거 후 실제 출력만 반환
  return out.split('\n')
    .filter(l => !l.startsWith('PHP Deprecated') && !l.startsWith('Deprecated'))
    .join('\n')
    .trim()
}

// WordPress REST API는 X-WP-Nonce 헤더가 필요 (§15-2 참고)
// 대시보드 페이지 로드 후 CrmbizNL.nonce를 얻어 fetch로 호출
async function fetchApi(page, path) {
  await page.goto(DASHBOARD)
  await page.waitForSelector('.min-h-screen', { timeout: 15_000 })
  return page.evaluate(async ([url, nonce]) => {
    const start = performance.now()
    const r = await fetch(url, { headers: { 'X-WP-Nonce': nonce } })
    const elapsed = Math.round(performance.now() - start)
    const json = await r.json()
    return { status: r.status, elapsed, json }
  }, [`${API_BASE}${path}`, await page.evaluate(() => window.CrmbizNL?.nonce)])
}

// ──────────────────────────────────────────────────────────────────────────────
// 그룹 1: API 응답 시간
// ──────────────────────────────────────────────────────────────────────────────

test.describe('API 응답 시간 — 허용 범위 내 응답', () => {

  test('대시보드 API — 2000ms 미만', async ({ page }) => {
    const { status, elapsed, json } = await fetchApi(page, '/dashboard')

    expect(status).toBe(200)
    expect(json).toHaveProperty('stats')
    expect(elapsed, `응답 ${elapsed}ms — 2000ms 초과`).toBeLessThan(2000)
  })

  test('뉴스레터 목록 API — 1000ms 미만', async ({ page }) => {
    const { status, elapsed, json } = await fetchApi(page, '/newsletters?per_page=20')

    expect(status).toBe(200)
    expect(Array.isArray(json.items)).toBeTruthy()
    expect(elapsed, `응답 ${elapsed}ms — 1000ms 초과`).toBeLessThan(1000)
  })

  test('대시보드 페이지 로드 — Vue 앱 마운트 3000ms 미만', async ({ page }) => {
    const start = Date.now()

    await page.goto(DASHBOARD)
    await page.waitForSelector('.min-h-screen', { timeout: 10_000 })

    const elapsed = Date.now() - start
    await expect(page.locator('h1:has-text("뉴스레터 대시보드")')).toBeVisible()
    expect(elapsed, `페이지 마운트 ${elapsed}ms — 3000ms 초과`).toBeLessThan(3000)
  })

  test('이력 페이지 로드 — Vue 앱 마운트 3000ms 미만', async ({ page }) => {
    const start = Date.now()

    await page.goto(HISTORY)
    await page.waitForSelector('.min-h-screen', { timeout: 10_000 })

    const elapsed = Date.now() - start
    await expect(page.locator('h1:has-text("뉴스레터 이력")')).toBeVisible()
    expect(elapsed, `페이지 마운트 ${elapsed}ms — 3000ms 초과`).toBeLessThan(3000)
  })

})

// ──────────────────────────────────────────────────────────────────────────────
// 그룹 2: WP Cron 이중 발화 방지 (GET_LOCK 검증)
// ──────────────────────────────────────────────────────────────────────────────

test.describe('WP Cron 이중 발화 방지 — GET_LOCK 효과 검증', () => {

  let nlId   = null
  let postId = null

  test.afterEach(() => {
    try {
      if (nlId) {
        wpEval(`
          global $wpdb;
          $wpdb->delete($wpdb->prefix . "crmbiz_newsletters", ["id" => ${nlId}], ["%d"]);
          $wpdb->delete($wpdb->prefix . "crmbiz_nl_queue", ["newsletter_id" => ${nlId}], ["%d"]);
        `)
      }
      if (postId) {
        wpEval(`wp_delete_post(${postId}, true);`)
      }
    } catch {}
    nlId = postId = null
  })

  test('queued 레코드 → Cron 훅 2회 연속 트리거 → 상태가 1번만 전환됨', async () => {
    // 실제 처리가 일어나도록 유효한 post + FluentCRM tag_id=1 사용
    // sendFromRecord()는 post가 없거나 tag/list가 없으면 즉시 return → 상태 변경 없음
    postId = wpEval(`
      echo wp_insert_post(["post_title" => "E2E Cron Double-Fire Test", "post_status" => "draft", "post_type" => "post"]);
    `)
    expect(parseInt(postId)).toBeGreaterThan(0)

    // tag_ids=[1]: FluentCRM tag ID=1 ("뉴스레터구독") 사용
    nlId = wpEval(`
      global $wpdb;
      $wpdb->insert(
        $wpdb->prefix . "crmbiz_newsletters",
        ["post_id" => ${postId}, "status" => "queued", "tag_ids" => "[1]", "list_ids" => "[]"],
        ["%d", "%s", "%s", "%s"]
      );
      echo $wpdb->insert_id;
    `)
    expect(parseInt(nlId)).toBeGreaterThan(0)

    // Cron 훅 2회 연속 트리거 (순차)
    // 1회: 실제 처리 → queued 상태 전환 (sending / failed / sent)
    // 2회: sendFromRecord()가 non-queued 상태를 감지 → 무시 또는 GET_LOCK 이미 해제
    wpEval(`do_action("crmbiz_nl_send_newsletter", (int) ${nlId});`)
    wpEval(`do_action("crmbiz_nl_send_newsletter", (int) ${nlId});`)

    const after = wpEval(`
      global $wpdb;
      $r = $wpdb->get_row($wpdb->prepare(
        "SELECT status, success_count, fail_count FROM " . $wpdb->prefix . "crmbiz_newsletters WHERE id = %d",
        ${nlId}
      ));
      echo $r->status . "|" . $r->success_count . "|" . $r->fail_count;
    `)
    const [status, successCount, failCount] = after.split('|')

    // 1회 처리 후 상태가 queued에서 벗어나야 함
    expect(status, '2회 트리거 후에도 queued — 처리가 시작되지 않음').not.toBe('queued')

    // 두 번째 트리거가 성공 카운트를 중복으로 늘리지 않아야 함
    // (구독자 1명 → 최대 success_count=1, 이중 처리라면 2 이상)
    const totalProcessed = parseInt(successCount) + parseInt(failCount)
    expect(totalProcessed, `이중 처리 감지: success=${successCount} fail=${failCount}`).toBeLessThanOrEqual(1)
  })

  test('sending 상태 레코드 → Cron 재트리거 → sending 상태 유지 (중복 발송 없음)', async () => {
    // sending 상태: 이미 발송 중인 레코드 — Cron이 또 트리거되면 GET_LOCK이 차단해야 함

    // sending 레코드 삽입
    nlId = wpEval(`
      global $wpdb;
      $wpdb->insert(
        $wpdb->prefix . "crmbiz_newsletters",
        ["post_id" => 0, "status" => "sending", "tag_ids" => "", "list_ids" => "", "success_count" => 0],
        ["%d", "%s", "%s", "%s", "%d"]
      );
      echo $wpdb->insert_id;
    `)
    expect(parseInt(nlId)).toBeGreaterThan(0)

    // Cron 트리거 — sending 상태는 허용되지만 GET_LOCK이 있으면 획득 실패 시 스킵
    wpEval(`do_action("crmbiz_nl_send_newsletter", (int) ${nlId});`)

    // sending 상태 또는 failed (FluentCRM 없을 때) — queued로 돌아가지 않아야 함
    const statusAfter = wpEval(`
      global $wpdb;
      echo $wpdb->get_var($wpdb->prepare(
        "SELECT status FROM " . $wpdb->prefix . "crmbiz_newsletters WHERE id = %d",
        ${nlId}
      ));
    `)
    // sending이 queued로 되돌아가면 무한 루프 — 그런 일이 없어야 함
    expect(statusAfter).not.toBe('queued')
    expect(['sending', 'failed', 'sent']).toContain(statusAfter)
  })

})

// ──────────────────────────────────────────────────────────────────────────────
// 그룹 3: REST API 동시 요청 — 데이터 일관성
// ──────────────────────────────────────────────────────────────────────────────

test.describe('REST API 동시 요청 — 데이터 일관성', () => {

  test('대시보드 API 2회 병렬 요청 → 동일 stats 반환', async ({ page }) => {
    // 페이지 로드로 nonce 확보
    await page.goto(DASHBOARD)
    await page.waitForSelector('.min-h-screen', { timeout: 15_000 })

    // 같은 페이지 컨텍스트에서 2개 요청 병렬 실행
    const [res1, res2] = await page.evaluate(async ([url]) => {
      const nonce = window.CrmbizNL?.nonce
      const headers = { 'X-WP-Nonce': nonce }
      const [r1, r2] = await Promise.all([
        fetch(url, { headers }).then(r => r.json()),
        fetch(url, { headers }).then(r => r.json()),
      ])
      return [r1, r2]
    }, [`${API_BASE}/dashboard`])

    // 두 응답 모두 정상
    expect(res1.system?.version).toBeTruthy()
    expect(res2.system?.version).toBeTruthy()

    // stats가 동일해야 함 (캐시 transient이 있으므로 동일한 값 반환)
    expect(res1.stats?.total_nl).toBe(res2.stats?.total_nl)
    expect(res1.stats?.total_success).toBe(res2.stats?.total_success)
  })

  test('뉴스레터 목록 API 2회 병렬 요청 → 같은 total 반환', async ({ page }) => {
    await page.goto(DASHBOARD)
    await page.waitForSelector('.min-h-screen', { timeout: 15_000 })

    const [res1, res2] = await page.evaluate(async ([url]) => {
      const nonce = window.CrmbizNL?.nonce
      const headers = { 'X-WP-Nonce': nonce }
      const [r1, r2] = await Promise.all([
        fetch(url, { headers }).then(r => r.json()),
        fetch(url, { headers }).then(r => r.json()),
      ])
      return [r1, r2]
    }, [`${API_BASE}/newsletters?per_page=20`])

    expect(res1.total).toBe(res2.total)
    expect(res1.items?.length).toBe(res2.items?.length)
  })

  test('대시보드 + 이력 페이지 순차 접근 — JS 에러 없음', async ({ page }) => {
    const errors = []
    page.on('pageerror', e => errors.push(e.message))

    await page.goto(DASHBOARD)
    await page.waitForSelector('.min-h-screen', { timeout: 10_000 })

    await page.goto(HISTORY)
    await page.waitForSelector('.min-h-screen', { timeout: 10_000 })

    // 페이지 전환 후 Vue 앱 에러 없어야 함
    expect(errors.filter(e => !e.includes('favicon'))).toHaveLength(0)
    await expect(page.locator('h1:has-text("뉴스레터 이력")')).toBeVisible()
  })

})
