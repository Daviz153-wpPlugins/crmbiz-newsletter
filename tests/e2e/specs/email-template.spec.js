import { test, expect } from '@playwright/test'

const SETTINGS_CUSTOM = 'wp-admin/admin.php?page=crmbiz-nl-settings&tab=customize'

test.describe('이메일 템플릿', () => {

  test('전체 미리보기 — 열기 성공', async ({ page, context }) => {
    await page.goto(SETTINGS_CUSTOM)

    // 새 탭에서 미리보기 열림
    const [preview] = await Promise.all([
      context.waitForEvent('page'),
      page.click('a:has-text("실제 이메일 전체보기")')
    ])

    await preview.waitForLoadState('domcontentloaded')

    // 403/에러 없이 이메일 HTML 로드
    await expect(preview).not.toHaveURL(/403|error/)
    await expect(preview.locator('body')).toBeVisible()
  })

  test('다크 테마 — 이메일 본문 배경이 흰색', async ({ page, context }) => {
    await page.goto(SETTINGS_CUSTOM)

    // 다크 프리셋 선택 후 저장
    await page.click('button:has-text("다크")')
    await page.click('button:has-text("커스터마이징 저장")')
    await page.waitForURL(/settings/)

    // 미리보기 열기
    const [preview] = await Promise.all([
      context.waitForEvent('page'),
      page.click('a:has-text("실제 이메일 전체보기")')
    ])
    await preview.waitForLoadState('domcontentloaded')

    // 본문 td 배경이 #ffffff인지 확인
    const bodyBg = await preview.locator('td.nl-body').getAttribute('style')
    expect(bodyBg).toContain('#ffffff')
  })

  test('모던 테마로 원복', async ({ page }) => {
    await page.goto(SETTINGS_CUSTOM)
    await page.click('button:has-text("모던")')
    await page.click('button:has-text("커스터마이징 저장")')
    await expect(page.locator('text=설정이 저장되었습니다')).toBeVisible()
  })

})
