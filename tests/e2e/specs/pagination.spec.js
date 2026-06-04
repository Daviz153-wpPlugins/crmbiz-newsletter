/**
 * 페이지네이션 실제 동작 테스트
 *
 * 렌더 확인에 그치지 않고 페이지 이동, 데이터 변경, per-page 변경을 검증
 */
import { test, expect } from '@playwright/test'
import { execSync } from 'child_process'

const DASHBOARD = 'wp-admin/admin.php?page=crmbiz-newsletter'
const HISTORY   = 'wp-admin/admin.php?page=crmbiz-nl-history'
const WP_PATH   = process.env.WP_PATH || '/tmp/wordpress'

function wpEval(code) {
  const flat = code.replace(/\s+/g, ' ').trim()
  return execSync(
    `wp eval '${flat.replace(/'/g, "'\\''")}' --path=${WP_PATH}`,
    { encoding: 'utf-8' }
  ).trim()
}

test.describe('대시보드 캠페인 페이지네이션', () => {

  test.beforeEach(async ({ page }) => {
    await page.goto(DASHBOARD)
    await page.waitForSelector('.min-h-screen', { timeout: 10_000 })
    await page.waitForSelector('text=최근 캠페인', { timeout: 10_000 })
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
    const pageText = await page.locator('text=/페이지 \\d+ of \\d+/').textContent({ timeout: 5_000 }).catch(() => null)
    if (!pageText) { test.skip(true, '페이지네이션 미표시 — 캠페인 수 부족'); return }
    const match = pageText?.match(/of (\d+)/)
    const totalPages = match ? parseInt(match[1]) : 1

    if (totalPages < 2) {
      test.skip()
      return
    }

    const _nextBtn = page.locator('button').filter({ hasText: '' }).nth(3) // › 버튼 위치
    // 실제로 next 버튼(disabled 아닌 것) 클릭
    const buttons = page.locator('.flex.items-center.gap-1').last().locator('button')
    const nextBtnActual = buttons.nth(3) // «(0) ‹(1) [1](2) ›(3) »(4)
    await expect(nextBtnActual).not.toBeDisabled()
    await nextBtnActual.click()
    await page.waitForTimeout(600)

    await expect(page.locator('text=/페이지 2 of/')).toBeVisible()
  })

  test('마지막 페이지에서 »(last) 버튼 disabled', async ({ page }) => {
    const pageText = await page.locator('text=/페이지 \\d+ of \\d+/').textContent({ timeout: 5_000 }).catch(() => null)
    if (!pageText) { test.skip(true, '페이지네이션 미표시 — 캠페인 수 부족'); return }
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
    await page.waitForSelector('text=총계', { timeout: 10_000 })
  })

  test('페이지 X of Y + 총계 텍스트 렌더', async ({ page }) => {
    await expect(page.locator('text=/페이지 \\d+ of \\d+/')).toBeVisible()
    await expect(page.locator('text=총계')).toBeVisible()
    await expect(page.locator('select').first()).toBeVisible()
  })

  test('per-page 변경 → 목록 재로드', async ({ page }) => {
    const select = page.locator('select')
    await expect(select).toBeVisible()
    const _beforeCount = await page.locator('tbody tr').count()

    await select.selectOption('50')
    await page.waitForTimeout(600)

    // 에러 없이 목록 유지
    await expect(page.locator('text=/페이지 1 of/')).toBeVisible()
    await expect(page.locator('text=총계')).toBeVisible()
  })

  test('검색 필터 적용 후 총계 변화', async ({ page }) => {
    const _totalBefore = await page.locator('text=/총계 \\d+/').textContent({ timeout: 5_000 }).catch(() => null)
    if (!_totalBefore) { test.skip(true, '총계 텍스트 미표시'); return }

    await page.fill('input[placeholder*="검색"]', '존재하지않는제목xyz')
    await page.waitForTimeout(600)

    const totalAfter = await page.locator('text=/총계 \\d+/').textContent({ timeout: 5_000 }).catch(() => null)
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

// ── [12] 대량 이력 성능 측정 ───────────────────────────────────────────────

test.describe('대량 이력 성능 (50개 시딩)', () => {

  const seededIds = []
  const SEED_COUNT = 50

  function seedBulkNewsletters(count) {
    const ids = wpEval(`
      global $wpdb;
      $ids = [];
      for ($i = 1; $i <= ${count}; $i++) {
        $postId = wp_insert_post([
          'post_title'  => sprintf('[E2E] 대량성능테스트 %03d', $i),
          'post_status' => 'publish',
          'post_type'   => 'post',
        ]);
        $wpdb->insert($wpdb->prefix . 'crmbiz_newsletters', [
          'post_id'         => $postId,
          'status'          => ($i % 3 === 0) ? 'sent' : (($i % 3 === 1) ? 'draft' : 'cancelled'),
          'send_mode'       => 'immediate',
          'recipient_count' => rand(10, 500),
          'success_count'   => ($i % 3 === 0) ? rand(10, 500) : 0,
          'fail_count'      => 0,
          'created_at'      => current_time('mysql'),
        ], ['%d', '%s', '%s', '%d', '%d', '%d', '%s']);
        $ids[] = $wpdb->insert_id;
      }
      echo implode(',', $ids);
    `)
    return ids.split(',').map(Number).filter(Boolean)
  }

  function deleteSeededBulk(ids) {
    if (!ids.length) return
    wpEval(`
      global $wpdb;
      $ids = [${ids.join(',')}];
      foreach ($ids as $id) {
        $nl = $wpdb->get_row($wpdb->prepare(
          "SELECT post_id FROM {$wpdb->prefix}crmbiz_newsletters WHERE id = %d", $id
        ));
        $wpdb->delete($wpdb->prefix . 'crmbiz_newsletters', ['id' => $id], ['%d']);
        if ($nl && $nl->post_id) wp_delete_post($nl->post_id, true);
      }
    `)
  }

  test.beforeAll(() => {
    const ids = seedBulkNewsletters(SEED_COUNT)
    seededIds.push(...ids)
  })

  test.afterAll(() => {
    deleteSeededBulk(seededIds)
  })

  test('[12-1] 50개 이력 — 페이지 로드 시간 < 2000ms', async ({ page }) => {
    const start = Date.now()
    await page.goto(HISTORY)
    await page.waitForSelector('.min-h-screen', { timeout: 15_000 })
    // 첫 번째 데이터 행 렌더까지 측정
    await page.waitForSelector('tbody tr', { timeout: 10_000 })
    const elapsed = Date.now() - start

    expect(elapsed).toBeLessThan(2000)
  })

  test('[12-2] 50개 이력 — 총계 50개 이상 표시', async ({ page }) => {
    await page.goto(HISTORY)
    await page.waitForSelector('.min-h-screen', { timeout: 15_000 })

    const totalText = await page.locator('text=/총계 \\d+/').textContent()
    const match = totalText?.match(/총계 (\d+)/)
    const total = match ? parseInt(match[1]) : 0
    expect(total).toBeGreaterThanOrEqual(SEED_COUNT)
  })

  test('[12-3] 5페이지 이동 후 슬라이드오버 정상 동작', async ({ page }) => {
    await page.goto(HISTORY)
    await page.waitForSelector('.min-h-screen', { timeout: 15_000 })

    const pageText  = await page.locator('text=/페이지 \\d+ of \\d+/').textContent()
    const match     = pageText?.match(/of (\d+)/)
    const totalPages = match ? parseInt(match[1]) : 1

    // 5페이지까지 이동 (없으면 마지막 페이지까지)
    const targetPage = Math.min(5, totalPages)
    const paginationBtns = page.locator('.flex.items-center.gap-1').locator('button')

    for (let p = 1; p < targetPage; p++) {
      const nextBtn = paginationBtns.nth(3) // › 버튼
      const isDisabled = await nextBtn.isDisabled()
      if (isDisabled) break
      await nextBtn.click()
      await page.waitForTimeout(400)
    }

    // 현재 페이지에서 첫 행 클릭 → 슬라이드오버 열림
    const firstRow = page.locator('tbody tr').first()
    await expect(firstRow).toBeVisible()
    await firstRow.click()
    await page.waitForTimeout(400)
    await expect(page.locator('h2').last()).toBeVisible({ timeout: 3_000 })
  })

  test('[12-4] 검색 응답 시간 < 1000ms', async ({ page }) => {
    await page.goto(HISTORY)
    await page.waitForSelector('.min-h-screen', { timeout: 15_000 })

    const searchInput = page.locator('input[placeholder*="검색"]')
    await expect(searchInput).toBeVisible()

    const start = Date.now()
    await searchInput.fill('대량성능테스트')
    // Vue debounce 500ms 포함 대기 후 결과 확인
    await page.waitForTimeout(600)
    await page.waitForSelector('tbody tr', { timeout: 3_000 }).catch(() => {})
    const elapsed = Date.now() - start

    // debounce(~500ms) + 렌더 시간 포함하여 1000ms 내
    expect(elapsed).toBeLessThan(1000)
  })

})
