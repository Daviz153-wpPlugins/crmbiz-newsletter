# Changelog

모든 주요 변경 사항을 이 파일에 기록합니다.  
형식: [Keep a Changelog](https://keepachangelog.com/ko/1.0.0/)

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
