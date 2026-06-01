import { test as setup, expect } from '@playwright/test'
import path from 'path'

const authFile = path.join('tests/e2e/.auth/admin.json')

setup('WP 관리자 로그인 저장', async ({ page }) => {
  await page.goto('/wp-login.php')

  await page.fill('#user_login', process.env.WP_ADMIN_USER || 'admin')
  await page.fill('#user_pass',  process.env.WP_ADMIN_PASS || 'password')
  await page.click('#wp-submit')

  // 로그인 성공 확인 — 대시보드로 이동
  await expect(page).toHaveURL(/wp-admin/)
  await page.context().storageState({ path: authFile })
})
