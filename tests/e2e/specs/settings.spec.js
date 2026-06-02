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

  test('시스템 로그 — 로그 초기화 버튼 존재', async ({ page }) => {
    await page.goto(SETTINGS_LOGS)
    await expect(page.locator('button:has-text("로그 초기화"), input[value*="초기화"]')).toBeVisible()
  })

  test('시스템 로그 — 초기화 후 cleared 파라미터 URL에 반영', async ({ page }) => {
    await page.goto(SETTINGS_LOGS)
    const clearBtn = page.locator('button:has-text("로그 초기화"), input[value*="초기화"]').first()
    await clearBtn.click()
    await page.waitForLoadState('domcontentloaded')
    await expect(page).toHaveURL(/cleared=1/)
    await expect(page.locator('h3:has-text("시스템 로그")')).toBeVisible()
  })

  test('기본 설정 — dry-run 체크박스 저장 후 재로드 유지', async ({ page }) => {
    await page.goto(SETTINGS)
    const checkbox = page.locator('input[name="dry_run"]')
    await expect(checkbox).toBeVisible()
    const before = await checkbox.isChecked()
    // 토글
    if (before) {
      await checkbox.uncheck()
    } else {
      await checkbox.check()
    }
    await page.click('button:has-text("설정 저장")')
    await expect(page.locator('text=설정이 저장되었습니다')).toBeVisible()
    // 재로드 후 상태 유지
    await page.reload()
    const after = await page.locator('input[name="dry_run"]').isChecked()
    expect(after).toBe(!before)
    // 원상복구
    if (!before) {
      await page.locator('input[name="dry_run"]').uncheck()
    } else {
      await page.locator('input[name="dry_run"]').check()
    }
    await page.click('button:has-text("설정 저장")')
  })

})
