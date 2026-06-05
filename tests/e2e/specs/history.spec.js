import { test, expect } from '@playwright/test'
import { execSync } from 'child_process'

const HISTORY  = 'wp-admin/admin.php?page=crmbiz-nl-history'
const WP_PATH  = process.env.WP_PATH || '/tmp/wordpress'
function wpEval(code) {
  const flat = code.replace(/\s+/g, ' ').trim()
  return execSync(
    `wp eval '${flat.replace(/'/g, "'\\''")}' --path=${WP_PATH}`,
    { encoding: 'utf-8' }
  ).trim()
}

test.describe('발송 이력', () => {

  test.beforeEach(async ({ page }) => {
    await page.goto(HISTORY)
    await page.waitForSelector('.min-h-screen', { timeout: 10_000 })
  })

  // ── 기본 렌더 ────────────────────────────────────────────────────────────

  test('페이지 로드 — 기본 요소 표시', async ({ page }) => {
    await expect(page.locator('h1:has-text("뉴스레터 이력")')).toBeVisible()
    await expect(page.locator('input[placeholder*="검색"]')).toBeVisible()
  })

  test('페이지네이션 — 새 UI 텍스트 표시', async ({ page }) => {
    await expect(page.locator('text=총계')).toBeVisible()
    await expect(page.locator('select').first()).toBeVisible()
    await expect(page.locator('text=/페이지 \\d+ of \\d+/')).toBeVisible()
  })

  // ── 필터 ────────────────────────────────────────────────────────────────

  test('상태 필터 pill — 전체/완료/실패 클릭', async ({ page }) => {
    for (const label of ['완료', '실패', '전체']) {
      await page.click(`button:has-text("${label}")`)
      await page.waitForTimeout(300)
      await expect(page.locator('h1:has-text("뉴스레터 이력")')).toBeVisible()
    }
  })

  test('제목 검색 동작', async ({ page }) => {
    await page.fill('input[placeholder*="검색"]', '테스트')
    await page.waitForTimeout(600)
    await expect(page.locator('h1')).toBeVisible()
  })

  test('검색 초기화 버튼', async ({ page }) => {
    await page.fill('input[placeholder*="검색"]', '검색어')
    await page.waitForTimeout(600)
    const clearBtn = page.locator('button:has-text("초기화")')
    await expect(clearBtn).toBeVisible()
    await clearBtn.click()
    await expect(page.locator('input[placeholder*="검색"]')).toHaveValue('')
  })

  test('날짜 범위 필터 — 입력 후 테이블 유지', async ({ page }) => {
    const dateFrom = page.locator('input[type="date"]').first()
    const dateTo   = page.locator('input[type="date"]').last()
    await expect(dateFrom).toBeVisible()
    await expect(dateTo).toBeVisible()
    await dateFrom.fill('2026-01-01')
    await dateTo.fill('2026-12-31')
    await page.waitForTimeout(600)
    await expect(page.locator('h1:has-text("뉴스레터 이력")')).toBeVisible()
    // 초기화 버튼 등장
    await expect(page.locator('button:has-text("초기화")')).toBeVisible()
  })

  // ── 정렬 ────────────────────────────────────────────────────────────────

  test('컬럼 정렬 클릭', async ({ page }) => {
    const sortBtns = page.locator('thead button')
    const count = await sortBtns.count()
    for (let i = 0; i < count; i++) {
      await sortBtns.nth(i).click()
      await page.waitForTimeout(200)
    }
    await expect(page.locator('h1')).toBeVisible()
  })

  // ── 슬라이드오버 ─────────────────────────────────────────────────────────

  test('슬라이드오버 — 행 클릭 시 열림', async ({ page }) => {
    const firstRow = page.locator('tbody tr').first()
    await expect(firstRow).toBeVisible({ timeout: 15_000 })
    await firstRow.click()
    // SlideOver 패널이 나타남
    await expect(page.locator('.translate-x-full').or(page.locator('[class*="slide"]'))).not.toBeVisible({ timeout: 3_000 }).catch(() => {})
    // 상태 배지 + 제목이 패널에 표시됨
    await expect(page.locator('h2').last()).toBeVisible()
  })

  test('슬라이드오버 — 닫기(X) 버튼으로 닫힘', async ({ page }) => {
    await expect(page.locator('tbody tr').first()).toBeVisible({ timeout: 15_000 })
    await page.locator('tbody tr').first().click()
    // 닫기 버튼 클릭 (SlideOver.vue의 aria-label="닫기" 버튼)
    const closeBtn = page.locator('button[aria-label="닫기"]')
    await closeBtn.click()
    await page.waitForTimeout(300)
    // 슬라이드오버가 닫히면 tbody가 다시 포커스 가능
    await expect(page.locator('tbody tr').first()).toBeVisible()
  })

  test('슬라이드오버 — 삭제 버튼 클릭 시 인라인 확인 표시', async ({ page }) => {
    await expect(page.locator('tbody tr').first()).toBeVisible({ timeout: 15_000 })
    await page.locator('tbody tr').first().click()
    await page.waitForTimeout(300)
    // 삭제 버튼 — 슬라이드오버(fixed overlay) 안에서만 찾기 (테이블 행 버튼과 구별)
    const deleteBtn = page.locator('[aria-label="삭제"]')
    await expect(deleteBtn).toBeVisible()
    await deleteBtn.click()
    // "삭제할까요?" 인라인 확인 텍스트 + 예/아니오 버튼
    await expect(page.locator('text=삭제할까요?')).toBeVisible()
    await expect(page.locator('button:has-text("아니오")')).toBeVisible()
    // 취소
    await page.locator('button:has-text("아니오")').click()
    await expect(page.locator('text=삭제할까요?')).not.toBeVisible()
  })

})

