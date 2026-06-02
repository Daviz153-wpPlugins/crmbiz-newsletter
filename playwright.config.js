import { defineConfig, devices } from '@playwright/test'
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

const AUTH_FILE        = 'tests/e2e/.auth/admin.json'
const AUTH_FILE_EDITOR = 'tests/e2e/.auth/editor.json'

const BASE_USE = {
  baseURL:    (process.env.WP_BASE_URL || 'http://localhost:8888/wordpress').replace(/\/$/, '') + '/',
  screenshot: 'only-on-failure',
  video:      'retain-on-failure',
  locale:     'ko-KR',
  storageState: AUTH_FILE,
}

export default defineConfig({
  testDir: './tests/e2e',
  timeout: 60_000,
  retries: 1,
  reporter: [['list'], ['html', { open: 'never' }]],

  use: {
    baseURL:    BASE_USE.baseURL,
    screenshot: 'only-on-failure',
    video:      'retain-on-failure',
    locale:     'ko-KR',
  },

  projects: [
    // ── 로그인 세션 저장 (Chromium에서 1회 실행, 전 브라우저 공유) ──────
    {
      name:      'setup',
      testMatch: '**/auth.setup.js',
      use:       { ...devices['Desktop Chrome'] },
    },

    // ── Chromium ───────────────────────────────────────────────────────
    {
      name:         'chromium',
      dependencies: ['setup'],
      use: {
        ...devices['Desktop Chrome'],
        storageState: AUTH_FILE,
      },
    },

    // ── Firefox ────────────────────────────────────────────────────────
    {
      name:         'firefox',
      dependencies: ['setup'],
      use: {
        ...devices['Desktop Firefox'],
        storageState: AUTH_FILE,
      },
    },

    // ── WebKit (Safari) ────────────────────────────────────────────────
    {
      name:         'webkit',
      dependencies: ['setup'],
      use: {
        ...devices['Desktop Safari'],
        storageState: AUTH_FILE,
      },
    },

    // ── Editor (비관리자) 접근 차단 전용 프로젝트 ─────────────────────
    {
      name:      'setup-editor',
      testMatch: '**/auth.setup.editor.js',
    },
    {
      name:         'editor',
      dependencies: ['setup-editor'],
      testMatch:    '**/access-control.spec.js',
      use: {
        ...devices['Desktop Chrome'],
        storageState: AUTH_FILE_EDITOR,
      },
    },

    // ── Mobile (iPhone 14) — responsive.spec.js 전용 ─────────────────
    {
      name:         'mobile',
      dependencies: ['setup'],
      testMatch:    '**/responsive.spec.js',
      use: {
        ...devices['iPhone 14'],
        storageState: AUTH_FILE,
      },
    },
  ],
})
