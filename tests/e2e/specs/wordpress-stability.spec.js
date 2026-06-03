/**
 * WordPress 환경 안정성 E2E 테스트
 *
 * WordPress 환경 변수나 설정이 플러그인 동작에 미치는 영향을 검증한다.
 *
 * 그룹 1: WP Cron 상태 경고 배너 (showCronNotice)
 *   - 대기 중인 뉴스레터 + Cron 미실행 → 경고 표시
 *   - 대기 없음 → 경고 없음
 *   - Cron 정상 실행 중 → 경고 없음
 *   - 배너 dismiss → 현재 페이지에서 사라짐
 *
 * 실행: npx playwright test wordpress-stability --project=chromium
 */
import { test, expect } from '@playwright/test'
import { execSync }      from 'child_process'

const WP_PATH  = process.env.WP_PATH    || '/Applications/MAMP/htdocs/wordpress'
const WP_BASE  = (process.env.WP_BASE_URL || 'http://localhost:8888/wordpress').replace(/\/$/, '')
const API_BASE = WP_BASE + '/wp-json/crmbiz-nl/v1'

const DASHBOARD = 'wp-admin/admin.php?page=crmbiz-newsletter'

// ─── 헬퍼 ────────────────────────────────────────────────────────────────────

function wpEval(code) {
  const flat = code.replace(/\s+/g, ' ').trim()
  const out = execSync(
    `wp eval '${flat.replace(/'/g, "'\\''")}' --path=${WP_PATH}`,
    { encoding: 'utf-8' }
  )
  return out.split('\n')
    .filter(l => !l.startsWith('PHP Deprecated') && !l.startsWith('Deprecated'))
    .join('\n')
    .trim()
}

// ──────────────────────────────────────────────────────────────────────────────
// 그룹 1: WP Cron 상태 경고 배너
// ──────────────────────────────────────────────────────────────────────────────

