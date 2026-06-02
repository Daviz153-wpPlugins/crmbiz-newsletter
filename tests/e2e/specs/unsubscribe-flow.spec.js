/**
 * 수신거부 완전 흐름 E2E 테스트
 *
 * 유효한 HMAC 토큰으로 실제 수신거부 → DB 기록 확인 → 관리자 해제까지 검증.
 * WP-CLI로 실제 토큰을 생성하므로 WP_PATH 환경변수 필요.
 */
import { test, expect } from '@playwright/test'
import { execSync } from 'child_process'

const BASE       = (process.env.WP_BASE_URL || 'http://localhost:8888/wordpress').replace(/\/$/, '')
const WP_PATH    = process.env.WP_PATH || '/tmp/wordpress'
const UNSUB_PAGE = 'wp-admin/admin.php?page=crmbiz-nl-unsubscribers'

const TEST_EMAIL = 'e2e-unsub-flow@test.example'

function wpEval(code) {
  const flat = code.replace(/\s+/g, ' ').trim()
  return execSync(
    `wp eval '${flat.replace(/'/g, "'\\''")}' --path=${WP_PATH}`,
    { encoding: 'utf-8' }
  ).trim()
}

function buildUnsubUrl(email, nlId = 0) {
  return wpEval(
    `echo CRMBizNewsletter\\UnsubscribeHandler::buildUnsubscribeUrl('${email}', ${nlId});`
  )
}

function clearUnsubRecord(email) {
  wpEval(`
    global $wpdb;
    $wpdb->delete($wpdb->prefix . 'crmbiz_nl_unsubscribers', ['email' => '${email}'], ['%s']);
  `)
}

function clearRateLimit() {
  wpEval(`
    global $wpdb;
    $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}crmbiz_nl_ratelimit");
  `)
}

function getUnsubCount(email) {
  return parseInt(wpEval(`
    global $wpdb;
    echo $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM {$wpdb->prefix}crmbiz_nl_unsubscribers WHERE email = %s",
      '${email}'
    ));
  `))
}

// ── 수신거부 완전 흐름 ──────────────────────────────────────────────────────

test.describe('수신거부 완전 흐름', () => {

  test.beforeAll(() => {
    clearUnsubRecord(TEST_EMAIL)
    clearRateLimit()
  })

  test.afterAll(() => {
    clearUnsubRecord(TEST_EMAIL)
    clearRateLimit()
  })

  test('[1-1] 유효한 토큰으로 접근 → 완료 페이지 렌더', async ({ page }) => {
    const url = buildUnsubUrl(TEST_EMAIL)
    expect(url).toContain('crmbiz_nl_action=unsubscribe')

    await page.goto(url)
    await expect(page.locator('h1')).toContainText('수신거부가 완료되었습니다')
    await expect(page.locator('body')).toContainText('더 이상')
    await expect(page.locator('a:has-text("홈으로 돌아가기")')).toBeVisible()
  })

  test('[1-2] 수신거부 후 DB에 이메일 기록됨', async () => {
    expect(getUnsubCount(TEST_EMAIL)).toBe(1)
  })

  test('[1-3] 동일 URL 재접근 → 중복 등록 없음 (멱등성)', async ({ page }) => {
    clearRateLimit()
    const url = buildUnsubUrl(TEST_EMAIL)
    await page.goto(url)

    const body = await page.locator('body').textContent()
    expect(
      body?.includes('수신거부가 완료') || body?.includes('이미') || body?.includes('유효하지 않은')
    ).toBeTruthy()

    // REPLACE INTO이므로 최대 1개
    expect(getUnsubCount(TEST_EMAIL)).toBe(1)
  })

  test('[1-4] 관리자 페이지에서 수신거부 해제 → 목록에서 제거', async ({ page }) => {
    await page.goto(UNSUB_PAGE + '&s=' + encodeURIComponent(TEST_EMAIL))
    await page.waitForLoadState('domcontentloaded')

    const row = page.locator('tr', { hasText: TEST_EMAIL })
    await expect(row).toBeVisible()

    const removeBtn = row.locator('.crmbiz-unsub-remove')
    await expect(removeBtn).toBeVisible()
    await removeBtn.click()

    await expect(page.locator('text=수신거부가 해제되었습니다')).toBeVisible({ timeout: 5_000 })
    await expect(row).not.toBeAttached({ timeout: 3_000 })
    expect(getUnsubCount(TEST_EMAIL)).toBe(0)
  })

})

// ── 위변조·만료 방어 ────────────────────────────────────────────────────────

test.describe('수신거부 — 위변조/만료 방어', () => {

  test.beforeEach(() => clearRateLimit())

  test('만료된 exp → 만료 메시지', async ({ page }) => {
    await page.goto(BASE + '/?crmbiz_nl_action=unsubscribe&enc=dGVzdA%3D%3D&token=fake&exp=1&nl=0')
    const body = await page.locator('body').textContent()
    expect(body?.includes('만료') || body?.includes('유효하지 않은')).toBeTruthy()
  })

  test('HMAC 위조 → 오류 페이지', async ({ page }) => {
    await page.goto(BASE + '/?crmbiz_nl_action=unsubscribe&enc=INVALID&token=BADTOKEN&exp=9999999999&nl=0')
    const body = await page.locator('body').textContent()
    expect(body?.includes('유효하지 않은') || body?.includes('수신거부 오류')).toBeTruthy()
  })

  test('파라미터 없음 → 403 또는 오류 페이지', async ({ page }) => {
    await page.goto(BASE + '/?crmbiz_nl_action=unsubscribe')
    const body = await page.locator('body').textContent()
    expect(
      body?.includes('유효하지 않은') || body?.includes('수신거부 오류') || body?.includes('invalid')
    ).toBeTruthy()
  })

})
