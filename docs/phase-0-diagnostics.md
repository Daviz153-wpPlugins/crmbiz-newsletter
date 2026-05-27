# Phase 0: Foundation & Email Diagnostics

> 목표: 이메일 1통이 확실하게 도달하는지 검증  
> 기간: 1~2주

---

## 목표

"이메일이 실제로 가는가?"를 확인하는 단계.  
비개발자도 관리자 페이지에서 직접 테스트할 수 있어야 합니다.

---

## 가입 필요 서비스

| 서비스 | 목적 | 비용 |
|--------|------|------|
| **Mailtrap.io** | Mock 테스트용 (실제 수신자에게 발송 안 됨) | 무료 |
| **SendGrid** 또는 **Mailgun** | 실제 발송용 | 무료 시작 |

### Mailtrap 설정 방법

1. mailtrap.io 가입
2. Email Testing → Inboxes → My Inbox → SMTP Settings 탭
3. SMTP 정보 복사 (host, port, username, password)
4. WordPress → FluentSMTP → SMTP 입력
5. 이후 모든 발송은 Mailtrap 받은편지함에서 확인 (실제 수신자에게 가지 않음)

---

## 구현 파일

### 플러그인 기반 구조

```
crmbiz-newsletter.php      — 진입점, 상수, 오토로더, 훅 등록
src/Support/Autoloader.php — PSR-4 오토로더
src/Support/Logger.php     — debug_log_enabled 게이트 error_log 래퍼
src/Plugin.php             — Singleton, boot()에서 WP 훅 등록
src/Settings.php           — 옵션 래퍼 (드라이런 모드, 배치 크기 추가)
```

### 설정 페이지 (`src/Admin/SettingsPage.php`)

```
[기본 설정]
- 발신자 이름
- 발신자 이메일
- 로고 URL
- 이메일 제목 접두사

[발송 설정]
- 드라이런 모드 ON/OFF  ← 실제 발송 없이 로그만
- 디버그 로그 ON/OFF
- 배치 크기 (기본 25통)
```

### 이메일 진단 페이지 (`src/Admin/DiagnosticsPage.php`)

메뉴: WordPress 관리자 → CRMBiz Newsletter → 이메일 진단

```
┌─ 이메일 진단 ──────────────────────────────────┐
│                                                │
│ 1. FluentSMTP 연결 상태                        │
│    [연결 확인]                                  │
│    → ✅ FluentSMTP 설정됨 (SMTP: mailtrap.io)  │
│    → ❌ FluentSMTP 플러그인이 설치되지 않음     │
│                                                │
│ 2. FluentCRM 연결 상태                         │
│    [연결 확인]                                  │
│    → ✅ FluentCRM 활성화됨 (연락처 1,234명)    │
│    → ❌ FluentCRM 플러그인이 설치되지 않음     │
│                                                │
│ 3. 테스트 이메일 발송 (단건)                   │
│    받는 사람: [이메일 주소 입력]               │
│    [지금 발송]                                 │
│    → ✅ 발송 성공 (발송 시간: 0.3초)           │
│    → ❌ 발송 실패: Connection refused          │
│                                                │
│ 4. 드라이런 모드 현재 상태                     │
│    ● ON (실제 발송 안 됨, 로그만 기록)         │
└────────────────────────────────────────────────┘
```

### 드라이런(Dry Run) 모드

- 설정에서 ON 시: 실제 `wp_mail()` 호출 없이 로그만 기록
- 이메일 내용, 수신자, 제목 전부 로그 확인 가능
- FluentSMTP 없어도 전체 흐름 테스트 가능

---

## 성공 기준

- [ ] 플러그인 설치 → 활성화 오류 없음
- [ ] 설정 페이지 저장 정상 동작
- [ ] 진단 페이지 → FluentSMTP ✅ 확인
- [ ] 진단 페이지 → FluentCRM ✅ 확인
- [ ] 테스트 이메일 → Mailtrap 수신함 도달 ✅
- [ ] 드라이런 모드 ON → 로그에서 이메일 내용 확인

---

## 검증 시나리오

**시나리오 A (Mailtrap Mock 테스트)**

1. FluentSMTP에 Mailtrap SMTP 설정
2. 드라이런 OFF
3. 진단 페이지 → 테스트 이메일 발송
4. Mailtrap 받은편지함에서 이메일 확인

**시나리오 B (드라이런 로그 확인)**

1. 드라이런 ON
2. 진단 페이지 → 테스트 이메일 발송
3. WordPress error_log에서 이메일 내용 확인
4. 실제 이메일은 발송되지 않음 확인
