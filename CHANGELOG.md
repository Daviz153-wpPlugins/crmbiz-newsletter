# Changelog

모든 주요 변경 사항을 이 파일에 기록합니다.  
형식: [Keep a Changelog](https://keepachangelog.com/ko/1.0.0/)

---

## [1.3.1] — 2026-06-08

### Fixed
- **uninstall 클린 삭제 보완** — 누락된 `crmbiz_nl_last_cron_run` 옵션, 대시보드/차트 트랜지언트(`crmbiz_nl_dash_stats`, `crmbiz_nl_dash_chart_*`), Logger 에러 rate-limit 트랜지언트(`crmbiz_nl_err_*`) 삭제 추가
- **Action Scheduler 이벤트 정리** — uninstall 시 `wp_clear_scheduled_hook()` 외에 `as_unschedule_all_actions()`도 호출해 AS 테이블 잔여 이벤트 제거

---

## [1.3.0] — 2026-06-04

### Added
- **DB UTC 컬럼 추가 (DB 2.2.0)** — 사이트 timezone 변경·서버 이전 시 데이터 무결성 보호. 기존 로컬 컬럼 유지, 신규 레코드부터 `*_gmt` UTC 컬럼 이중 저장. 5개 테이블(`newsletters`, `unsubscribers`, `events`, `sends`, `logs`) 적용.

---

## [1.2.1] — 2026-06-03

### Changed
- **PHP 최소 요건 8.1 → 8.2** — PHPUnit 11이 PHP 8.2+를 필요로 하며, PHP 8.1은 2024년 11월 EOL. composer.json, 플러그인 헤더 모두 반영.

---

## [1.2.0] — 2026-06-03

### Fixed
- **배치 크기 50 → 30** — 공유 호스팅 환경 타임아웃 완화.
- **데드코드 제거** — `EmailTemplateRenderer::render()`의 미사용 필터 호출 제거.

---

## [1.1.0] — 2026-06-02

### Added
- 발송 이력 페이지: 상태 필터, 날짜 범위, 재발송/취소/삭제
- 수신거부 관리 페이지: 검색, CSV 내보내기

---

## [1.0.0] — 2026-06-02

### Added
- 최초 릴리즈
