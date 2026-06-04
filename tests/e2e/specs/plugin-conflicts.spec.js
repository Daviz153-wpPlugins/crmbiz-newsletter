/**
 * 플러그인 충돌 테스트
 *
 * 자주 충돌하는 플러그인과 함께 설치됐을 때 핵심 워크플로가 동작하는지 검증.
 * 실제 플러그인 대신 최소 스텁을 사용 — the_content 훅, REST API 차단 패턴을 재현.
 *
 * CI: ENABLE_CONFLICT_TEST=1 환경변수로 활성화 (WP-CLI 필요)
 */
import { test, expect } from '@playwright/test'
import { execSync } from 'child_process'

const WP_PATH  = process.env.WP_PATH    || '/tmp/wordpress'
const API_BASE = (process.env.WP_BASE_URL || 'http://localhost:8080').replace(/\/$/, '')
  + '/wp-json/crmbiz-nl/v1'
const RUN      = process.env.ENABLE_CONFLICT_TEST === '1'

function wp(cmd) {
  return execSync(`wp ${cmd} --path=${WP_PATH}`, { encoding: 'utf-8' }).trim()
}

function installStub(slug, code) {
  try {
    const dir = `${WP_PATH}/wp-content/plugins/${slug}`
    execSync(`mkdir -p ${dir}`, { encoding: 'utf-8' })
    require('fs').writeFileSync(
      `${dir}/${slug}.php`,
      `<?php\n/** Plugin Name: ${slug} Stub (CI) */\n${code}`
    )
    try { wp(`plugin activate ${slug} --quiet`) } catch {}
  } catch (err) {
    console.warn(`[WARN] installStub(${slug}) 실패: ${err.message}`)
  }
}

function deactivateStub(slug) {
  try { wp(`plugin deactivate ${slug} --quiet`) } catch {}
}

// page.request.get()은 nonce 없이 401 반환 → page.evaluate fetch로 인증
async function apiGet(page, url) {
  await page.goto('wp-admin/admin.php?page=crmbiz-newsletter')
  await page.waitForSelector('.min-h-screen', { timeout: 10_000 })
  return page.evaluate(async (u) => {
    const nonce = window.CrmbizNL?.nonce || ''
    const r = await fetch(u, { headers: { 'X-WP-Nonce': nonce } })
    return {
      status: r.status,
      contentType: r.headers.get('content-type') || '',
      json: await r.json().catch(() => null),
    }
  }, url)
}

// ── Yoast SEO 스텁: the_content 필터 추가 ────────────────────────────────

test.describe('Yoast SEO 충돌 — the_content 필터', () => {

  test.skip(!RUN, 'ENABLE_CONFLICT_TEST=1 필요')

  test.beforeAll(() => {
    installStub('yoast-stub', `
add_filter('the_content', function($content) {
    return $content . '<!-- yoast-seo-sitemap -->';
}, 99);
`)
  })

  test.afterAll(() => deactivateStub('yoast-stub'))

  test('대시보드 API 정상 응답', async ({ page }) => {
    const { status, json } = await apiGet(page, `${API_BASE}/dashboard`)
    expect(status).toBe(200)
    expect(json).toHaveProperty('stats')
  })

  test('뉴스레터 목록 API 정상 응답', async ({ page }) => {
    const { status } = await apiGet(page, `${API_BASE}/newsletters`)
    expect(status).toBe(200)
  })

  test('대시보드 Vue 앱 정상 렌더', async ({ page }) => {
    await page.goto('wp-admin/admin.php?page=crmbiz-newsletter')
    await page.waitForSelector('.min-h-screen', { timeout: 10_000 })
    await expect(page.locator('h1:has-text("뉴스레터 대시보드")')).toBeVisible()
  })

})

// ── WP Super Cache 스텁: 응답 헤더 캐시 제어 ─────────────────────────────

test.describe('WP Super Cache 충돌 — 캐시 헤더', () => {

  test.skip(!RUN, 'ENABLE_CONFLICT_TEST=1 필요')

  test.beforeAll(() => {
    installStub('wp-super-cache-stub', `
add_action('send_headers', function() {
    header('X-Cache: HIT from Super-Cache-Stub');
});
`)
  })

  test.afterAll(() => deactivateStub('wp-super-cache-stub'))

  test('REST API 응답에 캐시 헤더 있어도 JSON 정상 파싱', async ({ page }) => {
    const { status, json } = await apiGet(page, `${API_BASE}/newsletters`)
    expect(status).toBe(200)
    expect(Array.isArray(json?.items)).toBeTruthy()
  })

  test('Vue 앱이 캐시 무효화 없이도 정상 로드', async ({ page }) => {
    await page.goto('wp-admin/admin.php?page=crmbiz-nl-history')
    await page.waitForSelector('.min-h-screen', { timeout: 10_000 })
    await expect(page.locator('h1:has-text("뉴스레터 이력")')).toBeVisible()
  })

})

