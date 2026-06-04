/**
 * 글 발행 → 뉴스레터 트리거 테스트
 *
 * 메타박스에서 즉시/수동/예약 모드 설정 후 글 발행 시
 * crmbiz_newsletters 레코드 생성 및 이력 페이지 반영 확인
 */
import { test, expect } from '@playwright/test'

const NEW_POST = 'wp-admin/post-new.php'
const HISTORY  = 'wp-admin/admin.php?page=crmbiz-nl-history'
const API_BASE = (process.env.WP_BASE_URL || 'http://localhost:8888/wordpress').replace(/\/$/, '')
  + '/wp-json/crmbiz-nl/v1'

// 고유 제목 생성
const uid = () => `E2E-${Date.now()}`

test.describe('글 발행 — 즉시 발송 트리거', () => {

  test('뉴스레터 활성화 + 즉시 발송 → 이력에 queued 레코드 생성', async ({ page, request }) => {
    const title = uid()
    await page.goto(NEW_POST)
    await page.waitForLoadState('domcontentloaded')
    const isGutenbergHidden = await page.evaluate(() => !!document.querySelector('#metaboxes.hidden'))
    test.skip(isGutenbergHidden, 'Gutenberg hidden 모드 — CI Classic Editor 환경에서만 실행')

    // 제목 입력 (클래식/블록 에디터 모두 대응)
    const titleInput = page.locator('#title, [aria-label="Add title"], [placeholder*="제목"]').first()
    await titleInput.fill(title)
    await page.waitForTimeout(300)

    // 뉴스레터 활성화
    const checkbox = page.locator('#crmbiz_nl_enabled')
    await expect(checkbox).toBeVisible({ timeout: 5_000 })
    if (!await checkbox.isChecked()) await checkbox.check()

    // 즉시 발송 선택 (기본값이지만 명시)
    const immediateRadio = page.locator('input[name="crmbiz_nl_send_mode"][value="immediate"]')
    await expect(immediateRadio).toBeVisible()
    await immediateRadio.check()

    // 발행 (클래식/블록 에디터 모두 대응)
    const publishBtn = page.locator('#publish, button:has-text("발행하기"), button:has-text("Publish")').first()
    await expect(publishBtn).toBeVisible()
    await publishBtn.click()
    await page.waitForLoadState('domcontentloaded')
    await page.waitForTimeout(1000)

    // 이력 API에서 해당 제목 레코드 확인
    const res  = await request.get(`${API_BASE}/newsletters?per_page=50`)
    expect(res.status()).toBe(200)
    const json = await res.json()
    const created = json.items?.find(i => i.post_title === title)
    expect(created).toBeTruthy()
    expect(['queued', 'sending', 'sent']).toContain(created.status)
  })

  test('뉴스레터 비활성화 → 이력에 레코드 미생성', async ({ page, request }) => {
    const title = uid() + '-NO-NL'
    await page.goto(NEW_POST)
    await page.waitForLoadState('domcontentloaded')
    const isGutenbergHidden = await page.evaluate(() => !!document.querySelector('#metaboxes.hidden'))
    test.skip(isGutenbergHidden, 'Gutenberg hidden 모드 — CI Classic Editor 환경에서만 실행')

    const titleInput = page.locator('#title, [aria-label="Add title"], [placeholder*="제목"]').first()
    await titleInput.fill(title)
    await page.waitForTimeout(300)

    // 메타박스 체크 안 함 (비활성화 상태 유지)
    const checkbox = page.locator('#crmbiz_nl_enabled')
    if (await checkbox.isChecked()) await checkbox.uncheck()

    const publishBtn = page.locator('#publish, button:has-text("발행하기"), button:has-text("Publish")').first()
    await publishBtn.click()
    await page.waitForLoadState('domcontentloaded')
    await page.waitForTimeout(1000)

    // 이력 API에 해당 제목 없어야 함
    const res  = await request.get(`${API_BASE}/newsletters?per_page=50`)
    const json = await res.json()
    const found = json.items?.find(i => i.post_title === title)
    expect(found).toBeFalsy()
  })

})

