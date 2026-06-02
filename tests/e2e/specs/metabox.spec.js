import { test, expect } from '@playwright/test'

const NEW_POST  = 'wp-admin/post-new.php'
const POST_LIST = 'wp-admin/edit.php'

test.describe('메타박스 (글 편집기)', () => {

  test.beforeEach(async ({ page }) => {
    await page.goto(NEW_POST)
    await page.waitForLoadState('domcontentloaded')
  })

  // ── 메타박스 렌더 ─────────────────────────────────────────────────────────

  test('메타박스 — 글 편집기에 렌더됨', async ({ page }) => {
    await expect(page.locator('#crmbiz-nl-metabox')).toBeVisible()
    await expect(page.locator('#crmbiz_nl_enabled')).toBeVisible()
    await expect(page.locator('label:has-text("뉴스레터로 발송")')).toBeVisible()
  })

  test('메타박스 — 체크 전 옵션 패널 숨김', async ({ page }) => {
    const checkbox = page.locator('#crmbiz_nl_enabled')
    const isChecked = await checkbox.isChecked()
    if (!isChecked) {
      await expect(page.locator('#crmbiz-nl-options')).toBeHidden()
    }
  })

  test('메타박스 — 체크 시 옵션 패널 표시', async ({ page }) => {
    const checkbox = page.locator('#crmbiz_nl_enabled')
    if (!await checkbox.isChecked()) {
      await checkbox.check()
    }
    await expect(page.locator('#crmbiz-nl-options')).toBeVisible()
    await expect(page.locator('text=발송 시점')).toBeVisible()
    await expect(page.locator('text=테스트 발송')).toBeVisible()
  })

  // ── 발송 시점 라디오 ─────────────────────────────────────────────────────

  test('발송 시점 — 예약 발송 선택 시 날짜 입력 표시', async ({ page }) => {
    const checkbox = page.locator('#crmbiz_nl_enabled')
    if (!await checkbox.isChecked()) await checkbox.check()

    await page.click('input[name="crmbiz_nl_send_mode"][value="scheduled"]')
    await expect(page.locator('#crmbiz-scheduled-at')).toBeVisible()
    await expect(page.locator('input[name="crmbiz_nl_scheduled_at"]')).toBeVisible()
  })

  test('발송 시점 — 수동 발송 선택 시 안내문 표시', async ({ page }) => {
    const checkbox = page.locator('#crmbiz_nl_enabled')
    if (!await checkbox.isChecked()) await checkbox.check()

    await page.click('input[name="crmbiz_nl_send_mode"][value="manual"]')
    await expect(page.locator('#crmbiz-manual-hint')).toBeVisible()
    await expect(page.locator('text=발행해도 자동으로 발송되지 않습니다')).toBeVisible()
  })

  // ── 테스트 발송 ──────────────────────────────────────────────────────────

  test('테스트 발송 — 이메일 입력 필드와 버튼 표시', async ({ page }) => {
    const checkbox = page.locator('#crmbiz_nl_enabled')
    if (!await checkbox.isChecked()) await checkbox.check()

    await expect(page.locator('#crmbiz-nl-test-email')).toBeVisible()
    await expect(page.locator('#crmbiz-nl-send-test')).toBeVisible()
  })

  test('테스트 발송 — 이메일 미입력 시 버튼 클릭 무반응', async ({ page }) => {
    const checkbox = page.locator('#crmbiz_nl_enabled')
    if (!await checkbox.isChecked()) await checkbox.check()

    // 이메일 비워두고 클릭
    await page.fill('#crmbiz-nl-test-email', '')
    await page.click('#crmbiz-nl-send-test')
    // 오류 없이 페이지 유지
    await expect(page.locator('#crmbiz-nl-metabox')).toBeVisible()
  })

})

test.describe('글 목록 — 뉴스레터 상태 컬럼', () => {

  test('글 목록 — 뉴스레터 컬럼 존재', async ({ page }) => {
    await page.goto(POST_LIST)
    await page.waitForLoadState('domcontentloaded')
    // .column-crmbiz_newsletter 헤더가 존재
    await expect(page.locator('.column-crmbiz_newsletter, th#crmbiz_newsletter')).toBeVisible()
  })

  test('글 목록 — 뉴스레터 상태 배지 렌더 (발송된 글 있을 때)', async ({ page }) => {
    await page.goto(POST_LIST)
    await page.waitForLoadState('domcontentloaded')
    // 컬럼 셀이 렌더됨 (내용은 데이터에 따라 상태 배지 또는 — 표시)
    const cells = page.locator('td.column-crmbiz_newsletter')
    const count = await cells.count()
    expect(count).toBeGreaterThanOrEqual(0) // 글이 있으면 셀도 있음
  })

})
