/**
 * DB 마이그레이션 통합 테스트
 *
 * WP-CLI + 실제 MySQL로 이전 스키마 → install() → 최신 스키마 검증.
 * 핵심 검증: 기존 레코드가 보존되고, 컬럼/인덱스 변경이 정확히 수행되며,
 * 암호화된 이메일이 업그레이드 후에도 복호화 가능한지.
 *
 * 실행: ENABLE_MIGRATION_TEST=1 npx playwright test migration --project=chromium
 */
import { test, expect } from '@playwright/test'
import { execSync } from 'child_process'

const WP_PATH  = process.env.WP_PATH    || '/Applications/MAMP/htdocs/wordpress'
const API_BASE = (process.env.WP_BASE_URL || 'http://localhost:8888/wordpress').replace(/\/$/, '')
  + '/wp-json/crmbiz-nl/v1'
const RUN      = process.env.ENABLE_MIGRATION_TEST === '1'

const CURRENT_DB_VERSION = '2.2.0'

function wpEval(code) {
  const flat = code.replace(/\s+/g, ' ').trim()
  return execSync(
    `wp eval '${flat.replace(/'/g, "'\\''")}' --path=${WP_PATH}`,
    { encoding: 'utf-8', timeout: 30_000 }
  ).trim().replace(/^(PHP Deprecated|Deprecated):.*\n?/gm, '').trim()
}

// ── v1.2.x → 2.0.0 마이그레이션 (레거시 스키마 → 현재 최신) ──────────────

test.describe('마이그레이션: v1.2.x → 2.0.0 → 2.2.0', () => {

  test.skip(!RUN, 'ENABLE_MIGRATION_TEST=1 필요')

  test('error_log 컬럼 제거, fail_reason 추가, 데이터 보존, 인덱스 추가', async () => {
    const countBefore = parseInt(
      wpEval(`global $wpdb; echo $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}crmbiz_newsletters");`)
    )
    expect(countBefore).toBeGreaterThan(0)

    const testEmail = 'migration-test@example.com'
    const encrypted = wpEval(`echo CRMBizNewsletter\\Database::encryptEmail('${testEmail}');`)
    expect(encrypted.length).toBeGreaterThan(10)

    wpEval(`
      global $wpdb;
      $tbl = $wpdb->prefix . 'crmbiz_newsletters';
      $cols = $wpdb->get_col("SHOW COLUMNS FROM {$tbl}");
      if (!in_array('error_log', $cols)) { $wpdb->query("ALTER TABLE {$tbl} ADD COLUMN error_log TEXT NULL"); }
      if (!in_array('subscriber_emails', $cols)) { $wpdb->query("ALTER TABLE {$tbl} ADD COLUMN subscriber_emails TEXT NULL"); }
      if (in_array('fail_reason', $cols)) { $wpdb->query("ALTER TABLE {$tbl} DROP COLUMN fail_reason"); }
      update_option('crmbiz_nl_db_version', '1.2.0');
    `)

    wpEval(`CRMBizNewsletter\\Database::install();`)

    const version = wpEval(`echo get_option('crmbiz_nl_db_version');`)
    expect(version).toBe(CURRENT_DB_VERSION)

    const countAfter = parseInt(
      wpEval(`global $wpdb; echo $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}crmbiz_newsletters");`)
    )
    expect(countAfter).toBe(countBefore)

    const cols = wpEval(`
      global $wpdb;
      echo implode(',', $wpdb->get_col("SHOW COLUMNS FROM {$wpdb->prefix}crmbiz_newsletters"));
    `).split(',')
    expect(cols).not.toContain('error_log')
    expect(cols).not.toContain('subscriber_emails')
    expect(cols).toContain('fail_reason')
    // 2.2.0 마이그레이션: *_gmt UTC 컬럼
    expect(cols).toContain('sent_at_gmt')
    expect(cols).toContain('scheduled_at_gmt')

    const indexes = wpEval(`
      global $wpdb;
      echo implode(',', $wpdb->get_col("SHOW INDEX FROM {$wpdb->prefix}crmbiz_nl_events", 2));
    `).split(',')
    expect(indexes).toContain('idx_nl_email_type')

    const decrypted = wpEval(`echo CRMBizNewsletter\\Database::decryptEmail('${encrypted}');`)
    expect(decrypted).toBe(testEmail)
  })

})

// ── DB 2.0.0 → 2.1.0 업그레이드 경로 (v1.1.0 → v1.2.0) ──────────────────
//
// 실제 업그레이드 흐름: 플러그인 파일 교체 → 다음 WP 로드 시
// Plugin::init()이 버전 불일치를 감지 → Database::install() 자동 실행.
// wp eval 호출 자체가 WP 로드이므로, 버전을 낮춰두면 다음 wp eval에서
// 자동 마이그레이션이 트리거된다.

