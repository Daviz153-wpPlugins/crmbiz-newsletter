# Changelog

모든 주요 변경 사항을 이 파일에 기록합니다.  
형식: [Keep a Changelog](https://keepachangelog.com/ko/1.0.0/)

---

## [1.3.0] — 2026-06-04

### Added
- **DB UTC 컬럼 추가 (DB 2.2.0)** — 사이트 timezone 변경·서버 이전 시 데이터 무결성 보호. 기존 로컬 컬럼 유지, 신규 레코드부터 `*_gmt` UTC 컬럼 이중 저장. 5개 테이블(`newsletters`, `unsubscribers`, `events`, `sends`, `logs`) 적용.

---

## [1.2.1] — 2026-06-03

### Changed
- **PHP 최소 요건 8.1 → 8.2** — PHPUnit 11이 PHP 8.2+를 필요로 하며, PHP 8.1은 2024년 11월 EOL. composer.json, 플러그인 헤더 모두 반영.

### Tests
- **migration.spec.js 전면 개선** — 4개 실패 → 14개 전부 통과. wpEval Deprecated 필터 추가, 버전 상수 통일, 큐 스키마 오류 수정. 2.0.0→2.1.0 자동 업그레이드·발송 중 업그레이드 안전성 7개 신규 추가.

---

## [1.2.0] — 2026-06-03

### Fixed
- **배치 크기 50 → 30** — 공유 호스팅(`max_execution_time=30`) 환경에서 SMTP 응답 지연이 쌓여 PHP 타임아웃이 발생할 수 있는 문제 완화. 실측: PHP 오버헤드 0.28초, SMTP 예산 0.99초/건으로 여유 확보.
- **데드코드 제거** — `EmailTemplateRenderer::render()`의 `fluent_crm/parse_campaign_email_text` 필터 호출 제거(1001명 부하 테스트에서 실제로 동작 안 함 확인). `buildHtml` 배열에서 미사용 `subscriber` 키 제거.

### Tests
- **ESLint 설정** — `eslint.config.js` 추가(Vue3 essential + Playwright). `package.json`에 `lint`/`lint:fix` 스크립트. GitHub Actions CI job 추가. 기존 오류 35건 수정(미사용 import·변수, BOM 리터럴 등).
- **Transient Eviction PHPUnit 7개** — Managed 호스팅(WP Engine/Kinsta)의 Redis 기반 캐시 강제 소거 환경에서 `getDashboard()`가 정확한 데이터를 반환하는지 검증.
- **DISABLE_WP_CRON E2E 4개** — 외부 cron(`do_action` 직접 트리거)으로 queued→sent 전환, 큐 완전 소진, GET_LOCK 이중 발송 방지 검증.
- **공유 호스팅 제약 E2E 2개** — 배치 30건 PHP 오버헤드(0.28초)·피크 메모리(2MB) 실측.

---

## [1.1.0] — 2026-06-03

### Performance
- **`getDashboard()` 통계·차트 캐싱** — stats(COUNT+SUM)와 차트(일별 집계)를 5분 transient으로 캐싱. campaigns/events JOIN은 추적 이벤트마다 변하므로 캐싱 제외. 무효화 지점: 발송 완료(`finalizeSend`) · 뉴스레터 삭제(`deleteNewsletter`). `RestApi::clearDashboardCache()` 헬퍼 추가.

### Fixed
- **FluentCRM 비활성화 경로 큐 고아** — `sendFromRecord()`에서 FluentCRM 미활성화로 `failed` 처리 시 큐를 정리하지 않던 문제 수정. `delete` 즉시 실행 추가.
- **`handleCleanup()` 고아 큐 안전망** — 정상 경로 외에 `failed`/`cancelled` 상태의 잔여 큐 행을 일 1회 정리하는 JOIN DELETE 추가.

### Database — 2.1.0
- **`crmbiz_nl_events` 커버링 인덱스 추가** — `idx_nl_email_type (newsletter_id, email, type)`. `getNewsletterDetail()`의 `WHERE newsletter_id=? GROUP BY email`에서 기존 `idx_nl_type(newsletter_id, type)`의 filesort를 제거. 마이그레이션 idempotent (SHOW INDEX 확인 후 조건부 추가).

### Tests
- 캐시 PHPUnit 3개 추가: 캐시 히트, `clearDashboardCache` 전체 삭제, days별 키 독립성
- `_wp_transients` 격리를 `RestApiBusinessLogicTest` setUp/tearDown에 추가
- `MINUTE_IN_SECONDS` 상수를 bootstrap.php에 추가

### Dropped (Phase A-2)
- GET_LOCK → FOR UPDATE SKIP LOCKED 전환 **보류**. 분석 결과: `wp_mail()` 호출이 배치 루프 내부에서 발생하므로 트랜잭션을 열어둔 채 SMTP I/O를 처리하면 오히려 락 타임아웃 위험이 증가함. GET_LOCK은 뉴스레터 단위 직렬화(이중 발송 방지)로 이미 올바름. 세션 종료 시 자동 해제되어 프로세스 크래시에도 안전. DEV_PLAYBOOK §13에 이유 기록.

---

## [1.0.0] — 2026-06-03

### Fixed
- **수신거부 해제 버튼 아이콘 누락** — `UnsubscribePage.php`의 `<button>` 닫는 `>` 누락으로 dashicons-undo 아이콘이 렌더되지 않던 문제 수정
- **대시보드 `getDashboard()` DivisionByZeroError** — `per_page` 파라미터 미지정 시 `$campaignPerPage = 0`이 되어 발생하던 0 나누기 오류 수정 (`?? 5` 누락)

### Tests — PHPUnit (251 → 319, +68)
- **`DatabaseTest`** 17개 — `getSecret()` 생성·재사용, `getVersion()`/`isInstalled()`, `checkRateLimit()` 추가 케이스, `getClientIp()` IP 헤더 우선순위 (CF → XFF → REMOTE_ADDR, 위조 방지 로직)
- **`RestApiBusinessLogicTest`** 22개 — `formatNewsletter()` open_rate/click_rate 계산 및 0 나누기 방어, `getProgress()` percent 공식·100% 캡, `getNewsletterDetail()` 통계 6종(open/click/fail/unsub/ctr rate), `getDashboard()` success_rate 계산·차트 길이·폴백
- **`PluginTest`** 29개 — `onPostPublished()` 조기 반환 4건·발송모드 3종, `showCronNotice()` 조기 반환·배너 표시·메시지 3분기, `savePostMeta()` Gutenberg 경쟁 조건, `parseScheduledAt()` 미래/과거/빈값/유효하지않은값

### Tests — E2E (561 → 724, +163)
- **P1** — 수신거부 완전 흐름(WP-CLI 토큰 생성·DB 확인·멱등성·관리자 해제), 오픈/클릭 트래킹 DB 카운트 반영, 발송 진행률 폴링 UI
- **P2** — 이력 필터 실제 DOM 결과 검증, resend-single 완전 흐름(WP-CLI 시딩·수신자탭·토스트), 설정 저장 → 재로드 값 유지
- **P3** — 수신거부 rate limit 429, Nonce 만료 UI 에러 처리(`page.route` 가로채기)
- **P4** — 모바일 반응형(iPhone 14): 가로스크롤·슬라이드오버·폼·모달
- **P5** — WP Cron 경고 배너(조기반환 3건·표시·dismiss), 대량 이력 성능(50개 시딩·로드 <2s·검색 <1s)
- **웹뷰 완전 흐름** — 유효 HMAC → post permalink 302 리다이렉트, 삭제된 post → 홈 리다이렉트
- **CSV 내보내기 파일 검증** — Content-Type, UTF-8 BOM, 헤더행, 데이터 행, nonce 없는 접근 보안

### Infrastructure — `tests/bootstrap.php`
- `sanitize_key()`, `human_time_diff()`, `wp_kses()`, `wp_create_nonce()` 전역 함수 스텁 추가
- `WpdbStub::$posts = 'wp_posts'`, `WpdbStub::esc_like()` 추가
- `current_time('timestamp')` → `time()` 반환 수정 (날짜 연산 정상화)

---

## [0.9.26–0.9.34] — 2026-06-02~03

### Added
- **시스템 로그 테이블** (`crmbiz_nl_logs`, DB 2.0.0) — 레벨별 로그 저장, 7일 자동 정리
- **GDPR 개인정보 내보내기/삭제 훅** — `wp_privacy_personal_data_exporters/erasers` 연동

### Fixed
- **페이지네이션 UI** — select 높이 틀어짐, 드롭다운 텍스트 잘림, 화살표 SVG background-image 방식으로 교체, border-radius inline style 강제 적용 (v0.9.26~v0.9.33)
- **uninstall.php 누락 테이블** — `crmbiz_nl_sends`, `crmbiz_nl_events`, `crmbiz_nl_ratelimit`, `crmbiz_nl_logs` 삭제 추가

### Tests
- Gate 2: 접근 제어·에러 복구·접근성 E2E (507개)
- Gate 3: i18n, 플러그인 충돌, 멀티사이트 PHPUnit+E2E
- Gate 4: `StatusTransitionTest` 38개, `EmailHeaderSecurityTest` 17개 — 총 251 PHPUnit / 561 E2E

### CI
- PHP 버전 매트릭스 8.1 / 8.2 / 8.3
- Firefox + WebKit(Safari) E2E 브라우저 추가
- Node.js 24 강제 적용 (`FORCE_JAVASCRIPT_ACTIONS_TO_NODE24`)
- 마이그레이션 E2E 통합 테스트 및 시드 데이터 개선

---

## [0.9.25] — 2026-06-01

### Fixed
- **대시보드 캠페인 클릭 시 접근 금지** — `?nl=` → `&nl=` URL 버그 수정
- **다크 테마 이메일 본문 배경** — `nl-inner` 전체에 `headerBg` 적용되던 문제, 본문은 `#ffffff` 고정
- **시그니처 미리보기 뷰포트 버튼** — `width:100%` 누락으로 max-width 변경이 시각적으로 반영 안 되던 문제
- **시그니처 실시간 미리보기** — 이벤트 핸들러 직접 바인딩 → `document` 위임 방식으로 변경, `sig_enabled` 토글 핸들러 추가
- **대시보드 최근 캠페인 순서** — `array_reverse()` 제거, 발송 이력과 동일한 최신순 통일

---

## [미출시] — v0.9.24

### Added
- **Action Scheduler Async Runner 활성화** — 트래픽 없는 서버에서도 즉시 큐 처리 (`action_scheduler_run_async` 필터)
- **FluentCRM 바운스 자동 수신거부** — `fluentcrm_subscriber_status_to_bounced/complained` 훅 연동, 수신거부 테이블 자동 등록
- **대시보드 차트 기간 선택** — 7일 / 30일 / 90일 토글
- **이력 페이지 상태 필터** — 전체/완료/발송중/대기/예약/실패/임시저장/취소 pill 버튼
- **이력 페이지 날짜 범위 필터** — 시작일~종료일 입력
- **이력 서버사이드 정렬** — 클라이언트 정렬 제거, API `sort_by`/`sort_dir` 파라미터
- **발송 실패 원인 표시** — `fail_reason` 컬럼(DB 1.8.0), 상세 패널 상단 배너
- **즉시 발송 결과 피드백** — 완료/배치진행 중 세분화된 토스트 메시지
- **수신거부 이름 검색** — FluentCRM `fc_subscribers` JOIN으로 이름 검색 지원

### Changed
- **AES-256-CBC → AES-256-GCM** — 인증 암호화(위변조 탐지), 기존 CBC URL 하위 호환 유지
- **발송 배치 쿼리 최적화** — 수신거부 N회 쿼리 → 1회 배치 조회, logSend N회 INSERT → 1회 bulk INSERT
- **BATCH_SIZE 필터 지원** — `crmbiz_nl_batch_size` 훅으로 배치 크기 조정 가능
- **복합 인덱스 추가(DB 1.9.0)** — `idx_status_sent_at`, `idx_nl_type` 집계 쿼리 최적화

### Tests
- 테스트 42개 → 84개 (166 assertions)
- 신규: RestApiPermissionTest, BounceHandlerTest, Scheduler unscheduleAll

---

## [0.9.23] — 2026-06-01

### Fixed
- MetaBox CSS 특이도 보강 — enabled 라벨/아이콘 `#crmbiz-nl-metabox` 스코핑

### CI
- Release 워크플로우 `overwrite: true` 추가 — 재릴리즈 시 ZIP 교체 보장

---

## [0.9.22] — 2026-06-01

### Added
- Prepublish 예약 패널 — Gutenberg 사이드바에 발송 시각 미리 표시

### Changed
- 인라인 스타일 CSS 클래스 전환

---

## [0.9.21] — 2026-06-01

### Added
- `crmbiz_nl_sends` 발송 로그 테이블(DB 1.7.0) — 개별 수신자 발송 기록 영구 보관

---

## [0.9.20] — 2026-06-01

### Fixed
- Action Scheduler 큐 장애 감지 로직 개선
- 비활성화 훅 추상화 통일
- 테스트 보강

---

## [0.9.19] — 2026-06-01

### Added
- WP Cron 미실행 감지 + 관리자 경고 배너 (`crmbiz_nl_last_cron_run` 옵션)
- `DISABLE_WP_CRON` 감지 시 서버 cron 설정 안내
- Vue 에러 바운더리 — 오류 시 블랭크 대신 "F12 확인" 메시지
- CI 번들 무결성 체크 — import/export 불일치 자동 감지

---

## [0.9.17–0.9.18] — 2026-06-01

### Fixed
- 발송 일시 시간대 통일 — 모든 DB 타임스탬프를 서울 로컬 시간으로 통일 (`scheduled_at`이 UTC로 저장되던 문제)

---

## [0.9.16] — 2026-05-31

### Fixed
- Vue 앱 완전 블랭크 수정 — `emptyOutDir:true` Vite 빌드로 `assets/vue/*` 전체 함께 커밋 필수

### CI
- GitHub Actions에 `npm ci && npm run build` 추가 — 빌드 파일 불일치 재발 방지

---

## [0.9.14–0.9.15] — 2026-05-31

### Added
- 이력 테이블 컬럼 정렬 — 제목/상태/발송일시/수신자/오픈률/클릭률 ↑↓ 정렬

### Fixed
- 대시보드 차트 초기화 순서 수정 (canvas DOM 마운트 후 Chart.js 초기화)
- 수신거부 페이지 FluentCRM 이름 표시

---

## [0.9.12–0.9.13] — 2026-05-31

### Added
- 슬라이드오버 패널 드래그 리사이즈 (min 400px, max 90vw, localStorage 저장)

### Fixed
- 뱃지 줄바꿈 완전 수정 (`whitespace-nowrap`)

---

## [0.9.11] — 2026-05-31

### Fixed
- MetaBox 저장 시 DB `scheduled_at` 즉시 동기화 — 발행된 포스트 저장 시 queued/scheduled 레코드에 예약 시각 반영

---

## [0.9.9–0.9.10] — 2026-05-31

### Changed
- 이메일 본문 날짜 포맷에 시간(H:i) 추가
- queued 상태 뱃지 레이블 "발송 예약" → "발송 대기"

---

## [0.9.7–0.9.8] — 2026-05-31

### Changed
- 이력 테이블 발송 일시 개선 — queued는 "대기 중", scheduled는 예약 시각 표시

---

## [0.9.6] — 2026-05-31

### Fixed
- 릴리즈 ZIP에서 PUC vendor 누락 수정 — rsync `--exclude='/vendor/'` (루트만 제외)

---

## [0.9.5] — 2026-05-31

### Fixed
- MetaBox CSS 누락 수정 — `post.php`, `post-new.php`에서도 `admin.css` 로드

---

## [0.9.4] — 2026-05-31

### Fixed
- GitHub Actions 릴리즈 워크플로우 — 브랜치 push 트리거 → `v*` 태그 push 트리거로 변경

---

## [0.9.3] — 2026-05-31

### Changed
- MetaBox UI 개선 — 볼드 레이블, 태그 줄바꿈, 라디오 간격, 테스트발송 너비

---

## [0.9.2] — 2026-05-31

### Fixed
- 업데이트 체커 fatal error — `.gitignore`에서 `includes/plugin-update-checker/vendor/` 누락 수정

---

## [0.9.0–0.9.1] — 2026-05-31

### Added
- FluentCRM 태그/리스트 기반 대량 발송 (배치 50건, Action Scheduler 우선/WP Cron 폴백)
- 오픈/클릭 트래킹, 수신거부 처리 (HMAC 서명 + AES 암호화)
- 대시보드, 발송 이력, 수신거부 관리, 설정 페이지 (Vue 3 + Tailwind)
- 이메일 템플릿 커스터마이징 (색상/너비/시그니처)
- 드라이런 모드, 테스트 발송, 관리자 알림
- GitHub Releases 기반 자동 업데이트 (Plugin Update Checker)

### Security
- 이메일 암호화 (AES-256-CBC), HMAC 서명, Rate Limiting, SQL Injection 방어

---
