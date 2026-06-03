import { test as setup } from '@playwright/test'
import path from 'path'

const authFile = path.join('tests/e2e/.auth/admin.json')

setup('WP 관리자 로그인 저장', async ({ page }) => {
  const base = process.env.WP_BASE_URL || 'http://localhost:8888/wordpress'
  await page.goto(base + '/wp-login.php', { waitUntil: 'domcontentloaded', timeout: 60_000 })

  await page.fill('#user_login', process.env.WP_ADMIN_USER || 'admin')
  await page.fill('#user_pass',  process.env.WP_ADMIN_PASS || 'password')
  await page.click('#wp-submit')

  // 로그인 성공 — wp-admin 페이지로 이동
  await page.waitForURL(/wp-admin/, { timeout: 30_000 })
  await page.context().storageState({ path: authFile })
  console.log('✅ 로그인 성공, 세션 저장 완료')
})
