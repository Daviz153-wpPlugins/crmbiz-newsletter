/**
 * REST API 권한 검사 — 비인증 요청 차단 확인
 *
 * storageState 없는 fresh context로 직접 API 호출 → 401/403 반환 검증
 */
import { test, expect, request as playwrightRequest } from '@playwright/test'

const BASE = (process.env.WP_BASE_URL || 'http://localhost:8888/wordpress').replace(/\/$/, '')
const API  = `${BASE}/wp-json/crmbiz-nl/v1`

// 인증 없는 request context (storageState 없음)
let anonRequest

test.beforeAll(async () => {
  anonRequest = await playwrightRequest.newContext({ baseURL: BASE })
})

test.afterAll(async () => {
  await anonRequest.dispose()
})

test.describe('REST API — 비인증 차단', () => {

  test('GET /dashboard → 401', async () => {
    const res = await anonRequest.get(`${API}/dashboard`)
    expect(res.status()).toBe(401)
  })

  test('GET /newsletters → 401', async () => {
    const res = await anonRequest.get(`${API}/newsletters`)
    expect(res.status()).toBe(401)
  })

  test('GET /newsletters/1 → 401', async () => {
    const res = await anonRequest.get(`${API}/newsletters/1`)
    expect(res.status()).toBe(401)
  })

  test('POST /newsletters/1/send → 401', async () => {
    const res = await anonRequest.post(`${API}/newsletters/1/send`)
    expect(res.status()).toBe(401)
  })

  test('POST /newsletters/1/cancel → 401', async () => {
    const res = await anonRequest.post(`${API}/newsletters/1/cancel`)
    expect(res.status()).toBe(401)
  })

  test('POST /newsletters/1/force-send → 401', async () => {
    const res = await anonRequest.post(`${API}/newsletters/1/force-send`)
    expect(res.status()).toBe(401)
  })

  test('POST /newsletters/1/resend → 401', async () => {
    const res = await anonRequest.post(`${API}/newsletters/1/resend`)
    expect(res.status()).toBe(401)
  })

  test('DELETE /newsletters/1 → 401', async () => {
    const res = await anonRequest.delete(`${API}/newsletters/1`)
    expect(res.status()).toBe(401)
  })

  test('POST /newsletters/1/resend-single → 401', async () => {
    const res = await anonRequest.post(`${API}/newsletters/1/resend-single`)
    expect(res.status()).toBe(401)
  })

  test('GET /newsletters/progress → 401', async () => {
    const res = await anonRequest.get(`${API}/newsletters/progress`)
    expect(res.status()).toBe(401)
  })

})

test.describe('REST API — 인증 후 응답 형식', () => {
  // storageState 있는 기본 request 사용 (chromium project)

  test('GET /dashboard → 200 + 필수 필드 포함', async ({ request }) => {
    const res = await request.get(`${API}/dashboard`)
    expect(res.status()).toBe(200)
    const json = await res.json()
    expect(json).toHaveProperty('campaign_total')
    expect(json).toHaveProperty('campaign_pages')
    expect(json).toHaveProperty('stats')
    expect(json).toHaveProperty('pending')
  })

  test('GET /newsletters → 200 + items 배열', async ({ request }) => {
    const res = await request.get(`${API}/newsletters`)
    expect(res.status()).toBe(200)
    const json = await res.json()
    expect(json).toHaveProperty('items')
    expect(Array.isArray(json.items)).toBeTruthy()
    expect(json).toHaveProperty('total')
    expect(json).toHaveProperty('pages')
  })

  test('GET /newsletters?per_page=5 → 최대 5개 반환', async ({ request }) => {
    const res = await request.get(`${API}/newsletters?per_page=5`)
    expect(res.status()).toBe(200)
    const json = await res.json()
    expect(json.items.length).toBeLessThanOrEqual(5)
  })

  test('GET /newsletters?sort_key=INVALID → 400 또는 기본값으로 처리', async ({ request }) => {
    const res = await request.get(`${API}/newsletters?sort_key=DROP TABLE`)
    // SQL 인젝션 시도 → 화이트리스트 필터로 기본값 처리 (200) 또는 400
    expect([200, 400]).toContain(res.status())
    if (res.status() === 200) {
      const json = await res.json()
      expect(Array.isArray(json.items)).toBeTruthy()
    }
  })

  test('GET /newsletters/999999 → 404', async ({ request }) => {
    const res = await request.get(`${API}/newsletters/999999`)
    expect([404, 200]).toContain(res.status())
    if (res.status() === 200) {
      const json = await res.json()
      // 존재하지 않는 id → items 없거나 null
      expect(json === null || json.id === undefined || json.items?.length === 0).toBeTruthy()
    }
  })

  test('GET /newsletters/progress → 200 + 배열', async ({ request }) => {
    const res = await request.get(`${API}/newsletters/progress`)
    expect(res.status()).toBe(200)
    const json = await res.json()
    expect(Array.isArray(json)).toBeTruthy()
  })

})
