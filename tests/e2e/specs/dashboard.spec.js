import { test, expect } from '@playwright/test'

const DASHBOARD = 'wp-admin/admin.php?page=crmbiz-newsletter'

test.describe('대시보드', () => {

  test.beforeEach(async ({ page }) => {
    await page.goto(DASHBOARD)
    // Vue 앱 로딩 대기
    await page.waitForSelector('.min-h-screen', { timeout: 10_000 })
  })

  test('페이지 로드 — 주요 섹션 표시', async ({ page }) => {
    await expect(page.locator('h1:has-text("뉴스레터 대시보드")')).toBeVisible()
    await expect(page.locator('text=발송 예약 / 대기 현황')).toBeVisible()
    await expect(page.locator('text=완료 캠페인')).toBeVisible()
    await expect(page.locator('text=발송 성공')).toBeVisible()
    await expect(page.locator('text=성공률')).toBeVisible()
  })

  test('콘솔 에러 없음', async ({ page }) => {
    const errors = []
    page.on('console', msg => { if (msg.type() === 'error') errors.push(msg.text()) })
    await page.goto(DASHBOARD)
    await page.waitForTimeout(2000)
    expect(errors.filter(e => !e.includes('favicon'))).toHaveLength(0)
  })

  test('차트 기간 토글 — 7일/30일/90일', async ({ page }) => {
    for (const days of ['7일', '90일', '30일']) {
      await page.click(`button:has-text("${days}")`)
      await page.waitForTimeout(500)
      await expect(page.locator(`text=최근 ${days.replace('일', '')}일 발송 추이`)).toBeVisible()
    }
  })

  test('최근 캠페인 클릭 → 이력 페이지 이동 (403 없음)', async ({ page }) => {
    const campaign = page.locator('a[href*="crmbiz-nl-history"]').first()
    await expect(campaign).toBeVisible()
    await campaign.click()
    await expect(page).not.toHaveURL(/403|error/)
    await expect(page).toHaveURL(/crmbiz-nl-history/)
  })

  test('캠페인 페이지네이션 — 새 UI 텍스트 표시', async ({ page }) => {
    await expect(page.locator('text=총계')).toBeVisible()
    await expect(page.locator('text=/ page')).toBeVisible()
    await expect(page.locator('text=/페이지 \\d+ of \\d+/')).toBeVisible()
  })

  test('캠페인 페이지네이션 — per-page 선택', async ({ page }) => {
    const select = page.locator('select').last()
    await expect(select).toBeVisible()
    await select.selectOption('10')
    await page.waitForTimeout(500)
    await expect(page.locator('text=최근 캠페인')).toBeVisible()
  })

  test('캠페인 페이지네이션 — 다음/이전 버튼 렌더', async ({ page }) => {
    // « ‹ 1 › » 버튼 5개가 모두 존재
    const paginationSection = page.locator('.flex.items-center.gap-1').last()
    await expect(paginationSection.locator('button')).toHaveCount(5)
  })

  test('발송 이력 버튼 → 이력 페이지 이동', async ({ page }) => {
    await page.click('a:has-text("발송 이력")')
    await expect(page).toHaveURL(/crmbiz-nl-history/)
  })

})
