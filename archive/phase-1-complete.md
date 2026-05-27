# Phase 1 아카이브 — MVP 발송 구현

**완료일**: 2026-05-28  
**버전**: 0.2.0

---

## 구현 완료 파일

| 파일 | 역할 |
|---|---|
| `src/UnsubscribeHandler.php` | HMAC-SHA256 토큰 수신거부 처리 + FluentCRM 상태 동기화 |
| `src/EmailTemplateRenderer.php` | 포스트 → 반응형 HTML 이메일 (인라인 CSS) |
| `src/NewsletterSender.php` | 발송 오케스트레이터 (즉시/수동 발송, DB 기록) |
| `src/Admin/MetaBox.php` | 포스트 편집 사이드바 메타박스 |
| `src/Admin/HistoryPage.php` | 발송 이력 목록 + 수동 발송 버튼 |
| `src/Plugin.php` | Phase 1 훅 추가 (transition_post_status, AJAX 4개) |

---

## 신규 AJAX 핸들러

| Action | 용도 | Nonce |
|---|---|---|
| `crmbiz_nl_test_email` | 테스트 이메일 발송 (Phase 0 유지) | `crmbiz_nl_diagnostics` |
| `crmbiz_nl_count_recipients` | 태그/리스트 기준 수신자 수 조회 | `crmbiz_nl_metabox` |
| `crmbiz_nl_manual_send` | draft 뉴스레터 수동 발송 | `crmbiz_nl_manual_send` |
| `crmbiz_nl_preview_email` | 포스트 HTML 미리보기 (GET) | `crmbiz_nl_preview_{post_id}` |

---

## 주요 설계 결정

### UnsubscribeHandler — 정적 메서드 분리
`isUnsubscribed()`, `buildUnsubscribeUrl()` 를 static으로 선언.  
이유: NewsletterSender, EmailTemplateRenderer 양쪽에서 인스턴스 없이 호출해야 해서.

### NewsletterSender — 즉시/수동 분리
- `sendForPost()`: 즉시 발송 (status=sending → sent/failed)
- `createDraftRecord()`: 수동 발송용 draft 레코드만 생성
- `sendManual()`: HistoryPage에서 트리거, draft → sending → sent

### EmailTemplateRenderer — FluentCRM 필터 재사용
`apply_filters('fluent_crm/parse_campaign_email_text', $content, $subscriber)` 호출.  
FluentCRM이 없으면 핸들러가 없어 원본 반환 — 의존성 없이 안전.  
`sanitizeHtml()` 도 FluentCRM 있으면 사용, 없으면 `wp_kses_post()` 폴백.

### MetaBox — PHP 인라인 렌더링
태그/리스트 목록은 PHP에서 직접 렌더링 (AJAX 없음).  
이유: 목록이 없으면 빈 선택기를 보여주는 게 더 명확. AJAX 실패 케이스 처리 불필요.  
수신자 수 카운트만 AJAX로 처리 (300ms debounce).

### 미리보기 — 더미 구독자 객체
현재 로그인한 관리자 정보를 더미 구독자로 사용.  
FluentCRM Subscriber 모델 없이 stdClass로 처리 — `render()`가 `$subscriber->email` 등 속성만 참조하므로 안전.

### `transition_post_status` 가드
- `$post->post_type !== 'post'` 체크 추가 — 페이지/커스텀 포스트타입 발송 방지
- `$oldStatus === 'publish'` 가드 — 포스트 수정 시 중복 발송 방지

---

## 발송 워크플로

```
포스트 발행 (publish)
    ↓
transition_post_status 훅
    ↓
_crmbiz_nl_enabled 확인
    ↓
send_mode 분기
├── immediate → sendForPost() → wp_mail() × N → DB 기록
├── manual    → createDraftRecord() → HistoryPage에서 대기
└── scheduled → (Phase 2)
```

---

## 이메일 템플릿 구조

```
[헤더: 사이트명]
[대표 이미지 (있을 경우)]
[포스트 제목]
[발행일]
[포스트 본문 (FluentCRM 스마트코드 치환됨)]
[웹에서 보기 버튼]
[최근 뉴스레터 목록 (최대 3개)]
[푸터: 수신거부 링크]
```

---

## 완료 기준 체크리스트

- [x] 포스트 편집 시 뉴스레터 메타박스 표시
- [x] 태그/리스트 다중 선택 (FluentCRM 데이터)
- [x] 예상 수신자 수 실시간 표시 (ContactsQuery)
- [x] 즉시 발송 — `wp_mail()` 개인화 이메일
- [x] 수동 발송 — draft 생성 후 HistoryPage에서 발송
- [x] 수신거부 링크 + HMAC-SHA256 토큰 검증
- [x] FluentCRM 연락처 상태 `unsubscribed` 동기화
- [x] 재구독 시 수신거부 테이블 자동 제거
- [x] 발송 이력 DB 기록 (recipients/success/fail)
- [x] HistoryPage 발송 이력 목록 + 수동 발송 버튼
- [x] HTML 미리보기 (관리자 이메일로 더미 렌더링)

---

## 알려진 제한사항

- 수신자가 많으면 (100명+) HTTP 요청 타임아웃 위험 → Phase 2 큐로 해결
- FluentCRM `countByStatus()` 는 메타박스 로딩 시 태그/리스트 수만큼 N번 쿼리 발생
  - 태그/리스트 10개이면 10번 쿼리 — 수백 개면 캐싱 필요
- 예약 발송(`scheduled` 모드)은 UI만 있고 실제 처리는 Phase 2

---

## 다음 단계 (Phase 2)

- `wp_crmbiz_nl_queue` 테이블 추가
- `QueueManager`: ContactsQuery offset 방식으로 큐 등록
- `BatchProcessor`: WP Cron 배치 처리 (프로바이더별 배치 크기)
- 예약 발송 cron 체크
- 실패 재시도 (최대 3회)
- 관리자 실패 알림 이메일