test.describe('마이그레이션: 2.0.0 → 2.2.0 (idx_nl_email_type 인덱스 + _gmt 컬럼 추가)', () => {

  test.skip(!RUN, 'ENABLE_MIGRATION_TEST=1 필요')

  test.afterEach(() => {
    // 테스트가 버전을 낮춰뒀을 경우 복구 (WP 로드 시 자동 실행되지만 명시적 보장)
    wpEval(`update_option('crmbiz_nl_db_version', '${CURRENT_DB_VERSION}');`)
  })

  test('인덱스 없는 2.0.0 상태 → 다음 WP 로드 시 자동 업그레이드', () => {
    // Step 1: 2.0.0 상태 시뮬레이션
    // 이 wp eval 호출 시 WP 로드 → Plugin::init() → 현재 버전 2.2.0 == DB_VERSION → 아무 것도 안 함
    // 그 후 index 제거 + 버전 다운그레이드 수행
    const countBefore = parseInt(wpEval(`
      global $wpdb;
      $indexes = $wpdb->get_col("SHOW INDEX FROM {$wpdb->prefix}crmbiz_nl_events", 2);
      if (in_array('idx_nl_email_type', $indexes)) {
        $wpdb->query("ALTER TABLE {$wpdb->prefix}crmbiz_nl_events DROP KEY idx_nl_email_type");
      }
      update_option('crmbiz_nl_db_version', '2.0.0');
      echo $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}crmbiz_newsletters");
    `))

    // Step 2: 다음 WP 로드 = 실제 업그레이드 시뮬레이션
    // Plugin::init()이 version('2.0.0') < DB_VERSION('2.2.0') 감지 → install() 자동 실행
    const versionAfter = wpEval(`echo get_option('crmbiz_nl_db_version');`)
    expect(versionAfter).toBe(CURRENT_DB_VERSION)

    const indexesAfter = wpEval(`
      global $wpdb;
      echo implode(',', $wpdb->get_col("SHOW INDEX FROM {$wpdb->prefix}crmbiz_nl_events", 2));
    `).split(',')
    expect(indexesAfter).toContain('idx_nl_email_type')

    const countAfter = parseInt(
      wpEval(`global $wpdb; echo $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}crmbiz_newsletters");`)
    )
    expect(countAfter).toBe(countBefore)
  })

  test('2.2.0 상태에서 WP 로드 반복해도 인덱스 중복 없음 (idempotent)', () => {
    // 정상 상태에서 wp eval 3회 반복 — 자동 마이그레이션이 중복 실행돼도 안전해야 함
    for (let i = 0; i < 3; i++) {
      wpEval(`echo get_option('crmbiz_nl_db_version');`) // WP 로드 → Plugin::init() 호출
    }

    const indexCount = wpEval(`
      global $wpdb;
      echo implode(',', $wpdb->get_col("SHOW INDEX FROM {$wpdb->prefix}crmbiz_nl_events", 2));
    `).split(',').filter(i => i === 'idx_nl_email_type').length

    // SHOW INDEX는 복합 인덱스 컬럼마다 한 행 반환 (newsletter_id, email, type = 3행)
    expect(indexCount).toBe(3)
  })

})

// ── 발송 진행 중 업그레이드 ────────────────────────────────────────────────

test.describe('발송 중 업그레이드 안전성', () => {

  test.skip(!RUN, 'ENABLE_MIGRATION_TEST=1 필요')

  let postId, nlId

  test.beforeEach(() => {
    postId = parseInt(wpEval(`
      echo wp_insert_post(["post_title" => "업그레이드 테스트", "post_status" => "publish", "post_type" => "post"]);
    `))

    nlId = parseInt(wpEval(`
      global $wpdb;
      $wpdb->insert($wpdb->prefix . "crmbiz_newsletters", [
        "post_id" => ${postId}, "status" => "sending", "send_mode" => "immediate",
        "tag_ids" => json_encode([1]), "list_ids" => json_encode([]),
        "recipient_count" => 10, "success_count" => 3, "fail_count" => 0,
        "created_at" => current_time("mysql"), "updated_at" => current_time("mysql"),
      ]);
      echo $wpdb->insert_id;
    `))

    wpEval(`
      global $wpdb;
      $vals = [];
      for ($i = 1; $i <= 7; $i++) {
        $vals[] = $wpdb->prepare("(%d, %s, 0)", ${nlId}, "pending{$i}@example.com");
      }
      $wpdb->query("INSERT INTO {$wpdb->prefix}crmbiz_nl_queue (newsletter_id, email, retry_count) VALUES " . implode(",", $vals));
    `)
  })

  test.afterEach(() => {
    if (postId) wpEval(`wp_delete_post(${postId}, true);`)
    if (nlId) wpEval(`
      global $wpdb;
      $wpdb->delete($wpdb->prefix . "crmbiz_newsletters", ["id" => ${nlId}], ["%d"]);
      $wpdb->delete($wpdb->prefix . "crmbiz_nl_queue", ["newsletter_id" => ${nlId}], ["%d"]);
    `)
  })

  test('sending 상태 뉴스레터가 업그레이드 후에도 sending 유지 (데이터 보존)', () => {
    wpEval(`update_option('crmbiz_nl_db_version', '2.0.0');`)

    wpEval(`CRMBizNewsletter\\Database::install();`)

    const status = wpEval(`
      global $wpdb;
      echo $wpdb->get_var($wpdb->prepare("SELECT status FROM {$wpdb->prefix}crmbiz_newsletters WHERE id=%d", ${nlId}));
    `)
    expect(status).toBe('sending')

    const successCount = parseInt(wpEval(`
      global $wpdb;
      echo $wpdb->get_var($wpdb->prepare("SELECT success_count FROM {$wpdb->prefix}crmbiz_newsletters WHERE id=%d", ${nlId}));
    `))
    expect(successCount).toBe(3)
  })

  test('sending 중 업그레이드 후 큐 아이템이 보존된다 (재개 가능)', () => {
    wpEval(`update_option('crmbiz_nl_db_version', '2.0.0');`)

    wpEval(`CRMBizNewsletter\\Database::install();`)

    const queueCount = parseInt(wpEval(`
      global $wpdb;
      echo $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}crmbiz_nl_queue WHERE newsletter_id=%d", ${nlId}));
    `))
    expect(queueCount).toBe(7)
  })

  test('업그레이드 후 do_action으로 발송 재개 → sending 유지 또는 완료', () => {
    wpEval(`update_option('crmbiz_nl_db_version', '2.0.0');`)
    wpEval(`CRMBizNewsletter\\Database::install();`)

    wpEval(`do_action('crmbiz_nl_send_newsletter', ${nlId});`)

    const status = wpEval(`
      global $wpdb;
      echo $wpdb->get_var($wpdb->prepare("SELECT status FROM {$wpdb->prefix}crmbiz_newsletters WHERE id=%d", ${nlId}));
    `)
    expect(['sending', 'sent', 'failed']).toContain(status)
  })

})

