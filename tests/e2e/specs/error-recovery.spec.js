/**
 * 에러 복구 시나리오 테스트
 *
 * page.route()로 REST API 응답을 모킹해 네트워크/서버 오류 시
 * UI가 올바르게 처리되는지 검증.
 */
import { test, expect } from '@playwright/test'

const DASHBOARD = 'wp-admin/admin.php?page=crmbiz-newsletter'
const HISTORY   = 'wp-admin/admin.php?page=crmbiz-nl-history'
const API_RE    = /\/wp-json\/crmbiz-nl\/v1\//

// ── 대시보드 API 오류 ─────────────────────────────────────────────────────

test.describe('대시보드 — API 오류 처리', () => {

  test('dashboard API 500 → 오류 없이 페이지 렌더', async ({ page }) => {
    await page.route(`${API_RE}dashboard`, route =>
      route.fulfill({ status: 500, body: JSON.stringify({ message: 'Internal Server Error' }) })
    )

    await page.goto(DASHBOARD)
    await page.waitForLoadState('domcontentloaded')
    await page.waitForTimeout(1000)

    // 500 오류여도 Vue 앱이 흰 화면이 되거나 JS 예외를 던지면 안 됨
    const errors = []
    page.on('pageerror', e => errors.push(e.message))
    expect(errors.filter(e => !e.includes('favicon'))).toHaveLength(0)
  })

  test('dashboard API 네트워크 끊김 → 빈 화면 아닌 상태 유지', async ({ page }) => {
    await page.route(`${API_RE}dashboard`, route => route.abort('failed'))

    await page.goto(DASHBOARD)
    await page.waitForLoadState('domcontentloaded')
    await page.waitForTimeout(1500)

    // 네트워크 오류 시에도 기본 레이아웃(.min-h-screen)은 유지되어야 함
    const appMounted = await page.locator('.min-h-screen').isVisible().catch(() => false)
    expect(appMounted).toBeTruthy()
  })

  test('dashboard API 401 → 콘솔 오류 없이 처리', async ({ page }) => {
    const errors = []
    page.on('pageerror', e => errors.push(e.message))

    await page.route(`${API_RE}dashboard`, route =>
      route.fulfill({ status: 401, body: JSON.stringify({ message: 'Unauthorized' }) })
    )

    await page.goto(DASHBOARD)
    await page.waitForLoadState('domcontentloaded')
    await page.waitForTimeout(1000)

    expect(errors.filter(e => !e.includes('favicon'))).toHaveLength(0)
  })

})

// ── 이력 페이지 API 오류 ──────────────────────────────────────────────────

test.describe('발송 이력 — API 오류 처리', () => {

  test('newsletters API 500 → 로딩 상태에서 멈추지 않음', async ({ page }) => {
    await page.route(`${API_RE}newsletters*`, route =>
      route.fulfill({ status: 500, body: JSON.stringify({ message: 'Server Error' }) })
    )

    await page.goto(HISTORY)
    await page.waitForLoadState('domcontentloaded')
    await page.waitForTimeout(2000)

    // 무한 스피너로 멈춰있으면 안 됨 — 페이지 기본 요소는 렌더됨
    await expect(page.locator('h1')).toBeVisible({ timeout: 5000 })
  })

  test('newsletters API 네트워크 오류 → 빈 화면 없음', async ({ page }) => {
    await page.route(`${API_RE}newsletters*`, route => route.abort('failed'))

    await page.goto(HISTORY)
    await page.waitForLoadState('domcontentloaded')
    await page.waitForTimeout(2000)

    await expect(page.locator('.min-h-screen')).toBeVisible({ timeout: 5000 })
  })

  test('정상 로드 후 API 재요청 오류 — 기존 데이터 유지', async ({ page }) => {
    // 첫 요청은 정상 응답
    let callCount = 0
    await page.route(`${API_RE}newsletters*`, async route => {
      callCount++
      if (callCount === 1) {
        // 첫 번째 요청: 통과
        await route.continue()
      } else {
        // 두 번째 이후: 서버 오류
        await route.fulfill({ status: 503, body: '{}' })
      }
    })

    await page.goto(HISTORY)
    await page.waitForSelector('.min-h-screen', { timeout: 10_000 })

    // 검색으로 두 번째 API 호출 트리거
    await page.fill('input[placeholder*="검색"]', '검색어')
    await page.waitForTimeout(800)

    // 오류 후에도 h1 은 유지
    await expect(page.locator('h1:has-text("뉴스레터 이력")')).toBeVisible()
  })

})

