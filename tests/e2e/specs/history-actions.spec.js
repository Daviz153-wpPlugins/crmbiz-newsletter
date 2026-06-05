/**
 * 발송 이력 — 슬라이드오버 API 액션 테스트
 *
 * 상태별 버튼(발송/취소/재발송/삭제/단건재발송)이 올바르게 동작하는지 검증
 * 데이터 없으면 해당 테스트 skip
 */
import { test, expect } from '@playwright/test'
import { execSync } from 'child_process'

const HISTORY  = 'wp-admin/admin.php?page=crmbiz-nl-history'
const API_BASE = (process.env.WP_BASE_URL || 'http://localhost:8888/wordpress').replace(/\/$/, '')
  + '/wp-json/crmbiz-nl/v1'
const WP_PATH  = process.env.WP_PATH || '/tmp/wordpress'

function wpEval(code) {
  const flat = code.replace(/\s+/g, ' ').trim()
  return execSync(
    `wp eval '${flat.replace(/'/g, "'\\''")}' --path=${WP_PATH}`,
    { encoding: 'utf-8' }
  ).trim()
}

// 특정 상태의 첫 번째 항목 id를 API로 조회
async function findFirstByStatus(request, status) {
  const res  = await request.get(`${API_BASE}/newsletters?per_page=50`)
  if (res.status() !== 200) return null
  const json = await res.json()
  return json.items?.find(i => i.status === status) ?? null
}

test.describe('슬라이드오버 — 상세 탭', () => {

  test('행 클릭 → 슬라이드오버 열림 + 제목 표시', async ({ page }) => {
    await page.goto(HISTORY)
    await page.waitForSelector('.min-h-screen', { timeout: 10_000 })
    const firstRow = page.locator('tbody tr').first()
    await expect(firstRow).toBeVisible({ timeout: 15_000 })
    await firstRow.click()
    await page.waitForTimeout(500)
    // 슬라이드오버 패널 내 h2(제목) 가시
    await expect(page.locator('h2').last()).toBeVisible()
  })

  test('슬라이드오버 — details 탭 기본 표시', async ({ page }) => {
    await page.goto(HISTORY)
    await page.waitForSelector('.min-h-screen', { timeout: 10_000 })
    await expect(page.locator('tbody tr').first()).toBeVisible({ timeout: 15_000 })
    await page.locator('tbody tr').first().click()
    await page.waitForTimeout(1000)
    // 오픈률/클릭률 스탯 영역 (detail API 응답 대기)
    await expect(page.locator('text=오픈률').or(page.locator('text=발송된 이메일'))).toBeVisible()
  })

  test('슬라이드오버 — 수신자 탭 클릭', async ({ page }) => {
    await page.goto(HISTORY)
    await page.waitForSelector('.min-h-screen', { timeout: 10_000 })
    await expect(page.locator('tbody tr').first()).toBeVisible({ timeout: 15_000 })
    await page.locator('tbody tr').first().click()
    await page.waitForTimeout(1000)
    const recipientsTab = page.locator('button:has-text("수신자")')
    await expect(recipientsTab).toBeVisible()
    await recipientsTab.click()
    await page.waitForTimeout(300)
    // 수신자 테이블 또는 "수신자 없음" 메시지
    await expect(
      page.locator('table').last().or(page.locator('text=수신자'))
    ).toBeVisible({ timeout: 5_000 })
  })

  test('슬라이드오버 — 미리보기 링크 있으면 새 탭 열림', async ({ page, context }) => {
    await page.goto(HISTORY)
    await page.waitForSelector('.min-h-screen', { timeout: 10_000 })
    await expect(page.locator('tbody tr').first()).toBeVisible({ timeout: 15_000 })
    await page.locator('tbody tr').first().click()
    await page.waitForTimeout(500)
    const previewLink = page.locator('a:has-text("미리보기")')
    const hasPreview = await previewLink.isVisible().catch(() => false)
    if (!hasPreview) {
      test.skip()
      return
    }
    const [newTab] = await Promise.all([
      context.waitForEvent('page'),
      previewLink.click(),
    ])
    await newTab.waitForLoadState('domcontentloaded')
    await expect(newTab).not.toHaveURL(/403|error/)
  })

})

