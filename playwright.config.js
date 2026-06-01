import { defineConfig } from '@playwright/test'
import { readFileSync } from 'fs'

// .env.test 로드
try {
  const env = readFileSync('.env.test', 'utf-8')
  env.split('\n').forEach(line => {
    const [k, ...rest] = line.split('=')
    if (k && rest.length && !k.startsWith('#')) {
      process.env[k.trim()] = rest.join('=').trim()
    }
  })
} catch {}

const AUTH_FILE = 'tests/e2e/.auth/admin.json'

export default defineConfig({
  testDir: './tests/e2e',
  timeout: 60_000,
  retries: 1,
  reporter: [['list'], ['html', { open: 'never' }]],

  use: {
    baseURL:    (process.env.WP_BASE_URL || 'http://localhost:8888/wordpress').replace(/\/$/, '') + '/',
    screenshot: 'only-on-failure',
    video:      'retain-on-failure',
    locale:     'ko-KR',
  },

  projects: [
    {
      name:      'setup',
      testMatch: '**/auth.setup.js',
    },
    {
      name:         'chromium',
      dependencies: ['setup'],
      use: {
        channel:      'chromium',
        storageState: AUTH_FILE,
      },
    },
  ],
})
