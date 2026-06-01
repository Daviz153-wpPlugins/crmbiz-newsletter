import { test, expect } from '@playwright/test'

const HISTORY = '/wp-admin/admin.php?page=crmbiz-nl-history'

test.describe('발송 이력', () => {

  test.beforeEach(async ({ page }) => {
    await page.goto(HISTORY)
    await page.waitForSelector('.min-h-screen', { timeout: 10_000 })
  })

  test('페이지 로드 — 기본 요소 표시', async ({ page }) => {
    await expect(page.locator('h1:has-text("뉴스레터 이력")')).toBeVisible()
    await expect(page.locator('input[placeholder*="검색"]')).toBeVisible()
  })

  test('페이지네이션 항상 표시', async ({ page }) => {
    await expect(page.locator('text=페이지당')).toBeVisible()
  })

  test('상태 필터 pill — 전체/완료/실패 클릭', async ({ page }) => {
    for (const label of ['완료', '실패', '전체']) {
      await page.click(`button:has-text("${label}")`)
      await page.waitForTimeout(300)
      await expect(page.locator('h1:has-text("뉴스레터 이력")')).toBeVisible()
    }
  })

  test('제목 검색 동작', async ({ page }) => {
    await page.fill('input[placeholder*="검색"]', '테스트')
    await page.waitForTimeout(600) // debounce 대기
    // 오류 없이 결과 표시 (빈 결과 포함)
    await expect(page.locator('h1')).toBeVisible()
  })

  test('검색 초기화 버튼', async ({ page }) => {
    await page.fill('input[placeholder*="검색"]', '검색어')
    await page.waitForTimeout(600)
    const clearBtn = page.locator('button:has-text("초기화")')
    if (await clearBtn.isVisible()) {
      await clearBtn.click()
      await expect(page.locator('input[placeholder*="검색"]')).toHaveValue('')
    }
  })

  test('컬럼 정렬 클릭', async ({ page }) => {
    const sortBtns = page.locator('thead button')
    const count = await sortBtns.count()
    for (let i = 0; i < count; i++) {
      await sortBtns.nth(i).click()
      await page.waitForTimeout(200)
    }
    await expect(page.locator('h1')).toBeVisible()
  })

})