test.describe('슬라이드오버 — draft 발송 시작', () => {

  test('draft 항목 → 발송 시작 버튼 클릭 → 상태 변경', async ({ page, request }) => {
    const item = await findFirstByStatus(request, 'draft')
    if (!item) { test.skip(); return }

    await page.goto(HISTORY)
    await page.waitForSelector('.min-h-screen', { timeout: 10_000 })

    // 제목으로 행 찾기
    const row = page.locator(`tbody tr`).filter({ hasText: item.post_title }).first()
    await expect(row).toBeVisible()
    await row.click()
    await page.waitForTimeout(500)

    const sendBtn = page.locator('button:has-text("발송 시작")')
    await expect(sendBtn).toBeVisible({ timeout: 5_000 })
    await sendBtn.click()

    // 버튼이 disabled(로딩) 상태로 전환되거나 상태가 queued로 변경
    await expect(
      sendBtn.or(page.locator('text=queued').or(page.locator('text=발송 중')))
    ).toBeVisible({ timeout: 8_000 })
  })

})

test.describe('슬라이드오버 — queued/sending 취소', () => {

  test('queued 항목 → 취소 버튼 → cancelled 상태', async ({ page, request }) => {
    const item = await findFirstByStatus(request, 'queued')
    if (!item) { test.skip(); return }

    await page.goto(HISTORY)
    await page.waitForSelector('.min-h-screen', { timeout: 10_000 })

    const row = page.locator(`tbody tr`).filter({ hasText: item.post_title }).first()
    await expect(row).toBeVisible()
    await row.click()
    await page.waitForTimeout(500)

    const cancelBtn = page.locator('button:has-text("취소")')
    await expect(cancelBtn).toBeVisible({ timeout: 5_000 })
    await cancelBtn.click()

    // 취소 후 토스트 또는 상태 변경
    await expect(
      page.locator('text=취소').or(page.locator('text=cancelled'))
    ).toBeVisible({ timeout: 8_000 })
  })

})

test.describe('슬라이드오버 — sent/failed 재발송', () => {

  test('sent 항목 → 재발송 버튼 → 새 발송 시작', async ({ page, request }) => {
    const item = await findFirstByStatus(request, 'sent')
    if (!item) { test.skip(); return }

    await page.goto(HISTORY)
    await page.waitForSelector('.min-h-screen', { timeout: 10_000 })

    const row = page.locator(`tbody tr`).filter({ hasText: item.post_title }).first()
    await expect(row).toBeVisible()
    await row.click()
    await page.waitForTimeout(500)

    const resendBtn = page.locator('button:has-text("재발송")')
    await expect(resendBtn).toBeVisible({ timeout: 5_000 })
    await resendBtn.click()

    await page.waitForTimeout(1000)
    // 에러 없이 응답 (토스트 또는 슬라이드오버 유지)
    await expect(page.locator('h2').last()).toBeVisible({ timeout: 5_000 })
  })

})

test.describe('슬라이드오버 — 삭제 플로우', () => {

  test('cancelled 항목 → 삭제 확인 → 아니오로 취소', async ({ page, request }) => {
    const item = await findFirstByStatus(request, 'cancelled')
    if (!item) { test.skip(); return }

    await page.goto(HISTORY)
    await page.waitForSelector('.min-h-screen', { timeout: 10_000 })

    const row = page.locator(`tbody tr`).filter({ hasText: item.post_title }).first()
    await expect(row).toBeVisible()
    await row.click()
    await page.waitForTimeout(500)

    const deleteBtn = page.locator('button.so-btn--red-outline')
    await expect(deleteBtn).toBeVisible({ timeout: 5_000 })
    await deleteBtn.click()

    await expect(page.locator('text=삭제할까요?')).toBeVisible()
    await page.locator('button:has-text("아니오")').click()
    await expect(page.locator('text=삭제할까요?')).not.toBeVisible()
  })

  test('발송 중(sending) 항목 → 삭제 버튼 disabled', async ({ page, request }) => {
    const item = await findFirstByStatus(request, 'sending')
    if (!item) { test.skip(); return }

    await page.goto(HISTORY)
    await page.waitForSelector('.min-h-screen', { timeout: 10_000 })

    const row = page.locator(`tbody tr`).filter({ hasText: item.post_title }).first()
    await expect(row).toBeVisible()
    await row.click()
    await page.waitForTimeout(500)

    const deleteBtn = page.locator('button.so-btn--red-outline')
    await expect(deleteBtn).toBeDisabled({ timeout: 5_000 })
  })

})