test.describe('WP Cron 상태 경고 배너 — showCronNotice()', () => {

  let nlId         = null
  let origLastRun  = null

  test.beforeEach(() => {
    // 원래 crmbiz_nl_last_cron_run 값 백업
    origLastRun = wpEval(`echo get_option("crmbiz_nl_last_cron_run", "0");`)
  })

  test.afterEach(() => {
    // crmbiz_nl_last_cron_run 복원
    if (origLastRun !== null) {
      wpEval(`update_option("crmbiz_nl_last_cron_run", ${origLastRun}, false);`)
    }
    // 테스트 뉴스레터 정리
    if (nlId) {
      try {
        wpEval(`
          global $wpdb;
          $wpdb->delete($wpdb->prefix . "crmbiz_newsletters", ["id" => ${nlId}], ["%d"]);
        `)
      } catch {}
      nlId = null
    }
  })

  // ── Cron 미실행 → 경고 표시 ───────────────────────────────────────────────

  test('대기 중인 뉴스레터 + Cron 한 번도 실행 안 됨 → 경고 배너 표시', async ({ page }) => {
    // queued 뉴스레터 삽입 (pending > 0 조건)
    nlId = wpEval(`
      global $wpdb;
      $wpdb->insert(
        $wpdb->prefix . "crmbiz_newsletters",
        ["post_id" => 0, "status" => "queued", "tag_ids" => "", "list_ids" => ""],
        ["%d", "%s", "%s", "%s"]
      );
      echo $wpdb->insert_id;
    `)
    expect(parseInt(nlId)).toBeGreaterThan(0)

    // Cron 한 번도 실행 안 됨 → $never = true
    wpEval(`update_option("crmbiz_nl_last_cron_run", 0, false);`)

    await page.goto(DASHBOARD)
    await page.waitForSelector('.min-h-screen', { timeout: 10_000 })

    // WP admin notice 영역에 CRMBiz Newsletter 경고 표시
    await expect(
      page.locator('.notice-warning:has-text("CRMBiz Newsletter")')
    ).toBeVisible({ timeout: 5_000 })
  })

  test('대기 중인 뉴스레터 + Cron 30분 이상 미실행 → stale 경고 표시', async ({ page }) => {
    nlId = wpEval(`
      global $wpdb;
      $wpdb->insert(
        $wpdb->prefix . "crmbiz_newsletters",
        ["post_id" => 0, "status" => "queued", "tag_ids" => "", "list_ids" => ""],
        ["%d", "%s", "%s", "%s"]
      );
      echo $wpdb->insert_id;
    `)

    // 마지막 실행: 33분 전 → $stale = true (1800초 초과)
    wpEval(`update_option("crmbiz_nl_last_cron_run", time() - 2000, false);`)

    await page.goto(DASHBOARD)
    await page.waitForSelector('.min-h-screen', { timeout: 10_000 })

    await expect(
      page.locator('.notice-warning:has-text("CRMBiz Newsletter")')
    ).toBeVisible({ timeout: 5_000 })
  })

  // ── 경고 없어야 할 조건 ────────────────────────────────────────────────────

  test('대기 중인 뉴스레터 없음 → 경고 배너 없음', async ({ page }) => {
    // queued/sending 뉴스레터 없는 상태에서 Cron도 오래 전에 실행됐다 해도 배너 없음
    wpEval(`update_option("crmbiz_nl_last_cron_run", 0, false);`)

    await page.goto(DASHBOARD)
    await page.waitForSelector('.min-h-screen', { timeout: 10_000 })

    // .notice-warning이 아예 없거나 CRMBiz Newsletter 텍스트 없어야 함
    const warnCount = await page.locator('.notice-warning:has-text("CRMBiz Newsletter")').count()
    expect(warnCount, '대기 없는데 Cron 경고가 표시됨').toBe(0)
  })

  test('Cron 최근 실행됨 → 경고 없음', async ({ page }) => {
    // queued 뉴스레터가 있어도 Cron이 30분 이내에 실행됐으면 경고 없음
    nlId = wpEval(`
      global $wpdb;
      $wpdb->insert(
        $wpdb->prefix . "crmbiz_newsletters",
        ["post_id" => 0, "status" => "queued", "tag_ids" => "", "list_ids" => ""],
        ["%d", "%s", "%s", "%s"]
      );
      echo $wpdb->insert_id;
    `)

    // 5분 전 실행 → stale 아님
    wpEval(`update_option("crmbiz_nl_last_cron_run", time() - 300, false);`)

    await page.goto(DASHBOARD)
    await page.waitForSelector('.min-h-screen', { timeout: 10_000 })

    const warnCount = await page.locator('.notice-warning:has-text("CRMBiz Newsletter")').count()
    expect(warnCount, 'Cron 정상 실행 중인데 경고가 표시됨').toBe(0)
  })

  // ── 배너 dismiss ─────────────────────────────────────────────────────────────

  test('경고 배너 dismiss 버튼 클릭 → 현재 페이지에서 사라짐', async ({ page }) => {
    nlId = wpEval(`
      global $wpdb;
      $wpdb->insert(
        $wpdb->prefix . "crmbiz_newsletters",
        ["post_id" => 0, "status" => "queued", "tag_ids" => "", "list_ids" => ""],
        ["%d", "%s", "%s", "%s"]
      );
      echo $wpdb->insert_id;
    `)
    wpEval(`update_option("crmbiz_nl_last_cron_run", 0, false);`)

    await page.goto(DASHBOARD)
    await page.waitForSelector('.min-h-screen', { timeout: 10_000 })

    const notice = page.locator('.notice-warning:has-text("CRMBiz Newsletter")')
    await expect(notice).toBeVisible({ timeout: 5_000 })

    // WordPress .is-dismissible 버튼 클릭
    await notice.locator('.notice-dismiss').click()

    // 현재 페이지에서 사라짐
    await expect(notice).toBeHidden({ timeout: 3_000 })
  })

  // ── 플러그인 전용 페이지에서만 표시 ──────────────────────────────────────────

  test('플러그인 외부 페이지(알림판)에서는 경고 없음', async ({ page }) => {
    // Cron 경고는 crmbiz 플러그인 페이지에서만 표시 (showCronNotice 내부 조건)
    nlId = wpEval(`
      global $wpdb;
      $wpdb->insert(
        $wpdb->prefix . "crmbiz_newsletters",
        ["post_id" => 0, "status" => "queued", "tag_ids" => "", "list_ids" => ""],
        ["%d", "%s", "%s", "%s"]
      );
      echo $wpdb->insert_id;
    `)
    wpEval(`update_option("crmbiz_nl_last_cron_run", 0, false);`)

    // WordPress 기본 알림판 — 플러그인 페이지가 아님
    await page.goto('wp-admin/index.php')
    await page.waitForLoadState('domcontentloaded')

    const warnCount = await page.locator('.notice-warning:has-text("CRMBiz Newsletter")').count()
    expect(warnCount, '플러그인 외부에서 Cron 경고가 표시됨').toBe(0)
  })

})
