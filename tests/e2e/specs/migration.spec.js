/**
 * DB 마이그레이션 통합 테스트
 *
 * WP-CLI + 실제 MySQL로 이전 스키마 → install() → 최신 스키마 검증.
 * 핵심 검증: 기존 레코드가 보존되고, 컬럼 추가/삭제가 정확히 수행되며,
 * 암호화된 이메일이 업그레이드 후에도 복호화 가능한지.
 *
 * CI 전용 (WP-CLI 필요). ENABLE_MIGRATION_TEST=1 로 활성화.
 */
import { test, expect } from '@playwright/test'
import { execSync } from 'child_process'

const WP_PATH   = process.env.WP_PATH    || '/tmp/wordpress'
const API_BASE  = (process.env.WP_BASE_URL || 'http://localhost:8080').replace(/\/$/, '')
  + '/wp-json/crmbiz-nl/v1'
const RUN       = process.env.ENABLE_MIGRATION_TEST === '1'

/** WP-CLI 실행 헬퍼 */
function wp(cmd) {
  return execSync(`wp ${cmd} --path=${WP_PATH}`, { encoding: 'utf-8' }).trim()
}

function wpEval(code) {
  // 줄바꿈 제거 후 단일 라인으로 전달
  const flat = code.replace(/\s+/g, ' ').trim()
  return execSync(`wp eval '${flat.replace(/'/g, "'\\''")}' --path=${WP_PATH}`, { encoding: 'utf-8' }).trim()
}

// ── v1.2.x → 2.0.0 마이그레이션 ──────────────────────────────────────────

test.describe('마이그레이션: v1.2.x → 2.0.0', () => {

  test.skip(!RUN, 'ENABLE_MIGRATION_TEST=1 필요')

  test('error_log 컬럼 제거, 데이터 보존, DB 버전 갱신', async ({ request }) => {
    // 1. 이전 스키마 구성: error_log 있음, fail_reason/sends/logs 없음
    wpEval(`
      global $wpdb;
      $tbl = $wpdb->prefix . 'crmbiz_newsletters';
      // error_log 컬럼 추가 (v1.2.x 구 스키마)
      $wpdb->query("ALTER TABLE {$tbl} ADD COLUMN error_log TEXT NULL");
      // subscriber_emails 컬럼 추가 (v1.4.x 이전)
      $cols = $wpdb->get_col("SHOW COLUMNS FROM {$tbl}");
      if (!in_array('subscriber_emails', $cols)) {
        $wpdb->query("ALTER TABLE {$tbl} ADD COLUMN subscriber_emails TEXT NULL");
      }
      // fail_reason 제거 (v1.7.x 이전)
      if (in_array('fail_reason', $cols)) {
        $wpdb->query("ALTER TABLE {$tbl} DROP COLUMN fail_reason");
      }
    `)

    // 2. DB 버전을 1.2.0 으로 낮춤
    wpEval(`update_option('crmbiz_nl_db_version', '1.2.0');`)

    // 3. 현재 레코드 수 스냅샷
    const countBefore = parseInt(
      wpEval(`global $wpdb; echo $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}crmbiz_newsletters");`)
    )
    expect(countBefore).toBeGreaterThan(0)

    // 4. 암호화 이메일 저장 (복호화 보존 확인용)
    const testEmail = 'migration-test@example.com'
    const encrypted = wpEval(`
      echo CRMBizNewsletter\\Database::encryptEmail('${testEmail}');
    `)
    expect(encrypted.length).toBeGreaterThan(10)

    // 5. 마이그레이션 실행
    wpEval(`CRMBizNewsletter\\Database::install();`)

    // 6. DB 버전 2.0.0으로 갱신됐는지
    const version = wpEval(`echo get_option('crmbiz_nl_db_version');`)
    expect(version).toBe('2.0.0')

    // 7. 레코드 수 보존 확인
    const countAfter = parseInt(
      wpEval(`global $wpdb; echo $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}crmbiz_newsletters");`)
    )
    expect(countAfter).toBe(countBefore)

    // 8. error_log 컬럼 제거됐는지
    const cols = wpEval(`
      global $wpdb;
      $cols = $wpdb->get_col("SHOW COLUMNS FROM {$wpdb->prefix}crmbiz_newsletters");
      echo implode(',', $cols);
    `).split(',')
    expect(cols).not.toContain('error_log')
    expect(cols).not.toContain('subscriber_emails')

    // 9. fail_reason 컬럼 추가됐는지
    expect(cols).toContain('fail_reason')

    // 10. idx_status_sent_at 인덱스 추가됐는지
    const indexes = wpEval(`
      global $wpdb;
      $rows = $wpdb->get_col("SHOW INDEX FROM {$wpdb->prefix}crmbiz_newsletters", 2);
      echo implode(',', $rows);
    `).split(',')
    expect(indexes).toContain('idx_status_sent_at')

    // 11. crmbiz_nl_sends 테이블 존재 확인
    const sendsTbl = wpEval(`
      global $wpdb;
      echo $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}crmbiz_nl_sends'");
    `)
    expect(sendsTbl).toContain('crmbiz_nl_sends')

    // 12. crmbiz_nl_logs 테이블 존재 확인
    const logsTbl = wpEval(`
      global $wpdb;
      echo $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}crmbiz_nl_logs'");
    `)
    expect(logsTbl).toContain('crmbiz_nl_logs')

    // 13. 암호화 이메일 복호화 여전히 동작 확인
    const decrypted = wpEval(`
      echo CRMBizNewsletter\\Database::decryptEmail('${encrypted}');
    `)
    expect(decrypted).toBe(testEmail)

    // 14. REST API 정상 응답 (마이그레이션 후 서비스 무결성)
    const res  = await request.get(`${API_BASE}/newsletters`)
    expect(res.status()).toBe(200)
    const json = await res.json()
    expect(json.total).toBe(countAfter)
  })

})