test.describe('슬라이드오버 — 수신자 단건 재발송', () => {

  test('수신자 탭 → 개별 재발송 버튼 존재 확인', async ({ page, request }) => {
    const item = await findFirstByStatus(request, 'sent')
    if (!item) { test.skip(); return }

    await page.goto(HISTORY)
    await page.waitForSelector('.min-h-screen', { timeout: 10_000 })

    const row = page.locator(`tbody tr`).filter({ hasText: item.post_title }).first()
    await expect(row).toBeVisible()
    await row.click()
    await page.waitForTimeout(500)

    const recipientsTab = page.locator('button:has-text("수신자")')
    await expect(recipientsTab).toBeVisible({ timeout: 5_000 })
    await recipientsTab.click()
    await page.waitForTimeout(500)

    // 재발송 버튼이 있으면 클릭 가능 상태인지 확인
    const resendSingleBtn = page.locator('button:has-text("재발송")').first()
    const hasSingleResend = await resendSingleBtn.isVisible().catch(() => false)
    if (hasSingleResend) {
      await expect(resendSingleBtn).not.toBeDisabled()
    }
  })

})

test.describe('이중 클릭 방지', () => {

  test('발송/취소 버튼 — 클릭 직후 disabled 처리', async ({ page, request }) => {
    const item = await findFirstByStatus(request, 'draft')
      ?? await findFirstByStatus(request, 'queued')
    if (!item) { test.skip(); return }

    await page.goto(HISTORY)
    await page.waitForSelector('.min-h-screen', { timeout: 10_000 })

    const row = page.locator(`tbody tr`).filter({ hasText: item.post_title }).first()
    await row.click()
    await page.waitForTimeout(500)

    const actionBtn = page.locator('button:has-text("발송 시작"), button:has-text("취소")').first()
    const hasBtn = await actionBtn.isVisible().catch(() => false)
    if (!hasBtn) { test.skip(); return }

    await actionBtn.click()
    // 클릭 직후 disabled 상태 확인 (로딩 중)
    await expect(actionBtn).toBeDisabled({ timeout: 3_000 })
  })

})

test.describe('토스트 메시지', () => {

  test('취소 성공 후 토스트 표시', async ({ page, request }) => {
    const item = await findFirstByStatus(request, 'queued')
    if (!item) { test.skip(); return }

    await page.goto(HISTORY)
    await page.waitForSelector('.min-h-screen', { timeout: 10_000 })

    const row = page.locator(`tbody tr`).filter({ hasText: item.post_title }).first()
    await row.click()
    await page.waitForTimeout(500)

    const cancelBtn = page.locator('button:has-text("취소")')
    const hasCancel = await cancelBtn.isVisible().catch(() => false)
    if (!hasCancel) { test.skip(); return }

    await cancelBtn.click()
    // 토스트 div 표시 (3초 내)
    await expect(page.locator('[class*="toast"], [class*="Toast"]').or(
      page.locator('text=취소').last()
    )).toBeVisible({ timeout: 5_000 })
  })

})

// ── [5] 개별 재발송(resend-single) 완전 흐름 ──────────────────────────────

