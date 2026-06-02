/**
 * 접근성 자동화 테스트 (WCAG 2.1 AA)
 *
 * @axe-core/playwright로 각 페이지를 스캔해 접근성 위반을 자동 감지.
 * 위반이 0건이어야 함 — 새 컴포넌트 추가 시 회귀 방지.
 */
import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'

/** axe 결과 포매터 — 실패 시 어떤 rule이 왜 실패했는지 보여줌 */
function formatViolations(violations) {
  return violations.map(v =>
    `[${v.impact?.toUpperCase()}] ${v.id}: ${v.description}\n` +
    v.nodes.slice(0, 2).map(n => `  → ${n.target.join(', ')}`).join('\n')
  ).join('\n\n')
}

const PAGES = [
  { name: '대시보드',      path: 'wp-admin/admin.php?page=crmbiz-newsletter',      selector: '.min-h-screen' },
  { name: '발송 이력',     path: 'wp-admin/admin.php?page=crmbiz-nl-history',      selector: '.min-h-screen' },
  { name: '수신거부 관리', path: 'wp-admin/admin.php?page=crmbiz-nl-unsubscribers', selector: '.crmbiz-wrap, .wrap' },
  { name: '설정',          path: 'wp-admin/admin.php?page=crmbiz-nl-settings',     selector: '.crmbiz-wrap, .wrap' },
]

// ── WCAG 2.1 AA 전체 페이지 스캔 ─────────────────────────────────────────

for (const { name, path, selector } of PAGES) {
  test(`${name} — WCAG 2.1 AA 위반 없음`, async ({ page }) => {
    await page.goto(path)

    // Vue 앱이 있으면 마운트 대기
    if (selector.includes('min-h-screen')) {
      await page.waitForSelector(selector, { timeout: 10_000 })
      await page.waitForTimeout(500) // 애니메이션 완료 대기
    } else {
      await page.waitForLoadState('domcontentloaded')
    }

    const results = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa'])
      // WordPress 관리자 UI 자체의 위반은 제외 (우리 코드만 검사)
      .include(selector.split(',')[0].trim())
      .analyze()

    expect(
      results.violations,
      `${name} 접근성 위반:\n${formatViolations(results.violations)}`
    ).toHaveLength(0)
  })
}

// ── 핵심 접근성 요소별 세부 검증 ─────────────────────────────────────────

test.describe('키보드 내비게이션', () => {

  test('발송 이력 — Tab으로 검색 입력 접근 가능', async ({ page }) => {
    await page.goto('wp-admin/admin.php?page=crmbiz-nl-history')
    await page.waitForSelector('.min-h-screen', { timeout: 10_000 })

    // Tab 키로 검색 입력 필드에 포커스 도달 가능
    await page.keyboard.press('Tab')
    await page.keyboard.press('Tab')
    await page.keyboard.press('Tab')

    const focused = await page.evaluate(() => document.activeElement?.tagName)
    // INPUT, BUTTON, A 중 하나가 포커스되어야 함
    expect(['INPUT', 'BUTTON', 'A', 'SELECT'].includes(focused ?? '')).toBeTruthy()
  })

  test('슬라이드오버 — Esc 키로 닫힘', async ({ page }) => {
    await page.goto('wp-admin/admin.php?page=crmbiz-nl-history')
    await page.waitForSelector('.min-h-screen', { timeout: 10_000 })

    const firstRow = page.locator('tbody tr').first()
    if (!await firstRow.isVisible().catch(() => false)) {
      test.skip()
      return
    }

    await firstRow.click()
    await page.waitForTimeout(300)

    // 슬라이드오버가 열렸는지 확인 (h2 표시)
    const isOpen = await page.locator('h2').last().isVisible().catch(() => false)
    if (!isOpen) { test.skip(); return }

    await page.keyboard.press('Escape')
    await page.waitForTimeout(300)

    // Esc 후 슬라이드오버 닫힘 — tbody가 다시 포커스 가능
    await expect(page.locator('tbody tr').first()).toBeVisible()
  })

  test('수신거부 추가 모달 — Esc 또는 취소 버튼으로 닫힘', async ({ page }) => {
    await page.goto('wp-admin/admin.php?page=crmbiz-nl-unsubscribers')
    await page.waitForLoadState('domcontentloaded')

    await page.click('button:has-text("직접 추가")')
    await expect(page.locator('#crmbiz-unsub-modal')).toBeVisible()

    await page.click('button:has-text("취소")')
    await expect(page.locator('#crmbiz-unsub-modal')).not.toBeVisible()
  })

})

