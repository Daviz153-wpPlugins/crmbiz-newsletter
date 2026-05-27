# CRMBiz Newsletter — 개발 플랜

## 개요

WordPress 포스트 발행 시 FluentCRM 연락처에 이메일 뉴스레터를 함께 발송하는 독립 플러그인.
Ghost.io처럼 글 발행 시 "뉴스레터로도 발송" 옵션을 선택할 수 있는 UX 제공.

### 핵심 원칙
- FluentCRM은 **수신자 DB 조회만** 담당 (태그/리스트 필터로 이메일·이름 가져오기)
- 이메일 발송은 `wp_mail()` → **FluentSMTP**가 SMTP 처리
- 발송 이력, 수신거부는 **플러그인 자체 DB**에서 관리
- 캠페인 생성 없음 — 단순하고 의존성 최소화

---

## 폴더 구조
crmbiz-newsletter/
├── crmbiz-newsletter.php 진입점 (상수 정의, 오토로더, 훅 등록)
├── uninstall.php DB 테이블 + 옵션 삭제
├── composer.json dev only (phpunit)
├── PLAN.md 이 문서
│
├── src/
│ ├── Plugin.php Singleton; boot()에서 모든 WP 훅 등록
│ ├── Settings.php Singleton; 옵션 래퍼; typed getters
│ │
│ ├── Support/
│ │ ├── Autoloader.php PSR-4 오토로더 (CRMBiz\Newsletter\ → src/)
│ │ └── Logger.php debug_log_enabled 게이트 error_log 래퍼
│ │
│ ├── Db/
│ │ ├── Schema.php dbDelta()로 테이블 생성/삭제
│ │ └── NewsletterRepository.php 모든 DB 쿼리 ($wpdb->prepare())
│ │
│ ├── Domain/
│ │ └── NewsletterStatus.php 상수: draft / sent / scheduled / failed
│ │
│ ├── Admin/
│ │ ├── Menu.php admin_menu 훅; 메뉴/서브메뉴 등록
│ │ ├── Notices.php Transient 기반 플래시 알림
│ │ ├── MetaBox.php 포스트 편집기 메타박스; 메타 저장; JS enqueue
│ │ ├── HistoryPage.php 발송 이력 목록 + 미리보기 + 수동 발송 액션
│ │ └── SettingsPage.php 설정 폼 (register_setting)
│ │
│ ├── Ajax/
│ │ └── FluentCrmData.php AJAX: FluentCRM 리스트/태그 목록 반환
│ │
│ ├── Service/
│ │ ├── EmailTemplateRenderer.php 포스트 + 설정 → 완성된 HTML 이메일 문자열
│ │ └── NewsletterSender.php FluentCRM 수신자 조회 → wp_mail() 개별 발송
│ │
│ └── Hook/
│ ├── PublishTransition.php transition_post_status → 발송 분기
│ └── UnsubscribeHandler.php init 훅; ?crmbiz_nl_unsub= 파라미터 처리
│
├── templates/
│ ├── admin-history-list.php 발송 이력 목록 HTML
│ ├── admin-settings.php 설정 페이지 HTML
│ ├── meta-box.php 포스트 편집기 메타박스 HTML
│ └── email/
│ └── newsletter.php HTML 이메일 템플릿 (테이블 기반, 인라인 CSS)
│
└── tests/
├── bootstrap.php
└── unit/
├── NewsletterStatusTest.php
└── EmailTemplateRendererTest.php

---
## 발행 흐름
포스트 발행 버튼 클릭
│
└── PublishTransition::handle() (transition_post_status 훅)
│
├── _crmbiz_nl_enabled = 0 → 아무것도 안 함 (일반 포스팅)
│
└── _crmbiz_nl_enabled = 1 → NewsletterSender::send()
│
├── immediate → 즉시 발송
├── manual → DB에 draft 저장 (History 페이지에서 수동 발송)
└── scheduled → wp_schedule_single_event() 등록
(서버 크론이 wp-cron.php 실행 시 발송)

---
## 이메일 구조
┌─────────────────────────────────┐
│ [사이트 로고] │
│ 웹에서 보기 → 포스트 URL │
├─────────────────────────────────┤
│ 포스트 전체 내용 │
│ (apply_filters the_content) │
├─────────────────────────────────┤
│ 최근 뉴스레터 (기본 4개) │
│ • 제목 → 포스트 URL │
├─────────────────────────────────┤
│ 수신거부 링크 │
└─────────────────────────────────┘

