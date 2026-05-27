# CRMBiz Newsletter — Master Plan (v2.0)

> FluentCRM 소스코드 분석 기반 고도화 버전 (2026-05-28)

---

## 핵심 목표

**"포스트 작성 → ☑ 뉴스레터 발송 → 발행"**

Ghost의 UX를 WordPress에서 구현. FluentCRM은 연락처 DB 전용으로만 사용하고, 발송·이력·수신거부는 플러그인이 독립 관리.

---

## FluentCRM 연동 전략 (소스 분석 결과)

### 공식 API 진입점

```php
// 모든 FluentCRM 연동은 공식 API 함수를 통해
FluentCrmApi('contacts')  // Contacts 클래스
FluentCrmApi('tags')      // Tags 클래스
FluentCrmApi('lists')     // Lists 클래스
```

### 수신자 조회 — ContactsQuery 사용 (핵심)

```php
use FluentCrm\App\Services\ContactsQuery;

$query = new ContactsQuery([
    'tags'     => [1, 2, 3],       // 태그 ID 배열
    'lists'    => [4, 5],          // 리스트 ID 배열
    'statuses' => ['subscribed'],  // 구독 상태 필터 (필수)
    'limit'    => 100,             // 배치 크기
    'offset'   => 0,               // 페이지 오프셋
]);

$subscribers = $query->get();   // Subscriber 컬렉션 반환
$total       = $query->getModel()->count();
```

- `tags` + `lists` 동시 지정 시 OR 조건으로 합산
- `statuses: ['subscribed']` 필수 — unsubscribed/bounced 자동 제외
- `limit/offset`으로 배치 분할 (Phase 2 큐 시스템의 기반)

### 수신자 수 미리보기

```php
// 태그별 구독자 수
$tag = FluentCrmApi('tags')->find($tagId);
$count = $tag->countByStatus('subscribed');

// 리스트별 구독자 수
$list = FluentCrmApi('lists')->find($listId);
$count = $list->countByStatus('subscribed');
```

### 이메일 개인화 — 스마트 코드 파싱

```php
// FluentCRM 내장 파서 활용
$personalizedHtml = apply_filters(
    'fluent_crm/parse_campaign_email_text',
    $rawHtml,
    $subscriber  // Subscriber 모델 인스턴스
);

// 사용 가능한 머지 태그
// {{first_name}}, {{last_name}}, {{email}}
// {{business.name}}, {{business.url}}
// Helper::getGlobalSmartCodes() 로 전체 목록 조회
```

### 전역 이메일 설정 조회

```php
use FluentCrm\App\Services\Helper;

$emailSettings = Helper::getGlobalEmailSettings();
// 반환: from_name, from_email, reply_to 등
```

### FluentCRM 이벤트 훅 연동

| FluentCRM 훅 | 트리거 시점 | 우리 플러그인 대응 |
|---|---|---|
| `fluentcrm_after_subscribers_deleted` | 연락처 삭제 | 수신거부 레코드 정리 |
| `fluentcrm_subscriber_status_to_subscribed` | 재구독 발생 | 수신거부 테이블에서 제거 |
| `fluent_crm/parse_campaign_email_text` | (필터) | 머지 태그 파싱에 재사용 |

---

## 아키텍처 다이어그램

```
WordPress Post
      │
      ▼ (transition_post_status)
 CRMBiz_Hooks
      │
      ├─→ [메타 플래그 확인] → 비활성이면 종료
      │
      ▼
 NewsletterSender
      │
      ├─→ ContactsQuery (tag_ids + list_ids + status=subscribed)
      │         └─→ FluentCRM fc_subscribers / fc_subscriber_pivot
      │
      ├─→ [수신거부 테이블 교차 제외]
      │         └─→ wp_crmbiz_nl_unsubscribers
      │
      ├─→ EmailTemplateRenderer
      │         ├─→ apply_filters('fluent_crm/parse_campaign_email_text')
      │         └─→ Helper::getEmailFooterContent()
      │
      └─→ wp_mail() × N명
                └─→ FluentSMTP (SMTP 배달)
```

---

## 데이터베이스 스키마

### wp_crmbiz_newsletters

