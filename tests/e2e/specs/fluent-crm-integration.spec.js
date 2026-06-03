/**
 * FluentCRM 연동 통합 테스트
 *
 * 두 가지 실행 환경을 모두 지원한다:
 *
 * [로컬 실환경] FluentCRM 실제 설치됨 (기본)
 *   → 그룹 A: 실제 FluentCRM 활성화 상태 검증 (항상 실행)
 *
 * [CI 환경] FluentCRM 미설치
 *   → 그룹 B: 비활성화 상태 안전 동작 검증 (항상 실행)
 *   → 그룹 C: 스텁 기반 연동 배선 검증 (ENABLE_FLUENTCRM_STUB=1 필요)
 *   → 그룹 D: Graceful degradation (ENABLE_FLUENTCRM_STUB=1 필요)
 *
 * 실행 방법:
 *   로컬:  npx playwright test fluent-crm-integration --project=chromium
 *   CI:    ENABLE_FLUENTCRM_STUB=1 npx playwright test fluent-crm-integration
 *
 * ⚠️  그룹 C는 수제 스텁으로 연동 '배선'(분기·API·UI)을 검증한다.
 *     실제 FluentCRM 내부 호환성은 그룹 A(실환경)가 담당한다.
 */
import { test, expect } from '@playwright/test'
import { execSync }      from 'child_process'
import * as fs           from 'fs'

const WP_PATH   = process.env.WP_PATH    || '/tmp/wordpress'
const WP_BASE   = (process.env.WP_BASE_URL || 'http://localhost:8888/wordpress').replace(/\/$/, '')
const API_BASE  = WP_BASE + '/wp-json/crmbiz-nl/v1'
const STUB_SLUG = 'fluent-crm-e2e-stub'
const RUN_STUB  = process.env.ENABLE_FLUENTCRM_STUB === '1'

const DASHBOARD = 'wp-admin/admin.php?page=crmbiz-newsletter'
const NEW_POST  = 'wp-admin/post-new.php'

// ─── 헬퍼 ────────────────────────────────────────────────────────────────────

function wp(cmd) {
  return execSync(`wp ${cmd} --path=${WP_PATH}`, { encoding: 'utf-8' }).trim()
}

function wpEval(code) {
  const flat = code.replace(/\s+/g, ' ').trim()
  return execSync(
    `wp eval '${flat.replace(/'/g, "'\\''")}' --path=${WP_PATH}`,
    { encoding: 'utf-8' }
  ).trim()
}

function ensureStubDeactivated() {
  try { wp(`plugin deactivate ${STUB_SLUG} --quiet`) } catch { /* 없거나 이미 비활성화 */ }
}

function installAndActivateStub() {
  const dir = `${WP_PATH}/wp-content/plugins/${STUB_SLUG}`
  execSync(`mkdir -p ${dir}`)
  fs.writeFileSync(`${dir}/${STUB_SLUG}.php`, STUB_PHP)
  try { wp(`plugin activate ${STUB_SLUG} --quiet`) } catch {}
}

function deactivateStub() {
  try { wp(`plugin deactivate ${STUB_SLUG} --quiet`) } catch {}
}

