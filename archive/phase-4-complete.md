# Phase 4 아카이브 — 기능 추가 + MVP 배포

**완료일**: 2026-05-29  
**버전**: 0.5.3  
**작업 범위**: 실시간 진행률·실패 재시도·관리자 알림·스키마 정리 + 프로덕션 버그 수정 + 이벤트 테이블 정리 크론

---

## 구현 완료 항목

| # | 항목 | 관련 파일 |
|---|------|-----------|
| 1 | 실시간 발송 진행률 폴링 | `assets/admin-history.js`, `src/Admin/AjaxHandlers.php`, `src/Admin/HistoryPage.php`, `src/Plugin.php` |
| 2 | 실패 재시도 로직 (MAX_RETRIES=3) | `src/NewsletterSender.php`, `src/Database.php` |
| 3 | 발송 완료 관리자 이메일 알림 | `src/NewsletterSender.php` |
| 4 | `subscriber_emails` 컬럼 DROP | `src/Database.php` |
| 5 | `finalizeSend()` null 가드 버그 수정 | `src/NewsletterSender.php` |
| 6 | 이벤트 테이블 90일 보존 일별 크론 | `src/Plugin.php`, `crmbiz-newsletter.php` |
| 7 | 릴리즈 ZIP에서 dev 파일 제외 | `.github/workflows/release.yml` |
| 8 | 플러그인 헤더에 `Requires PHP: 8.0`, `Requires Plugins: fluentcrm` 추가 | `crmbiz-newsletter.php` |

---

## #1 실시간 발송 진행률 폴링

### 변경 전
30초 카운트다운 타이머 + 전체 페이지 리로드 방식.

### 변경 후
- 5초 AJAX 폴링 (`crmbiz_nl_progress` 액션)
- `sending` / `queued` 상태 행에만 폴링 시작
- 상태가 바뀌면 `location.reload()`, 유지되는 동안은 `.crmbiz-progress-text` / `.crmbiz-progress-fill` DOM 인플레이스 업데이트
- 우측 하단 "발송 중 — 자동 업데이트 중" 플로팅 인디케이터 표시

**추가된 AJAX 핸들러** (`AjaxHandlers::handleProgress`):
```php
// POST ids[] → SELECT id, status, success_count, fail_count, recipient_count
// 반환: id, status, done, recipient_count, percent
```

**JS 핵심 로직** (`assets/admin-history.js`):
```javascript
function poll() {
    $.post(ajaxUrl, { action: 'crmbiz_nl_progress', nonce, ids })
    // status 변경 시 reload, sending 유지 시 DOM 업데이트
}
var pollTimer = setInterval(poll, 5000);
```

---

## #2 실패 재시도 로직

### 변경 전
발송 실패 시 즉시 큐에서 삭제 → 재시도 없음.

### 변경 후
`crmbiz_nl_queue` 테이블에 `retry_count TINYINT UNSIGNED NOT NULL DEFAULT 0` 컬럼 추가.

**처리 흐름** (`NewsletterSender::sendFromRecord`):
```
배치 rows 조회 (id, email, retry_count 포함)
  ↓
이메일 → 큐 행 맵 구성 ($emailToRow)
  ↓
FluentCRM에서 구독자 조회
  ↓
각 구독자 처리:
  - 수신거부 → $toDelete (fail 카운트 없음)
  - 발송 성공 → $toDelete, success++
  - 발송 실패 + retry_count+1 >= MAX_RETRIES → $toDelete, fail++ (영구 실패)
  - 발송 실패 + 재시도 가능 → $toRetry (retry_count만 +1, 큐 유지)
  ↓
FluentCRM에 없는 이메일 → $toDelete (구독자 삭제됨, 조용히 건너뜀)
  ↓
DELETE $toDelete / UPDATE retry_count+1 for $toRetry
```

**상수**:
- `BATCH_SIZE = 50`
- `MAX_RETRIES = 3`

**DB 마이그레이션** (v1.5.0):
```sql
ALTER TABLE crmbiz_nl_queue ADD COLUMN retry_count TINYINT UNSIGNED NOT NULL DEFAULT 0
```

---

## #3 발송 완료 관리자 알림

`finalizeSend()` 완료 후 `get_option('admin_email')`로 HTML 이메일 발송.

포함 정보: 뉴스레터 제목, 성공 건수, 실패 건수, 성공률, 발송 이력 페이지 링크.

- Dry-Run 모드이거나 admin_email이 유효하지 않으면 건너뜀
- 발신자는 설정 페이지의 From Name / From Email 사용

---

## #4 subscriber_emails 컬럼 DROP

Phase 3에서 큐 테이블로 교체됐으나 컬럼이 잔존했음.

**v1.5.0 마이그레이션**에서 처리:
```sql
ALTER TABLE crmbiz_newsletters DROP COLUMN subscriber_emails
```

`CREATE TABLE` 정의에서도 제거 (신규 설치에서도 생성 안 됨).

---

## #5 finalizeSend() null 가드

**버그**: 크론 실행 중 newsletter 레코드가 삭제된 경우 `$final->success_count` 접근 시 fatal error 발생.

**수정**:
```php
if (!$final) {
    return;
}
```

---

## #6 이벤트 테이블 정리 크론

발송 이벤트(`crmbiz_nl_events`)가 무제한 누적되는 문제 방지.

- 훅: `crmbiz_nl_cleanup` (일별)
- 플러그인 활성화 시 `wp_schedule_event`로 자동 등록
- 플러그인 비활성화 시 `wp_clear_scheduled_hook`으로 자동 해제
- 보존 기간: 90일 (`Plugin::RETAIN_DAYS = 90`)

```php
DELETE FROM crmbiz_nl_events WHERE occurred_at < DATE_SUB(NOW(), INTERVAL 90 DAY)
```

---

## 현재 DB 스키마 (v1.5.0)

```sql
crmbiz_newsletters (
  id, post_id, status, send_mode, scheduled_at, sent_at,
  recipient_count, success_count, fail_count,
  tag_ids, list_ids,
  created_at, updated_at
)

crmbiz_nl_unsubscribers (
  id, email, unsubscribed_at, token_used
)

crmbiz_nl_queue (
  id, newsletter_id, email, retry_count  ← v1.5.0 추가
)

crmbiz_nl_events (
  id, newsletter_id, email, type, url, occurred_at
)
```

---

## 릴리즈 ZIP 구성

`.github/workflows/release.yml` — `v*` 태그 push 시 자동 빌드.

**포함**: `src/`, `assets/`, `templates/`, `vendor/`, `autoload.php`, `crmbiz-newsletter.php`, `uninstall.php`  
**제외**: `tests/`, `vendor/bin/`, `phpunit.xml`, `composer.json`, `composer.lock`, `.gitignore`, `*.md`, `.github/`, `archive/`

---

## 서버 요구사항

- PHP 8.0 이상
- PHP openssl 확장
- WordPress 6.0 이상
- FluentCRM 플러그인 (필수)
- FluentSMTP 또는 SMTP 플러그인 (권장)
- 서버 크론 설정 권장:
  ```
  */5 * * * * wget -q -O - https://사이트주소/wp-cron.php?doing_wp_cron >/dev/null 2>&1
  ```

---

## 다음 단계 후보

- 이벤트 테이블 보존 기간 설정 페이지 UI 추가 (현재 90일 고정)
- 발송 이력 페이지 페이지네이션 (newsletter 행 수 증가 시)
- 구독자 임포트 / 태그 관리 UI
- 수신거부 통계 대시보드