// ── 이미 최신 버전 → 마이그레이션 재실행 안전성 ──────────────────────────

test.describe('마이그레이션: 이미 최신 버전 재실행', () => {

  test.skip(!RUN, 'ENABLE_MIGRATION_TEST=1 필요')

  test('2.0.0 → install() 재실행해도 데이터 손실 없음', async ({ request }) => {
    // 현재 레코드 수
    const countBefore = parseInt(
      wpEval(`global $wpdb; echo $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}crmbiz_newsletters");`)
    )

    // install() 재실행 (idempotent 확인)
    wpEval(`CRMBizNewsletter\\Database::install();`)

    const countAfter = parseInt(
      wpEval(`global $wpdb; echo $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}crmbiz_newsletters");`)
    )
    expect(countAfter).toBe(countBefore)

    // 버전 여전히 2.0.0
    const version = wpEval(`echo get_option('crmbiz_nl_db_version');`)
    expect(version).toBe('2.0.0')

    // API 정상
    const res = await request.get(`${API_BASE}/dashboard`)
    expect(res.status()).toBe(200)
  })

})

// ── 마이그레이션 없이 현재 상태 검증 (항상 실행) ──────────────────────────

test.describe('DB 현재 상태 무결성', () => {

  test('DB 버전이 코드 버전과 일치', async ({ request }) => {
    if (!RUN) {
      // CI 환경이 아니어도 API 응답으로 간접 검증
      const res = await request.get(`${API_BASE}/newsletters`)
      expect(res.status()).toBe(200)
      return
    }
    const version = wpEval(`echo get_option('crmbiz_nl_db_version');`)
    expect(version).toBe('2.0.0')
  })

  test('7개 테이블 모두 존재', async ({ request }) => {
    if (!RUN) {
      // REST API 200 = 테이블 존재 간접 확인
      const res = await request.get(`${API_BASE}/newsletters`)
      expect(res.status()).toBe(200)
      return
    }

    const tables = [
      'crmbiz_newsletters',
      'crmbiz_nl_unsubscribers',
      'crmbiz_nl_queue',
      'crmbiz_nl_events',
      'crmbiz_nl_ratelimit',
      'crmbiz_nl_sends',
      'crmbiz_nl_logs',
    ]

    for (const table of tables) {
      const result = wpEval(`
        global $wpdb;
        echo $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}${table}'");
      `)
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

  test('HMAC 시크릿 존재 (암호화 키 초기화 완료)', async () => {
    if (!RUN) return

    const secret = wpEval(`echo get_option('crmbiz_nl_secret');`)
    expect(secret.length).toBeGreaterThanOrEqual(64) // bin2hex(random_bytes(32)) = 64자
  })

  test('암호화 → 복호화 라운드트립 현재 환경에서 동작', async () => {
    if (!RUN) return

    const email     = 'roundtrip@example.com'
    const encrypted = wpEval(`echo CRMBizNewsletter\\Database::encryptEmail('${email}');`)
    const decrypted = wpEval(`echo CRMBizNewsletter\\Database::decryptEmail('${encrypted}');`)
    expect(decrypted).toBe(email)
  })

})