// ─── FluentCRM 최소 스텁 PHP (CI 전용) ───────────────────────────────────────
// String.raw — 백슬래시(PHP 네임스페이스 구분자)가 JS escape sequence로 해석되지 않음
// 스텁 반환값:
//   FluentCrmApi('contacts').getInstance().count()   → 10
//   _StubFcTag::countByStatus()                      → 5   ("스텁 태그 (5명)")
//   _StubFcList::countByStatus()                     → 3   ("스텁 리스트 (3명)")
//   FluentCrm\App\Services\ContactsQuery::count()    → 8   (AJAX 수신자 수)
const STUB_PHP = String.raw`<?php
/** Plugin Name: FluentCRM E2E Stub (CI)
    Description: CI 전용 — FluentCRM 연동 배선 테스트를 위한 최소 스텁 */

namespace {
    if (!defined('FLUENTCRM')) {
        define('FLUENTCRM', true);
    }
    if (!class_exists('_StubFcTag')) {
        class _StubFcTag {
            public int $id;
            public string $title;
            public function __construct(int $id, string $title) {
                $this->id = $id; $this->title = $title;
            }
            public function countByStatus(string $status): int { return 5; }
        }
    }
    if (!class_exists('_StubFcList')) {
        class _StubFcList {
            public int $id;
            public string $title;
            public function __construct(int $id, string $title) {
                $this->id = $id; $this->title = $title;
            }
            public function countByStatus(string $status): int { return 3; }
        }
    }
    if (!class_exists('_StubFcContactModel')) {
        class _StubFcContactModel {
            public function count(): int { return 10; }
        }
    }
    if (!class_exists('_StubFcContactsApi')) {
        class _StubFcContactsApi {
            public function getInstance() { return new _StubFcContactModel(); }
        }
    }
    if (!class_exists('_StubFcTagsApi')) {
        class _StubFcTagsApi {
            public function get(): array { return [new _StubFcTag(1, '스텁 태그')]; }
        }
    }
    if (!class_exists('_StubFcListsApi')) {
        class _StubFcListsApi {
            public function get(): array { return [new _StubFcList(1, '스텁 리스트')]; }
        }
    }
    if (!function_exists('FluentCrmApi')) {
        function FluentCrmApi(string $entity) {
            switch ($entity) {
                case 'contacts': return new _StubFcContactsApi();
                case 'tags':     return new _StubFcTagsApi();
                case 'lists':    return new _StubFcListsApi();
            }
            return null;
        }
    }
}

namespace FluentCrm\App\Services {
    if (!class_exists('FluentCrm\App\Services\Helper')) {
        class Helper {
            public static function getGlobalEmailSettings(): array { return []; }
        }
    }
    if (!class_exists('FluentCrm\App\Services\_CQModel')) {
        class _CQModel {
            public function count(): int { return 8; }
        }
    }
    if (!class_exists('FluentCrm\App\Services\ContactsQuery')) {
        class ContactsQuery {
            public function __construct(array $args = []) {}
            public function getModel() { return new _CQModel(); }
        }
    }
}
`

// ──────────────────────────────────────────────────────────────────────────────
// 그룹 A: 실제 FluentCRM 활성화 환경 (로컬 실환경, 항상 실행)
// FluentCRM v3.1.0이 설치·활성화된 실환경을 전제로 한다.
// ──────────────────────────────────────────────────────────────────────────────

// ── 인증된 API fetch 헬퍼 ─────────────────────────────────────────────────────
// WordPress REST API는 쿠키 인증 시 X-WP-Nonce 헤더 필수.
// page.request.get()은 쿠키는 보내지만 nonce를 포함하지 않아 401 반환.
// 해결책: 대시보드 페이지를 로드한 뒤 window.crmbizNl.nonce를 얻어
// page.evaluate의 fetch()로 직접 호출한다 (동일 출처 → nonce 자동 적용).
async function fetchDashboard(page, apiBase) {
  await page.goto(`wp-admin/admin.php?page=crmbiz-newsletter`)
  await page.waitForSelector('.min-h-screen', { timeout: 15_000 })
  return page.evaluate(async (url) => {
    // Plugin.php: wp_localize_script('crmbiz-nl-vue-dash', 'CrmbizNL', { nonce: wp_create_nonce('wp_rest') })
    const nonce = window.CrmbizNL?.nonce
    const r = await fetch(url, { headers: { 'X-WP-Nonce': nonce } })
    return r.json()
  }, `${apiBase}/dashboard`)
}