test.describe('글 발행 — 수동 발송 트리거', () => {

  test('수동 발송 모드 → 이력에 draft 상태로 생성', async ({ page, request }) => {
    const title = uid() + '-MANUAL'
    await page.goto(NEW_POST)
    await page.waitForLoadState('domcontentloaded')
    const isGutenbergHidden = await page.evaluate(() => !!document.querySelector('#metaboxes.hidden'))
    test.skip(isGutenbergHidden, 'Gutenberg hidden 모드 — CI Classic Editor 환경에서만 실행')

    const titleInput = page.locator('#title, [aria-label="Add title"], [placeholder*="제목"]').first()
    await titleInput.fill(title)
    await page.waitForTimeout(300)

    const checkbox = page.locator('#crmbiz_nl_enabled')
    if (!await checkbox.isChecked()) await checkbox.check()

    await page.locator('input[name="crmbiz_nl_send_mode"][value="manual"]').check()

    const publishBtn = page.locator('#publish, button:has-text("발행하기"), button:has-text("Publish")').first()
    await publishBtn.click()
    await page.waitForLoadState('domcontentloaded')
    await page.waitForTimeout(1000)

    const res  = await request.get(`${API_BASE}/newsletters?per_page=50`)
    const json = await res.json()
    const created = json.items?.find(i => i.post_title === title)
    expect(created).toBeTruthy()
    // 수동 모드 → draft 상태
    expect(created.status).toBe('draft')
  })

})

test.describe('글 발행 — 이력 페이지 반영', () => {

  test('발행 후 이력 페이지에서 제목 확인', async ({ page }) => {
    const title = uid() + '-HIST'
    await page.goto(NEW_POST)
    await page.waitForLoadState('domcontentloaded')
    const isGutenbergHidden = await page.evaluate(() => !!document.querySelector('#metaboxes.hidden'))
    test.skip(isGutenbergHidden, 'Gutenberg hidden 모드 — CI Classic Editor 환경에서만 실행')

    const titleInput = page.locator('#title, [aria-label="Add title"], [placeholder*="제목"]').first()
    await titleInput.fill(title)
    await page.waitForTimeout(300)

    const checkbox = page.locator('#crmbiz_nl_enabled')
    if (!await checkbox.isChecked()) await checkbox.check()

    const publishBtn = page.locator('#publish, button:has-text("발행하기"), button:has-text("Publish")').first()
    await publishBtn.click()
    await page.waitForLoadState('domcontentloaded')
    await page.waitForTimeout(1000)

    await page.goto(HISTORY)
    await page.waitForSelector('.min-h-screen', { timeout: 10_000 })

    // 이력 테이블에 방금 발행한 제목 표시
    await expect(page.locator(`text=${title}`)).toBeVisible({ timeout: 8_000 })
  })

  test('예약 발송 설정 → 이력에 scheduled 상태', async ({ page, request }) => {
    const title = uid() + '-SCHED'
    // 미래 날짜 (1시간 후)
    const futureDate = new Date(Date.now() + 3600 * 1000)
    const localStr   = futureDate.toISOString().slice(0, 16) // "YYYY-MM-DDTHH:MM"

    await page.goto(NEW_POST)
    await page.waitForLoadState('domcontentloaded')
    const isGutenbergHidden = await page.evaluate(() => !!document.querySelector('#metaboxes.hidden'))
    test.skip(isGutenbergHidden, 'Gutenberg hidden 모드 — CI Classic Editor 환경에서만 실행')

    const titleInput = page.locator('#title, [aria-label="Add title"], [placeholder*="제목"]').first()
    await titleInput.fill(title)
    await page.waitForTimeout(300)

    const checkbox = page.locator('#crmbiz_nl_enabled')
    if (!await checkbox.isChecked()) await checkbox.check()

    await page.locator('input[name="crmbiz_nl_send_mode"][value="scheduled"]').check()
    await page.locator('input[name="crmbiz_nl_scheduled_at"]').fill(localStr)

    const publishBtn = page.locator('#publish, button:has-text("발행하기"), button:has-text("Publish")').first()
    await publishBtn.click()
    await page.waitForLoadState('domcontentloaded')
    await page.waitForTimeout(1000)

    const res  = await request.get(`${API_BASE}/newsletters?per_page=50`)
    const json = await res.json()
    const created = json.items?.find(i => i.post_title === title)
    expect(created).toBeTruthy()
    expect(['scheduled', 'queued']).toContain(created.status)
  })

})
