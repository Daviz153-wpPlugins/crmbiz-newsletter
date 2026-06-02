/**
 * 발송 진행률 폴링 UI 테스트
 *
 * sending 상태 레코드를 WP-CLI로 주입하고 Vue 앱의 폴링 동작을 검증한다.
 * - progress bar / "발송 중" 텍스트 렌더
 * - /progress REST 엔드포인트 응답 검증
 * - 상태 변경 후 폴링 종료 확인
 */
import { test, expect } from '@playwright/test'
import { execSync } from 'child_process'

const BASE     = (process.env.WP_BASE_URL || 'http://localhost:8888/wordpress').replace(/\/$/, '')
const WP_PATH  = process.env.WP_PATH || '/tmp/wordpress'
const API_BASE = BASE + '/wp-json/crmbiz-nl/v1'
const HISTORY  = 'wp-admin/admin.php?page=crmbiz-nl-history'

function wpEval(code) {
  const flat = code.replace(/\s+/g, ' ').trim()
  return execSync(
    `wp eval '${flat.replace(/'/g, "'\\''")}' --path=${WP_PATH}`,
    { encoding: 'utf-8' }
  ).trim()
}

function seedSendingNewsletter() {
  const id = wpEval(`
    global $wpdb;
    $postId = wp_insert_post([
      'post_title'  => '[E2E] 진행률 폴링 테스트',
      'post_status' => 'publish',
      'post_type'   => 'post',
    ]);
    $wpdb->insert($wpdb->prefix . 'crmbiz_newsletters', [
      'post_id'         => $postId,
      'status'          => 'sending',
      'send_mode'       => 'immediate',
      'recipient_count' => 100,
      'success_count'   => 40,
      'fail_count'      => 0,
      'created_at'      => current_time('mysql'),
    ], ['%d', '%s', '%s', '%d', '%d', '%d', '%s']);
    echo $wpdb->insert_id;
  `)
  return parseInt(id)
}

function setStatus(id, status) {
  wpEval(`
    global $wpdb;
    $wpdb->update(
      $wpdb->prefix . 'crmbiz_newsletters',
      ['status' => '${status}', 'sent_at' => current_time('mysql')],
      ['id' => ${id}],
      ['%s', '%s'], ['%d']
    );
  `)
}

function deleteSeeded(id) {
  wpEval(`
    global $wpdb;
    $nl = $wpdb->get_row($wpdb->prepare(
      "SELECT post_id FROM {$wpdb->prefix}crmbiz_newsletters WHERE id = %d", ${id}
    ));
    $wpdb->delete($wpdb->prefix . 'crmbiz_newsletters', ['id' => ${id}], ['%d']);
    if ($nl && $nl->post_id) wp_delete_post($nl->post_id, true);
  `)
}

test.describe('발송 진행률 폴링 UI', () => {

  let seededId

  test.beforeAll(() => {
    seededId = seedSendingNewsletter()
  })

  test.afterAll(() => {
    if (seededId) deleteSeeded(seededId)
  })

  // ── REST 엔드포인트 ──────────────────────────────────────────────────

  test('[3-3] /progress API — sending 레코드의 percent/done/status 반환', async ({ request }) => {
    const res = await request.get(`${API_BASE}/newsletters/progress?ids[]=${seededId}`)
    expect(res.status()).toBe(200)

    const json = await res.json()
    const row = json.find((r) => r.id === seededId)
    expect(row).toBeDefined()
    expect(row.status).toBe('sending')
    expect(row.percent).toBe(40)   // 40/100 * 100
    expect(row.done).toBe(40)
    expect(row.recipient_count).toBe(100)
  })

  // ── UI — sending 상태 렌더 ───────────────────────────────────────────

  test('[3-1] sending 행 — 파란 테두리 또는 "발송 중" 텍스트 표시', async ({ page }) => {
    await page.goto(HISTORY)
    await page.waitForSelector('.min-h-screen', { timeout: 15_000 })

    // Vue 폴링이 progress를 받아오면 "발송 중..." 텍스트 렌더됨
    // 폴링 첫 interval(3초) 대기
    await page.waitForTimeout(4_000)

    // sending 상태 행: 파란 왼쪽 테두리 클래스 또는 "발송 중" 텍스트
    const hasBlueBorder = await page.locator('tbody tr').filter({
      has: page.locator('[class*="border-l-blue"]')
    }).count()

    const hasSendingText = await page.locator('text=발송 중').count()

    expect(hasBlueBorder + hasSendingText).toBeGreaterThan(0)
  })

  test('[3-2] sending 행 슬라이드오버 — 진행률 % 표시', async ({ page }) => {
    await page.goto(HISTORY)
    await page.waitForSelector('.min-h-screen', { timeout: 15_000 })
    await page.waitForTimeout(4_000)

    // 시딩한 레코드 행이 반드시 존재해야 함 (skip 아닌 fail)
    const seededRow = page.locator('tbody tr', { hasText: '진행률 폴링 테스트' }).first()
    await expect(seededRow).toBeVisible({ timeout: 5_000 })

    await seededRow.click()
    await page.waitForTimeout(500)

    // 슬라이드오버에 진행률 % 텍스트 표시 (Vue: selectedItem._progress.percent + '%')
    await expect(
      page.locator('text=40%').or(page.locator('text=발송 중'))
    ).toBeVisible({ timeout: 5_000 })
  })

  // ── 상태 변경 → 폴링 멈춤 ───────────────────────────────────────────

  test('[3-4] sent 상태로 변경 후 — /progress에서 sent 반환', async ({ request }) => {
    setStatus(seededId, 'sent')

    const res = await request.get(`${API_BASE}/newsletters/progress?ids[]=${seededId}`)
    expect(res.status()).toBe(200)

    const json = await res.json()
    const row = json.find((r) => r.id === seededId)
    expect(row).toBeDefined()
    expect(row.status).toBe('sent')
    // Vue: status !== 'sending' → fetchList() 호출 → pollTimer 해제
  })

  test('[3-5] 페이지 새로고침 후 — 해당 행이 sending 아님', async ({ page }) => {
    await page.goto(HISTORY)
    await page.waitForSelector('.min-h-screen', { timeout: 15_000 })

    const seededRow = page.locator('tbody tr', { hasText: '진행률 폴링 테스트' }).first()
    await expect(seededRow).toBeVisible({ timeout: 5_000 })

    // sent 상태이므로 파란 테두리(sending) 없어야 함
    const classAttr = await seededRow.getAttribute('class')
    expect(classAttr ?? '').not.toContain('border-l-blue')
  })

})
