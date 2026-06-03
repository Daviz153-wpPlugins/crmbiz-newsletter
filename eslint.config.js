import js from '@eslint/js'
import pluginVue from 'eslint-plugin-vue'
import globals from 'globals'

// vue3 essential (correctness rules only, no formatting opinions)
const vueConfigs = pluginVue.configs['flat/essential'].map(cfg => ({
  ...cfg,
  files: ['resources/js/**/*.{js,vue}'],
}))

export default [
  // Vue 소스 파일
  ...vueConfigs,
  {
    files: ['resources/js/**/*.{js,vue}'],
    languageOptions: {
      globals: { ...globals.browser },
    },
    rules: {
      ...js.configs.recommended.rules,
      'no-console': ['warn', { allow: ['error', 'warn'] }],
      'no-unused-vars': ['error', { argsIgnorePattern: '^_', varsIgnorePattern: '^_' }],
      'no-empty': ['error', { allowEmptyCatch: true }],
      'vue/multi-word-component-names': 'off',
    },
  },
  // E2E 스펙 파일 — page.evaluate() 안에서 브라우저 globals 사용
  {
    files: ['tests/e2e/**/*.js'],
    languageOptions: {
      globals: {
        ...globals.node,
        window: 'readonly',
        document: 'readonly',
        getComputedStyle: 'readonly',
      },
      parserOptions: { ecmaVersion: 'latest', sourceType: 'module' },
    },
    rules: {
      ...js.configs.recommended.rules,
      'no-unused-vars': ['error', { argsIgnorePattern: '^_', varsIgnorePattern: '^_' }],
      'no-console': 'off',
      'no-empty': ['error', { allowEmptyCatch: true }],
    },
  },
]