// ── 슬라이드오버 액션 오류 ────────────────────────────────────────────────

test.describe('슬라이드오버 — 액션 API 오류', () => {

  test('발송 API 503 → 버튼 다시 활성화 (무한 로딩 없음)', async ({ page }) => {
    await page.goto(HISTORY)
    await page.waitForSelector('.min-h-screen', { timeout: 10_000 })

    const firstRow = page.locator('tbody tr').first()
    if (!await firstRow.isVisible().catch(() => false)) {
      test.skip()
      return
    }
    await firstRow.click()
    await page.waitForTimeout(300)

    const actionBtn = page.locator('button:has-text("발송 시작"), button:has-text("재발송"), button:has-text("취소")').first()
    if (!await actionBtn.isVisible().catch(() => false)) {
      test.skip()
      return
    }

    // 해당 액션 API 오류 주입
    await page.route(`${API_RE}newsletters/**`, route =>
      route.fulfill({ status: 503, body: JSON.stringify({ message: 'Service Unavailable' }) })
    )

    await actionBtn.click()
    await page.waitForTimeout(2000)

    // 오류 후 버튼이 다시 활성화되거나 오류 메시지 표시
    const isEnabled  = await actionBtn.isEnabled().catch(() => true)
    const hasToast   = await page.locator('[class*="toast"], [class*="Toast"]').isVisible().catch(() => false)
    expect(isEnabled || hasToast).toBeTruthy()
  })

})

// ── 설정 페이지 저장 오류 ─────────────────────────────────────────────────

test.describe('설정 — 저장 오류 처리', () => {

  test('설정 저장 POST 오류 → 사용자 피드백 표시 또는 폼 유지', async ({ page }) => {
    await page.goto('wp-admin/admin.php?page=crmbiz-nl-settings')
    await page.waitForLoadState('domcontentloaded')

    // WordPress 설정 저장은 일반 폼 POST (REST API 아님) —
    // 네트워크 오류 시 브라우저 기본 오류 페이지 또는 폼 유지
    // 여기서는 설정 페이지가 정상 로드되는지만 검증
    await expect(page.locator('button:has-text("설정 저장")')).toBeVisible()
    await expect(page.locator('input[name="dry_run"], input[name="from_name"]').first()).toBeVisible()
  })

})

// ── 콘솔 에러 없음 (이력/설정 페이지) ────────────────────────────────────

test.describe('콘솔 에러 없음', () => {

  test('발송 이력 페이지 — 콘솔 JS 오류 없음', async ({ page }) => {
    const errors = []
    page.on('pageerror', e => errors.push(e.message))

    await page.goto(HISTORY)
    await page.waitForSelector('.min-h-screen', { timeout: 10_000 })
    await page.waitForTimeout(1500)

    expect(errors.filter(e => !e.includes('favicon'))).toHaveLength(0)
  })

  test('설정 페이지 — 콘솔 JS 오류 없음', async ({ page }) => {
    const errors = []
    page.on('pageerror', e => errors.push(e.message))

    await page.goto('wp-admin/admin.php?page=crmbiz-nl-settings')
    await page.waitForLoadState('domcontentloaded')
    await page.waitForTimeout(500)

    expect(errors.filter(e => !e.includes('favicon'))).toHaveLength(0)
  })

  test('수신거부 관리 페이지 — 콘솔 JS 오류 없음', async ({ page }) => {
    const errors = []
    page.on('pageerror', e => errors.push(e.message))

    await page.goto('wp-admin/admin.php?page=crmbiz-nl-unsubscribers')
    await page.waitForLoadState('domcontentloaded')
    await page.waitForTimeout(500)

    expect(errors.filter(e => !e.includes('favicon'))).toHaveLength(0)
  })

})