test.describe('FluentCRM 실환경 — 활성화 상태 검증', () => {

  const SKIP_MSG = 'FluentCRM 비활성화 상태 — 그룹 B에서 검증'

  // ── API 검증 ──────────────────────────────────────────────────────────────

  test('Dashboard API — fluent_crm: true, contact_count ≥ 0', async ({ page }) => {
    const json = await fetchDashboard(page, API_BASE)

    test.skip(json.system?.fluent_crm !== true, SKIP_MSG)

    expect(json.system.fluent_crm).toBe(true)
    expect(typeof json.system.contact_count).toBe('number')
    expect(json.system.contact_count).toBeGreaterThanOrEqual(0)
  })

  test('Dashboard API — 필수 필드 구조 정상', async ({ page }) => {
    const json = await fetchDashboard(page, API_BASE)
    test.skip(json.system?.fluent_crm !== true, SKIP_MSG)

    expect(json).toHaveProperty('stats')
    expect(json).toHaveProperty('pending')
    expect(json).toHaveProperty('chart')
    expect(json.system).toHaveProperty('fluent_crm')
    expect(json.system).toHaveProperty('fluent_smtp')
    expect(json.system).toHaveProperty('contact_count')
    expect(json.system).toHaveProperty('version')
  })

  // ── 대시보드 Vue 앱 검증 ──────────────────────────────────────────────────

  test('대시보드 Vue 앱 — JS 에러 없이 정상 렌더', async ({ page }) => {
    const errors = []
    page.on('pageerror', e => errors.push(e.message))

    const json = await fetchDashboard(page, API_BASE)  // 이미 대시보드 로드됨
    test.skip(json.system?.fluent_crm !== true, SKIP_MSG)

    await expect(page.locator('h1:has-text("뉴스레터 대시보드")')).toBeVisible()
    expect(errors.filter(e => !e.includes('favicon'))).toHaveLength(0)
  })

  // ── 메타박스 UI 검증 ──────────────────────────────────────────────────────

  test('메타박스 — FluentCRM 비활성화 경고 없음', async ({ page }) => {
    const json = await fetchDashboard(page, API_BASE)
    test.skip(json.system?.fluent_crm !== true, SKIP_MSG)

    await page.goto(NEW_POST)
    await page.waitForLoadState('domcontentloaded')
    // Gutenberg에서 메타박스는 초기에 width:0, height:0 → toBeVisible 대신 attached 대기
    await page.locator('#crmbiz-nl-metabox').waitFor({ state: 'attached', timeout: 10_000 })

    // FluentCRM 활성화 → 경고 div 자체가 PHP에서 렌더되지 않음 → count=0
    const warnCount = await page.locator('.crmbiz-mb-notice--warn').count()
    expect(warnCount, 'FluentCRM 비활성화 경고가 렌더됨 — isAvailable() 점검 필요').toBe(0)
  })

  test('메타박스 — 태그/리스트 항목 렌더 (FluentCRM 데이터 연동)', async ({ page }) => {
    const json = await fetchDashboard(page, API_BASE)
    test.skip(json.system?.fluent_crm !== true, SKIP_MSG)

    await page.goto(NEW_POST)
    await page.waitForLoadState('domcontentloaded')
    await page.locator('#crmbiz-nl-metabox').waitFor({ state: 'attached', timeout: 10_000 })

    // Gutenberg이 메타박스를 #metaboxes.hidden 안에 숨기면 상호작용 불가 → 스킵
    const gutenbergHidden = await page.evaluate(
      () => !!document.querySelector('#metaboxes.hidden')
    )
    test.skip(gutenbergHidden, 'Gutenberg hidden 모드 — 메타박스 상호작용 불가 (CI Classic Editor 환경에서만 실행)')

    const checkbox = page.locator('#crmbiz_nl_enabled')
    if (!await checkbox.isChecked()) await checkbox.check()
    await page.waitForTimeout(300)

    const hasItems   = await page.locator('.crmbiz-recipient-check').count() > 0
    const hasNoItems = await page.locator('text=FluentCRM에 태그/리스트가 없습니다').count() > 0
    expect(hasItems || hasNoItems, '태그/리스트 섹션이 전혀 렌더되지 않음').toBe(true)
  })

  test('메타박스 — 태그 레이블 형식 "이름 (N명)" 준수', async ({ page }) => {
    const json = await fetchDashboard(page, API_BASE)
    test.skip(json.system?.fluent_crm !== true, SKIP_MSG)

    await page.goto(NEW_POST)
    await page.waitForLoadState('domcontentloaded')
    await page.locator('#crmbiz-nl-metabox').waitFor({ state: 'attached', timeout: 10_000 })

    const gutenbergHidden = await page.evaluate(
      () => !!document.querySelector('#metaboxes.hidden')
    )
    test.skip(gutenbergHidden, 'Gutenberg hidden 모드 — CI Classic Editor 환경에서만 실행')

    const checkbox = page.locator('#crmbiz_nl_enabled')
    if (!await checkbox.isChecked()) await checkbox.check()

    if (await page.locator('.crmbiz-recipient-check').count() === 0) {
      test.skip() // 태그/리스트 없는 환경
      return
    }

    // getTagsForSelect() 반환 형식: "태그명 (N명)"
    const firstLabel = await page.locator('label.crmbiz-mb-check-label').first().textContent()
    expect(firstLabel?.trim()).toMatch(/^.+\s\(\d+명\)$/)
  })

  // ── AJAX 수신자 수 검증 ───────────────────────────────────────────────────

  test('수신자 카운트 AJAX — 태그 체크 시 숫자 응답 (0 이상)', async ({ page }) => {
    const json = await fetchDashboard(page, API_BASE)
    test.skip(json.system?.fluent_crm !== true, SKIP_MSG)

    await page.goto(NEW_POST)
    await page.waitForLoadState('domcontentloaded')
    await page.locator('#crmbiz-nl-metabox').waitFor({ state: 'attached', timeout: 10_000 })

    const gutenbergHidden = await page.evaluate(
      () => !!document.querySelector('#metaboxes.hidden')
    )
    test.skip(gutenbergHidden, 'Gutenberg hidden 모드 — CI Classic Editor 환경에서만 실행')

    const checkbox = page.locator('#crmbiz_nl_enabled')
    if (!await checkbox.isChecked()) await checkbox.check()

    const tagChecks = page.locator('.crmbiz-recipient-check')
    if (await tagChecks.count() === 0) {
      test.skip()
      return
    }

    await tagChecks.first().check()
    await page.waitForTimeout(1_000)

    await page.locator('#crmbiz-recipient-count').waitFor({ state: 'visible', timeout: 5_000 })
    const countText = await page.locator('#crmbiz-count-value').textContent()
    expect(parseInt(countText ?? '-1')).toBeGreaterThanOrEqual(0)
  })

})

