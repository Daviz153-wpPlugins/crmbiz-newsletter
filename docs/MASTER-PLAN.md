# CRMBiz Newsletter — 마스터 플랜

> 작성일: 2026-05-27  
> 리포지토리: pureugong/crmbiz-newsletter

---

## 이 플러그인이 해결하는 문제

**현재 FluentCRM 사용 시 고통**

```
[지금]
포스트 작성 → 발행 → FluentCRM으로 이동 → 캠페인 생성
→ 내용 다시 작성 (이중 작업!) → 수신자 선택 → 발송

[플러그인 적용 후]
포스트 작성 → 메타박스에서 ☑ 뉴스레터 발송 + 태그 선택 → 발행
→ 끝. 포스트가 곧 이메일.
```

Ghost로 갈아타고 싶은 이유가 정확히 이것: **포스트 = 뉴스레터** 자동화.  
이 플러그인은 WordPress에서 Ghost의 그 경험을 제공합니다.

---

## 핵심 원칙

| 원칙 | 내용 |
|------|------|
| **FluentCRM 역할** | 수신자 DB 조회만 (태그/리스트로 이메일·이름 가져오기) |
| **이메일 발송** | `wp_mail()` → FluentSMTP가 SMTP 처리 |
| **발송 이력·수신거부** | 플러그인 자체 DB에서 관리 |
| **캠페인 생성 없음** | FluentCRM 캠페인 화면은 전혀 사용하지 않음 |

> **FluentCRM은 단지 연락처 주소록으로만 사용합니다.**  
> FluentCRM에서 캠페인 만들거나 이메일 작성할 필요가 전혀 없습니다.  
> 포스트 편집기를 벗어날 필요가 없습니다.

---

## 이메일 발송 인프라

`wp_mail()` → FluentSMTP를 사용합니다.

**FluentSMTP 연동 가능 서비스**

| 서비스 | Rate Limit | 추천 규모 |
|--------|------------|-----------|
| **AWS SES** | 초당 14~200통 | 1만+ 구독자 |
| **SendGrid** | 충분 | 소규모 시작 |
| **Mailgun** | 충분 | 중소규모 |
| **Gmail SMTP** | 500통/일 | 테스트 전용 |
| **Mailtrap** | — | Mock 테스트 전용 |

**MVP 추천**: Mailtrap(Mock 테스트) → SendGrid 또는 Mailgun(실제 발송)

---

## Rate Limiting 해결 방법

`wp_mail()` 루프 직접 실행의 문제:
- 포스트 발행 시 브라우저가 수십 초 멈춤
- SMTP 서버 한도 초과 시 중간 실패
- 실패해도 재시도 없음

**해결: WP Cron 배치 큐 (Phase 2)**

```
포스트 발행 → DB에 "발송 대기" 저장 → wp_schedule_single_event() → 브라우저 즉시 응답

[WP Cron 실행 (1분 이내)]
수신자 목록을 25통 배치로 분할
배치 1 발송 → 1초 대기 → 배치 2 발송 → ... → 완료
```

---

## 전체 로드맵

| Phase | 기간 | 목표 | 핵심 |
|-------|------|------|------|
| **Phase 0** | 1~2주 | 이메일 1통 확실히 도달 검증 | 진단 페이지 + Mailtrap Mock |
| **Phase 1** | 2~3주 | 포스트 발행 시 5~20명 발송 성공 | 메타박스 + FluentCRM + 즉시 발송 |
| **Phase 2** | 2~3주 | 100~1,000명 안정 발송 | WP Cron 배치 큐 + 예약 발송 |
| **Phase 3** | 이후 | 오픈 추적, 재발송, 구독 폼 | 고급 기능 |

---

## 세부 페이즈 문서

- [Phase 0: Foundation & Email Diagnostics](./phase-0-diagnostics.md)
- [Phase 1: WordPress MVP](./phase-1-mvp.md)
- [Phase 2: 큐 + Rate Limiting 안정화](./phase-2-queue.md)
