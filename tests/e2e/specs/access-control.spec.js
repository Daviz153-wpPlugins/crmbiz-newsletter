/**
 * 비관리자 접근 차단 테스트 (editor 프로젝트에서 실행)
 *
 * Editor 권한 사용자가 플러그인 페이지와 REST API에 접근하면
 * WordPress가 차단하는지 검증.
 */
import { test, expect, request as playwrightRequest } from '@playwright/test'

const BASE = (process.env.WP_BASE_URL || 'http://localhost:8888/wordpress').replace(/\/$/, '')
const API  = BASE + '/wp-json/crmbiz-nl/v1'

const ADMIN_PAGES = [
  { name: '대시보드',      path: 'wp-admin/admin.php?page=crmbiz-newsletter' },
  { name: '발송 이력',     path: 'wp-admin/admin.php?page=crmbiz-nl-history' },
  { name: '수신거부 관리', path: 'wp-admin/admin.php?page=crmbiz-nl-unsubscribers' },
  { name: '설정',          path: 'wp-admin/admin.php?page=crmbiz-nl-settings' },
]

// ── 관리자 페이지 접근 차단 ───────────────────────────────────────────────

test.describe('Editor → 관리자 페이지 접근 차단', () => {

  for (const { name, path } of ADMIN_PAGES) {
    test(`${name} 페이지 접근 시 차단됨`, async ({ page }) => {
      await page.goto(path)
      await page.waitForLoadState('domcontentloaded')

      // WordPress의 "권한이 없습니다" 처리:
      //   - wp-login.php 로 리다이렉트 (세션 만료 오류)
      //   - "죄송합니다, 이 페이지에 접근할 수 있는 권한이 없습니다." 메시지
      //   - HTTP 403 응답
      const url     = page.url()
      const content = await page.locator('body').textContent()

      const isBlocked =
        url.includes('wp-login') ||
        url.includes('403') ||
        content?.includes('권한이 없습니다') ||
        content?.includes('죄송합니다') ||
        content?.includes('Sorry') ||
        content?.includes('Access denied')

      expect(isBlocked, `${name} 페이지가 editor에게 차단되어야 함`).toBeTruthy()
    })
  }

})

// ── REST API 접근 차단 ────────────────────────────────────────────────────

test.describe('Editor → REST API 접근 차단', () => {

  test('GET /dashboard → 403', async ({ page }) => {
    // editor 세션 쿠키를 활용한 REST API 요청
    const res = await page.request.get(`${API}/dashboard`)
    expect([401, 403]).toContain(res.status())
  })

  test('GET /newsletters → 403', async ({ page }) => {
    const res = await page.request.get(`${API}/newsletters`)
    expect([401, 403]).toContain(res.status())
  })

  test('POST /newsletters/1/send → 403', async ({ page }) => {
    const res = await page.request.post(`${API}/newsletters/1/send`)
    expect([401, 403]).toContain(res.status())
  })

  test('DELETE /newsletters/1 → 403', async ({ page }) => {
    const res = await page.request.delete(`${API}/newsletters/1`)
    expect([401, 403]).toContain(res.status())
  })

})

// ── 비인증 접근 차단 ──────────────────────────────────────────────────────

test.describe('비인증 → 관리자 페이지 리다이렉트', () => {

  test('로그인 없이 대시보드 접근 → wp-login.php 리다이렉트', async ({ browser }) => {
    // storageState 없는 새 컨텍스트 (비로그인 상태)
    const ctx  = await browser.newContext()
    const page = await ctx.newPage()

    await page.goto('wp-admin/admin.php?page=crmbiz-newsletter',
      { waitUntil: 'domcontentloaded' })

    await expect(page).toHaveURL(/wp-login/)
    await ctx.close()
  })

  test('비인증 REST API → 401', async ({ browser }) => {
    const ctx     = await browser.newContext()
    const anonReq = await ctx.request.newContext()

    const res = await anonReq.get(`${API}/newsletters`)
    expect(res.status()).toBe(401)

    await anonReq.dispose()
    await ctx.close()
  })

})