```sql
CREATE TABLE wp_crmbiz_newsletters (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    post_id         BIGINT UNSIGNED NOT NULL,
    status          ENUM('draft','pending','sending','sent','failed','scheduled') DEFAULT 'draft',
    send_mode       ENUM('immediate','manual','scheduled') DEFAULT 'immediate',
    scheduled_at    DATETIME NULL,
    sent_at         DATETIME NULL,
    recipient_count INT UNSIGNED DEFAULT 0,
    success_count   INT UNSIGNED DEFAULT 0,
    fail_count      INT UNSIGNED DEFAULT 0,
    tag_ids         TEXT,   -- JSON: FluentCRM tag ID 배열
    list_ids        TEXT,   -- JSON: FluentCRM list ID 배열
    error_log       TEXT,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_post_id (post_id),
    INDEX idx_status  (status)
);
```

### wp_crmbiz_nl_queue (Phase 2 추가)

```sql
CREATE TABLE wp_crmbiz_nl_queue (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    newsletter_id   BIGINT UNSIGNED NOT NULL,
    subscriber_email VARCHAR(191) NOT NULL,
    subscriber_name  VARCHAR(191),
    status          ENUM('pending','sent','failed') DEFAULT 'pending',
    attempts        TINYINT UNSIGNED DEFAULT 0,
    error_message   TEXT,
    scheduled_at    DATETIME NULL,
    processed_at    DATETIME NULL,
    INDEX idx_newsletter_status (newsletter_id, status),
    INDEX idx_scheduled (scheduled_at)
);
```

### wp_crmbiz_nl_unsubscribers

```sql
CREATE TABLE wp_crmbiz_nl_unsubscribers (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email           VARCHAR(191) NOT NULL,
    unsubscribed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    token_used      VARCHAR(64),
    UNIQUE KEY uq_email (email)
);
```

---

## 클래스 구조

```
crmbiz-newsletter/
├── crmbiz-newsletter.php          # 플러그인 진입점
├── autoload.php                   # PSR-4 오토로더
└── src/
    ├── Plugin.php                 # 싱글톤, 훅 등록
    ├── Settings.php               # 설정 래퍼 (get_option 타입 안전)
    ├── Database.php               # 테이블 생성/업그레이드
    ├── FluentCRMBridge.php        # FluentCRM API 래퍼 (단일 접점)
    ├── NewsletterSender.php       # 발송 오케스트레이터
    ├── EmailTemplateRenderer.php  # 포스트 → HTML 이메일
    ├── UnsubscribeHandler.php     # HMAC 토큰 수신거부 처리
    ├── Queue/
    │   ├── QueueManager.php       # WP Cron 배치 관리 (Phase 2)
    │   └── BatchProcessor.php     # 배치 실행 (Phase 2)
    └── Admin/
        ├── SettingsPage.php       # 설정 페이지
        ├── HistoryPage.php        # 발송 이력 페이지
        ├── DiagnosticsPage.php    # 진단 대시보드 (Phase 0)
        └── MetaBox.php            # 포스트 편집 메타박스
```

---

## 보안 원칙

| 위협 | 대응 |
|---|---|
| SQL Injection | 모든 쿼리 `$wpdb->prepare()` 사용 |
| XSS | `esc_html()`, `wp_kses_post()` 출력 이스케이핑 |
| CSRF | 모든 폼/AJAX에 `wp_nonce_verify()` |
| 수신거부 위조 | HMAC-SHA256 토큰 (`hash_hmac('sha256', $email, NONCE_KEY)`) |
| HTML 이메일 인젝션 | `Helper::sanitizeHtml()` 또는 `wp_kses_post()` |
| 컴플라이언스 | `Helper::hasComplianceText()` 로 수신거부 링크 존재 검증 |

---

## 개발 단계 요약

| Phase | 목표 | 핵심 구현 |
|---|---|---|
| 0 | 기반 + 진단 | 플러그인 뼈대, 설정 페이지, 테스트 이메일 발송 |
| 1 | MVP | 메타박스, ContactsQuery 연동, wp_mail 발송, 수신거부 |
| 2 | 큐 | WP Cron 배치, Queue 테이블, 재시도 로직 |
| 3 | 고급 기능 | 오픈 추적, 클릭 추적, 재구독 폼 |

---

## SMTP 프로바이더 선택 가이드

| 규모 | 추천 서비스 | 무료 한도 |
|---|---|---|
| 테스트 | Mailtrap | 무제한 (인터셉트) |
| ~100명/일 | Gmail SMTP (FluentSMTP) | 500/일 |
| ~500명/일 | SendGrid / Mailgun | 100/일 무료 |
| 1,000명+ | AWS SES | $0.10/1,000건 |
| 10,000명+ | AWS SES + 전용 IP | 요금제 |

> AWS SES는 샌드박스 승인 1-2일 필요. 프로덕션 전환 미리 신청 필수.
