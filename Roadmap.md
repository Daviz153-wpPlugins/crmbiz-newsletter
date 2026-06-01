# CRMBiz Newsletter — Roadmap

> 현재 버전: v0.9.23 (2026-06-01 기준)  
> FluentCRM 애드온 전용. 한국 시장 우선 출시 → 글로벌 확장 계획.

---

## Phase 1 — 핵심 기능 신뢰성

### ✅ 완료

- [x] **WP Cron Phase 1: 관찰 가능성** — 크론 미실행 감지 + 관리자 경고 배너 + DISABLE_WP_CRON 안내 _(v0.9.19)_
- [x] **WP Cron Phase 2: AS Async Runner 활성화** — `action_scheduler_run_async` 필터 적용. 트래픽 없는 서버에서도 즉시 큐 처리 _(v0.9.23)_
- [x] **Vue 에러 바운더리** — 오류 시 블랭크 대신 "F12 확인" 메시지 표시 _(v0.9.19)_
- [x] **CI 번들 무결성 체크** — GitHub Actions에서 import/export 불일치 자동 감지 _(v0.9.19)_
- [x] **Gutenberg 경쟁 조건 수정** — 예약 발송 누락 방지 _(v0.9.20)_
- [x] **개별 수신자 발송 로그** — `crmbiz_nl_sends` 테이블 영구 기록 _(v0.9.21)_
- [x] **prepublish 예약 패널** — 발송 시각 표시 + 인라인 스타일 제거 _(v0.9.22)_

### ✅ 완료

- [x] **바운스 처리** — `fluentcrm_subscriber_status_to_bounced` / `complained` 훅 연동. 바운스/스팸 신고 시 `crmbiz_nl_unsubscribers`에 자동 등록, `token_used`에 `fc_bounced` 기록 _(v0.9.24)_

---

## Phase 2 — UX 완성도

데이터가 누적될수록 현재 UI의 한계가 드러나는 항목들.

- [x] **대시보드 차트 기간 선택** — 7일/30일/90일 토글. API `days` 파라미터 추가 _(v0.9.24)_
- [x] **이력 페이지 상태/날짜 필터** — 상태 필터 pill + 날짜 범위 입력. 제목 검색과 조합 가능 _(v0.9.24)_
- [x] **이력 서버사이드 정렬** — `sort_by`/`sort_dir` API 파라미터 추가. 클라이언트 정렬 제거 _(v0.9.24)_
- [x] **발송 실패 상세 원인 표시** — `fail_reason` 컬럼 추가(DB 1.8.0). FluentCRM 비활성화/수신자 없음/전체 실패 등 원인 저장 → 상세 패널 상단 배너로 표시 _(v0.9.24)_
- [x] **즉시 발송 버튼 결과 피드백** — force-send API 응답에 현재 카운트 추가. 완료/배치진행/일반 세 가지 토스트로 구분 _(v0.9.24)_
- [x] **수신거부 이름 검색** — WHERE에 `fc.first_name/last_name LIKE` 조건 추가. placeholder 업데이트 _(v0.9.24)_

---

## Phase 3 — 시스템 안정성

- [ ] **DB UTC 마이그레이션** — 현재 `current_time('mysql')` 서울 로컬 저장. 사이트 TZ 변경 시 전체 기록 틀어짐. `*_gmt` UTC 컬럼 추가 + 기존 데이터 마이그레이션 스크립트. **[L]**
- [x] **암호화 AES-GCM 업그레이드** — AES-256-CBC → AES-256-GCM 전환. 버전 바이트(0x01)로 포맷 구분, 레거시 CBC URL 하위 호환 유지. 테스트 3개 추가(위변조 감지, 버전 바이트, CBC 폴백) _(v0.9.24)_
- [x] **테스트 커버리지 확대** — 42개/63 assertions → 84개/166 assertions. 추가: Scheduler unscheduleAll, REST API 권한+입력검증, FluentCRM 바운스 핸들러, isUnsubscribed _(v0.9.24)_
- [x] **대용량 발송 최적화** — 수신거부 N회 쿼리→1회 배치 조회, logSend N회 INSERT→bulk INSERT, crmbiz_nl_batch_size 필터 추가, 복합 인덱스 추가(DB 1.9.0): idx_status_sent_at, idx_nl_type _(v0.9.24)_

---

## Phase 4 — 유료화 준비

- [ ] **라이선스 + 업데이트 게이팅** — 현재 공개 GitHub 레포에서 누구나 무료 자동업데이트 가능. Freemius SDK 연동해 유료 구매자만 업데이트 수신. **[L]**
- [ ] **CHANGELOG.md** — 버전별 변경사항 공개 기록. 구매자 신뢰 확보. **[S]**
- [ ] **구매자용 문서** — 설치 가이드, FAQ, 트러블슈팅 (서버 cron 포함). **[M]**
- [ ] **`__()` i18n 감싸기 (PHP)** — 관리자 화면 한국어 문자열 ~300개 `__()` 함수화. 글로벌 전환 시 번역파일만 추가하면 되도록 준비. 기능 변화 없음. **[1일]**

---

## Phase 5 — 글로벌 확장 (한국 출시 후 결정)

- [ ] **Vue/JS i18n 구조 도입** — `wp_localize_script`로 PHP 번역 문자열 주입 또는 `vue-i18n` 도입. ~141개 문자열 처리. **[M]**
- [ ] **영문 번역파일 (.pot/.po)** — PHP `__()` 감싸기 완료 후 진행. **[M]**
- [ ] **GDPR/CAN-SPAM 대응** — EU 데이터 처리 동의, 이메일 푸터 법적 고지 강화. **[M]**
- [ ] **영문 문서** — 설치 가이드, FAQ 영문 버전. **[M]**

---

## 우선순위 요약

| 단계 | 핵심 항목 | 상태 |
|------|-----------|------|
| Phase 1 | AS Async Runner | ✅ 완료 |
| Phase 1 | 바운스 처리 | 🔴 다음 작업 |
| Phase 2 | 이력 필터 + 서버사이드 정렬 | 🟠 단기 |
| Phase 2 | 차트 기간 선택 | 🟠 단기 |
| Phase 3 | DB UTC 마이그레이션 | 🟡 중기 |
| Phase 3 | 암호화 AES-GCM | 🟡 중기 |
| Phase 4 | 라이선스 + 업데이트 게이팅 | 🟡 유료화 결정 시 |
| Phase 5 | 글로벌 i18n | 🟢 장기 |

> 노력 표기: **[S]** 반나절~1일 / **[M]** 2~3일 / **[L]** 1주일+
