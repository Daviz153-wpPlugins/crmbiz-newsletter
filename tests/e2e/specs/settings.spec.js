import { test, expect } from '@playwright/test'

const SETTINGS        = 'wp-admin/admin.php?page=crmbiz-nl-settings'
const SETTINGS_CUSTOM = SETTINGS + '&tab=customize'
const SETTINGS_LOGS   = SETTINGS + '&tab=logs'

test.describe('설정 페이지', () => {

  test('기본 설정 — 저장 성공 메시지', async ({ page }) => {
    await page.goto(SETTINGS)
    await page.click('button:has-text("설정 저장")')
    await expect(page.locator('text=설정이 저장되었습니다')).toBeVisible()
  })

  test('탭 전환 — 기본설정/이메일커스터마이징/시스템로그', async ({ page }) => {
    await page.goto(SETTINGS)
    for (const tab of ['이메일 커스터마이징', '시스템 로그', '기본 설정']) {
      await page.click(`a:has-text("${tab}")`)
      await expect(page.locator('.crmbiz-settings-section').first()).toBeVisible()
    }
  })

  test('이메일 커스터마이징 — 저장 성공', async ({ page }) => {
    await page.goto(SETTINGS_CUSTOM)
    await page.click('button:has-text("커스터마이징 저장")')
    await expect(page.locator('text=설정이 저장되었습니다')).toBeVisible()
  })

  test('이메일 커스터마이징 — 프리셋 클릭', async ({ page }) => {
    await page.goto(SETTINGS_CUSTOM)
    for (const preset of ['모던', '다크', '미니멀']) {
      await page.click(`button:has-text("${preset}")`)
      await page.waitForTimeout(200)
    }
    // 프리셋 변경 후 오류 없음
    await expect(page.locator('button:has-text("커스터마이징 저장")')).toBeVisible()
  })

  test('시그니처 미리보기 — 이름 입력 시 실시간 반영', async ({ page }) => {
    await page.goto(SETTINGS_CUSTOM)
    await page.fill('#sig_name', '테스트 이름')
    await page.waitForTimeout(300)
    await expect(page.locator('#crmbiz-preview-name')).toContainText('테스트 이름')
  })

  test('시스템 로그 탭 — 로드 및 필터 버튼', async ({ page }) => {
    await page.goto(SETTINGS_LOGS)
    await expect(page.locator('h3:has-text("시스템 로그")')).toBeVisible()
    await expect(page.locator('a[href*="tab=logs"]:has-text("전체")')).toBeVisible()
    await expect(page.locator('a[href*="log_level=ERROR"]')).toBeVisible()
    await expect(page.locator('a[href*="log_level=WARN"]')).toBeVisible()
  })

  test('시스템 로그 — ERROR 필터', async ({ page }) => {
    await page.goto(SETTINGS_LOGS + '&log_level=ERROR')
    await expect(page.locator('h3:has-text("시스템 로그")')).toBeVisible()
    await expect(page.locator('.crmbiz-settings-section').first()).toBeVisible()
  })

})
