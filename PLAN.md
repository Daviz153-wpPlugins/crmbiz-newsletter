# CRMBiz Newsletter — 개발 계획 (v2.0)

> FluentCRM 소스코드 분석 기반 고도화 버전 (2026-05-28)
> 상세 문서: `docs/` 폴더 참고

---

## 한줄 요약

WordPress 포스트를 뉴스레터로 자동 발송하는 플러그인.
FluentCRM = 연락처 DB 전용. 발송/이력/수신거부 = 플러그인 독립 관리.

---

## v1 → v2 주요 변경사항

### FluentCRM 공식 API 활용 (핵심 변경)

| 항목 | v1 계획 | v2 (소스 분석 후) |
|---|---|---|
| 수신자 조회 | 직접 DB 쿼리 | `ContactsQuery` 클래스 사용 |
| 이메일 파싱 | 직접 문자열 치환 | `fluent_crm/parse_campaign_email_text` 필터 |
| 발신자 설정 | 플러그인 설정만 | `Helper::getGlobalEmailSettings()` 폴백 |
| HTML 정리 | 없음 | `Helper::sanitizeHtml()` |
| 컴플라이언스 | 없음 | `Helper::hasComplianceText()` 검증 |
| 디버그 로그 | 없음 | `Helper::debugLog()` |
| 재구독 연동 | 없음 | `fluentcrm_subscriber_status_to_subscribed` 훅 |
| 연락처 삭제 연동 | 없음 | `fluentcrm_after_subscribers_deleted` 훅 |

### 수신자 조회 방식 (정확한 API 확정)

```php
// ContactsQuery — tags + lists 동시 OR 조건, status 필터, 페이징 지원
$query = new \FluentCrm\App\Services\ContactsQuery([
    'tags'     => [1, 2],
    'lists'    => [3],
    'statuses' => ['subscribed'],
    'limit'    => 100,
    'offset'   => $offset,
]);
$subscribers = $query->get();
```

### Phase 2 배치 최적화

```
수신자 500명 청크로 DB 큐 등록 → WP Cron → 프로바이더별 배치
SendGrid/Mailgun: 100명/배치
AWS SES: 50명/배치
Gmail: 10명/배치
```

---

## 개발 단계

| Phase | 내용 | 예상 기간 |
|---|---|---|
| **0** | 플러그인 뼈대 + 진단 대시보드 | 1-2일 |
| **1** | MVP 발송 (ContactsQuery + wp_mail) | 3-4일 |
| **2** | WP Cron 큐 + 배치 발송 | 2-3일 |
| **3** | 오픈/클릭 추적 + 재구독 | 2-3일 |

---

## 의존성

- WordPress 5.8+
- FluentCRM 2.x+ (활성화 필수)
- FluentSMTP (권장 — SMTP 배달)
- PHP 7.4+

---

## 상세 문서

- [MASTER-PLAN.md](docs/MASTER-PLAN.md) — 전체 아키텍처 + DB 스키마 + 보안
- [phase-0-diagnostics.md](docs/phase-0-diagnostics.md) — 기반 + 진단 (코드 포함)
- [phase-1-mvp.md](docs/phase-1-mvp.md) — MVP 발송 (코드 포함)
- [phase-2-queue.md](docs/phase-2-queue.md) — 큐 + 배치 발송 (코드 포함)
- [phase-3-advanced.md](docs/phase-3-advanced.md) — 오픈/클릭 추적 + 재구독
