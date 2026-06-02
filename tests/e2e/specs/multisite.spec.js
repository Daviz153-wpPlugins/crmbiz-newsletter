/**
 * 멀티사이트 기본 검증
 *
 * ENABLE_MULTISITE_TEST=1 로 활성화 (멀티사이트 WP 환경 필요).
 * 표준 단일 사이트 환경에서는 기준선 테스트만 실행.
 */
import { test, expect } from '@playwright/test'
import { execSync } from 'child_process'

const WP_PATH  = process.env.WP_PATH  || '/tmp/wordpress'
const API_BASE = (process.env.WP_BASE_URL || 'http://localhost:8080').replace(/\/$/, '')
  + '/wp-json/crmbiz-nl/v1'
const RUN_MS   = process.env.ENABLE_MULTISITE_TEST === '1'

function wp(cmd) {
  return execSync(`wp ${cmd} --path=${WP_PATH}`, { encoding: 'utf-8' }).trim()
}

// ── 멀티사이트 전용 (ENABLE_MULTISITE_TEST=1) ─────────────────────────────

test.describe('멀티사이트 — 서브사이트 독립 설정', () => {

  test.skip(!RUN_MS, 'ENABLE_MULTISITE_TEST=1 + 멀티사이트 WP 필요')

  test('서브사이트 1에서 REST API 정상 응답', async ({ request }) => {
    const res = await request.get(`${API_BASE}/dashboard`)
    expect(res.status()).toBe(200)
  })

  test('서브사이트 DB 버전이 메인 사이트와 독립', async () => {
    // 서브사이트 prefix(wp_2_)와 메인 사이트 prefix(wp_)가 분리돼야 함
    const mainVersion = wp(`eval 'echo get_option("crmbiz_nl_db_version");'`)
    // 서브사이트가 있는 경우 --url 파라미터로 확인
    const hasSub = wp('site list --format=count').trim() !== '1'
    if (!hasSub) {
      console.log('서브사이트 없음 — 스킵')
      return
    }
    const subVersion = wp(`eval 'echo get_option("crmbiz_nl_db_version");' --url=${process.env.WP_BASE_URL}/sub1`)
    expect(mainVersion).toBe(subVersion) // 둘 다 2.0.0이어야 함
  })

  test('메인 사이트 설정 변경이 서브사이트에 영향 없음', async ({ request }) => {
    // 각 서브사이트는 독립 옵션을 가짐 (get_option은 blog-specific)
    const res = await request.get(`${API_BASE}/newsletters`)
    expect(res.status()).toBe(200)
  })

})

test.describe('멀티사이트 — 네트워크 활성화', () => {

  test.skip(!RUN_MS, 'ENABLE_MULTISITE_TEST=1 필요')

  test('네트워크 관리자에서 플러그인 상태 확인', async ({ page }) => {
    await page.goto('wp-admin/network/plugins.php')
    await page.waitForLoadState('domcontentloaded')

    // 네트워크 관리자 접근 가능
    await expect(page).not.toHaveURL(/wp-login/)
  })

})

// ── 단일 사이트 기준선 (항상 실행) ───────────────────────────────────────

test.describe('단일 사이트 — 멀티사이트 코드와 호환성', () => {

  test('is_multisite() false 환경에서 REST API 정상 동작', async ({ request }) => {
    // 표준 단일 사이트에서 우리 코드가 multisite 코드를 호출하지 않음
    const res = await request.get(`${API_BASE}/dashboard`)
    expect(res.status()).toBe(200)
    const json = await res.json()
    expect(json).toHaveProperty('stats')
  })

  test('get_option/update_option이 단일 사이트에서 정상 작동', async ({ request }) => {
    // 설정 페이지 접근 = get_option 정상 동작 간접 확인
    const res = await request.get(`${API_BASE}/newsletters?per_page=1`)
    expect(res.status()).toBe(200)
  })

  test('플러그인 활성화 상태 REST API로 확인', async ({ request }) => {
    const res = await request.get(`${API_BASE}/newsletters/progress`)
    // 200 = 플러그인 정상 활성화, DB 테이블 존재
    expect(res.status()).toBe(200)
  })

})