// ──────────────────────────────────────────────────────────────────────────────
// 그룹 B: FluentCRM 비활성화 상태 — 안전 동작 검증 (CI 환경 기본)
// ──────────────────────────────────────────────────────────────────────────────

test.describe('FluentCRM 비활성화 — 플러그인 안전 동작 (CI)', () => {

  let insertedNlId = null

  test.beforeAll(() => {
    // 스텁이 있으면 제거 — 이 그룹은 순수 비활성화 상태를 전제로 함
    ensureStubDeactivated()
  })

  test.afterAll(() => {
    // Cron 테스트 삽입 레코드 제거 — 다른 스펙의 stats 카운트 오염 방지
    if (insertedNlId) {
      try {
        wpEval(`
          global $wpdb;
          $wpdb->delete(
            $wpdb->prefix . "crmbiz_newsletters",
            ["id" => ${insertedNlId}],
            ["%d"]
          );
        `)
      } catch {}
      insertedNlId = null
    }
  })

  // FluentCRM이 실제로 활성화된 환경(로컬)에서는 이 그룹 전체 스킵
  const SKIP_MSG = 'FluentCRM 활성화 상태 — 그룹 A에서 검증'

  test('Dashboard API — fluent_crm: false, contact_count: 0', async ({ page }) => {
    const json = await fetchDashboard(page, API_BASE)
    test.skip(json.system?.fluent_crm === true, SKIP_MSG)

    expect(json.system.fluent_crm).toBe(false)
    expect(json.system.contact_count).toBe(0)
  })

  test('대시보드 Vue 앱 — FluentCRM 없어도 빈 화면 없음, JS 에러 없음', async ({ page }) => {
    const errors = []
    page.on('pageerror', e => errors.push(e.message))

    const json = await fetchDashboard(page, API_BASE)  // 이미 대시보드 로드됨
    test.skip(json.system?.fluent_crm === true, SKIP_MSG)

    await expect(page.locator('h1:has-text("뉴스레터 대시보드")')).toBeVisible()
    expect(errors.filter(e => !e.includes('favicon'))).toHaveLength(0)
  })

  test('메타박스 — FluentCRM 비활성화 경고 표시', async ({ page }) => {
    const json = await fetchDashboard(page, API_BASE)
    test.skip(json.system?.fluent_crm === true, SKIP_MSG)

    await page.goto(NEW_POST)
    await page.waitForLoadState('domcontentloaded')

    await expect(page.locator('#crmbiz-nl-metabox')).toBeVisible({ timeout: 5_000 })
    await expect(
      page.locator('.crmbiz-mb-notice--warn:has-text("FluentCRM이 활성화되지 않았습니다")')
    ).toBeVisible()
  })

  test('메타박스 — FluentCRM 없으면 태그/리스트 체크박스 없음', async ({ page }) => {
    const json = await fetchDashboard(page, API_BASE)
    test.skip(json.system?.fluent_crm === true, SKIP_MSG)

    await page.goto(NEW_POST)
    await page.waitForLoadState('domcontentloaded')

    const checkbox = page.locator('#crmbiz_nl_enabled')
    await expect(checkbox).toBeVisible({ timeout: 5_000 })
    if (!await checkbox.isChecked()) await checkbox.check()

    expect(await page.locator('.crmbiz-recipient-check').count()).toBe(0)
  })

  test('queued 뉴스레터 → Cron 트리거 → failed + FluentCRM fail_reason', async ({ page }) => {
    const json = await fetchDashboard(page, API_BASE)
    test.skip(json.system?.fluent_crm === true, SKIP_MSG)

    insertedNlId = wpEval(`
      global $wpdb;
      $wpdb->insert(
        $wpdb->prefix . "crmbiz_newsletters",
        ["post_id" => 0, "status" => "queued", "tag_ids" => "", "list_ids" => ""],
        ["%d", "%s", "%s", "%s"]
      );
      echo $wpdb->insert_id;
    `)
    expect(parseInt(insertedNlId)).toBeGreaterThan(0)

    wpEval(`do_action("crmbiz_nl_send_newsletter", (int) ${insertedNlId});`)

    const row = wpEval(`
      global $wpdb;
      $r = $wpdb->get_row($wpdb->prepare(
        "SELECT status, fail_reason FROM " . $wpdb->prefix . "crmbiz_newsletters WHERE id = %d",
        ${insertedNlId}
      ));
      echo $r ? $r->status . "|" . $r->fail_reason : "not_found";
    `)
    const [status, failReason] = row.split('|')
    expect(status).toBe('failed')
    expect(failReason).toContain('FluentCRM')
  })

})

