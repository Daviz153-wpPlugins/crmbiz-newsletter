# Phase 1: WordPress MVP (소규모 발송)

> 목표: 포스트 발행 시 FluentCRM 태그 구독자 5~20명에게 발송 성공  
> 기간: 2~3주  
> 전제: Phase 0 완료 (이메일 발송 파이프라인 검증됨)

---

## 목표

이 단계가 완성되면 Ghost와 동일한 UX를 WordPress에서 경험할 수 있습니다.

```
포스트 작성 → ☑ 뉴스레터 발송 → 발행
→ 포스트 내용이 자동으로 이메일로 변환되어 구독자에게 발송
→ 이중 작업 없음
```

---

## 구현 파일

### DB 스키마 (`src/Db/Schema.php`)

```sql
wp_crmbiz_newsletters
  id            BIGINT UNSIGNED AUTO_INCREMENT PK
  post_id       BIGINT UNSIGNED
  status        VARCHAR(20)   -- draft / sent / scheduled / failed
  send_timing   VARCHAR(20)   -- immediate / manual / scheduled
  scheduled_at  DATETIME NULL
  sent_at       DATETIME NULL
  recipient_count INT DEFAULT 0
  recipient_lists TEXT         -- JSON [1, 3]
  recipient_tags  TEXT         -- JSON [2, 7]
  error_message TEXT NULL
  created_at    DATETIME

wp_crmbiz_nl_unsubscribers
  id              BIGINT UNSIGNED AUTO_INCREMENT PK
  email           VARCHAR(200) UNIQUE
  unsubscribed_at DATETIME
```

### 메타박스 UI

`src/Admin/MetaBox.php` + `templates/meta-box.php`

```
┌─ 뉴스레터 발송 ────────────────────────────────┐
│ ☐ 이 포스트를 뉴스레터로 발송                  │
│                                                │
│ 수신 대상                                      │
│ 리스트: [멀티셀렉트 ▼]                         │
│ 태그:   [멀티셀렉트 ▼]                         │
│ → 예상 수신자: 12명                            │
│                                                │
│ 발송 시점                                      │
│ ● 즉시  ○ 수동 발송  ○ 예약 발송              │
│                                                │
│ [HTML 미리보기]                                │
└────────────────────────────────────────────────┘
```

### 포스트 메타 키

| 키 | 타입 | 값 |
|----|------|----|
| `_crmbiz_nl_enabled` | int | `1` / `0` |
| `_crmbiz_nl_recipient_lists` | JSON | `[1, 3]` |
| `_crmbiz_nl_recipient_tags` | JSON | `[2, 7]` |
| `_crmbiz_nl_send_timing` | string | `immediate` / `manual` / `scheduled` |
| `_crmbiz_nl_scheduled_at` | string | `2026-06-01 09:00:00` |

### 이메일 HTML 구조

`src/Service/EmailTemplateRenderer.php` + `templates/email/newsletter.php`

```
포스트 내용(apply_filters('the_content'))을 자동 변환

┌─────────────────────────────────┐
│ [사이트 로고]                    │
│ 웹에서 보기 → 포스트 URL         │
├─────────────────────────────────┤
│ 포스트 전체 내용                 │
│ (HTML, 인라인 CSS)               │
├─────────────────────────────────┤
│ 최근 뉴스레터 4개 링크           │
├─────────────────────────────────┤
│ 수신거부 링크                    │
└─────────────────────────────────┘
```

### 발송 흐름

```
포스트 발행
    ↓
PublishTransition::handle()
    ↓
_crmbiz_nl_enabled = 1?  NO → 종료
    ↓ YES
NewsletterSender::send()
    ↓
FluentCRM에서 태그/리스트 구독자 조회
    ↓
수신거부 목록 제외
    ↓
wp_mail() 개별 발송 (Phase 1: 즉시, 소규모)
    ↓
DB 기록 (status=sent)
```

> ⚠️ Phase 1 한계: 20명 초과 시 느려질 수 있음 → Phase 2에서 큐로 해결

### 수신거부 처리

```
발송 시: hash_hmac('sha256', email.'|'.post_id, wp_salt('auth')) 토큰 생성
링크:    /?crmbiz_nl_unsub=TOKEN&email=EMAIL
클릭 시: 토큰 검증 → DB 저장 → 이후 발송 제외
```

---

## 테스트 시나리오 (비개발자 진행 가능)

1. FluentCRM → 연락처 → "뉴스레터-테스트" 태그 생성
2. 본인 이메일 + 테스트용 이메일 2~3개를 해당 태그에 추가
3. WordPress 포스트 작성
4. 편집기 우측 메타박스에서:
   - "이 포스트를 뉴스레터로 발송" 체크
   - 태그: "뉴스레터-테스트" 선택
   - 발송 시점: "즉시" 선택
5. 발행 버튼 클릭
6. 수신함 확인 → 포스트 내용이 이메일로 도착
7. 관리자 → CRMBiz Newsletter → 발송 이력 확인

---

## 성공 기준

- [ ] 포스트 발행 시 태그 구독자에게 이메일 도달
- [ ] 이메일 내용 = 포스트 내용 (이중 작성 불필요 확인)
- [ ] 발송 이력 페이지에 기록 확인
- [ ] 체크박스 미선택 시 이메일 미발송 확인
- [ ] 수신거부 이메일은 발송 목록에서 제외 확인
