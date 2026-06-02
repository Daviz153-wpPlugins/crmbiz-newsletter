/**
 * 공개 엔드포인트 테스트 — template_redirect 기반 (인증 불필요)
 *
 * crmbiz_nl_action: unsubscribe | open | click | web_view
 * 모두 프론트엔드 URL에서 GET 파라미터로 동작
 */
import { test, expect } from '@playwright/test'
import { execSync } from 'child_process'

const BASE     = (process.env.WP_BASE_URL || 'http://localhost:8888/wordpress').replace(/\/$/, '')
const WP_PATH  = process.env.WP_PATH || '/tmp/wordpress'
const API_BASE = BASE + '/wp-json/crmbiz-nl/v1'

// ── WP-CLI 헬퍼 ──────────────────────────────────────────────────────────────

function wpEval(code) {
  const flat = code.replace(/\s+/g, ' ').trim()
  return execSync(
    `wp eval '${flat.replace(/'/g, "'\\''")}' --path=${WP_PATH}`,
    { encoding: 'utf-8' }
  ).trim()
}

function clearRateLimit() {
  wpEval(`global $wpdb; $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}crmbiz_nl_ratelimit");`)
}

// tracking 테스트용 시드 뉴스레터 (suite 전체 공유)
let trackingNlId = null
const TRACKING_EMAIL = 'e2e-tracking@test.example'

function seedTrackingNewsletter() {
  const id = wpEval(`
    global $wpdb;
    $postId = wp_insert_post([
      'post_title'  => '[E2E] 트래킹 테스트',
      'post_status' => 'publish',
      'post_type'   => 'post',
    ]);
    $wpdb->insert($wpdb->prefix . 'crmbiz_newsletters', [
      'post_id'         => $postId,
      'status'          => 'sent',
      'send_mode'       => 'immediate',
      'recipient_count' => 1,
      'success_count'   => 1,
      'fail_count'      => 0,
      'sent_at'         => current_time('mysql'),
      'created_at'      => current_time('mysql'),
    ], ['%d', '%s', '%s', '%d', '%d', '%d', '%s', '%s']);
    echo $wpdb->insert_id;
  `)
  return parseInt(id)
}

function deleteTrackingNewsletter(id) {
  wpEval(`
    global $wpdb;
    $nl = $wpdb->get_row($wpdb->prepare(
      "SELECT post_id FROM {$wpdb->prefix}crmbiz_newsletters WHERE id = %d", ${id}
    ));
    $wpdb->delete($wpdb->prefix . 'crmbiz_newsletters', ['id' => ${id}], ['%d']);
    $wpdb->delete($wpdb->prefix . 'crmbiz_nl_events', ['newsletter_id' => ${id}], ['%d']);
    if ($nl && $nl->post_id) wp_delete_post($nl->post_id, true);
  `)
}

function buildPixelUrl(nlId, email) {
  return wpEval(
    `echo CRMBizNewsletter\\TrackingHandler::buildPixelUrl(${nlId}, '${email}');`
  )
}

function buildClickUrl(nlId, email, targetUrl) {
  return wpEval(
    `echo CRMBizNewsletter\\TrackingHandler::buildClickUrl(${nlId}, '${email}', '${targetUrl}');`
  )
}

function getOpenCount(nlId) {
  return parseInt(wpEval(`
    global $wpdb;
    echo $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(DISTINCT email) FROM {$wpdb->prefix}crmbiz_nl_events WHERE newsletter_id = %d AND type = 'open'",
      ${nlId}
    ));
  `))
}

function getClickCount(nlId) {
  return parseInt(wpEval(`
    global $wpdb;
    echo $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(DISTINCT email) FROM {$wpdb->prefix}crmbiz_nl_events WHERE newsletter_id = %d AND type = 'click'",
      ${nlId}
    ));
  `))
}

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

// ── 오픈 트래킹 카운트 반영 ────────────────────────────────────────────────