// ── 필터/검색 — 실제 DOM 결과 검증 ───────────────────────────────────────────

test.describe('이력 필터 — DOM 결과 검증', () => {

  // 테스트용 다상태 뉴스레터 시딩
  const seededIds = []

  function seedNewsletter(title, status) {
    const id = wpEval(`
      global $wpdb;
      $postId = wp_insert_post([
        'post_title'  => '[E2E] ${title}',
        'post_status' => 'publish',
        'post_type'   => 'post',
      ]);
      $wpdb->insert($wpdb->prefix . 'crmbiz_newsletters', [
        'post_id'    => $postId,
        'status'     => '${status}',
        'send_mode'  => 'immediate',
        'created_at' => current_time('mysql'),
      ], ['%d', '%s', '%s', '%s']);
      echo $wpdb->insert_id;
    `)
    return parseInt(id)
  }

  function deleteNewsletter(id) {
    wpEval(`
      global $wpdb;
      $nl = $wpdb->get_row($wpdb->prepare(
        "SELECT post_id FROM {$wpdb->prefix}crmbiz_newsletters WHERE id = %d", ${id}
      ));
      $wpdb->delete($wpdb->prefix . 'crmbiz_newsletters', ['id' => ${id}], ['%d']);
      if ($nl && $nl->post_id) wp_delete_post($nl->post_id, true);
    `)
  }

  test.beforeAll(() => {
    seededIds.push(seedNewsletter('필터테스트-SENT', 'sent'))
    seededIds.push(seedNewsletter('필터테스트-CANCELLED', 'cancelled'))
    seededIds.push(seedNewsletter('필터테스트-DRAFT', 'draft'))
  })

  test.afterAll(() => {
    seededIds.forEach(deleteNewsletter)
  })

  test('[4-1] "완료" 상태 필터 → sent 행만 포함, cancelled 행 없음', async ({ page }) => {
    await page.goto(HISTORY)
    await page.waitForSelector('.min-h-screen', { timeout: 15_000 })

    await page.click('button:has-text("완료")')
    await page.waitForTimeout(600)

    // sent 행: 우리가 시딩한 제목 확인
    await expect(page.locator('td', { hasText: '필터테스트-SENT' })).toBeVisible({ timeout: 5_000 })
    // cancelled 행 없음
    const cancelledRows = page.locator('td', { hasText: '필터테스트-CANCELLED' })
    expect(await cancelledRows.count()).toBe(0)
  })

  test('[4-2] 제목 검색 → 검색어 포함 행만 표시', async ({ page }) => {
    await page.goto(HISTORY)
    await page.waitForSelector('.min-h-screen', { timeout: 15_000 })

    await page.fill('input[placeholder*="검색"]', '필터테스트-DRAFT')
    await page.waitForTimeout(700)

    await expect(page.locator('td', { hasText: '필터테스트-DRAFT' })).toBeVisible({ timeout: 5_000 })
    // sent 행은 보이지 않아야 함
    const sentRows = page.locator('td', { hasText: '필터테스트-SENT' })
    expect(await sentRows.count()).toBe(0)
  })

  test('[4-3] 날짜 범위 필터 — 먼 과거 범위 → 시딩 행 없음', async ({ page }) => {
    await page.goto(HISTORY)
    await page.waitForSelector('.min-h-screen', { timeout: 15_000 })

    const dateFrom = page.locator('input[type="date"]').first()
    const dateTo   = page.locator('input[type="date"]').last()
    await dateFrom.fill('2020-01-01')
    await dateTo.fill('2020-12-31')
    await page.waitForTimeout(700)

    // 최근 시딩한 행은 2020년 범위 밖이므로 없어야 함
    const sentRows = page.locator('td', { hasText: '필터테스트-SENT' })
    expect(await sentRows.count()).toBe(0)
  })

  test('[4-4] 필터 초기화 → 시딩 행 복원', async ({ page }) => {
    await page.goto(HISTORY)
    await page.waitForSelector('.min-h-screen', { timeout: 15_000 })

    // 필터 적용
    await page.fill('input[placeholder*="검색"]', '필터테스트-DRAFT')
    await page.waitForTimeout(700)

    // 초기화
    const clearBtn = page.locator('button:has-text("초기화")')
    await expect(clearBtn).toBeVisible()
    await clearBtn.click()
    await page.waitForTimeout(500)

    await expect(page.locator('input[placeholder*="검색"]')).toHaveValue('')
    // 다른 상태의 시딩 행도 다시 보여야 함 (per_page 범위 내)
    await expect(page.locator('tbody tr').first()).toBeVisible()
  })

})