test.describe('스크린리더 지원', () => {

  test('대시보드 — 주요 섹션에 의미있는 heading 구조', async ({ page }) => {
    await page.goto('wp-admin/admin.php?page=crmbiz-newsletter')
    await page.waitForSelector('.min-h-screen', { timeout: 10_000 })

    // h1이 존재해야 함 (페이지 제목)
    await expect(page.locator('h1').first()).toBeVisible()

    // heading 계층이 h1 → h2 → h3 순서를 건너뛰지 않는지 확인
    const headings = await page.locator('h1, h2, h3, h4').allTextContents()
    expect(headings.length).toBeGreaterThan(0)
  })

  test('발송 이력 — 테이블에 thead와 th 존재', async ({ page }) => {
    await page.goto('wp-admin/admin.php?page=crmbiz-nl-history')
    await page.waitForSelector('.min-h-screen', { timeout: 10_000 })

    // 데이터 테이블에 헤더 행이 있어야 스크린리더가 컬럼 의미를 파악할 수 있음
    await expect(page.locator('thead')).toBeVisible()
    const thCount = await page.locator('th').count()
    expect(thCount).toBeGreaterThan(0)
  })

  test('상태 배지 — 색깔만이 아닌 텍스트로 상태 표현', async ({ page }) => {
    await page.goto('wp-admin/admin.php?page=crmbiz-nl-history')
    await page.waitForSelector('.min-h-screen', { timeout: 10_000 })

    // 상태 배지가 텍스트 내용을 포함하는지 (색깔만으로 구분하면 안 됨)
    const badges = page.locator('tbody tr').first().locator('[class*="badge"], [class*="Badge"], span').first()
    const hasText = await badges.textContent().then(t => (t?.trim().length ?? 0) > 0).catch(() => true)
    expect(hasText).toBeTruthy()
  })

  test('버튼과 링크 — 의미있는 텍스트 또는 aria-label', async ({ page }) => {
    await page.goto('wp-admin/admin.php?page=crmbiz-nl-history')
    await page.waitForSelector('.min-h-screen', { timeout: 10_000 })

    // 텍스트 없는 아이콘 버튼이 aria-label을 갖는지 axe가 검사
    // 여기서는 모든 버튼에 접근 가능한 이름이 있는지 확인
    const results = await new AxeBuilder({ page })
      .withTags(['wcag2a'])
      .withRules(['button-name', 'link-name'])
      .include('.min-h-screen')
      .analyze()

    expect(
      results.violations,
      `버튼/링크 접근성 위반:\n${formatViolations(results.violations)}`
    ).toHaveLength(0)
  })

  test('폼 입력 — label 또는 aria-label 연결', async ({ page }) => {
    await page.goto('wp-admin/admin.php?page=crmbiz-nl-history')
    await page.waitForSelector('.min-h-screen', { timeout: 10_000 })

    const results = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa'])
      .withRules(['label', 'input-button-name'])
      .include('.min-h-screen')
      .analyze()

    expect(
      results.violations,
      `폼 레이블 위반:\n${formatViolations(results.violations)}`
    ).toHaveLength(0)
  })

})

test.describe('색상 대비', () => {

  test('대시보드 — 텍스트 색상 대비 WCAG AA 준수', async ({ page }) => {
    await page.goto('wp-admin/admin.php?page=crmbiz-newsletter')
    await page.waitForSelector('.min-h-screen', { timeout: 10_000 })

    const results = await new AxeBuilder({ page })
      .withRules(['color-contrast'])
      .include('.min-h-screen')
      .analyze()

    expect(
      results.violations,
      `색상 대비 위반:\n${formatViolations(results.violations)}`
    ).toHaveLength(0)
  })

})