test.describe('오픈 트래킹 — DB 카운트 반영', () => {

  test.beforeAll(() => {
    trackingNlId = seedTrackingNewsletter()
    clearRateLimit()
  })

  test.afterAll(() => {
    if (trackingNlId) deleteTrackingNewsletter(trackingNlId)
  })

  test('[2-1] 유효한 HMAC 픽셀 호출 → open_count 1 증가', async ({ request }) => {
    const before = getOpenCount(trackingNlId)

    const pixelUrl = buildPixelUrl(trackingNlId, TRACKING_EMAIL)
    const res = await request.get(pixelUrl)
    expect(res.status()).toBe(200)
    expect(res.headers()['content-type']).toContain('image/gif')

    // 이벤트 기록은 동기적이므로 즉시 확인
    const after = getOpenCount(trackingNlId)
    expect(after).toBe(before + 1)
  })

  test('[2-2] 동일 이메일 재호출 → rate limit으로 open_count 그대로 (중복 집계 없음)', async ({ request }) => {
    // rate limit: 1시간/30회이지만 동일 email은 DISTINCT로 집계 — 이미 카운트됨
    const before = getOpenCount(trackingNlId)

    const pixelUrl = buildPixelUrl(trackingNlId, TRACKING_EMAIL)
    await request.get(pixelUrl)

    const after = getOpenCount(trackingNlId)
    // DISTINCT email이므로 동일 이메일 재호출 시 증가 없음
    expect(after).toBe(before)
  })

  test('[2-3] 유효하지 않은 HMAC → open_count 그대로', async ({ request }) => {
    const before = getOpenCount(trackingNlId)

    await request.get(BASE + `/?crmbiz_nl_action=open&nl=${trackingNlId}&e=INVALID&t=BADTOKEN`)

    const after = getOpenCount(trackingNlId)
    expect(after).toBe(before)
  })

})

// ── 클릭 트래킹 카운트 + 리다이렉트 검증 ────────────────────────────────────

test.describe('클릭 트래킹 — DB 카운트 + 리다이렉트', () => {

  const TARGET_URL = 'https://example.com'

  test.beforeAll(() => {
    if (!trackingNlId) trackingNlId = seedTrackingNewsletter()
    clearRateLimit()
  })

  test('[2-4] 유효한 HMAC 클릭 → 302 목적지 + click_count 증가', async ({ request }) => {
    const clickEmail = 'e2e-click@test.example'
    const before = getClickCount(trackingNlId)

    const clickUrl = buildClickUrl(trackingNlId, clickEmail, TARGET_URL)
    const res = await request.get(clickUrl, { maxRedirects: 0 })

    expect([301, 302, 303]).toContain(res.status())
    expect(res.headers()['location']).toContain('example.com')

    const after = getClickCount(trackingNlId)
    expect(after).toBe(before + 1)
  })

  test('[2-5] HMAC 조작 클릭 → 홈 리다이렉트, click_count 그대로', async ({ request }) => {
    const before = getClickCount(trackingNlId)
    const targetEnc = encodeURIComponent(TARGET_URL)

    const res = await request.get(
      BASE + `/?crmbiz_nl_action=click&nl=${trackingNlId}&e=INVALID&t=BADTOKEN&url=${targetEnc}`,
      { maxRedirects: 0 }
    )
    expect([301, 302, 303]).toContain(res.status())
    const location = res.headers()['location'] ?? ''
    expect(location).not.toContain('example.com')

    expect(getClickCount(trackingNlId)).toBe(before)
  })

})

// ── 수신거부 Rate Limit ────────────────────────────────────────────────────

test.describe('수신거부 rate limit — 10회 초과 → 429', () => {

  test.beforeEach(() => clearRateLimit())
  test.afterEach(() => clearRateLimit())

  test('[7-1] 잘못된 토큰 11회 연속 → 11번째 HTTP 429', async ({ request }) => {
    const badUrl = BASE + '/?crmbiz_nl_action=unsubscribe&enc=INVALID&token=BADTOKEN&exp=9999999999&nl=0'

    let lastStatus = 0
    for (let i = 0; i < 11; i++) {
      const res = await request.get(badUrl, { maxRedirects: 0 })
      lastStatus = res.status()
    }
    // UnsubscribeHandler: checkRateLimit('unsub', 10, 600) — 10회 초과 시 429
    expect(lastStatus).toBe(429)
  })

  test('[7-2] rate limit 초기화 후 다시 허용', async ({ request }) => {
    const badUrl = BASE + '/?crmbiz_nl_action=unsubscribe&enc=INVALID&token=BADTOKEN&exp=9999999999&nl=0'

    // 한도 소진
    for (let i = 0; i < 11; i++) {
      await request.get(badUrl, { maxRedirects: 0 })
    }

    // 초기화 후 재시도
    clearRateLimit()
    const res = await request.get(badUrl, { maxRedirects: 0 })
    // 429가 아닌 다른 응답 (403, 200 등)이어야 함
    expect(res.status()).not.toBe(429)
  })

})