---
## FluentCRM 연동 범위
| 항목 | FluentCRM | 플러그인 자체 |
|------|-----------|-------------|
| 수신자 조회 (태그/리스트) | ✅ | |
| 이메일 발송 | | ✅ (wp_mail) |
| 발송 이력 | | ✅ |
| 수신거부 | | ✅ |
| 오픈 추적 | | v2 예정 |
---
## 메타박스 UI
┌─ 뉴스레터 발송 ──────────────────────────┐
│ ☐ 이 포스트를 뉴스레터로 발송 │
│ │
│ 수신 대상 │
│ 리스트: [멀티셀렉트 ▼] │
│ 태그: [멀티셀렉트 ▼] │
│ │
│ 발송 시점 │
│ ● 즉시 ○ 수동 발송 ○ 예약 발송 │
│ 예약: [날짜/시간 입력] │
│ │
│ [미리보기] │
└──────────────────────────────────────────┘

---
## 포스트 메타 키
| 키 | 타입 | 값 |
|----|------|----|
| `_crmbiz_nl_enabled` | int | `1` / `0` |
| `_crmbiz_nl_recipient_lists` | JSON | `[1, 3]` |
| `_crmbiz_nl_recipient_tags` | JSON | `[2, 7]` |
| `_crmbiz_nl_send_timing` | string | `immediate` / `manual` / `scheduled` |
| `_crmbiz_nl_scheduled_at` | string | `2026-06-01 09:00:00` |
---
## DB 테이블
### wp_crmbiz_newsletters
| 컬럼 | 타입 | 설명 |
|------|------|------|
| `id` | bigint UNSIGNED PK | |
| `post_id` | bigint UNSIGNED | 연결된 포스트 |
| `status` | varchar(20) | draft / sent / scheduled / failed |
| `send_timing` | varchar(20) | immediate / manual / scheduled |
| `scheduled_at` | datetime NULL | 예약 시각 |
| `sent_at` | datetime NULL | 실제 발송 시각 |
| `recipient_count` | int | 발송 대상 수 |
| `recipient_lists` | text | JSON |
| `recipient_tags` | text | JSON |
| `error_message` | text NULL | 실패 시 오류 내용 |
| `created_at` | datetime | |
### wp_crmbiz_nl_unsubscribers
| 컬럼 | 타입 | 설명 |
|------|------|------|
| `id` | bigint UNSIGNED PK | |
| `email` | varchar(200) UNIQUE | 수신거부 이메일 |
| `unsubscribed_at` | datetime | |
---
## 설정 키 (crmbiz_newsletter_settings)
| 키 | 기본값 | 설명 |
|----|--------|------|
| `logo_url` | `''` | 이메일 헤더 로고 URL |
| `footer_newsletter_count` | `4` | 푸터 최근 뉴스레터 개수 (1~10) |
| `default_lists` | `'[]'` | 메타박스 기본 선택 리스트 ID |
| `default_tags` | `'[]'` | 메타박스 기본 선택 태그 ID |
| `email_subject_prefix` | `''` | 이메일 제목 접두사 |
| `debug_log_enabled` | `0` | 디버그 로그 |
---
## 수신거부 처리
1. 발송 시 토큰 생성: `hash_hmac('sha256', email.'|'.post_id, wp_salt('auth'))`
2. 푸터 링크: `/?crmbiz_nl_unsub=TOKEN&email=EMAIL`
3. 클릭 시 토큰 검증 후 DB 저장
4. 이후 발송 시 제외
---
## 보안 체크리스트
- [ ] 모든 DB 쿼리: `$wpdb->prepare()`
- [ ] 모든 HTML 출력: `esc_html()`, `esc_attr()`, `esc_url()`
- [ ] 어드민 액션: nonce + `manage_options`
- [ ] AJAX: nonce + admin-only
- [ ] 수신거부 토큰: HMAC 검증
---
## 개발 순서
1. 기반 — 진입점, Autoloader, Settings, Plugin
2. DB — Schema, Repository
3. 발송 엔진 — EmailTemplateRenderer, NewsletterSender
4. 훅 — PublishTransition, UnsubscribeHandler
5. 어드민 — MetaBox, Menu, HistoryPage, SettingsPage
6. AJAX — FluentCrmData
7. 템플릿 — meta-box.php, email/newsletter.php
8. 테스트 — PHPUnit
9. 배포 — 첫 릴리즈