// ──────────────────────────────────────────────────────────────────────────────
// 그룹 C: 스텁 기반 연동 배선 검증 (CI, ENABLE_FLUENTCRM_STUB=1 필요)
// ──────────────────────────────────────────────────────────────────────────────

test.describe('FluentCRM 스텁 활성화 — 연동 배선 검증 (CI)', () => {

  test.skip(!RUN_STUB, 'ENABLE_FLUENTCRM_STUB=1 필요')

  test.beforeAll(() => {
    if (!RUN_STUB) return
    installAndActivateStub()
  })

  test.afterAll(() => {
    if (!RUN_STUB) return
    deactivateStub()
  })

  test('Dashboard API — fluent_crm: true, contact_count: 10 (스텁)', async ({ request }) => {
    const res  = await request.get(`${API_BASE}/dashboard`)
    expect(res.status()).toBe(200)
    const json = await res.json()

    expect(json.system.fluent_crm).toBe(true)
    expect(json.system.contact_count).toBe(10) // _StubFcContactModel::count()
  })

  test('메타박스 — FluentCRM 경고 없음', async ({ page }) => {
    await page.goto(NEW_POST)
    await page.waitForLoadState('domcontentloaded')

    await expect(page.locator('#crmbiz-nl-metabox')).toBeVisible({ timeout: 5_000 })
    await expect(
      page.locator('.crmbiz-mb-notice--warn:has-text("FluentCRM이 활성화되지 않았습니다")')
    ).toBeHidden()
  })

  test('메타박스 — 태그 레이블 "스텁 태그 (5명)" 표시', async ({ page }) => {
    await page.goto(NEW_POST)
    await page.waitForLoadState('domcontentloaded')

    const checkbox = page.locator('#crmbiz_nl_enabled')
    await expect(checkbox).toBeVisible({ timeout: 5_000 })
    if (!await checkbox.isChecked()) await checkbox.check()

    // getTagsForSelect() → title + ' (' + countByStatus() + '명)'
    await expect(
      page.locator('label.crmbiz-mb-check-label:has-text("스텁 태그 (5명)")')
    ).toBeVisible()
  })

  test('메타박스 — 리스트 레이블 "스텁 리스트 (3명)" 표시', async ({ page }) => {
    await page.goto(NEW_POST)
    await page.waitForLoadState('domcontentloaded')

    const checkbox = page.locator('#crmbiz_nl_enabled')
    await expect(checkbox).toBeVisible({ timeout: 5_000 })
    if (!await checkbox.isChecked()) await checkbox.check()

    await expect(
      page.locator('label.crmbiz-mb-check-label:has-text("스텁 리스트 (3명)")')
    ).toBeVisible()
  })

  test('수신자 카운트 AJAX — 태그 체크 시 count: 8 (스텁)', async ({ page }) => {
    await page.goto(NEW_POST)
    await page.waitForLoadState('domcontentloaded')

    const checkbox = page.locator('#crmbiz_nl_enabled')
    await expect(checkbox).toBeVisible({ timeout: 5_000 })
    if (!await checkbox.isChecked()) await checkbox.check()

    const tagCheckbox = page.locator('.crmbiz-recipient-check').first()
    await expect(tagCheckbox).toBeVisible({ timeout: 3_000 })
    await tagCheckbox.check()
    await page.waitForTimeout(1_000)

    // ContactsQuery._CQModel::count() = 8
    await expect(page.locator('#crmbiz-recipient-count')).toBeVisible({ timeout: 5_000 })
    const countText = await page.locator('#crmbiz-count-value').textContent()
    expect(parseInt(countText ?? '-1')).toBe(8)
  })

})

