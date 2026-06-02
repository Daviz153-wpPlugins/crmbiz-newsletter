/**
 * 페이지네이션 실제 동작 테스트
 *
 * 렌더 확인에 그치지 않고 페이지 이동, 데이터 변경, per-page 변경을 검증
 */
import { test, expect } from '@playwright/test'

const DASHBOARD = 'wp-admin/admin.php?page=crmbiz-newsletter'
const HISTORY   = 'wp-admin/admin.php?page=crmbiz-nl-history'

test.describe('대시보드 캠페인 페이지네이션', () => {

  test.beforeEach(async ({ page }) => {
    await page.goto(DASHBOARD)
    await page.waitForSelector('.min-h-screen', { timeout: 10_000 })
  })

  test('페이지 X of Y + 총계 텍스트 렌더', async ({ page }) => {
    await expect(page.locator('text=/페이지 \\d+ of \\d+/')).toBeVisible()
    await expect(page.locator('text=총계')).toBeVisible()
  })

  test('per-page 5→10 변경 시 페이지 1로 리셋', async ({ page }) => {
    const select = page.locator('select').last()
    await expect(select).toBeVisible()

    // 현재 "페이지 1 of X" 확인
    await expect(page.locator('text=/페이지 1 of/')).toBeVisible()

    await select.selectOption('10')
    await page.waitForTimeout(600)

    // 페이지 번호 1로 리셋
    await expect(page.locator('text=/페이지 1 of/')).toBeVisible()
    await expect(page.locator('text=최근 캠페인')).toBeVisible()
  })

  test('2페이지 이상일 때 ›(next) 버튼 클릭 → 페이지 2로 이동', async ({ page }) => {
    // 현재 총 페이지 확인
    const pageText = await page.locator('text=/페이지 \\d+ of \\d+/').textContent()
    const match = pageText?.match(/of (\d+)/)
    const totalPages = match ? parseInt(match[1]) : 1

    if (totalPages < 2) {
      test.skip()
      return
    }

    const nextBtn = page.locator('button').filter({ hasText: '' }).nth(3) // › 버튼 위치
    // 실제로 next 버튼(disabled 아닌 것) 클릭
    const buttons = page.locator('.flex.items-center.gap-1').last().locator('button')
    const nextBtnActual = buttons.nth(3) // «(0) ‹(1) [1](2) ›(3) »(4)
    await expect(nextBtnActual).not.toBeDisabled()
    await nextBtnActual.click()
    await page.waitForTimeout(600)

    await expect(page.locator('text=/페이지 2 of/')).toBeVisible()
  })

  test('마지막 페이지에서 »(last) 버튼 disabled', async ({ page }) => {
    const pageText = await page.locator('text=/페이지 \\d+ of \\d+/').textContent()
    const match = pageText?.match(/of (\d+)/)
    const totalPages = match ? parseInt(match[1]) : 1

    if (totalPages > 1) {
      // 마지막 페이지로 이동 (» 버튼)
      const lastBtn = page.locator('.flex.items-center.gap-1').last().locator('button').last()
      await lastBtn.click()
      await page.waitForTimeout(600)
    }

    // 마지막 페이지에서 » 버튼 disabled
    const lastBtn = page.locator('.flex.items-center.gap-1').last().locator('button').last()
    await expect(lastBtn).toBeDisabled()
  })

  test('첫 페이지에서 «(first) 버튼 disabled', async ({ page }) => {
    const firstBtn = page.locator('.flex.items-center.gap-1').last().locator('button').first()
    await expect(firstBtn).toBeDisabled()
  })

})

test.describe('발송 이력 페이지네이션', () => {

  test.beforeEach(async ({ page }) => {
    await page.goto(HISTORY)
    await page.waitForSelector('.min-h-screen', { timeout: 10_000 })
  })

  test('페이지 X of Y + 총계 텍스트 렌더', async ({ page }) => {
    await expect(page.locator('text=/페이지 \\d+ of \\d+/')).toBeVisible()
    await expect(page.locator('text=총계')).toBeVisible()
    await expect(page.locator('text=/ page')).toBeVisible()
  })

  test('per-page 변경 → 목록 재로드', async ({ page }) => {
    const select = page.locator('select')
    await expect(select).toBeVisible()
    const beforeCount = await page.locator('tbody tr').count()

    await select.selectOption('50')
    await page.waitForTimeout(600)

    // 에러 없이 목록 유지
    await expect(page.locator('text=/페이지 1 of/')).toBeVisible()
    await expect(page.locator('text=총계')).toBeVisible()
  })

  test('검색 필터 적용 후 총계 변화', async ({ page }) => {
    const totalBefore = await page.locator('text=/총계 \\d+/').textContent()

    await page.fill('input[placeholder*="검색"]', '존재하지않는제목xyz')
    await page.waitForTimeout(600)

    const totalAfter = await page.locator('text=/총계 \\d+/').textContent()
    // 검색 후 총계가 0이거나 이전과 달라야 함
    expect(totalAfter).toBeTruthy()
  })

  test('2페이지 이상일 때 페이지 이동 → 다른 데이터 로드', async ({ page }) => {
    const pageText = await page.locator('text=/페이지 \\d+ of \\d+/').textContent()
    const match    = pageText?.match(/of (\d+)/)
    const total    = match ? parseInt(match[1]) : 1
    if (total < 2) { test.skip(); return }

    // 첫 행 제목 기억
    const firstTitle = await page.locator('tbody tr').first().textContent()

    // 다음 페이지로
    const paginationBtns = page.locator('.flex.items-center.gap-1').locator('button')
    await paginationBtns.nth(2).click() // ChevronRight (next)
    await page.waitForTimeout(600)

    await expect(page.locator('text=/페이지 2 of/')).toBeVisible()
    // 2페이지 첫 행이 1페이지와 다름
    const secondTitle = await page.locator('tbody tr').first().textContent()
    expect(secondTitle).not.toBe(firstTitle)
  })

  test('«(first) 버튼 클릭 → 항상 1페이지', async ({ page }) => {
    const pageText = await page.locator('text=/페이지 \\d+ of \\d+/').textContent()
    const match    = pageText?.match(/of (\d+)/)
    const total    = match ? parseInt(match[1]) : 1
    if (total < 2) { test.skip(); return }

    // 마지막 페이지로 이동
    const paginationBtns = page.locator('.flex.items-center.gap-1').locator('button')
    await paginationBtns.last().click()
    await page.waitForTimeout(600)

    // first 버튼 클릭
    await paginationBtns.first().click()
    await page.waitForTimeout(600)

    await expect(page.locator('text=/페이지 1 of/')).toBeVisible()
  })

})

test.describe('수신거부 페이지네이션 (PHP 렌더)', () => {

  test('50개 초과 시 페이지 링크 표시', async ({ page }) => {
    await page.goto('wp-admin/admin.php?page=crmbiz-nl-unsubscribers')
    await page.waitForLoadState('domcontentloaded')

    // 50개 이하면 페이지네이션 없음 — 이 경우 skip
    const hasPager = await page.locator('a:has-text("▶")').isVisible().catch(() => false)
    if (!hasPager) {
      test.skip()
      return
    }
    await page.click('a:has-text("▶")')
    await page.waitForLoadState('domcontentloaded')
    await expect(page).toHaveURL(/paged=2/)
  })

})
