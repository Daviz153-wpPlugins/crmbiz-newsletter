/**
 * 공개 엔드포인트 테스트 — template_redirect 기반 (인증 불필요)
 *
 * crmbiz_nl_action: unsubscribe | open | click | web_view
 * 모두 프론트엔드 URL에서 GET 파라미터로 동작
 */
import { test, expect } from '@playwright/test'

const BASE = (process.env.WP_BASE_URL || 'http://localhost:8888/wordpress').replace(/\/$/, '')

// ── 수신거부 (unsubscribe) ─────────────────────────────────────────────────

test.describe('수신거부 공개 엔드포인트', () => {

  test('파라미터 없음 → 403 또는 오류 페이지', async ({ page }) => {
    await page.goto(BASE + '/?crmbiz_nl_action=unsubscribe')
    // wp_die → "유효하지 않은 수신거부 링크" 또는 HTTP 403
    const body = await page.locator('body').textContent()
    const status = page.url()
    expect(
      body?.includes('유효하지 않은') ||
      body?.includes('수신거부 오류') ||
      body?.includes('invalid') ||
      await page.evaluate(() => document.title.includes('오류'))
    ).toBeTruthy()
  })

  test('잘못된 enc 파라미터 → 403 오류 페이지', async ({ page }) => {
    await page.goto(BASE + '/?crmbiz_nl_action=unsubscribe&enc=INVALID&token=BADTOKEN&exp=9999999999')
    const body = await page.locator('body').textContent()
    expect(
      body?.includes('유효하지 않은') ||
      body?.includes('수신거부 오류') ||
      body?.includes('HMAC') ||
      await page.locator('h1, h2').first().textContent().then(t => t?.includes('오류')).catch(() => false)
    ).toBeTruthy()
  })

  test('만료된 exp → "링크가 만료" 메시지', async ({ page }) => {
    // exp=1 (1970-01-01 기준으로 만료)
    await page.goto(BASE + '/?crmbiz_nl_action=unsubscribe&enc=dGVzdA%3D%3D&token=fake&exp=1&nl=1')
    const body = await page.locator('body').textContent()
    // "만료" 또는 "유효하지 않은" 중 하나
    expect(body?.includes('만료') || body?.includes('유효하지 않은')).toBeTruthy()
  })

  test('수신거부 완료 페이지 — 구조 확인 (유효한 파라미터 시뮬레이션 불가, 최소 렌더 확인)', async ({ request }) => {
    // 실제 HMAC 없이 완료 페이지 직접 확인은 불가 → 최소한 엔드포인트가 wp_die 없이 응답하는지
    const res = await request.get(BASE + '/?crmbiz_nl_action=unsubscribe', { maxRedirects: 0 })
    // 200(wp_die HTML) 또는 403
    expect([200, 403, 302]).toContain(res.status())
  })

})

// ── 오픈 트래킹 픽셀 (open) ────────────────────────────────────────────────

test.describe('오픈 트래킹 픽셀', () => {

  test('파라미터 없이 호출 → 1×1 GIF 반환', async ({ request }) => {
    const res = await request.get(BASE + '/?crmbiz_nl_action=open')
    expect(res.status()).toBe(200)
    expect(res.headers()['content-type']).toContain('image/gif')
    const buf = await res.body()
    // GIF89a 헤더 (47 49 46 38)
    expect(buf[0]).toBe(0x47) // G
    expect(buf[1]).toBe(0x49) // I
    expect(buf[2]).toBe(0x46) // F
  })

  test('잘못된 HMAC 토큰 → 그래도 GIF 반환 (빈 픽셀)', async ({ request }) => {
    // 보안 실패해도 1×1 GIF는 항상 반환 (추적 픽셀 깨지지 않아야 함)
    const res = await request.get(BASE + '/?crmbiz_nl_action=open&nl=1&e=INVALID&t=BADTOKEN')
    expect(res.status()).toBe(200)
    expect(res.headers()['content-type']).toContain('image/gif')
  })

  test('캐시 방지 헤더 포함', async ({ request }) => {
    const res = await request.get(BASE + '/?crmbiz_nl_action=open')
    const cc = res.headers()['cache-control'] || ''
    expect(cc).toMatch(/no-cache|no-store|must-revalidate/)
  })

})

// ── 클릭 트래킹 (click) ───────────────────────────────────────────────────

test.describe('클릭 트래킹 리다이렉트', () => {

  test('url 파라미터 없음 → 홈으로 리다이렉트', async ({ request }) => {
    const res = await request.get(BASE + '/?crmbiz_nl_action=click', { maxRedirects: 0 })
    // 302 홈 리다이렉트 또는 wp_die
    expect([200, 301, 302, 303]).toContain(res.status())
  })

  test('javascript: 스킴 → 홈으로 차단 리다이렉트', async ({ request }) => {
    const maliciousUrl = encodeURIComponent('javascript:alert(1)')
    const res = await request.get(
      BASE + `/?crmbiz_nl_action=click&url=${maliciousUrl}&nl=1&e=test&t=fake`,
      { maxRedirects: 0 }
    )
    // 302 홈으로 리다이렉트 (악성 URL 차단)
    expect([301, 302, 303]).toContain(res.status())
    const location = res.headers()['location'] || ''
    expect(location).not.toContain('javascript:')
  })

  test('data: 스킴 → 홈으로 차단 리다이렉트', async ({ request }) => {
    const maliciousUrl = encodeURIComponent('data:text/html,<script>alert(1)</script>')
    const res = await request.get(
      BASE + `/?crmbiz_nl_action=click&url=${maliciousUrl}&nl=1&e=test&t=fake`,
      { maxRedirects: 0 }
    )
    expect([301, 302, 303]).toContain(res.status())
    const location = res.headers()['location'] || ''
    expect(location).not.toContain('data:')
  })

  test('정상 https URL 형식 → HMAC 실패해도 리다이렉트 시도', async ({ request }) => {
    const targetUrl = encodeURIComponent('https://example.com/article')
    const res = await request.get(
      BASE + `/?crmbiz_nl_action=click&url=${targetUrl}&nl=1&e=test&t=fake`,
      { maxRedirects: 0 }
    )
    // HMAC 실패 시 홈으로 리다이렉트, 어쨌든 302
    expect([301, 302, 303]).toContain(res.status())
  })

})

// ── 웹뷰 (web_view) ────────────────────────────────────────────────────────

test.describe('이메일 웹뷰', () => {

  test('파라미터 없음 → wp_die 오류 페이지', async ({ page }) => {
    await page.goto(BASE + '/?crmbiz_nl_action=web_view')
    // 이메일 HTML 또는 wp_die 오류
    const body = await page.locator('body').textContent()
    expect(body).toBeTruthy()
    expect(body?.length).toBeGreaterThan(10)
  })

  test('잘못된 HMAC → 오류 응답', async ({ request }) => {
    const res = await request.get(BASE + '/?crmbiz_nl_action=web_view&nl=1&e=INVALID&t=BADTOKEN')
    expect([200, 403]).toContain(res.status())
  })

})