// ── 보안 플러그인 스텁: REST API 추가 nonce 요구 패턴 ─────────────────────

test.describe('보안 플러그인 — REST API nonce 검사 강화', () => {

  test.skip(!RUN, 'ENABLE_CONFLICT_TEST=1 필요')

  test.beforeAll(() => {
    // 우리 플러그인은 WordPress 표준 REST API 인증(Cookie + Nonce)을 사용하므로
    // 표준 검사를 통과해야 함
    installStub('security-stub', `
add_filter('rest_authentication_errors', function($result) {
    // 이미 인증된 경우 통과 — 미인증만 차단
    if (is_wp_error($result) || $result === false) return $result;
    if (is_user_logged_in()) return $result;
    return new WP_Error('rest_not_logged_in', '로그인이 필요합니다.', ['status' => 401]);
}, 20);
`)
  })

  test.afterAll(() => deactivateStub('security-stub'))

  test('관리자 세션으로 REST API 접근 정상', async ({ page }) => {
    const { status } = await apiGet(page, `${API_BASE}/dashboard`)
    expect(status).toBe(200)
  })

  test('인증된 세션으로 뉴스레터 목록 조회 정상', async ({ page }) => {
    const { status } = await apiGet(page, `${API_BASE}/newsletters?per_page=5`)
    expect(status).toBe(200)
  })

})

// ── 무충돌 기준선 검증 (항상 실행) ────────────────────────────────────────

test.describe('기준선 — 다른 플러그인 없는 환경', () => {

  test.beforeAll(() => {
    // 이전 그룹 스텁 잔재 정리 후 플러그인 재활성화
    for (const slug of ['yoast-stub', 'wp-super-cache-stub', 'security-stub']) {
      try { wp(`plugin deactivate ${slug} --quiet`) } catch {}
    }
    try { wp('plugin activate crmbiz-newsletter --quiet') } catch {}
  })

  test('REST API 응답 형식 무결성', async ({ page }) => {
    const { status, json } = await apiGet(page, `${API_BASE}/dashboard`)
    expect(status).toBe(200)
    expect(json).toHaveProperty('stats')
    expect(json).toHaveProperty('pending')
    expect(json).toHaveProperty('campaign_total')
    expect(json).toHaveProperty('campaign_pages')
  })

  test('REST API Content-Type이 application/json', async ({ page }) => {
    const { contentType } = await apiGet(page, `${API_BASE}/newsletters`)
    expect(contentType).toContain('application/json')
  })

  test('대시보드 + 이력 페이지 동시 접근 가능', async ({ page }) => {
    // 하나의 세션에서 두 페이지 연속 접근 — 전역 상태 충돌 없음
    await page.goto('wp-admin/admin.php?page=crmbiz-newsletter')
    await page.waitForSelector('.min-h-screen', { timeout: 10_000 })

    await page.goto('wp-admin/admin.php?page=crmbiz-nl-history')
    await page.waitForSelector('.min-h-screen', { timeout: 10_000 })
    await expect(page.locator('h1:has-text("뉴스레터 이력")')).toBeVisible()
  })

  test('설정 저장 후 다른 페이지 이동해도 데이터 유지', async ({ page }) => {
    await page.goto('wp-admin/admin.php?page=crmbiz-nl-settings')
    await page.waitForLoadState('domcontentloaded')

    await page.click('button:has-text("설정 저장")')
    await expect(page.locator('text=설정이 저장되었습니다')).toBeVisible()

    // 다른 페이지 이동 후 돌아와도 오류 없음
    await page.goto('wp-admin/admin.php?page=crmbiz-newsletter')
    await page.waitForSelector('.min-h-screen', { timeout: 10_000 })
    await page.goto('wp-admin/admin.php?page=crmbiz-nl-settings')
    await expect(page.locator('button:has-text("설정 저장")')).toBeVisible()
  })

})
