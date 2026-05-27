# Phase 2 아카이브 — 추적·재발송·보안 강화

**완료일**: 2026-05-28  
**버전 범위**: 0.2.3 → 0.3.2  
**포함 커밋**: Gutenberg 경쟁 조건 수정부터 v0.3.2 보안 수정까지

---

## 구현 완료 항목

| 항목 | 버전 | 파일 |
|---|---|---|
| 오픈 추적 (1×1 GIF 픽셀) | v0.3.0 | `src/TrackingHandler.php` |
| 클릭 추적 (리디렉트 래퍼) | v0.3.0 | `src/TrackingHandler.php` |
| 이메일 발송 로그 (AJAX 레이지 로드) | v0.3.0 | `src/Admin/HistoryPage.php` |
| 재발송 버튼 | v0.3.0 | `src/Plugin.php`, `src/Admin/HistoryPage.php` |
| Gutenberg 경쟁 조건 보완 | v0.2.3 | `src/Plugin.php` |
| 태그/리스트 선택 목록 버그 수정 | v0.2.4 | `src/FluentCRMBridge.php` |
| GitHub Actions ZIP 자동 빌드 | v0.2.3 | `.github/workflows/release.yml` |

---

## TrackingHandler 설계

### 오픈 추적
- `template_redirect` 훅에서 `?crmbiz_nl_action=open` 처리
- HMAC-SHA256 토큰 검증 후 `crmbiz_nl_events` 테이블에 `open` 이벤트 기록
- 검증 실패 시에도 1×1 투명 GIF 반환 (이미지 요청 에러 방지)
- 토큰 형식: `hash_hmac('sha256', "open:{$newsletterId}|{$email}", wp_salt('auth'))`

### 클릭 추적
- `?crmbiz_nl_action=click&url=...` 요청에서 토큰 검증 후 목적지로 리디렉트
- 토큰 검증 실패 시 `home_url('/')` 로 폴백 — 오픈 리디렉트 방지
- 토큰에 목적지 URL 포함 — URL 교체 공격 방지
- 토큰 형식: `hash_hmac('sha256', "click:{$newsletterId}|{$email}|{$targetUrl}", wp_salt('auth'))`

### 오픈/클릭 토큰 분리 이유
- v0.3.0 최초 구현은 동일한 토큰 형식 사용 → 오픈 토큰으로 클릭 리디렉트 가능
- v0.3.1에서 `open:` / `click:` 접두어 + click에 URL 포함으로 분리

### EmailTemplateRenderer 링크 치환
- `injectTracking()`: `href="https?://..."` 패턴을 `html_entity_decode()` 후 클릭 URL로 교체
- `html_entity_decode` 필요 이유: `esc_url()`이 `&`를 `&amp;`로 인코딩하는데,
  WordPress 콘텐츠 내 링크가 이 형태로 저장되어 있으면 클릭 URL에 `&amp;`가 그대로 들어가 이중 인코딩됨

---

## 발송 로그 설계

### crmbiz_nl_events 테이블
| 컬럼 | 타입 | 설명 |
|---|---|---|
| `newsletter_id` | INT | 뉴스레터 레코드 FK |
| `email` | VARCHAR | 수신자 이메일 |
| `type` | VARCHAR(10) | send / fail / open / click |
| `url` | VARCHAR(2083) | 클릭 이벤트의 목적지 URL |
| `occurred_at` | DATETIME | 발생 시각 |

### AJAX 레이지 로드
- 이력 페이지 최초 렌더링 시 로그 행을 포함하지 않음 — N+1 쿼리 방지
- "로그" 버튼 클릭 시 `crmbiz_nl_get_log` AJAX로 해당 뉴스레터 이벤트만 로드
- 이미 로드된 경우 `data-loaded` 속성으로 재요청 차단

### HistoryPage 오픈율 계산
- `open_count` = `COUNT(DISTINCT email)` — 중복 오픈 제거
- 오픈율(%) = `open_count / success_count × 100` — success_count가 0이면 표시 안 함