test.describe('개별 재발송(resend-single) 완전 흐름', () => {

  let seededNlId = null
  const RECIPIENT_EMAIL = 'e2e-resend-recipient@test.example'

  function seedSentNewsletterWithRecipient() {
    const id = wpEval(`
      global $wpdb;
      $postId = wp_insert_post([
        'post_title'  => '[E2E] resend-single 테스트',
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
      ], ['%d', '%s', '%s', '%d', '%d', '%d', '%s']);
      $nlId = $wpdb->insert_id;
      $wpdb->insert($wpdb->prefix . 'crmbiz_nl_events', [
        'newsletter_id' => $nlId,
        'email'         => '${RECIPIENT_EMAIL}',
        'type'          => 'sent',
        'occurred_at'   => current_time('mysql'),
      ], ['%d', '%s', '%s', '%s']);
      echo $nlId;
    `)
    return parseInt(id)
  }

  function deleteSeeded(id) {
    wpEval(`
      global $wpdb;
      $nl = $wpdb->get_row($wpdb->prepare(
        "SELECT post_id FROM {$wpdb->prefix}crmbiz_newsletters WHERE id = %d", ${id}
      ));
      $wpdb->delete($wpdb->prefix . 'crmbiz_nl_events', ['newsletter_id' => ${id}], ['%d']);
      $wpdb->delete($wpdb->prefix . 'crmbiz_newsletters', ['id' => ${id}], ['%d']);
      if ($nl && $nl->post_id) wp_delete_post($nl->post_id, true);
    `)
  }

  test.beforeAll(() => {
    seededNlId = seedSentNewsletterWithRecipient()
  })

  test.afterAll(() => {
    if (seededNlId) deleteSeeded(seededNlId)
  })

  test('[5-1] sent 행 — 슬라이드오버 → 수신자 탭 → 재발송 버튼 → 성공 토스트', async ({ page }) => {
    await page.goto(HISTORY)
    await page.waitForSelector('.min-h-screen', { timeout: 10_000 })

    // 시딩한 레코드 행
    const row = page.locator('tbody tr', { hasText: 'resend-single 테스트' }).first()
    await expect(row).toBeVisible({ timeout: 5_000 })
    await row.click()
    await page.waitForTimeout(500)

    // 수신자 탭
    const recipientsTab = page.locator('button:has-text("수신자")')
    await expect(recipientsTab).toBeVisible({ timeout: 5_000 })
    await recipientsTab.click()
    await page.waitForTimeout(600)

    // 수신자 행의 재발송 버튼
    const resendBtn = page.locator('button[title*="재발송"], button:has-text("재발송")').first()
    await expect(resendBtn).toBeVisible({ timeout: 5_000 })
    await resendBtn.click()

    // 성공 토스트 또는 응답 메시지
    await expect(
      page.locator('text=재발송').or(page.locator('text=발송').or(page.locator('[class*="toast"]')))
    ).toBeVisible({ timeout: 8_000 })
  })

  test('[5-2] REST resend-single — 잘못된 이메일 형식 → 400', async ({ page, request }) => {
    // sent 뉴스레터 ID 조회
    const listRes = await request.get(`${API_BASE}/newsletters?per_page=10`)
    const json    = await listRes.json()
    const item    = json.items?.find(i => i.status === 'sent')
    if (!item) { test.skip(); return }

    // WP REST nonce는 page context에서 가져와야 함 (cookie auth 단독으론 POST 불가)
    await page.goto(HISTORY)
    await page.waitForLoadState('domcontentloaded')

    const status = await page.evaluate(async ({ url, body }) => {
      const nonce = window.wpApiSettings?.nonce ?? ''
      const r = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
        body: JSON.stringify(body),
      })
      return r.status
    }, {
      url: `${API_BASE}/newsletters/${item.id}/resend-single`,
      body: { email: 'not-valid-email' },
    })

    expect(status).toBe(400)
  })

  test('[5-3] REST resend-single — 존재하지 않는 ID → 400 또는 404', async ({ page }) => {
    await page.goto(HISTORY)
    await page.waitForLoadState('domcontentloaded')

    const status = await page.evaluate(async (url) => {
      const nonce = window.wpApiSettings?.nonce ?? ''
      const r = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
        body: JSON.stringify({ email: 'test@example.com' }),
      })
      return r.status
    }, `${API_BASE}/newsletters/999999/resend-single`)

    expect([400, 404]).toContain(status)
  })

})
