import { test, expect } from '@playwright/test'
import { execSync } from 'child_process'

const UNSUB     = 'wp-admin/admin.php?page=crmbiz-nl-unsubscribers'
const WP_PATH   = process.env.WP_PATH || '/tmp/wordpress'

function wpEval(code) {
  const flat = code.replace(/\s+/g, ' ').trim()
  return execSync(
    `wp eval '${flat.replace(/'/g, "'\\''")}' --path=${WP_PATH}`,
    { encoding: 'utf-8' }
  ).trim()
}

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

// ── CSV 내보내기 — 실제 파일 내용 검증 ────────────────────────────────────────

test.describe('CSV 내보내기 — 파일 내용', () => {

  const CSV_EMAIL_1 = 'e2e-csv-first@test.example'
  const CSV_EMAIL_2 = 'e2e-csv-second@test.example'

  function seedUnsubscribers() {
    wpEval(`
      global $wpdb;
      $tbl = $wpdb->prefix . 'crmbiz_nl_unsubscribers';
      $wpdb->replace($tbl, ['email' => '${CSV_EMAIL_1}', 'unsubscribed_at' => '2026-01-15 09:00:00'], ['%s', '%s']);
      $wpdb->replace($tbl, ['email' => '${CSV_EMAIL_2}', 'unsubscribed_at' => '2026-02-20 14:30:00'], ['%s', '%s']);
    `)
  }

  function cleanupUnsubscribers() {
    wpEval(`
      global $wpdb;
      $tbl = $wpdb->prefix . 'crmbiz_nl_unsubscribers';
      $wpdb->delete($tbl, ['email' => '${CSV_EMAIL_1}'], ['%s']);
      $wpdb->delete($tbl, ['email' => '${CSV_EMAIL_2}'], ['%s']);
    `)
  }

  test.beforeAll(() => {
    seedUnsubscribers()
  })

  test.afterAll(() => {
    cleanupUnsubscribers()
  })

  // nonce가 있는 export URL을 페이지에서 가져오는 헬퍼
  async function getExportUrl(page) {
    await page.goto(UNSUB)
    await page.waitForLoadState('domcontentloaded')
    const exportLink = page.locator('a:has-text("CSV 내보내기")')
    await expect(exportLink).toBeVisible()
    return exportLink.getAttribute('href')
  }

  test('[CSV-1] Content-Type: text/csv + Content-Disposition: attachment', async ({ page }) => {
    const exportUrl = await getExportUrl(page)

    // page.request는 브라우저 쿠키(auth) 포함
    const res = await page.request.get(exportUrl)
    expect(res.status()).toBe(200)

    const contentType = res.headers()['content-type'] ?? ''
    expect(contentType).toContain('text/csv')

    const disposition = res.headers()['content-disposition'] ?? ''
    expect(disposition).toContain('attachment')
    expect(disposition).toMatch(/unsubscribers-\d{4}-\d{2}-\d{2}\.csv/)
  })

  test('[CSV-2] UTF-8 BOM 포함 (엑셀 한글 깨짐 방지)', async ({ page }) => {
    const exportUrl = await getExportUrl(page)
    const res  = await page.request.get(exportUrl)
    const body = await res.body()

    // 파일 첫 3바이트가 UTF-8 BOM (EF BB BF)
    expect(body[0]).toBe(0xEF)
    expect(body[1]).toBe(0xBB)
    expect(body[2]).toBe(0xBF)
  })

  test('[CSV-3] 헤더행 — 이름, 이메일, 수신거부 일시 컬럼', async ({ page }) => {
    const exportUrl = await getExportUrl(page)
    const res  = await page.request.get(exportUrl)
    const text = await res.text()

    // BOM 제거 후 첫 줄 확인
    const lines = text.replace(/^\uFEFF/, '').split('\n')
    const header = lines[0]
    expect(header).toContain('이름')
    expect(header).toContain('이메일')
    expect(header).toContain('수신거부 일시')
  })

  test('[CSV-4] 시딩한 이메일 주소가 CSV에 포함됨', async ({ page }) => {
    const exportUrl = await getExportUrl(page)
    const res  = await page.request.get(exportUrl)
    const text = await res.text()

    expect(text).toContain(CSV_EMAIL_1)
    expect(text).toContain(CSV_EMAIL_2)
  })

  test('[CSV-5] nonce 없이 접근 → 보안 오류 (wp_die)', async ({ page }) => {
    const BASE_URL = (process.env.WP_BASE_URL || 'http://localhost:8888/wordpress').replace(/\/$/, '')
    // nonce 없이 직접 export 파라미터 접근
    const badUrl = BASE_URL + '/wp-admin/admin.php?page=crmbiz-nl-unsubscribers&crmbiz_export=unsub'

    await page.goto(badUrl)
    await page.waitForLoadState('domcontentloaded')

    const body = await page.locator('body').textContent()
    expect(body?.includes('보안 검증 실패') || body?.includes('nonce')).toBeTruthy()
  })

})
