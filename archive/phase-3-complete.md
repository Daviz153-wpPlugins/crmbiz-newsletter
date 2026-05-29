# Phase 3 아카이브 — 큐 테이블·코드 구조 개선·테스트

**완료일**: 2026-05-29
**버전**: 0.4.0
**작업 범위**: 코드 품질 리뷰 후 5개 항목 수정 + 모바일 버그 수정

---

## 구현 완료 항목

| # | 항목 | 관련 파일 |
|---|---|---|
| 1 | `buildHtml()` → `templates/email.php` 분리 | `src/EmailTemplateRenderer.php`, `templates/email.php` |
| 2 | `subscriber_emails` JSON blob → `crmbiz_nl_queue` 테이블 | `src/Database.php`, `src/NewsletterSender.php`, `src/Plugin.php` |
| 3 | `Plugin.php` AJAX 핸들러 → `AjaxHandlers` 분리 | `src/Plugin.php`, `src/Admin/AjaxHandlers.php` |
| 4 | `cleanupOnDelete()` 빈 메서드 + 훅 제거 | `src/UnsubscribeHandler.php` |
| 5 | PHPUnit 테스트 코드 추가 (29개 테스트) | `tests/`, `composer.json`, `phpunit.xml` |
| 6 | 모바일에서 "웹에서 보기" 문구 숨김 제거 | `templates/email.php` |

---

## #1 이메일 템플릿 파일 분리

### 변경 전
`EmailTemplateRenderer::buildHtml()` 내부에서 115줄 분량의 HTML을 문자열로 조립.

### 변경 후
- `buildHtml()`은 escape된 변수 및 HTML 섹션을 준비한 뒤 `ob_start()` + `include` + `ob_get_clean()` 패턴으로 렌더링
- `templates/email.php`: 순수 HTML 템플릿, 이미 escape된 변수만 사용
- phpcs:ignore 주석: `$content`, `$recentSection` (이미 사전 처리된 HTML)

---

## #2 crmbiz_nl_queue 테이블 (DB v1.4.0)

### 문제
`subscriber_emails MEDIUMTEXT`에 전체 구독자 이메일 목록을 JSON으로 저장 →
- 구독자 수 많을수록 컬럼 크기 증가
- 배치 재시작 시 전체 목록 재조회 없이 슬라이싱만 가능해 JSON 파싱 비용 발생
- 취소 시 null 처리 외에 정리 로직 없음

### 해결

**새 테이블 스키마**
```sql
CREATE TABLE crmbiz_nl_queue (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  newsletter_id BIGINT UNSIGNED NOT NULL,
  email         VARCHAR(191) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_nl_email (newsletter_id, email),
  KEY idx_newsletter_id (newsletter_id)
)
```

**`sendFromRecord()` 변경**

| 항목 | 변경 전 | 변경 후 |
|---|---|---|
| 시그니처 | `sendFromRecord(int $id, int $offset): int` | `sendFromRecord(int $id): bool` |
| 반환 의미 | 다음 배치 오프셋 (0 = 완료) | true = 더 남음, false = 완료 |
| 큐 방식 | JSON 슬라이싱 | `crmbiz_nl_queue` 행 DELETE로 진행 |
| 첫 배치 판단 | `$offset === 0` | `COUNT(*) === 0` |

**마이그레이션 안전성**
- `UNIQUE KEY`와 `INSERT IGNORE` 조합으로 재시작 시 중복 삽입 방지
- `subscriber_emails` 컬럼은 DROP하지 않고 잔존 (dbDelta는 컬럼 삭제 안 함, 하위호환)
- COUNT 기반 큐 판단: 배포 중 `sending` 상태인 뉴스레터가 있어도 큐를 다시 채워 재발송 (오프셋 기반보다 안전)

**취소 시 처리**
```php
$wpdb->delete('crmbiz_nl_queue', ['newsletter_id' => $id], ['%d']);
```

---

## #3 Plugin.php 클래스 분리

### 변경 전
`Plugin.php` 546줄 — AJAX 핸들러 8개가 부트스트랩 클래스에 혼재.

### 변경 후

| 파일 | 역할 | 줄 수 |
|---|---|---|
| `src/Plugin.php` | 싱글톤 부트스트랩, 훅 등록, 포스트 발행, cron | 249줄 |
| `src/Admin/AjaxHandlers.php` | AJAX 핸들러 8개 + `buildTestEmailBody()` | 308줄 |

**연결 방식**
```php
// Plugin::registerHooks() 내
$ajax = new AjaxHandlers($this->settings, self::CRON_HOOK);
add_action('wp_ajax_crmbiz_nl_test_email', [$ajax, 'handleTestEmail']);
// ...
```

`AjaxHandlers`는 `Settings`와 `cronHook` 문자열을 생성자로 받아 `Plugin` 클래스에 직접 의존하지 않음.

---

## #4 cleanupOnDelete 제거

`UnsubscribeHandler::init()`에 등록된 `fluentcrm_after_subscribers_deleted` 훅과 빈 메서드 `cleanupOnDelete(array $subscriberIds): void {}` 제거.

**이유**: FluentCRM에서 연락처가 삭제돼도 수신거부 레코드는 스팸 재구독 방지를 위해 보존하는 것이 의도이므로 훅 자체가 불필요.

---

## #5 PHPUnit 테스트 (29개)

**환경**
- PHPUnit 11 / PHP 8.5
- `tests/bootstrap.php`: WordPress 함수 in-memory 스텁 (get_option, update_option, transients 등)
- Composer 자동로더로 `src/` 클래스 로드 — WordPress 없이 실행 가능

**테스트 파일**

| 파일 | 테스트 수 | 커버 항목 |
|---|---|---|
| `DatabaseEncryptionTest.php` | 9 | 암호화 라운드트립, 오류 입력, 레이트리밋 |
| `UnsubscribeHandlerTest.php` | 11 | maskEmail, verifyToken, buildUnsubscribeUrl |
| `SettingsTest.php` | 9 | get/set, saveFromPost, isDryRun |

**실행**
```bash
composer test
# 또는
./vendor/bin/phpunit
```

**결과**: OK (29 tests, 42 assertions)

---

## #6 모바일 "웹에서 보기" 노출

`templates/email.php` 미디어쿼리에서 `.nl-web { display:none !important; }` 제거.

**이전**: 모바일(≤620px)에서 헤더 우측 "웹에서 보기 →" 링크 숨김
**이후**: 데스크톱·모바일 모두 표시

---

## 알려진 제한사항

- `subscriber_emails` 컬럼이 DB에 잔존 (사용 안 함, 나중에 정리 가능)
- WP Cron이 실제 방문자 기반으로 동작하므로 로컬 개발 환경에서는 수동 트리거 필요
- 테스트가 WordPress 실제 DB / WP Cron / FluentCRM 연동을 커버하지 않음 (단위 테스트 범위)

---

## 다음 단계 (Phase 4 후보)

- 실패 재시도 (최대 N회, `crmbiz_nl_queue`에 `retry_count` 컬럼 추가)
- 관리자 발송 완료/실패 알림 이메일
- 실시간 발송 진행률 표시 (AJAX 폴링)
- `subscriber_emails` 컬럼 DROP 마이그레이션 (v1.5.0)
