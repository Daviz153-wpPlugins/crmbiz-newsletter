import { test as setup, expect } from '@playwright/test'
import path from 'path'

const authFile = path.join('tests/e2e/.auth/editor.json')

setup('Editor 로그인 저장', async ({ page }) => {
  const base = process.env.WP_BASE_URL || 'http://localhost:8888/wordpress'
  await page.goto(base + '/wp-login.php', { waitUntil: 'domcontentloaded', timeout: 60_000 })

  await page.fill('#user_login', process.env.WP_EDITOR_USER || 'editor_test')
  await page.fill('#user_pass',  process.env.WP_EDITOR_PASS || 'editorpass')
  await page.click('#wp-submit')

  await page.waitForURL(/wp-admin/, { timeout: 30_000 })
  await page.context().storageState({ path: authFile })
  console.log('✅ Editor 로그인 성공, 세션 저장 완료')
})
