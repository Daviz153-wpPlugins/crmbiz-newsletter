import { test, expect } from '@playwright/test'

const UNSUB = 'wp-admin/admin.php?page=crmbiz-nl-unsubscribers'

test.describe('수신거부 관리', () => {

  test.beforeEach(async ({ page }) => {
    await page.goto(UNSUB)
    await page.waitForLoadState('domcontentloaded')
  })

  // ── 기본 렌더 ────────────────────────────────────────────────────────────

  test('페이지 로드 — 주요 요소 표시', async ({ page }) => {
    await expect(page.locator('h1:has-text("수신거부 관리")')).toBeVisible()
    await expect(page.locator('button:has-text("직접 추가")')).toBeVisible()
    await expect(page.locator('a:has-text("CSV 내보내기")')).toBeVisible()
  })

  test('테이블 헤더 — 이름/이메일/수신거부 일시 컬럼 표시', async ({ page }) => {
    // 데이터가 없을 경우 빈 상태 메시지, 있을 경우 테이블
    const hasTable = await page.locator('#crmbiz-unsub-table').isVisible().catch(() => false)
    if (hasTable) {
      await expect(page.locator('th:has-text("이름")')).toBeVisible()
      await expect(page.locator('th:has-text("이메일")')).toBeVisible()
      await expect(page.locator('th:has-text("수신거부 일시")')).toBeVisible()
    } else {
      await expect(page.locator('text=수신거부된 이메일이 없습니다')).toBeVisible()
    }
  })

  // ── 검색 ────────────────────────────────────────────────────────────────

  test('이메일 검색 — URL에 검색어 반영', async ({ page }) => {
    const searchInput = page.locator('input[name="s"]')
    await expect(searchInput).toBeVisible()
    await searchInput.fill('test@example.com')
    await page.keyboard.press('Enter')
    await page.waitForLoadState('domcontentloaded')
    await expect(page).toHaveURL(/s=test/)
    await expect(page.locator('h1:has-text("수신거부 관리")')).toBeVisible()
  })

  test('검색 초기화 링크 — 검색 후 표시됨', async ({ page }) => {
    await page.goto(UNSUB + '&s=test')
    await page.waitForLoadState('domcontentloaded')
    await expect(page.locator('a:has-text("초기화")')).toBeVisible()
    await page.click('a:has-text("초기화")')
    await expect(page).toHaveURL(/page=crmbiz-nl-unsubscribers/)
    await expect(page).not.toHaveURL(/[&?]s=/)
  })

  // ── 직접 추가 모달 ───────────────────────────────────────────────────────

  test('직접 추가 — 모달 열기/닫기', async ({ page }) => {
    await page.click('button:has-text("직접 추가")')
    await expect(page.locator('#crmbiz-unsub-modal')).toBeVisible()
    await expect(page.locator('#crmbiz-unsub-email-input')).toBeVisible()
    await expect(page.locator('button:has-text("추가")')).toBeVisible()
    // 취소로 닫기
    await page.click('button:has-text("취소")')
    await expect(page.locator('#crmbiz-unsub-modal')).not.toBeVisible()
  })

  test('직접 추가 — 유효하지 않은 이메일 입력 시 추가 불가', async ({ page }) => {
    await page.click('button:has-text("직접 추가")')
    const emailInput = page.locator('#crmbiz-unsub-email-input')
    await emailInput.fill('not-an-email')
    await page.click('#crmbiz-unsub-modal-confirm')
    // 브라우저 네이티브 유효성 검사로 모달이 그대로 열려있어야 함
    await expect(page.locator('#crmbiz-unsub-modal')).toBeVisible()
  })

  // ── CSV 내보내기 ─────────────────────────────────────────────────────────

  test('CSV 내보내기 링크 — nonce 포함 URL', async ({ page }) => {
    const exportLink = page.locator('a:has-text("CSV 내보내기")')
    const href = await exportLink.getAttribute('href')
    expect(href).toContain('crmbiz_export=unsub')
    expect(href).toContain('_wpnonce=')
  })

  // ── 수신거부 해제 (데이터 있을 때만) ─────────────────────────────────────

  test('수신거부 해제 버튼 — 데이터 있을 때 행 제거', async ({ page }) => {
    const table = page.locator('#crmbiz-unsub-table')
    const hasData = await table.isVisible().catch(() => false)
    if (!hasData) {
      test.skip()
      return
    }
    const firstRemoveBtn = page.locator('.crmbiz-unsub-remove').first()
    await expect(firstRemoveBtn).toBeVisible()
    const firstRow = page.locator('#crmbiz-unsub-table tbody tr').first()
    await firstRemoveBtn.click()
    // 행이 사라지거나 성공 토스트가 표시됨
    await expect(
      firstRow.or(page.locator('text=수신거부가 해제되었습니다'))
    ).not.toBeAttached({ timeout: 5_000 }).catch(async () => {
      await expect(page.locator('text=수신거부가 해제되었습니다')).toBeVisible({ timeout: 5_000 })
    })
  })

  // ── 일괄 선택 ────────────────────────────────────────────────────────────

  test('전체 선택 체크박스 — 개별 체크박스 동기화', async ({ page }) => {
    const table = page.locator('#crmbiz-unsub-table')
    const hasData = await table.isVisible().catch(() => false)
    if (!hasData) {
      test.skip()
      return
    }
    const selectAll = page.locator('#crmbiz-unsub-all')
    await selectAll.check()
    const allChecked = await page.locator('.crmbiz-unsub-check:checked').count()
    const total      = await page.locator('.crmbiz-unsub-check').count()
    expect(allChecked).toBe(total)
    // 일괄 해제 버튼 활성화
    await expect(page.locator('#crmbiz-unsub-bulk-remove')).not.toBeDisabled()
  })

})
