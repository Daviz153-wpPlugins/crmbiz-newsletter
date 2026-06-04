import { test, expect } from '@playwright/test'
import { execSync } from 'child_process'

const DASHBOARD = 'wp-admin/admin.php?page=crmbiz-newsletter'
const WP_PATH   = process.env.WP_PATH || '/tmp/wordpress'

function wpEval(code) {
  const flat = code.replace(/\s+/g, ' ').trim()
  return execSync(
    `wp eval '${flat.replace(/'/g, "'\\''")}' --path=${WP_PATH}`,
    { encoding: 'utf-8' }
  ).trim()
}

test.describe('대시보드', () => {

  test.beforeEach(async ({ page }) => {
    await page.goto(DASHBOARD)
    // Vue 앱 로딩 대기
    await page.waitForSelector('.min-h-screen', { timeout: 10_000 })
    // 캠페인 데이터 로딩 대기 (API 응답 후 섹션 렌더)
    await page.waitForSelector('text=최근 캠페인', { timeout: 10_000 })
  })

  test('페이지 로드 — 주요 섹션 표시', async ({ page }) => {
    await expect(page.locator('h1:has-text("뉴스레터 대시보드")')).toBeVisible()
    await expect(page.locator('text=발송 예약 / 대기 현황')).toBeVisible()
    await expect(page.locator('text=완료 캠페인')).toBeVisible()
    await expect(page.locator('text=발송 성공')).toBeVisible()
    await expect(page.locator('text=성공률')).toBeVisible()
  })

  test('콘솔 에러 없음', async ({ page }) => {
    const errors = []
    page.on('console', msg => { if (msg.type() === 'error') errors.push(msg.text()) })
    await page.goto(DASHBOARD)
    await page.waitForTimeout(2000)
    expect(errors.filter(e => !e.includes('favicon'))).toHaveLength(0)
  })

  test('차트 기간 토글 — 7일/30일/90일', async ({ page }) => {
    for (const days of ['7일', '90일', '30일']) {
      await page.click(`button:has-text("${days}")`)
      await page.waitForTimeout(500)
      await expect(page.locator(`text=최근 ${days.replace('일', '')}일 발송 추이`)).toBeVisible()
    }
  })

  test('최근 캠페인 클릭 → 이력 페이지 이동 (403 없음)', async ({ page }) => {
    const campaign = page.locator('a[href*="crmbiz-nl-history"]').first()
    await expect(campaign).toBeVisible()
    await campaign.click()
    await expect(page).not.toHaveURL(/403|error/)
    await expect(page).toHaveURL(/crmbiz-nl-history/)
  })

  test('캠페인 페이지네이션 — 새 UI 텍스트 표시', async ({ page }) => {
    await expect(page.locator('text=총계')).toBeVisible()
    // select 옵션은 DOM에 있지만 visible 체크 불가 — select 요소 자체가 보이는지 확인
    await expect(page.locator('select').last()).toBeVisible()
    await expect(page.locator('text=/페이지 \\d+ of \\d+/')).toBeVisible()
  })

  test('캠페인 페이지네이션 — per-page 선택', async ({ page }) => {
    const select = page.locator('select').last()
    await expect(select).toBeVisible()
    await select.selectOption('10')
    await page.waitForTimeout(500)
    await expect(page.locator('text=최근 캠페인')).toBeVisible()
  })

  test('캠페인 페이지네이션 — 다음/이전 버튼 렌더', async ({ page }) => {
    // « ‹ 1 › » 버튼 5개가 모두 존재
    const paginationSection = page.locator('.flex.items-center.gap-1').last()
    await expect(paginationSection.locator('button')).toHaveCount(5)
  })

  test('발송 이력 버튼 → 이력 페이지 이동', async ({ page }) => {
    await page.click('a:has-text("발송 이력")')
    await expect(page).toHaveURL(/crmbiz-nl-history/)
  })

})

// ── [11] WP Cron 비활성화 경고 배너 ───────────────────────────────────────

test.describe('WP Cron 경고 배너', () => {

  test.beforeAll(() => {
    // crmbiz_nl_last_cron_run = 0 → "한 번도 실행되지 않음" 조건 강제
    wpEval(`update_option('crmbiz_nl_last_cron_run', 0);`)
  })

  test.afterAll(() => {
    // 배너가 다른 테스트에 영향 안 주도록 현재 시각으로 복구
    wpEval(`update_option('crmbiz_nl_last_cron_run', time());`)
  })

  test('[11-1] cron 미실행 시 경고 배너 표시', async ({ page }) => {
    await page.goto(DASHBOARD)
    await page.waitForLoadState('domcontentloaded')

    // WP admin notice: "notice notice-warning is-dismissible"
    const notice = page.locator('.notice-warning', { hasText: 'CRMBiz Newsletter' })
    await expect(notice).toBeVisible({ timeout: 5_000 })
    // 메시지 내용 확인
    await expect(notice).toContainText('CRMBiz Newsletter')
  })

  test('[11-2] 배너 dismiss 버튼 클릭 → 배너 DOM에서 제거됨', async ({ page }) => {
    await page.goto(DASHBOARD)
    await page.waitForLoadState('domcontentloaded')

    const notice = page.locator('.notice-warning', { hasText: 'CRMBiz Newsletter' })
    await expect(notice).toBeVisible({ timeout: 5_000 })

    // WP 기본 dismiss 버튼 (.notice-dismiss)
    const dismissBtn = notice.locator('button.notice-dismiss')
    await expect(dismissBtn).toBeVisible()
    await dismissBtn.click()

    // 클릭 후 notice가 DOM에서 사라짐
    await expect(notice).not.toBeVisible({ timeout: 3_000 })
  })

  test('[11-3] 새로고침 후 배너 재표시 (플러그인은 세션 내 숨김만 지원)', async ({ page }) => {
    await page.goto(DASHBOARD)
    await page.waitForLoadState('domcontentloaded')

    // dismiss 후 재로드
    const notice = page.locator('.notice-warning', { hasText: 'CRMBiz Newsletter' })
    await expect(notice).toBeVisible({ timeout: 5_000 })
    await notice.locator('button.notice-dismiss').click()
    await expect(notice).not.toBeVisible({ timeout: 3_000 })

    // 재로드 → 배너 다시 표시됨 (서버 상태 변경 없으므로)
    await page.reload()
    await page.waitForLoadState('domcontentloaded')
    await expect(
      page.locator('.notice-warning', { hasText: 'CRMBiz Newsletter' })
    ).toBeVisible({ timeout: 5_000 })
  })

})