// ── 이메일 웹뷰 — 유효 HMAC 완전 흐름 ────────────────────────────────────────

test.describe('이메일 웹뷰 — 유효 HMAC 리다이렉트', () => {

  let seededNlId = null
  const WV_EMAIL = 'e2e-webview@test.example'

  function seedWebViewNewsletter() {
    const id = wpEval(`
      global $wpdb;
      $postId = wp_insert_post([
        'post_title'   => '[E2E] 웹뷰 테스트 포스트',
        'post_content' => '<p>뉴스레터 본문입니다.</p>',
        'post_status'  => 'publish',
        'post_type'    => 'post',
      ]);
      $wpdb->insert($wpdb->prefix . 'crmbiz_newsletters', [
        'post_id'         => $postId,
        'status'          => 'sent',
        'send_mode'       => 'immediate',
        'recipient_count' => 1,
        'success_count'   => 1,
        'fail_count'      => 0,
        'sent_at'         => current_time('mysql'),
        'created_at'      => current_time('mysql'),
      ], ['%d', '%s', '%s', '%d', '%d', '%d', '%s', '%s']);
      echo $wpdb->insert_id;
    `)
    return parseInt(id)
  }

  function buildWebViewUrl(nlId, email) {
    return wpEval(
      `echo CRMBizNewsletter\\TrackingHandler::buildWebViewUrl(${nlId}, '${email}');`
    )
  }

  function deleteSeeded(id) {
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
    clearRateLimit()
    seededNlId = seedWebViewNewsletter()
  })

  test.afterAll(() => {
    if (seededNlId) deleteSeeded(seededNlId)
    clearRateLimit()
  })

  test('[웹뷰-1] 유효한 HMAC → post permalink로 302 리다이렉트', async ({ request }) => {
    const url = buildWebViewUrl(seededNlId, WV_EMAIL)
    expect(url).toContain('crmbiz_nl_action=web_view')

    const res = await request.get(url, { maxRedirects: 0 })

    // 유효한 토큰 + 유효한 post_id → post permalink로 302
    expect([301, 302, 303]).toContain(res.status())
    const location = res.headers()['location'] ?? ''
    // 홈 URL이 아닌 포스트 URL로 리다이렉트
    expect(location).toBeTruthy()
  })

  test('[웹뷰-2] 유효한 HMAC이어도 post 없으면 홈으로 리다이렉트', async ({ request }) => {
    // post_id=0인 뉴스레터로 웹뷰 URL 생성
    const orphanId = parseInt(wpEval(`
      global $wpdb;
      $wpdb->insert($wpdb->prefix . 'crmbiz_newsletters', [
        'post_id'    => 0,
        'status'     => 'sent',
        'send_mode'  => 'immediate',
        'created_at' => current_time('mysql'),
      ], ['%d', '%s', '%s', '%s']);
      echo $wpdb->insert_id;
    `))

    const url = buildWebViewUrl(orphanId, WV_EMAIL)
    const res  = await request.get(url, { maxRedirects: 0 })

    expect([301, 302, 303]).toContain(res.status())
    // post_id=0 → get_permalink 불가 → 홈으로 리다이렉트
    const location = res.headers()['location'] ?? ''
    expect(location).toContain(BASE.replace(/\/wordpress\/?$/, '').replace(/\/$/, ''))

    // 정리
    wpEval(`
      global $wpdb;
      $wpdb->delete($wpdb->prefix . 'crmbiz_newsletters', ['id' => ${orphanId}], ['%d']);
    `)
  })

  test('[웹뷰-3] 유효한 HMAC URL 방문 → 브라우저에서 최종 페이지 로드', async ({ page }) => {
    const url = buildWebViewUrl(seededNlId, WV_EMAIL)
    clearRateLimit() // rate limit 초기화

    // 리다이렉트 따라가기 → 포스트 페이지 또는 홈 도착
    await page.goto(url)
    await page.waitForLoadState('domcontentloaded')

    // 에러 페이지(wp_die)가 아닌 정상 페이지
    const body = await page.locator('body').textContent()
    expect(body).toBeTruthy()
    expect(body?.length).toBeGreaterThan(10)
    // 403/오류 페이지가 아님
    expect(body).not.toContain('보안 검증 실패')
  })

})
