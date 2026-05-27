# Phase 0 아카이브 — 기반 구조 + 이메일 진단

**완료일**: 2026-05-28  
**버전**: 0.1.0

---

## 구현 완료 파일

| 파일 | 역할 |
|---|---|
| `crmbiz-newsletter.php` | 플러그인 진입점 (헤더, 상수, 초기화) |
| `autoload.php` | PSR-4 오토로더 (`CRMBizNewsletter\` → `src/`) |
| `src/Plugin.php` | 싱글톤, 훅 등록, AJAX 핸들러 |
| `src/Settings.php` | 타입 안전 설정 래퍼 (get_option 기반) |
| `src/Database.php` | 테이블 생성 (dbDelta), 버전 관리 |
| `src/FluentCRMBridge.php` | FluentCRM 공식 API 단일 접점 |
| `src/Admin/SettingsPage.php` | 발신자 설정 + Dry-run 토글 |
| `src/Admin/DiagnosticsPage.php` | 상태 대시보드 + 테스트 이메일 AJAX |

---

## 생성된 DB 테이블

- `wp_crmbiz_newsletters` — 발송 이력 (Phase 1부터 사용)
- `wp_crmbiz_nl_unsubscribers` — 수신거부 목록 (Phase 1부터 사용)

---

## 주요 설계 결정

### FluentCRMBridge 패턴
모든 FluentCRM API 호출을 `FluentCRMBridge`로 래핑.  
이유: FluentCRM 미설치 환경에서 fatal error 방지 + try/catch로 실패 격리.

### 인라인 JS (외부 파일 없음)
Phase 0은 단순 AJAX 1개 — 외부 JS 파일 추가 복잡도를 피해 `render()` 내 인라인 처리.  
Phase 1 이상에서 상호작용이 복잡해지면 외부 파일 분리 예정.

### Settings 폴백 체인
`커스텀 설정 → FluentCRM 전역 설정 → WordPress 기본값` 순서로 폴백.  
FluentCRM이 없어도 플러그인이 독립적으로 동작.

### dbDelta 주의사항
- 각 CREATE TABLE을 별도 `dbDelta()` 호출로 분리 (멀티 테이블 파싱 오류 방지)
- 컬럼 정의를 한 줄씩 작성 (dbDelta 파서 요구사항)
- `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` — MySQL 5.6+ 필요

---

## 완료 기준 체크리스트

- [x] 플러그인 활성화 시 오류 없이 테이블 생성
- [x] 진단 페이지 FluentCRM 상태 표시
- [x] 진단 페이지 FluentSMTP 상태 표시
- [x] 테스트 이메일 AJAX 발송 기능
- [x] Dry-run 모드 지원
- [x] 설정 저장/불러오기 동작
- [x] FluentCRM 없을 때도 플러그인 정상 로드

---

## 알려진 제한사항

- `updated_at DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` 는 MySQL 5.6+ 전용.  
  구버전 환경에서는 `updated_at` 수동 갱신 필요.
- `countByStatus()` 호출은 태그/리스트 수가 많으면 N+1 쿼리 발생.  
  Phase 1에서 메타박스 AJAX로 이동 시 캐싱 고려.

---

## 다음 단계 (Phase 1)

- MetaBox (포스트 편집 뉴스레터 설정)
- NewsletterSender (ContactsQuery 기반 발송)
- EmailTemplateRenderer (포스트 → HTML 이메일)
- UnsubscribeHandler (HMAC 토큰 수신거부)
- HistoryPage (발송 이력 목록)
