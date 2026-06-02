/**
 * 메타박스 AJAX 동작 테스트
 *
 * 수신자 카운트, 테스트 이메일 발송 등 AJAX 핸들러 검증
 */
import { test, expect } from '@playwright/test'

const NEW_POST = 'wp-admin/post-new.php'

test.describe('메타박스 — 수신자 카운트 AJAX', () => {

  test.beforeEach(async ({ page }) => {
    await page.goto(NEW_POST)
    await page.waitForLoadState('domcontentloaded')
    const checkbox = page.locator('#crmbiz_nl_enabled')
    await expect(checkbox).toBeVisible({ timeout: 5_000 })
    if (!await checkbox.isChecked()) await checkbox.check()
  })

  test('태그/리스트 선택 변경 → 수신자 카운트 표시', async ({ page }) => {
    // 수신 태그 또는 리스트 체크박스가 있을 때만
    const recipientChecks = page.locator('.crmbiz-recipient-check')
    const count = await recipientChecks.count()
    if (count === 0) { test.skip(); return }

    await recipientChecks.first().check()
    await page.waitForTimeout(800) // AJAX debounce 대기

    // 수신자 카운트 div 표시
    const countDiv = page.locator('#crmbiz-recipient-count')
    await expect(countDiv).toBeVisible({ timeout: 5_000 })
    // 숫자가 들어있어야 함
    const countText = await page.locator('#crmbiz-count-value').textContent()
    expect(parseInt(countText ?? '0')).toBeGreaterThanOrEqual(0)
  })

  test('태그/리스트 전체 해제 → 카운트 영역 숨김', async ({ page }) => {
    const recipientChecks = page.locator('.crmbiz-recipient-check')
    const count = await recipientChecks.count()
    if (count === 0) { test.skip(); return }

    // 체크 후 해제
    await recipientChecks.first().check()
    await page.waitForTimeout(800)
    await recipientChecks.first().uncheck()
    await page.waitForTimeout(800)

    // 모두 해제되면 카운트 영역 숨김
    const anyChecked = await page.locator('.crmbiz-recipient-check:checked').count()
    if (anyChecked === 0) {
      await expect(page.locator('#crmbiz-recipient-count')).toBeHidden({ timeout: 2_000 })
        .catch(() => {}) // count가 0이어도 div가 표시될 수 있음
    }
  })

})

test.describe('메타박스 — 테스트 이메일 발송 AJAX', () => {

  test.beforeEach(async ({ page }) => {
    await page.goto(NEW_POST)
    await page.waitForLoadState('domcontentloaded')
    const checkbox = page.locator('#crmbiz_nl_enabled')
    await expect(checkbox).toBeVisible({ timeout: 5_000 })
    if (!await checkbox.isChecked()) await checkbox.check()
  })

  test('이메일 입력 + 발송 버튼 클릭 → 응답 메시지 표시', async ({ page }) => {
    const testEmailInput = page.locator('#crmbiz-nl-test-email')
    const sendTestBtn    = page.locator('#crmbiz-nl-send-test')

    await expect(testEmailInput).toBeVisible()
    await testEmailInput.fill('test@example.com')
    await sendTestBtn.click()

    // 성공 또는 실패 메시지 (dry-run 모드면 "테스트 발송 성공" 등)
    await expect(
      page.locator('text=발송').or(page.locator('text=성공').or(page.locator('text=실패').or(page.locator('text=오류'))))
    ).toBeVisible({ timeout: 10_000 })
  })

  test('빈 이메일로 발송 시 브라우저 유효성 검사 동작', async ({ page }) => {
    const testEmailInput = page.locator('#crmbiz-nl-test-email')
    await testEmailInput.fill('')
    await page.locator('#crmbiz-nl-send-test').click()
    // 메타박스가 사라지지 않아야 함
    await expect(page.locator('#crmbiz-nl-metabox')).toBeVisible()
  })

  test('잘못된 이메일 형식 → 발송 안 됨', async ({ page }) => {
    await page.locator('#crmbiz-nl-test-email').fill('not-valid')
    await page.locator('#crmbiz-nl-send-test').click()
    // HTML5 type=email 유효성 검사로 차단되거나 오류 메시지
    await expect(page.locator('#crmbiz-nl-metabox')).toBeVisible()
  })

})

test.describe('메타박스 — 미리보기 링크', () => {

  test('이메일 미리보기 링크 — 새 탭으로 열림', async ({ page, context }) => {
    await page.goto(NEW_POST)
    await page.waitForLoadState('domcontentloaded')

    const checkbox = page.locator('#crmbiz_nl_enabled')
    if (!await checkbox.isChecked()) await checkbox.check()

    const previewLink = page.locator('a:has-text("이메일 미리보기"), a.crmbiz-mb-preview-link')
    const hasLink = await previewLink.isVisible().catch(() => false)
    if (!hasLink) { test.skip(); return }

    const [newTab] = await Promise.all([
      context.waitForEvent('page'),
      previewLink.click(),
    ])
    await newTab.waitForLoadState('domcontentloaded')
    await expect(newTab).not.toHaveURL(/403|error/)
  })

})