// ──────────────────────────────────────────────────────────────────────────────
// 그룹 D: Graceful degradation (CI, ENABLE_FLUENTCRM_STUB=1 필요)
// ──────────────────────────────────────────────────────────────────────────────

test.describe('FluentCRM Graceful degradation — 비활성화 ↔ 활성화 전환 (CI)', () => {

  test.skip(!RUN_STUB, 'ENABLE_FLUENTCRM_STUB=1 필요')

  test.beforeAll(() => {
    if (!RUN_STUB) return
    ensureStubDeactivated()
  })

  test.afterAll(() => {
    if (!RUN_STUB) return
    deactivateStub()
  })

  test('[1/4] 비활성화 기준선 — fluent_crm: false', async ({ request }) => {
    const res  = await request.get(`${API_BASE}/dashboard`)
    expect(res.status()).toBe(200)
    expect((await res.json()).system.fluent_crm).toBe(false)
  })

  test('[2/4] 스텁 활성화 직후 — fluent_crm: true', async ({ request }) => {
    installAndActivateStub()

    const res  = await request.get(`${API_BASE}/dashboard`)
    const json = await res.json()
    expect(json.system.fluent_crm).toBe(true)
    expect(json.system.contact_count).toBe(10)
  })

  test('[3/4] 스텁 비활성화 직후 — fluent_crm: false, contact_count: 0', async ({ request }) => {
    deactivateStub()

    const res  = await request.get(`${API_BASE}/dashboard`)
    const json = await res.json()
    expect(json.system.fluent_crm).toBe(false)
    expect(json.system.contact_count).toBe(0)
  })

  test('[4/4] 전환 중 대시보드 Vue 앱 — JS 에러 없음', async ({ page }) => {
    installAndActivateStub()

    const errors = []
    page.on('pageerror', e => errors.push(e.message))

    await page.goto(DASHBOARD)
    await page.waitForSelector('.min-h-screen', { timeout: 10_000 })

    deactivateStub()

    await page.reload()
    await page.waitForSelector('.min-h-screen', { timeout: 10_000 })
    await expect(page.locator('h1:has-text("뉴스레터 대시보드")')).toBeVisible()

    expect(errors.filter(e => !e.includes('favicon'))).toHaveLength(0)
  })

})