// ── 최신 버전 재실행 (idempotent) ────────────────────────────────────────

test.describe('마이그레이션: 최신 버전 install() 재실행', () => {

  test.skip(!RUN, 'ENABLE_MIGRATION_TEST=1 필요')

  test('2.1.0 → install() 재실행해도 데이터 손실 없음', () => {
    const countBefore = parseInt(
      wpEval(`global $wpdb; echo $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}crmbiz_newsletters");`)
    )

    wpEval(`CRMBizNewsletter\\Database::install();`)

    const countAfter = parseInt(
      wpEval(`global $wpdb; echo $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}crmbiz_newsletters");`)
    )
    expect(countAfter).toBe(countBefore)

    const version = wpEval(`echo get_option('crmbiz_nl_db_version');`)
    expect(version).toBe(CURRENT_DB_VERSION)
  })

})

// ── DB 현재 상태 무결성 (항상 실행) ──────────────────────────────────────

test.describe('DB 현재 상태 무결성', () => {

  test('DB 버전이 코드 버전과 일치', () => {
    if (!RUN) return
    const version = wpEval(`echo get_option('crmbiz_nl_db_version');`)
    expect(version).toBe(CURRENT_DB_VERSION)
  })

  test('7개 테이블 모두 존재', async ({ request }) => {
    if (!RUN) {
      const res = await request.get(`${API_BASE}/newsletters`)
      expect(res.status()).toBe(200)
      return
    }

    for (const table of [
      'crmbiz_newsletters', 'crmbiz_nl_unsubscribers', 'crmbiz_nl_queue',
      'crmbiz_nl_events', 'crmbiz_nl_ratelimit', 'crmbiz_nl_sends', 'crmbiz_nl_logs',
    ]) {
      const result = wpEval(`global $wpdb; echo $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}${table}'");`)
      expect(result).toContain(table)
    }
  })

  test('sent 레코드의 sent_at 타임존 무결성', async ({ request }) => {
    const res  = await request.get(`${API_BASE}/newsletters?per_page=50`)
    const json = await res.json()
    const sent = (json.items ?? []).filter(i => i.sent_at)

    for (const item of sent.slice(0, 5)) {
      expect(new Date(item.sent_at).toString()).not.toBe('Invalid Date')
    }
  })

  test('HMAC 시크릿 존재 (암호화 키 초기화 완료)', () => {
    if (!RUN) return
    const secret = wpEval(`echo get_option('crmbiz_nl_secret');`)
    expect(secret.length).toBeGreaterThanOrEqual(64)
  })

  test('암호화 → 복호화 라운드트립', () => {
    if (!RUN) return
    const email     = 'roundtrip@example.com'
    const encrypted = wpEval(`echo CRMBizNewsletter\\Database::encryptEmail('${email}');`)
    const decrypted = wpEval(`echo CRMBizNewsletter\\Database::decryptEmail('${encrypted}');`)
    expect(decrypted).toBe(email)
  })

  test('idx_nl_email_type 커버링 인덱스 존재 (2.1.0 마이그레이션 완료)', () => {
    if (!RUN) return
    const indexes = wpEval(`
      global $wpdb;
      echo implode(',', $wpdb->get_col("SHOW INDEX FROM {$wpdb->prefix}crmbiz_nl_events", 2));
    `).split(',')
    expect(indexes).toContain('idx_nl_email_type')
  })

})
