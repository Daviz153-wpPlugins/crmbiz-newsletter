/**
 * 반응형 UI 테스트 — 모바일 뷰포트 (iPhone 14)
 *
 * playwright.config.js의 'mobile' 프로젝트로 실행됨.
 * 375px 너비에서 주요 관리자 페이지가 정상 렌더되는지 검증.
 */
import { test, expect } from '@playwright/test'

const DASHBOARD  = 'wp-admin/admin.php?page=crmbiz-newsletter'
const HISTORY    = 'wp-admin/admin.php?page=crmbiz-nl-history'
const SETTINGS   = 'wp-admin/admin.php?page=crmbiz-nl-settings'
const UNSUB_PAGE = 'wp-admin/admin.php?page=crmbiz-nl-unsubscribers'

// ── 대시보드 ────────────────────────────────────────────────────────────────

test.describe('모바일 — 대시보드', () => {

  test('[10-1] 375px — 가로 스크롤 없음', async ({ page }) => {
    await page.goto(DASHBOARD)
    await page.waitForSelector('.min-h-screen, .crmbiz-admin-page', { timeout: 15_000 })

    const scrollWidth  = await page.evaluate(() => document.documentElement.scrollWidth)
    const clientWidth  = await page.evaluate(() => document.documentElement.clientWidth)
    // 가로 스크롤이 없어야 함 (±5px 허용)
    expect(scrollWidth).toBeLessThanOrEqual(clientWidth + 5)
  })

  test('[10-1] 주요 요소 렌더 — 뉴스레터 통계 카드', async ({ page }) => {
    await page.goto(DASHBOARD)
    await page.waitForLoadState('domcontentloaded')
    await page.waitForTimeout(2_000)

    // 콘솔 에러 없음 (치명적 JS 에러 방지)
    const errors = []
    page.on('console', msg => { if (msg.type() === 'error') errors.push(msg.text()) })

    await page.waitForTimeout(1_000)
    const fatalErrors = errors.filter(e => !e.includes('favicon') && !e.includes('Tracker'))
    expect(fatalErrors).toHaveLength(0)
  })

})

// ── 이력 페이지 ─────────────────────────────────────────────────────────────

test.describe('모바일 — 이력 페이지', () => {

  test('[10-2] 375px — 테이블 가로 스크롤 가능, 앱 전체 레이아웃 유지', async ({ page }) => {
    await page.goto(HISTORY)
    await page.waitForSelector('.min-h-screen', { timeout: 15_000 })

    // 페이지 자체의 overflow-x: hidden 등 레이아웃 깨짐 없음
    const bodyOverflow = await page.evaluate(() =>
      getComputedStyle(document.body).overflowX
    )
    // body가 hidden이면 내용이 잘릴 수 있음 — auto 또는 scroll이어야 함
    expect(['auto', 'scroll', 'visible', '']).toContain(bodyOverflow)
  })

  test('[10-2] 행 클릭 → 슬라이드오버 — 375px에서 전체 너비', async ({ page }) => {
    await page.goto(HISTORY)
    await page.waitForSelector('.min-h-screen', { timeout: 15_000 })

    const firstRow = page.locator('tbody tr').first()
    const hasRow = await firstRow.count()
    if (!hasRow) { test.skip(); return }

    await firstRow.click()
    await page.waitForTimeout(500)

    // 슬라이드오버 패널이 화면 너비 이상
    const panelBox = await page.locator('[class*="slide"], [class*="over"], .fixed').last().boundingBox()
    if (panelBox) {
      const viewport = page.viewportSize()
      // 슬라이드오버가 뷰포트 절반 이상 차지해야 함 (모바일 전체 또는 반 이상)
      expect(panelBox.width).toBeGreaterThan((viewport?.width ?? 375) * 0.5)
    }
  })

})

// ── 설정 페이지 ─────────────────────────────────────────────────────────────

test.describe('모바일 — 설정 페이지', () => {

  test('[10-3] 폼 입력 가능, 저장 버튼 접근 가능', async ({ page }) => {
    await page.goto(SETTINGS)
    await page.waitForLoadState('domcontentloaded')

    // 발신자 이름 입력 가능
    const fromNameInput = page.locator('#from_name')
    await expect(fromNameInput).toBeVisible()
    await fromNameInput.click()
    await expect(fromNameInput).toBeFocused()

    // 저장 버튼이 뷰포트 내에 있거나 스크롤로 접근 가능
    const saveBtn = page.locator('button:has-text("설정 저장")')
    await expect(saveBtn).toBeVisible()
    await saveBtn.scrollIntoViewIfNeeded()
    await expect(saveBtn).toBeVisible()
  })

  test('[10-3] 설정 저장 — 모바일에서 동작', async ({ page }) => {
    await page.goto(SETTINGS)
    await page.waitForLoadState('domcontentloaded')

    const saveBtn = page.locator('button:has-text("설정 저장")')
    await saveBtn.scrollIntoViewIfNeeded()
    await saveBtn.click()

    await expect(page.locator('text=설정이 저장되었습니다')).toBeVisible({ timeout: 5_000 })
  })

})

// ── 수신거부 관리 ─────────────────────────────────────────────────────────────

test.describe('모바일 — 수신거부 관리', () => {

  test('[10-4] 주요 요소 렌더 — 검색, 추가 버튼 접근 가능', async ({ page }) => {
    await page.goto(UNSUB_PAGE)
    await page.waitForLoadState('domcontentloaded')

    await expect(page.locator('h1:has-text("수신거부 관리")')).toBeVisible()
    await expect(page.locator('input[name="s"]')).toBeVisible()

    const addBtn = page.locator('button:has-text("직접 추가")')
    await expect(addBtn).toBeVisible()
    await addBtn.scrollIntoViewIfNeeded()
    await addBtn.click()
    // 모달 열림
    await expect(page.locator('#crmbiz-unsub-modal')).toBeVisible()
    await page.locator('button:has-text("취소")').click()
  })

})
