import { defineConfig } from '@playwright/test'
import { readFileSync } from 'fs'

// .env.test 로드
try {
  const env = readFileSync('.env.test', 'utf-8')
  env.split('\n').forEach(line => {
    const [k, v] = line.split('=')
    if (k && v && !k.startsWith('#')) process.env[k.trim()] = v.trim()
  })
} catch {}

export default defineConfig({
  testDir: './tests/e2e',
  timeout: 30_000,
  retries: 1,
  reporter: [['list'], ['html', { open: 'never' }]],

  use: {
    baseURL:      process.env.WP_BASE_URL || 'http://localhost:8080',
    storageState: 'tests/e2e/.auth/admin.json',
    screenshot:   'only-on-failure',
    video:        'retain-on-failure',
    locale:       'ko-KR',
  },

  projects: [
    // 로그인 상태 저장 (다른 테스트보다 먼저 실행)
    {
      name:   'setup',
      testMatch: '**/auth.setup.js',
      use: { storageState: undefined },
    },
    // 실제 테스트 (로그인 상태 재사용)
    {
      name:         'chromium',
      use:          { channel: 'chromium' },
      dependencies: ['setup'],
    },
  ],
})