---

## Gutenberg 경쟁 조건 처리

### 문제
Gutenberg REST API가 `transition_post_status`보다 먼저 발행 상태를 변경하지만,
이 시점에 아직 메타박스 데이터가 저장되지 않아 `_crmbiz_nl_enabled`가 false인 상태.
→ `transition_post_status` 훅이 발화해도 `_crmbiz_nl_enabled`가 없어서 발송 건너뜀.
이후 `save_post`에서 메타가 저장될 때는 이미 `publish` 상태이므로 다시 발화 없음.

### 해결책
`savePostMeta()`에 fallback 트리거 추가:
- `save_post` 시점에 "이미 발행 + 뉴스레터 활성 + DB 레코드 없음" 조건 충족 시 발송

---

## 보안 수정 이력

### v0.3.1 (커밋 2bc39a6, 2ed4fe5)

| # | 심각도 | 내용 |
|---|---|---|
| 1 | **CRITICAL** | 오픈 리디렉트: `handleClick()`에서 토큰 검증 실패 시에도 `$url`로 리디렉트 → 검증 실패 시 `home_url('/')` 폴백 |
| 2 | HIGH | 클릭 토큰에 URL 미포함 → 토큰 재사용으로 다른 URL 리디렉트 가능 → URL 포함으로 수정 |
| 3 | HIGH | `savePostMeta()` post_type 가드 미적용 → 페이지, 첨부파일 저장 시 발송 가능 |
| 4 | MEDIUM | `&amp;` 이중 인코딩 → 클릭 URL에 `&amp;`가 그대로 들어가는 버그 |

### v0.3.2 (커밋 b23542d)

| # | 심각도 | 내용 |
|---|---|---|
| 1 | HIGH | `savePostMeta()` `wp_is_post_revision()` + `post_type !== 'post'` 가드 추가 |
| 2 | HIGH | `handleResend()` 무조건 성공 반환 → `sendForPost()` 호출 전 FluentCRM 가용성·포스트 존재·태그/리스트 유무 사전 검증 추가 |

---

## 신규 AJAX 핸들러

| Action | 용도 | Nonce |
|---|---|---|
| `crmbiz_nl_resend` | 발송 완료/실패 뉴스레터 재발송 | `crmbiz_nl_manual_send` |
| `crmbiz_nl_get_log` | 특정 뉴스레터 이벤트 로그 조회 | `crmbiz_nl_get_log` |

---

## 완료 기준 체크리스트

- [x] 오픈 추적 — 1×1 GIF 픽셀 삽입 + HMAC 토큰 검증
- [x] 클릭 추적 — 링크 치환 + 리디렉트 + HMAC 토큰 검증
- [x] 오픈/클릭 토큰 분리 (접두어 + URL 바인딩)
- [x] 오픈 리디렉트 취약점 제거
- [x] 이력 페이지 오픈율/클릭 수 표시
- [x] 발송 로그 (send/fail/open/click 이벤트) AJAX 레이지 로드
- [x] 재발송 버튼 + 사전 검증
- [x] Gutenberg 경쟁 조건 보완
- [x] GitHub Actions v* 태그 기반 ZIP 자동 빌드
- [x] `savePostMeta()` post_type 가드

---

## 알려진 제한사항

- `sendForPost()` 는 void — 재발송 AJAX가 구독자 0명이어도 성공 반환 (pre-flight만 추가됨)
- 수신자 100명+ 이상이면 HTTP 타임아웃 위험 → Phase 3 큐로 해결 예정
- 예약 발송(`scheduled` 모드) UI만 있고 실제 처리 미구현

---

## 다음 단계 (Phase 3)

- `wp_crmbiz_nl_queue` 테이블 추가
- `QueueManager`: ContactsQuery offset 방식으로 큐 등록
- `BatchProcessor`: WP Cron 배치 처리 (배치 크기 설정 가능)
- 예약 발송 cron 처리
- 실패 재시도 (최대 3회)
- 관리자 실패 알림 이메일
