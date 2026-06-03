# 개발자 플레이북 — 프로젝트 계획 수립 & 단계별 실행 가이드

> 주니어 개발자를 위한 실전 지침서.  
> "왜 이 순서인가"를 이해하면 어떤 프로젝트에도 적용할 수 있다.

---

## 목차

1. [이 문서가 생긴 이유](#1-이-문서가-생긴-이유)
2. [전문 개발자의 사고방식](#2-전문-개발자의-사고방식)
3. [프로젝트 5단계 프레임워크](#3-프로젝트-5단계-프레임워크)
   - Phase 0: Discovery & Design
   - Phase 1: Foundation
   - Phase 2: Core MVP
   - Phase 3: Iteration
   - Phase 4: Polish & Release
4. [각 기능의 완성 기준 (Definition of Done)](#4-각-기능의-완성-기준-definition-of-done)
5. [커밋 전략 & 버전 관리](#5-커밋-전략--버전-관리)
6. [반드시 피해야 할 패턴](#6-반드시-피해야-할-패턴)
7. [프로젝트 시작 체크리스트](#7-프로젝트-시작-체크리스트)
8. [자주 쓰는 설계 질문 목록](#8-자주-쓰는-설계-질문-목록)
9. [회귀 테스트 구축 실전 가이드](#9-회귀-테스트-구축-실전-가이드)
10. [Gate 4 실전 기록 — 상태 전환·헤더 인젝션 보안 테스트](#10-gate-4-실전-기록--상태-전환--헤더-인젝션-보안-테스트-2026-06-02-v0934)
11. [E2E 테스트 전략 — 이 플러그인에 필요한 것들](#11-e2e-테스트-전략--이-플러그인에-필요한-것들)
12. [v1.0.0 실전 기록 — PHPUnit 비즈니스 로직 테스트 (2026-06-03)](#12-v100-실전-기록--phpunit-비즈니스-로직-테스트-2026-06-03)
13. [플러그인 고도화 로드맵](#13-플러그인-고도화-로드맵)
14. [Phase A 실전 기록 — 안정화 (2026-06-03, v1.1.0)](#14-phase-a-실전-기록--안정화-2026-06-03-v110)
15. [FluentCRM 연동 E2E 테스트 실전 기록 (2026-06-03)](#15-fluentcrm-연동-e2e-테스트-실전-기록-2026-06-03-v110)
16. [서버 부하 E2E 테스트 실전 기록 (2026-06-03)](#16-서버-부하-e2e-테스트-실전-기록-2026-06-03)
17. [WordPress 환경 안정성 E2E 테스트 실전 기록 (2026-06-03)](#17-wordpress-환경-안정성-e2e-테스트-실전-기록-2026-06-03)

---

## 1. 이 문서가 생긴 이유

실제 프로젝트(crmbiz-newsletter, v0.1 → v0.9.1)를 돌아보면서 반복된 문제 패턴이 있었다.

```
feat: MVP 발송 구현              ← 기능 먼저
fix: MVP 보안 버그 4건           ← 보안 나중에
fix: security improvements      ← 또 보안
Fix O(n²) performance           ← 성능도 나중에
refactor(ui): Phase 1           ← UI 일관성도 나중에
fix: WP admin CSS conflicts     ← 또 CSS
fix: unify page layout          ← 또 레이아웃
```

**보안, 성능, UI 일관성이 전부 사후 수습이었다.**  
기능을 먼저 만들고 문제를 뒤에 고치는 패턴이 반복됐다.  
이 문서는 그 반성에서 시작한다.

---

## 2. 전문 개발자의 사고방식

### "뒤에서 앞으로 설계, 앞에서 뒤로 구현"

설계할 때는 **완성된 모습**에서 역으로 생각한다.

```
완성 상태를 먼저 그린다
    → 그걸 달성하려면 무엇이 필요한가?
        → 그 전에 무엇이 필요한가?
            → 그 전에 무엇이 필요한가?
                → 이게 Phase 0의 출발점
```

구현할 때는 **가장 기반이 되는 것부터** 앞으로 나아간다.

### 나중에 고치기 어려운 순서

```
1위. DB 스키마          — 데이터가 쌓인 뒤 구조 변경은 마이그레이션 지옥
2위. 인증/보안 모델     — 나중에 끼워 넣으면 구조 자체를 바꿔야 함
3위. 핵심 아키텍처      — 레이어 분리, 의존성 방향
4위. 외부 API 계약      — 버전 바꾸면 연동된 것 모두 깨짐
5위. 퍼블릭 인터페이스  — 다른 코드가 의존하기 시작하면 변경 비용 폭증
```

**이 순서대로 먼저 결정하고, 나중에 바꾸지 않는다.**

### "임시"는 없다

> "일단 임시로 만들고 나중에 고치자"

이 생각이 기술 부채의 90%를 만든다.  
임시 코드는 **반드시 영구 코드가 된다.**  
만들 시간이 없으면 → 처음부터 범위에 넣지 않는다.

---

## 3. 프로젝트 5단계 프레임워크

---

### Phase 0: Discovery & Design (착수 전)

> **목표:** 무엇을 만들지 명확히 한다. 코드는 한 줄도 쓰지 않는다.  
> **산출물:** 문서 (PLAN.md, 스키마 초안, 인터페이스 정의)

#### 해야 할 것

**문제 정의**
- 누가 (사용자)
- 어떤 상황에서
- 무엇이 불편한가?
- 지금은 어떻게 해결하고 있나? (현재 해결책의 단점은?)

**성공 기준 (측정 가능하게)**
```
나쁜 예: "빠르게 작동한다"
좋은 예: "1,000명 수신자에게 5분 내에 발송 완료"

나쁜 예: "사용하기 쉽다"
좋은 예: "신규 사용자가 설명 없이 3분 내 첫 발송 가능"
```

**범위 결정 (In-Scope vs Out-of-Scope)**
```markdown
## 이번 버전에서 만드는 것
- [ ] 기능 A
- [ ] 기능 B

## 이번 버전에서 만들지 않는 것
- 기능 C (다음 버전)
- 기능 D (요구사항 불명확, 보류)
```

Out-of-scope를 명시하는 것이 더 중요하다. 범위가 안 정해지면 끝이 없다.

**기술 스택 결정 (근거 있게)**
```markdown
| 항목       | 선택      | 이유                          | 대안과 비교               |
|----------|---------|----------------------------|----------------------|
| 언어       | PHP 8.0 | WordPress 플러그인 필수          | —                    |
| 프론트엔드    | Vue 3   | 반응형 UI 필요, 팀 경험 있음         | React (학습 비용 높음)      |
| 이메일 발송   | FluentCRM | 이미 설치된 의존성 활용           | 자체 구현 (과도한 범위)       |
| 스케줄링     | WP Cron | WordPress 네이티브, 별도 서버 불필요  | Action Scheduler (추후) |
```

**데이터 모델 설계**
- 주요 엔티티와 관계 정의
- DB 스키마 초안 (실제 SQL 수준까지)
- 인덱스 전략 (어떤 컬럼으로 자주 조회하나?)
- 마이그레이션 전략 (버전 업 시 어떻게 스키마를 바꿀 것인가?)

**보안 요구사항**
- 어떤 데이터가 민감한가? (개인정보, 결제정보 등)
- 누가 무엇에 접근할 수 있나? (권한 모델)
- 외부에 노출되는 엔드포인트는? (공격 표면)
- 어떤 보안 메커니즘이 필요한가? (HMAC, 암호화, rate limiting)

**비기능 요구사항**
```markdown
| 항목   | 기준                    |
|------|------------------------|
| 성능   | 1,000명 발송 ≤ 5분         |
| 규모   | 초기 10개 사이트, 최대 100개    |
| 가용성  | WordPress 표준 (99% 이상)  |
| 브라우저 | Chrome/Firefox/Safari 최신 |
```

---

### Phase 1: Foundation (기반 구축)

> **목표:** 나중에 고치기 어려운 것들을 먼저 잡는다.  
> **원칙:** 이 단계에서 "임시로" 만드는 것은 없다.

#### 해야 할 것 (이 순서로)

**1. 프로젝트 구조**
```
src/           핵심 비즈니스 로직 (프레임워크 의존성 없게)
src/Admin/     관리자 화면 관련
templates/     뷰 레이어
assets/        프론트엔드 파일
tests/         테스트 (이 시점에 만든다)
```

**2. 설정 파일들**
- `.gitignore` — 커밋하면 안 되는 것들 (vendor/, node_modules/, .env)
- `.editorconfig` — 팀 코딩 스타일 통일
- `composer.json` / `package.json`
- CI/CD 설정 (`.github/workflows/`)

**3. DB 스키마 & 마이그레이션**
- Phase 0에서 설계한 스키마를 실제 코드로 구현
- 마이그레이션 함수 작성 (플러그인 활성화 시 자동 실행)
- 이 시점에 스키마를 확정한다. 이후 변경은 마이그레이션으로만.

**4. 보안 레이어**
- 인증/권한 확인 위치 결정 (미들웨어? 각 함수?)
- 입력 검증 위치 결정 (컨트롤러 진입 시점)
- CSRF, nonce 전략
- 비밀값 관리 (하드코딩 금지, 환경변수 또는 DB 옵션)

**5. 에러 처리 & 로깅 전략**
- 어떤 에러를 로깅하나?
- 로그 형식은? (timestamp, level, context)
- 사용자에게 보여주는 에러 vs 내부 로그 분리

**6. 테스트 환경**
- 테스트 러너 설정 (phpunit.xml 등)
- 첫 테스트 1개라도 통과하는 상태로 만들기
- CI에서 테스트 자동 실행 연결

---

### Phase 2: Core MVP (핵심 기능)

> **목표:** 가장 중요한 기능 1개를 **완전하게** 만든다.  
> **"완전하게"** = 기능 작동 + 에러 처리 + 보안 + 테스트 (4개가 동시에)

#### 기능 구현 순서

각 기능을 만들 때 이 순서를 따른다:

```
1. 인터페이스 먼저 정의 (함수 시그니처, 파라미터, 반환값)
2. 테스트 작성 (아직 구현 없음, 실패 상태)
3. 구현
4. 테스트 통과
5. 입력 검증 & 권한 확인 추가
6. 에러 처리 추가
7. 코드 리뷰 (스스로 읽어보기)
```

#### 나쁜 예 vs 좋은 예

```
나쁜 예 (기능 먼저, 보안 나중):
  commit: feat: add delete handler
  commit: feat: add delete button
  commit: feat: register AJAX action
  commit: fix: add nonce check       ← 보안이 뒤에

좋은 예 (기능 + 보안 + 테스트 동시):
  commit: feat: newsletter delete — handler, AJAX, button, nonce, test
```

#### MVP의 범위를 지킨다

MVP에서 "좋으면 좋겠는데" 싶은 것은 전부 뺀다.  
MVP가 완성되고 나서 → 검증 → 그 다음에 추가한다.

---

### Phase 3: Iteration (반복 확장)

> **목표:** MVP를 검증하고 기능을 추가한다.

#### 원칙

**기능 추가 전 확인**
```
□ 현재 기능이 안정적인가? (최근 2주 버그가 없었나?)
□ 테스트가 충분한가?
□ 성능이 수용 가능한가?
→ 세 가지 모두 Yes일 때 다음 기능으로
```

**리팩토링과 기능 추가를 섞지 않는다**
```
나쁜 예:
  commit: feat+refactor: fix draft send regression, remove dead code
  
좋은 예:
  commit: refactor: extract email validation to trait
  commit: feat: add draft send retry logic
```

하나의 PR/커밋은 하나의 목적만 가진다.

**기술 부채를 추적한다**
```markdown
## TODO (다음 이터레이션)
- [ ] getNewsletters() SQL 최적화 (현재 N+1 쿼리)
- [ ] 설정 페이지 캐싱 추가
- [ ] 단위 테스트 커버리지 60% → 80%
```

이 목록 없이 "나중에 고치자"고 하면 → 영원히 안 고친다.

---

### Phase 4: Polish & Release (완성도)

> **목표:** 실사용자가 쓸 수 있는 품질로 끌어올린다.

#### 체크리스트

**성능**
- [ ] 추측 말고 측정 (프로파일링 도구 사용)
- [ ] 느린 DB 쿼리 확인 (EXPLAIN ANALYZE)
- [ ] N+1 쿼리 없는지 확인
- [ ] 캐시 전략 검토

**UI/UX 일관성**
- [ ] 모든 페이지 동일한 레이아웃 구조
- [ ] 에러 메시지 톤 통일
- [ ] 빈 상태 (empty state) 처리
- [ ] 로딩 상태 처리

**문서화**
- [ ] README (설치, 설정, 사용법)
- [ ] 코드 주석 (WHY가 비자명한 곳만)
- [ ] CHANGELOG

**릴리즈**
- [ ] 버전 번호 확정 (Semantic Versioning)
- [ ] 릴리즈 노트 작성
- [ ] 태그 생성 (`git tag v1.0.0`)
- [ ] 프로덕션 배포 절차 문서화

---

## 4. 각 기능의 완성 기준 (Definition of Done)

기능 하나를 "완성"이라고 부르려면 다음 **모두** 충족해야 한다.

```
□ Happy path 작동한다
□ Edge case 처리된다
  - null, 빈 값, 0, 음수
  - 권한 없는 사용자가 접근하면?
  - 동시에 두 번 클릭하면?
  - 네트워크 끊기면?
□ 보안 검토 완료
  - 입력 검증 (type, length, format)
  - SQL 인젝션 방어
  - XSS 방어
  - CSRF 방어 (nonce)
  - 권한 확인
□ 에러 처리
  - 사용자에게 의미 있는 에러 메시지
  - 내부 로그에 디버깅 가능한 정보
□ 테스트 1개 이상 통과
□ 코드를 다시 읽었을 때 이해된다 (동료 관점)
```

이 중 하나라도 빠지면 → 완성이 아니다.

---

## 5. 커밋 전략 & 버전 관리

### 커밋 메시지 형식

```
<type>: <what changed, why if not obvious>

type:
  feat     새 기능
  fix      버그 수정
  refactor 동작 변경 없는 코드 개선
  test     테스트 추가/수정
  docs     문서
  chore    빌드, 패키지, CI 등
  perf     성능 개선
  security 보안 수정
```

**좋은 커밋 메시지:**
```
feat: batch send with WP Cron — prevents timeout on large lists
fix: guard null in finalizeSend() — fatal error when record deleted mid-send
perf: eliminate O(n²) query in getNewsletters() — correlated subquery → JOIN
```

**나쁜 커밋 메시지:**
```
fix: bug fix
update: changes
feat: add stuff
```

### Semantic Versioning

```
버전: MAJOR.MINOR.PATCH

PATCH (0.0.x): 버그 수정 — 기존 사용자 영향 없음
MINOR (0.x.0): 새 기능 추가 — 하위 호환 유지
MAJOR (x.0.0): 구조 변경 — 하위 비호환, 마이그레이션 필요
```

### 브랜치 전략 (간단한 프로젝트)

```
main        — 언제나 배포 가능한 상태
feature/*   — 기능 개발
fix/*       — 버그 수정
release/*   — 릴리즈 준비 (버전 번호 확정, CHANGELOG 작성)
```

혼자 작업해도 브랜치를 나누면 → 실수했을 때 되돌리기 쉽다.

---

## 6. 반드시 피해야 할 패턴

### Anti-Pattern 1: 기능 폭포 (Feature Cascade)

```
feat: add button
feat: add handler
feat: register AJAX
feat: add nonce   ← 보안이 마지막
```

→ **대신:** 기능 하나를 처음부터 끝까지 완성하고 다음으로 넘어간다.

### Anti-Pattern 2: 무한 리팩토링

```
refactor: Phase 1
refactor: Phase 2
refactor: Phase 3
refactor: remove inline styles
refactor: unify layout
refactor: unify heading sizes
...
```

→ **대신:** 처음에 CSS 설계 시스템(디자인 토큰, 클래스 규칙)을 잡는다.  
나중에 리팩토링할 것들은 처음에 옳게 만든다.

### Anti-Pattern 3: 임시 코드

```php
// TODO: 나중에 고치자
$sql = "SELECT * FROM " . $table;  // 일단 다 가져옴
```

→ **대신:** 만들 시간이 없으면 그 기능은 이번 버전에서 빼라.

### Anti-Pattern 4: 범위 크리프 (Scope Creep)

> "어차피 여기까지 했으니 이것도 추가하자"

→ **대신:** Out-of-scope 목록을 문서로 관리하고,  
새 아이디어는 목록에 추가하고 이번 버전은 계획대로 완료한다.

### Anti-Pattern 5: 테스트 없는 리팩토링

```
refactor: restructure entire admin layer
```

→ **대신:** 리팩토링 전에 기존 동작을 테스트로 고정하고,  
리팩토링 후 테스트가 통과하는지 확인한다.

---

## 7. 프로젝트 시작 체크리스트

새 프로젝트를 시작할 때 이 순서로 진행한다.

### 코딩 시작 전

```markdown
## Discovery
- [ ] 문제 정의 1문장으로 쓰기
- [ ] 사용자 시나리오 3개 쓰기 (happy path)
- [ ] 성공 기준 측정 가능하게 정의
- [ ] 이번 버전 범위 확정 + out-of-scope 목록

## 설계
- [ ] 기술 스택 결정 (근거 있게, 표로 정리)
- [ ] DB 스키마 초안 (실제 SQL 수준)
- [ ] 인증/권한 모델 결정
- [ ] 외부 의존성 목록 (버전 포함)
- [ ] 보안 요구사항 목록
- [ ] 비기능 요구사항 (성능, 규모, 브라우저)
- [ ] 위험 요소 목록 (잘 모르는 것, 불확실한 것)
```

### 첫 코드 작성 시

```markdown
## Foundation
- [ ] 폴더 구조 확정
- [ ] .gitignore, .editorconfig
- [ ] 패키지 매니저 설정 (composer.json / package.json)
- [ ] 테스트 환경 구축 (첫 테스트 1개 통과 상태)
- [ ] CI/CD 기본 구성 (lint + test 자동 실행)
- [ ] DB 마이그레이션 코드
- [ ] 에러 로깅 유틸리티
- [ ] 보안 레이어 (nonce, 권한 확인 위치)
```

### 각 기능 구현 시

```markdown
## Feature Checklist
- [ ] 인터페이스 먼저 정의 (함수 시그니처)
- [ ] 테스트 작성 (구현 전)
- [ ] 구현
- [ ] 테스트 통과
- [ ] 입력 검증 추가
- [ ] 권한 확인 추가
- [ ] 에러 처리 추가
- [ ] Happy path 직접 테스트
- [ ] Edge case 직접 테스트
```

### 릴리즈 전

```markdown
## Release Checklist
- [ ] 모든 테스트 통과
- [ ] 성능 측정 (프로파일링)
- [ ] 보안 리뷰 (입력 검증, SQL, XSS, CSRF)
- [ ] UI/UX 일관성 검토
- [ ] README 최신화
- [ ] CHANGELOG 작성
- [ ] 버전 번호 확정
- [ ] 프로덕션 배포 테스트 (스테이징 환경에서)
```

---

## 8. 자주 쓰는 설계 질문 목록

프로젝트 초반에 스스로에게 던져야 하는 질문들.

### 데이터

- 어떤 데이터를 저장하나? 언제까지 보관하나?
- 어떤 데이터가 민감한가? (개인정보, 결제 등)
- 데이터 규모는? 1년 후 레코드 수는?
- 어떤 쿼리가 자주 실행되나? 인덱스가 필요한가?
- 스키마가 바뀌면 기존 데이터는 어떻게 되나?

### 보안

- 외부에서 접근 가능한 엔드포인트는 어디인가?
- 각 엔드포인트에서 누가 접근할 수 있나?
- 사용자 입력이 어디에서 어디로 흐르나?
- 어떤 값을 암호화/해시해야 하나?
- 인증 토큰의 유효기간은?

### 성능

- 가장 자주 실행되는 코드 경로는?
- 외부 API 호출이 있는가? 실패하면 어떻게 되나?
- 대량 처리가 필요한 작업이 있는가? (배치 처리 전략)
- 캐시가 필요한 데이터는?

### 에러

- 각 컴포넌트가 실패하면 사용자 경험은?
- 어떤 에러를 사용자에게 보여주고, 어떤 건 내부적으로만 기록하나?
- 재시도 로직이 필요한 작업은?
- 알림이 필요한 심각한 에러는?

### 유지보수

- 6개월 후 다른 개발자가 이 코드를 보면 이해할 수 있나?
- 어떤 부분이 가장 자주 변경될 것 같은가?
- 의존하는 외부 라이브러리가 사라지면 어떻게 되나?
- 로컬 환경과 프로덕션 환경의 차이는?

---

## 마치며

좋은 계획은 **불확실성을 줄이는 것**이지, 모든 것을 미리 아는 것이 아니다.

처음에는 모르는 것이 많다. 그래서:

1. 불확실한 것을 먼저 파악한다 (위험 요소 목록)
2. 가장 불확실한 것을 가장 먼저 검증한다 (프로토타입, PoC)
3. 확실해진 것부터 제대로 만든다

> "빨리 가고 싶으면 혼자 가라. 멀리 가고 싶으면 함께 가라."  
> "제대로 만들고 싶으면 먼저 설계하라. 빠르게 만들고 싶어도 먼저 설계하라."

---

*이 문서는 crmbiz-newsletter 프로젝트(v0.1~v0.9.1) 개발 경험을 바탕으로 작성됨.*  
*최초 작성: 2026-05-31*

---

## 9. 회귀 테스트 구축 실전 가이드

> crmbiz-newsletter v0.9.25 → v0.9.26 작업(2026-06-02)에서 얻은 교훈.  
> WordPress 플러그인 기준이지만 구조는 어떤 프로젝트에도 적용된다.

---

### 9-1. 두 가지 테스트 레이어

WordPress 플러그인에는 목적이 다른 두 종류의 테스트가 필요하다.

```
PHP 단위 테스트 (PHPUnit)
  → 비즈니스 로직 검증
  → WordPress 없이 인메모리로 실행
  → 빠름 (전체 84개 테스트, 0.02초)
  → 대상: 암호화, 발송 로직, 권한 확인, 수신거부 처리 등

E2E 테스트 (Playwright)
  → 실제 브라우저 + 실제 WordPress 환경
  → 느림 (23개 테스트, 17초)
  → 대상: 페이지 로드, UI 인터랙션, API 연동, 리다이렉트
```

**언제 어느 것을 쓰나?**

| 검증 대상 | 사용할 테스트 |
|---------|-----------|
| 함수 로직, 계산, 검증 | PHPUnit |
| DB 쿼리 결과 | PHPUnit (WpdbStub) |
| 보안 토큰, HMAC | PHPUnit |
| 페이지가 렌더되는가 | Playwright |
| 버튼 클릭 → 결과 | Playwright |
| API → UI 연동 | Playwright |
| 발송 파이프라인 전체 흐름 | PHPUnit (핵심) + Playwright (부분) |

---

### 9-2. PHP 단위 테스트: WordPress 스텁 패턴

WordPress 함수들(`get_option`, `wp_mail`, `$wpdb` 등)은 실제 WP 환경 없이 테스트에서 스텁으로 대체한다.

**핵심 원칙: `tests/bootstrap.php`에 스텁을 모아둔다**

```php
// tests/bootstrap.php
define('ABSPATH', dirname(__DIR__) . '/');
define('DAY_IN_SECONDS', 86400);

// WP 함수 스텁
function get_option(string $key, $default = false) {
    return $GLOBALS['_wp_options'][$key] ?? $default;
}
function update_option(string $key, $value): bool {
    $GLOBALS['_wp_options'][$key] = $value;
    return true;
}
function current_time(string $type): string {
    return date('Y-m-d H:i:s');
}

// $wpdb 스텁 — 인메모리 배열로 구현
class WpdbStub {
    public string $prefix = 'wp_';
    public int $insert_id = 0;      // ← 반드시 public (코드가 $wpdb->insert_id로 접근)
    private int $last_insert_id = 0;

    public function insert(string $table, array $data, array $formats = []): int {
        $id = count($GLOBALS['_wpdb_newsletters']) + 1;
        $data['id'] = $id;
        $GLOBALS['_wpdb_newsletters'][] = $data;
        $this->last_insert_id = $id;
        $this->insert_id      = $id; // ← 두 곳 모두 동기화
        return 1;
    }
    // ...
}
$GLOBALS['wpdb'] = new WpdbStub();
```

**자주 빠뜨리는 스텁**
- `$wpdb->insert_id` → WpdbStub에 `public int $insert_id` 필요
- `wp_timezone()` → `new DateTimeZone('Asia/Seoul')` 반환
- `apply_filters()` → 첫 번째 인자 그대로 반환

**Reflection API 사용 시 주의 (PHP 8.5)**

```php
// PHP 8.1+ 부터 setAccessible()이 불필요 (8.5에서 deprecated)
// 나쁜 예
$prop = $ref->getProperty('settings');
$prop->setAccessible(true);   // ← 이 줄 불필요, 8.5에서 경고
$prop->setValue($obj, $val);

// 좋은 예
$prop = $ref->getProperty('settings');
$prop->setValue($obj, $val);  // 8.1+ 에서 setAccessible 없어도 동작
```

---

### 9-3. E2E 테스트: Playwright + WordPress

#### baseURL 설정 — 가장 흔한 실수

```js
// playwright.config.js
baseURL: 'http://localhost:8888/wordpress/',  // ← 반드시 trailing slash

// spec 파일
const DASHBOARD = 'wp-admin/admin.php?page=crmbiz-newsletter'  // ← leading slash 없음
await page.goto(DASHBOARD)
// 결과: http://localhost:8888/wordpress/wp-admin/admin.php?page=crmbiz-newsletter ✓

// 잘못된 예
baseURL: 'http://localhost:8888/wordpress'  // trailing slash 없음
const DASHBOARD = '/wp-admin/...'           // leading slash 있음
// 결과: http://localhost:8888/wp-admin/...  (wordpress 경로가 날아감) ✗
```

**규칙:** `baseURL`은 trailing slash로 끝내고, 경로는 leading slash 없이 시작한다.

#### 로그인 상태 재사용 (auth setup)

매 테스트마다 로그인하면 느리다. `storageState`로 1회 저장 후 재사용:

```js
// tests/e2e/auth.setup.js
setup('WP 관리자 로그인 저장', async ({ page }) => {
    const base = process.env.WP_BASE_URL || 'http://localhost:8888/wordpress'
    await page.goto(base + '/wp-login.php', { waitUntil: 'domcontentloaded', timeout: 60_000 })
    await page.fill('#user_login', process.env.WP_ADMIN_USER)
    await page.fill('#user_pass',  process.env.WP_ADMIN_PASS)
    await page.click('#wp-submit')
    await page.waitForURL(/wp-admin/, { timeout: 30_000 })
    await page.context().storageState({ path: 'tests/e2e/.auth/admin.json' })
})

// playwright.config.js
projects: [
    { name: 'setup', testMatch: '**/auth.setup.js' },
    {
        name: 'chromium',
        dependencies: ['setup'],
        use: { storageState: 'tests/e2e/.auth/admin.json' },
    },
]
```

#### Soft Assertion Anti-Pattern

```js
// 나쁜 예 — 데이터가 없어도 통과, 기능이 망가져도 통과
test('캠페인 클릭', async ({ page }) => {
    const link = page.locator('a[href*="history"]').first()
    if (await link.count() > 0) {   // ← soft guard: 항상 통과
        await link.click()
    }
})

// 좋은 예 — 실제로 검증
test('캠페인 클릭', async ({ page }) => {
    const link = page.locator('a[href*="history"]').first()
    await expect(link).toBeVisible()  // ← 없으면 실패
    await link.click()
    await expect(page).toHaveURL(/history/)
})
```

**`if (isVisible())` 또는 `if (count > 0)` 가드가 붙은 테스트는 사실상 테스트가 아니다.**  
데이터가 없는 상태에서도 기능이 동작해야 한다면 → 테스트 전에 데이터를 시딩한다.

---

### 9-4. CI/CD: GitHub Actions에서 WordPress 환경 구축

#### MySQL 서비스 컨테이너 — 포트 매핑 필수

```yaml
services:
  mysql:
    image: mysql:8.0
    env:
      MYSQL_ROOT_PASSWORD: root
      MYSQL_DATABASE: wordpress
    ports:
      - 3306:3306                          # ← 없으면 127.0.0.1로 접근 불가
    options: >-
      --health-cmd="mysqladmin ping -h 127.0.0.1 -u root -proot"
      --health-interval=10s
      --health-timeout=5s
      --health-retries=5
```

#### WordPress 설치 후 퍼머링크 활성화 필수

```yaml
- name: Install & configure WordPress
  run: |
    wp core install --url=http://localhost:8080 ...
    # ← 이게 없으면 REST API(/wp-json/...)가 404 반환
    wp rewrite structure '/%postname%/' --hard --path=/tmp/wordpress
    wp rewrite flush --hard --path=/tmp/wordpress
```

**증상:** Vue 앱이 뜨지 않고 콘솔에 `TypeError: Cannot read properties of undefined (reading 'version')` 에러. REST API 호출이 404를 반환하기 때문.

#### 테스트 데이터 시딩 — 페이지네이션 주의

```yaml
- name: Seed test data
  run: |
    wp eval '
    global $wpdb;
    for ($i = 1; $i <= 6; $i++) {   // ← 기본 5개/페이지 초과해야 select 렌더됨
        $post_id = wp_insert_post([...]);
        $wpdb->insert($wpdb->prefix . "crmbiz_newsletters", [...]);
    }
    ' --path=/tmp/wordpress
```

**기본값보다 1개 더 시딩한다.** 기본 per-page가 5이면 6개 이상 시딩해야 페이지네이션 UI가 렌더된다.

#### `wp server` vs `php -S`

```yaml
# 나쁜 예: WordPress URL 라우팅 안 됨
- run: php -S localhost:8080 -t /tmp/wordpress &

# 좋은 예: WP-CLI 내장 라우터가 WordPress 라우팅 처리
- run: wp server --host=localhost --port=8080 --path=/tmp/wordpress &
         timeout 15 bash -c 'until curl -sf http://localhost:8080/wp-login.php; do sleep 1; done'
```

#### Node.js 버전 경고 제거

```yaml
# 워크플로 최상단에 추가
env:
  FORCE_JAVASCRIPT_ACTIONS_TO_NODE24: true
```

GitHub Actions가 Node.js 24로 전환(2026-06-16)하기 전에 미리 추가해두면 경고가 사라진다. 모든 workflow 파일(`*.yml`)에 각각 추가해야 한다.

---

### 9-5. 코드 리뷰에서 반복되는 버그 패턴

#### 타임존 혼용 — WordPress + MySQL

```php
// 나쁜 예: MySQL NOW()는 서버 timezone, sent_at은 WP 로컬로 저장됨
$wpdb->get_results($wpdb->prepare(
    "SELECT ... WHERE sent_at >= DATE_SUB(NOW(), INTERVAL %d DAY)",
    $days
));

// 좋은 예: PHP에서 WP timezone 기준으로 계산
$since = date('Y-m-d 00:00:00', current_time('timestamp') - ($days * DAY_IN_SECONDS));
$wpdb->get_results($wpdb->prepare(
    "SELECT ... WHERE sent_at >= %s",
    $since
));
```

**규칙:** `current_time('mysql')`로 저장한 컬럼은 PHP `current_time('timestamp')` 기준으로 범위를 계산한다. `NOW()`와 섞지 않는다.

#### DI 우회 — 클래스 중간에서 new 직접 생성

```php
// 나쁜 예: 생성자에서 주입받은 $settings가 있는데 내부에서 또 new
public function forceSend(): void {
    $settings = new Settings();  // ← DI 우회, 불일치 위험
    (new NewsletterSender($settings))->send();
}

// 좋은 예: 생성자 주입 일관성 유지
public function __construct(private Settings $settings) {}

public function forceSend(): void {
    (new NewsletterSender($this->settings))->send();
}
```

**클래스 안에서 의존 객체를 `new`로 직접 생성하면** → 생성자 변경 시 이 지점만 빠져서 버그가 된다. 생성자 주입을 일관되게 쓴다.

---

### 9-6. 회귀 테스트 구축 체크리스트

새 플러그인 시작 시 Phase 1에서 이것을 먼저 만든다.

```markdown
## PHP 단위 테스트
- [ ] phpunit.xml 설정
- [ ] tests/bootstrap.php — WP 함수 스텁, WpdbStub
- [ ] WpdbStub에 insert_id public 프로퍼티 포함
- [ ] 첫 테스트 1개 통과 확인

## E2E 테스트
- [ ] Playwright 설치 (npm install -D @playwright/test)
- [ ] playwright.config.js — baseURL에 trailing slash 포함
- [ ] tests/e2e/auth.setup.js — 로그인 storageState 저장
- [ ] tests/e2e/.auth/ gitignore에 추가
- [ ] 스펙 파일 경로는 leading slash 없이 작성
- [ ] .env.test — WP_BASE_URL, WP_ADMIN_USER, WP_ADMIN_PASS

## GitHub Actions CI
- [ ] PHP 단위 테스트 job (setup-php + phpunit)
- [ ] E2E job — MySQL ports 매핑 포함
- [ ] WordPress 설치 + wp rewrite structure
- [ ] 테스트 데이터 시딩 (기본 per-page + 1개 이상)
- [ ] wp server로 서버 기동
- [ ] FORCE_JAVASCRIPT_ACTIONS_TO_NODE24: true 전체 yml에 추가
- [ ] 실패 시 playwright-report artifact 업로드
```

---

## 10. Gate 4 실전 기록 — 상태 전환 · 헤더 인젝션 보안 테스트 (2026-06-02, v0.9.34)

> **무엇을 했나:** PHPUnit 테스트 2종 신규 작성 + bootstrap 스텁 확장 + PHPUnit 12 deprecation 제거.  
> 총 **251 tests / 443 assertions** → OK (경고 0개).

---

### 10-1. 추가된 테스트 파일

| 파일 | 테스트 수 | 검증 범위 |
|------|----------|-----------|
| `tests/StatusTransitionTest.php` | 38개 | 뉴스레터 7가지 상태(draft/queued/scheduled/sending/sent/failed/cancelled) 전환 완전성 |
| `tests/EmailHeaderSecurityTest.php` | 17개 | From 헤더 CRLF 인젝션 방어 전수 검증 |

---

### 10-2. 상태 전환 테스트 (StatusTransitionTest) 설계 원칙

**핵심 아이디어:** API 메서드마다 "허용되는 상태"와 "차단되는 상태"를 *전부* 테스트한다.  
놓친 전환이 있으면 프로덕션에서 잘못된 상태로 뉴스레터가 중복 발송되거나 삭제된다.

```
send        : draft만 허용 → queued      (queued/sending/sent/failed/cancelled/scheduled → 400)
cancel      : queued/sending/scheduled → cancelled  (draft/sent/failed/cancelled → 400)
force-send  : queued/sending만 허용     (나머지 → 400)
delete      : sending이면 차단          (나머지 상태 → 200)
resend      : 레코드 없으면 404, 포스트 없으면 400
resend-single: 이메일 형식 무효 → 400
```

**연속 전환 시나리오도 반드시 작성한다.**

```php
// draft → queued → cancelled 연속 전환 검증
$sendRes = $this->api->sendNewsletter($req);
$this->assertSame('queued', $DB[0]['status']);  // DB 반영 확인

$cancelRes = $this->api->cancelNewsletter($req);
$this->assertSame('cancelled', $DB[0]['status']);
```

단순 반환값만 확인하지 말고 **DB에도 반영됐는지** `$GLOBALS['_wpdb_newsletters']`를 직접 확인한다.

---

### 10-3. 이메일 헤더 인젝션 테스트 (EmailHeaderSecurityTest) 설계 원칙

**공격 패턴:** From 이름/이메일에 `\r\n`을 넣으면 SMTP 서버가 별도 헤더(Bcc, Cc, Reply-To)로 해석한다.

```
"Legit Name\r\nBcc: attacker@evil.com"
→ 방어 안 되면: From: Legit Name
                Bcc: attacker@evil.com   ← 인젝션 성공
→ 방어 되면:   From: Legit NameBcc: attacker@evil.com (한 줄 → MTA가 별도 헤더로 해석 불가)
```

**어설션 방향:** "Bcc: 가 없어야 한다"가 아니라 **"\r\n 이 없어야 한다"** 가 올바른 검증이다.

```php
// 나쁜 예 — str_replace 후 텍스트가 이어붙어도 Bcc:는 남아있음
$this->assertStringNotContainsString('Bcc:', $fromHeader);  // ← 잘못된 어설션

// 좋은 예 — 방어의 본질은 CRLF 제거
$this->assertStringNotContainsString("\r\n", $fromHeader);
$this->assertStringNotContainsString("\r",   $fromHeader);
$this->assertStringNotContainsString("\n",   $fromHeader);
```

**검증해야 할 패턴 4가지:**  
`\r\n` (CRLF), `\n` (LF), `\r` (CR), 다중 인젝션 (`\r\n...\r\n...\r\n...`)

**방어 코드 위치 (이 플러그인 기준):**

| 파일 | 라인 | 적용 시점 |
|------|------|---------|
| `src/Admin/AjaxHandlers.php` | 43-44 | 테스트 이메일 발송 시 |
| `src/Admin/AjaxHandlers.php` | 188-189 | 테스트 뉴스레터 발송 시 |
| `src/NewsletterSender.php` | 342-343, 432-433 | 실제 발송 시마다 |

새 발송 경로가 추가될 때마다 `str_replace(["\r", "\n"], '', $fromName)` 패턴을 반드시 적용한다.

---

### 10-4. bootstrap.php 스텁 확장 — REST API

`WP_REST_Request / WP_REST_Response / WP_Error`가 없으면 RestApi 클래스를 PHPUnit에서 로드할 수 없다.  
새 API 엔드포인트 테스트 작성 전에 항상 스텁 존재 여부를 확인한다.

```php
// tests/bootstrap.php에 추가된 스텁 (if (!class_exists) 가드 필수)
class WP_REST_Request {
    public function __construct(string $method = 'GET', array $params = []) { ... }
    public function get_param(string $key) { return $this->params[$key] ?? null; }
}
class WP_REST_Response {
    public function get_data(): mixed { return $this->data; }
    public function get_status(): int  { return $this->status; }
}
class WP_Error {
    public function get_error_code(): string    { ... }
    public function get_error_message(): string { ... }
}
function rest_ensure_response(mixed $data): WP_REST_Response { ... }
function is_wp_error(mixed $val): bool { return $val instanceof WP_Error; }
```

**WpdbStub 쿼리 패턴 추가 요령:**  
SQL에 줄바꿈이 있는 경우 `/s` 플래그를 사용해야 `preg_match`가 매칭된다.

```php
// 잘못된 예 — SQL에 \n 있으면 매칭 실패
preg_match("/UPDATE \S+crmbiz_newsletters SET status = .../i", $sql, $m)

// 올바른 예 — /is 플래그로 줄바꿈 무시
preg_match("/UPDATE \S+crmbiz_newsletters\s+SET status = .../is", $sql, $m)
```

---

### 10-5. PHPUnit @dataProvider → #[DataProvider] 마이그레이션

PHPUnit 11부터 doc-comment 기반 `@dataProvider`는 deprecated, PHPUnit 12에서 제거된다.

```php
// 나쁜 예 (PHPUnit 11에서 deprecation 경고)
/** @dataProvider nonDraftStatuses */
public function test_send_non_draft_returns_400(string $status): void { ... }

// 좋은 예 (attribute 방식)
use PHPUnit\Framework\Attributes\DataProvider;

#[DataProvider('nonDraftStatuses')]
public function test_send_non_draft_returns_400(string $status): void { ... }
```

`OK, but there were issues! — PHPUnit Deprecations: N` 메시지가 나오면 이 마이그레이션이 필요한 것이다.

---

### 10-6. 앞으로 참고할 테스트 우선순위

새 기능을 추가하거나 기존 코드를 수정할 때 **이 순서로 테스트를 작성/실행한다.**

#### 즉시 (코드 수정 직후 항상)

```bash
./vendor/bin/phpunit   # 전체 PHPUnit — 0.02초, 항상 돌린다
```

#### 새 API 엔드포인트 추가 시 — 반드시 작성

1. **상태 전환 테스트** — 허용/차단 상태 전부 열거 (`StatusTransitionTest` 참고)  
   - 허용 케이스: 기대 응답 코드 + DB 상태 변경 확인  
   - 차단 케이스: 400 반환 + DB 상태 **그대로인지** 확인  
   - 연속 전환 시나리오 (draft→queued→cancelled 등)

2. **비존재 ID 케이스** — `id: 9999` 같은 없는 레코드 → 400 또는 404 확인

#### 이메일 발송 경로 추가/수정 시 — 반드시 작성

3. **헤더 인젝션 테스트** — `\r\n`, `\n`, `\r`, 다중 인젝션 4패턴 (`EmailHeaderSecurityTest` 참고)  
   어설션은 newline 문자 유무로 확인한다.

#### 보안 관련 코드 수정 시

4. **토큰/HMAC 테스트** — 수신거부 URL, 암호화 토큰 (`UnsubscribeTest`, `EncryptionTest` 참고)  
5. **권한 확인 테스트** — `current_user_can` false 시 wp_die 또는 403 반환 확인

#### 대규모 변경(리팩토링, 레이어 재구성) 후

6. **전체 PHPUnit + E2E 순서로** 실행  
   ```bash
   ./vendor/bin/phpunit                          # PHP 단위
   npx playwright test --project=chromium        # E2E 크로미엄
   npx playwright test                           # 전체 브라우저
   ```

#### 테스트 추가가 필요한데 빠른 작업이 필요할 때 — 최소 기준

```
□ 성공 케이스 1개 (happy path)
□ 실패 케이스 1개 (없는 ID 또는 잘못된 입력)
□ DB 상태 변경이 있는 경우 — 변경 전/후 직접 확인
```

이 3가지가 없으면 "테스트 있음"이라고 부르지 않는다.

---

## 11. E2E 테스트 전략 — 이 플러그인에 필요한 것들

> 분석 기준일: 2026-06-02 (v0.9.34).  
> 기존 스펙 19개(561개 테스트)를 분석하고 **실제로 빠진 것**만 정리했다.  
> "있으면 좋겠다"가 아니라 "프로덕션에서 이게 깨지면 사용자가 바로 체감한다" 기준이다.

---

### 11-1. 이미 커버된 영역 (건드리지 않아도 됨)

| 스펙 파일 | 커버 범위 |
|-----------|-----------|
| `access-control.spec.js` | 비인증 REST 403 / 로그인 리다이렉트 |
| `accessibility.spec.js` | WCAG 2.1 AA, tab 네비게이션, aria-label, 색상 대비 |
| `dashboard.spec.js` | 페이지 로드, 차트 토글, 페이지네이션, 콘솔 에러 없음 |
| `email-template.spec.js` | 미리보기, 다크/라이트 테마 |
| `error-recovery.spec.js` | API 500 / 네트워크 오류 시 빈 화면 없음 |
| `history-actions.spec.js` | 슬라이드오버, send/cancel/resend 버튼, 토스트 |
| `lifecycle.spec.js` | 활성화·비활성화·삭제, DB 마이그레이션 |
| `metabox.spec.js` | 메타박스 렌더, 체크박스 토글, 예약 입력 표시 |
| `pagination.spec.js` | per-page 선택, 다음/이전 버튼 |
| `plugin-conflicts.spec.js` | 타 플러그인 충돌 |
| `post-publish.spec.js` | 발행 → queued/draft/scheduled 레코드 생성 |
| `public-endpoints.spec.js` | 트래킹 픽셀 GIF 반환, 클릭 스킴 차단, 수신거부 구조 |
| `settings.spec.js` | 설정 저장 성공 |
| `unsubscribers.spec.js` | 수신거부 관리 UI (검색, 추가 모달, 해제) |

---

### 11-2. 빠진 E2E — 우선순위 순

#### 🔴 P1 — 핵심 사용자 플로우 (지금 없으면 프로덕션 버그가 자동 감지 안 됨)

---

**[1] 수신거부 완전 흐름** (`unsubscribe-flow.spec.js` 신규 작성)

현재 `public-endpoints.spec.js`는 구조(403, 만료 메시지)만 확인한다.  
"유효한 토큰으로 실제 수신거부" 흐름이 E2E로 검증되지 않는다.

```
시나리오:
1. WP-CLI로 수신거부 URL 직접 생성 (토큰 포함)
   wp eval 'echo CRMBizNewsletter\UnsubscribeHandler::buildUrl("user@test.com", $nl_id);'
2. Playwright로 해당 URL 접근 → "수신거부 완료" 페이지 렌더 확인
3. REST API로 수신거부 목록 확인 → 이메일 추가됐는지
4. 같은 URL 재접근 → 중복 추가 없음 (멱등성)
5. 수신거부 해제(관리자) → 목록에서 제거 확인
```

왜 중요한가: 수신거부는 GDPR/스팸 방지 핵심이다. 이게 깨지면 법적 문제.

---

**[2] 오픈 트래킹 카운트 반영** (기존 `public-endpoints.spec.js`에 추가)

현재: 픽셀 GIF가 반환되는지만 확인.  
빠진 것: 유효 HMAC 토큰으로 호출 시 DB의 open 이벤트가 기록되는지.

```
시나리오:
1. WP-CLI로 유효한 픽셀 URL 생성 (HMAC 포함)
2. request.get(pixelUrl) 호출
3. REST API로 해당 뉴스레터 detail 조회 → open_count 증가 확인
4. 30분 내 동일 이메일로 재호출 → open_count 그대로 (rate limit)
5. 유효하지 않은 HMAC → open_count 그대로
```

왜 중요한가: 오픈율이 통계의 핵심이다. 숫자가 틀리면 발송 전략 판단이 왜곡된다.

---

**[3] 클릭 트래킹 카운트 + 리다이렉트** (기존 `public-endpoints.spec.js`에 추가)

현재: 정상 URL이 "리다이렉트 시도"되는지만 확인. 실제 리다이렉트 목적지와 click_count 미확인.

```
시나리오:
1. 유효한 클릭 URL 생성 (target: https://example.com)
2. request.get(clickUrl, { maxRedirects: 0 }) → 302 + Location: https://example.com 확인
3. REST API로 click_count 증가 확인
4. 동일 이메일 rate limit (30분/30회) — 31번째 호출 시 카운트 증가 없음
5. HMAC 조작 URL → 홈으로 리다이렉트, click_count 그대로
```

---

**[4] 발송 진행률 폴링 UI** (`history-progress.spec.js` 신규 작성)

`getProgress` REST 엔드포인트가 있고 Vue 앱이 폴링하지만, 이 흐름의 E2E가 없다.

```
시나리오:
1. WP-CLI로 sending 상태의 뉴스레터 직접 삽입
2. 이력 페이지 로드 → progress bar 또는 % 수치 표시 확인
3. WP-CLI로 상태를 sent로 변경
4. 페이지에서 폴링이 멈추고 최종 상태 표시 확인
5. 콘솔에 무한 요청 없음 (Network 탭에서 /progress 요청이 멈췄는지)
```

왜 중요한가: 폴링이 멈추지 않으면 서버 부하 + 사용자가 "발송 중" 화면에 갇힌다.

---

#### 🟡 P2 — 데이터 정확성 (숫자가 틀리면 신뢰를 잃는다)

---

**[5] 이력 필터/검색 결과 검증** (기존 `history.spec.js`에 추가)

현재: 검색어가 URL에 반영되는지만 확인. 테이블 내용은 미검증.

```
시나리오:
1. 여러 상태(draft, sent, cancelled)의 이력 시딩
2. status=sent 필터 → 테이블에 sent 행만 표시, cancelled 행 없음
3. 텍스트 검색 → 검색어 포함 제목만 표시
4. 필터 초기화 → 전체 목록 복원
5. 날짜 범위 필터 → 범위 외 항목 사라짐
```

---

**[6] 개별 재발송(resend-single) 완전 흐름** (기존 `history-actions.spec.js`에 추가)

현재: 수신자 탭의 "개별 재발송 버튼 존재" 확인만 있다.

```
시나리오:
1. sent 상태 + 수신자 데이터 있는 뉴스레터 시딩
2. 슬라이드오버 → 수신자 탭 → 특정 행의 재발송 버튼 클릭
3. 성공 토스트 표시
4. 잘못된 이메일 형식 입력 → 버튼 비활성화 또는 에러
```

---

**[7] 설정 저장 → API 반영 확인** (기존 `settings.spec.js`에 추가)

현재: 저장 성공 메시지만 확인.

```
시나리오:
1. from_name을 "새 발신자명"으로 변경 저장
2. REST GET /wp-json/crmbiz-nl/v1/dashboard → settings.from_name 값 일치 확인
3. from_email 변경 → 동일
```

---

#### 🟠 P3 — 보안 경계 (방어 코드가 실제로 동작하는지)

---

**[8] 수신거부 Rate Limit** (`public-endpoints.spec.js`에 추가)

`UnsubscribeHandler`에 10분/10회 제한이 있지만 E2E 미검증.

```
시나리오:
1. 잘못된 토큰으로 수신거부 URL 11회 연속 요청
2. 11번째 → HTTP 429 응답 확인
   (동일 IP 기준이므로 request 객체로 직접 호출)
```

---

**[9] Nonce 만료 → UI 처리** (`metabox-ajax.spec.js`에 추가)

```
시나리오:
1. 페이지를 로드하고 nonce를 의도적으로 조작
   page.evaluate(() => window.crmbizNl.nonce = 'invalid')
2. 테스트 이메일 발송 버튼 클릭
3. UI가 에러 상태 표시 (토스트 또는 alert)
4. 무한 로딩에 빠지지 않음
```

---

#### 🔵 P4 — 반응형 (모바일 사용자 보호)

---

**[10] 모바일 뷰포트 주요 페이지** (`responsive.spec.js` 신규 작성)

현재 모든 E2E가 데스크톱 기준이다. 관리자가 모바일에서 접근하면 깨진다.

```js
// playwright.config.js에 프로젝트 추가
{
  name: 'mobile',
  use: { ...devices['iPhone 14'] },
  dependencies: ['setup'],
  testMatch: '**/responsive.spec.js',
}
```

```
시나리오:
1. 대시보드 — 375px에서 가로 스크롤 없음, 차트 축소 렌더
2. 이력 페이지 — 테이블 스크롤 가능, 슬라이드오버 전체 너비
3. 설정 페이지 — 폼 입력 가능, 저장 버튼 접근 가능
```

---

#### ⚪ P5 — 장기 안정성 (스트레스 상황 대비)

---

**[11] WP Cron 비활성화 경고 배너** (`dashboard.spec.js`에 추가)

```
시나리오:
1. wp-config.php에 DISABLE_WP_CRON = true 추가 (CI에서 환경변수 오버라이드)
2. 대시보드 로드 → 경고 배너 표시 확인
3. 배너 dismiss 버튼 → 배너 사라짐
4. 새로고침 → 배너 다시 표시되지 않음 (상태 저장 확인)
```

---

**[12] 대량 이력 성능** (`pagination.spec.js`에 추가)

```
시나리오:
1. WP-CLI로 50개 이력 시딩
2. 이력 페이지 로드 시간 < 2000ms (page.waitForLoadState 측정)
3. 5페이지 이동 후 슬라이드오버 정상 동작
4. 검색 후 결과 표시 시간 < 1000ms
```

---

### 11-3. 스펙 파일별 작업 계획 요약

| 우선순위 | 작업 | 파일 | 방식 |
|---------|------|------|------|
| 🔴 P1 | 수신거부 완전 흐름 | `unsubscribe-flow.spec.js` 신규 | WP-CLI로 토큰 생성 |
| 🔴 P1 | 오픈 트래킹 카운트 | `public-endpoints.spec.js` 추가 | WP-CLI로 픽셀 URL 생성 |
| 🔴 P1 | 클릭 트래킹 카운트 | `public-endpoints.spec.js` 추가 | WP-CLI로 클릭 URL 생성 |
| 🔴 P1 | 발송 진행률 폴링 | `history-progress.spec.js` 신규 | WP-CLI로 sending 레코드 주입 |
| 🟡 P2 | 이력 필터/검색 결과 | `history.spec.js` 추가 | 다상태 시딩 후 DOM 확인 |
| 🟡 P2 | 개별 재발송 흐름 | `history-actions.spec.js` 추가 | 수신자 데이터 시딩 필요 |
| 🟡 P2 | 설정 → API 반영 | `settings.spec.js` 추가 | REST GET으로 값 검증 |
| 🟠 P3 | 수신거부 rate limit | `public-endpoints.spec.js` 추가 | 11회 연속 request 호출 |
| 🟠 P3 | Nonce 만료 UI 처리 | `metabox-ajax.spec.js` 추가 | page.evaluate로 nonce 조작 |
| 🔵 P4 | 모바일 반응형 | `responsive.spec.js` 신규 | iPhone 14 viewport 프로젝트 |
| ⚪ P5 | WP Cron 경고 배너 | `dashboard.spec.js` 추가 | CI 환경변수 조작 |
| ⚪ P5 | 대량 이력 성능 | `pagination.spec.js` 추가 | 50개 시딩 + 타임 측정 |

---

### 11-4. E2E 작성 시 공통 패턴

#### 추적/수신거부 토큰을 테스트에서 생성하는 방법

`public-endpoints.spec.js`의 주석처럼 "유효한 파라미터 시뮬레이션 불가"는 틀린 말이다.  
WP-CLI `wp eval`로 PHP를 직접 실행하면 실제 토큰을 생성할 수 있다.

```js
// Playwright 테스트에서 WP-CLI로 토큰 생성
import { execSync } from 'child_process'

function buildUnsubUrl(email, nlId) {
    const url = execSync(
        `wp eval --path=/tmp/wordpress 'echo CRMBizNewsletter\\Database::buildUnsubUrl("${email}", ${nlId});'`
    ).toString().trim()
    return url
}

test('유효한 토큰으로 수신거부 성공', async ({ page }) => {
    const url = buildUnsubUrl('test@example.com', 1)
    await page.goto(url)
    await expect(page.locator('h1')).toContainText('수신거부가 완료')
})
```

#### 카운트 증가 검증 패턴

```js
// 이벤트 기록 전 값 읽기 → 동작 → 후 값 비교
test('오픈 픽셀 → open_count 증가', async ({ request }) => {
    const before = await request.get('/wp-json/crmbiz-nl/v1/newsletters/1')
    const beforeCount = (await before.json()).open_count

    await request.get(buildPixelUrl(1, 'user@test.com'))  // 유효 HMAC

    const after = await request.get('/wp-json/crmbiz-nl/v1/newsletters/1')
    expect((await after.json()).open_count).toBe(beforeCount + 1)
})
```

#### Rate Limit 테스트 — CI에서 IP 차단 우회

```js
// rate limit은 IP 기준이므로 같은 IP에서 연속 호출
test('수신거부 rate limit — 11번째 → 429', async ({ request }) => {
    const badUrl = BASE + '/?crmbiz_nl_action=unsubscribe&enc=invalid&token=x&exp=0'
    for (let i = 0; i < 10; i++) {
        await request.get(badUrl)
    }
    const res = await request.get(badUrl)
    expect(res.status()).toBe(429)
})
// 주의: CI에서 rate limit 상태가 다음 테스트로 유출될 수 있음
// → 각 테스트 전 wp eval로 rate limit transient 초기화
```

---

### 11-5. 지금 당장 시작한다면

**1주 안에 가치가 가장 높은 것:**

1. `unsubscribe-flow.spec.js` — 수신거부는 법적 의무, 최우선
2. `public-endpoints.spec.js`에 open/click count 추가 — 통계의 신뢰성
3. `history.spec.js`에 필터 결과 검증 추가 — 현재 어설션이 절반만 함

**1개월 내:**

4. `history-progress.spec.js` — 폴링 UI (구현 복잡도 높음)
5. `responsive.spec.js` — 모바일 뷰포트

**여유 시:**

6. Nonce 만료, Rate limit, WP Cron 배너 — 실제 발생 빈도는 낮지만 방어 코드 동작 확인용

---

## 12. v1.0.0 실전 기록 — PHPUnit 비즈니스 로직 테스트 (2026-06-03)

> **무엇을 했나:** 섹션 11 E2E 플랜 전체 구현(+163) + PHPUnit 3개 신규 클래스(+68) + 버그 2건 수정.  
> 총 **319 PHPUnit / 724 E2E** → v1.0.0 릴리즈.

---

### 12-1. 테스트로 발견된 실제 버그 2건

테스트를 작성하기 전에는 발견하지 못했던 버그들이다.

**버그 1: `RestApi::getDashboard()` DivisionByZeroError**

```php
// 나쁜 코드 (버그)
$campaignPerPage = in_array((int) ($req->get_param('per_page') ?? 5), [5, 10, 20], true)
                   ? (int) $req->get_param('per_page') : 5;
//                   ↑ 검사할 때는 ?? 5로 5를 대입하지만
//                   ↑ 결과값 취할 때는 null을 (int)로 변환 → 0

// 고친 코드
$campaignPerPage = in_array((int) ($req->get_param('per_page') ?? 5), [5, 10, 20], true)
                   ? (int) ($req->get_param('per_page') ?? 5) : 5;
//                          ↑ 양쪽 모두 ?? 5 적용
```

**교훈:** 조건식과 결과식에서 같은 null coalescing을 대칭으로 써야 한다.  
PHPUnit 없이는 `per_page` 파라미터를 빼고 API를 호출하는 테스트를 작성할 일이 없었고, 프로덕션에서만 발생하는 버그였다.

**버그 2: `UnsubscribePage.php` 버튼 아이콘 누락**

```php
// 나쁜 코드 — <button> 태그 닫는 >가 없어서 <span>이 attribute로 파싱됨
<button type="button" ... title="수신거부 해제"
    <span class="dashicons dashicons-undo"></span>
</button>

// 고친 코드
<button type="button" ... title="수신거부 해제">
    <span class="dashicons dashicons-undo"></span>
</button>
```

**교훈:** HTML 파싱 오류는 브라우저가 조용히 무시하기 때문에 기능은 작동하지만 UI가 깨진다. 스크린샷 기반 회귀 테스트가 없으면 발견하기 어렵다.

---

### 12-2. WpdbStub 확장 패턴 — 복잡한 쿼리 처리

`RestApiBusinessLogicTest`, `PluginTest`처럼 복잡한 SQL(JOIN, IN 절, 집계)이 필요한 경우,  
`bootstrap.php`의 WpdbStub을 직접 수정하지 않고 **테스트 파일 내에서 서브클래스로 확장**한다.

```php
// tests/RestApiBusinessLogicTest.php 상단에 선언
class RestApiWpdbStub extends WpdbStub {

    public function get_results(string $sql, string $output = 'OBJECT'): array {
        // getProgress: WHERE id IN (1,2,3) 처리
        if (preg_match("/crmbiz_newsletters WHERE id IN \(([0-9, ]+)\)/i", $sql, $m)) {
            $ids  = array_map('intval', explode(',', $m[1]));
            $rows = array_filter(
                $GLOBALS['_wpdb_newsletters'],
                fn($r) => in_array((int)($r['id'] ?? 0), $ids, true)
            );
            return array_map(fn($r) => (object)$r, array_values($rows));
        }
        return parent::get_results($sql, $output);
    }

    public function get_row(string $sql, string $output = 'OBJECT') {
        // getDashboard 집계: SUM(success_count)
        if (strpos($sql, 'SUM(success_count)') !== false) {
            $sent = array_filter($GLOBALS['_wpdb_newsletters'], fn($r) => $r['status'] === 'sent');
            return (object)[
                'total_nl'      => count($sent),
                'total_success' => array_sum(array_column($sent, 'success_count')),
                'total_fail'    => array_sum(array_column($sent, 'fail_count')),
            ];
        }
        return parent::get_row($sql, $output);
    }
}

// setUp()에서 교체
$GLOBALS['wpdb'] = new RestApiWpdbStub();

// tearDown()에서 원래대로
$GLOBALS['wpdb'] = new WpdbStub();
```

**원칙:**
- bootstrap.php의 WpdbStub은 전체 테스트가 공유하므로 건드리지 않는다
- 새 쿼리 패턴이 필요하면 테스트 파일 안에서 서브클래스를 만든다
- `parent::method()`를 호출해 기존 패턴은 그대로 위임한다

---

### 12-3. PHP 싱글톤 테스트 패턴

`Plugin::getInstance()`처럼 싱글톤인 클래스를 PHPUnit에서 테스트할 때.

```php
// setUp(): 싱글톤 생성 전에 필요한 상태 먼저 주입
protected function setUp(): void {
    // install() 호출 방지 — ABSPATH 아래 upgrade.php가 없으므로 require_once가 터짐
    $GLOBALS['_wp_options'][Database::DB_VERSION_OPTION] = Database::DB_VERSION;

    $this->plugin = Plugin::getInstance();
}

// tearDown(): Reflection으로 싱글톤 초기화 → 다음 테스트에서 새 인스턴스 생성 가능
protected function tearDown(): void {
    (new \ReflectionClass(Plugin::class))
        ->getProperty('instance')
        ->setValue(null, null);
}
```

**주의:** PHP 8.1+에서는 `setAccessible(true)` 없이 `setValue()` 직접 호출 가능.

---

### 12-4. 예약 시각 테스트의 시간대 함정

`parseScheduledAt()`처럼 미래 시각을 파싱하는 테스트에서 흔히 발생하는 함정.

```php
// 나쁜 예 — 시스템이 UTC이고 WP 시간대가 Asia/Seoul(UTC+9)이면
// time() + 3600 을 UTC 형식으로 포맷 → Seoul 기준으로 9시간 전 = 과거!
$future = date('Y-m-d H:i:s', time() + 3600); // ← 1시간 후지만 시차로 과거 취급될 수 있음

// 좋은 예 — 30일 이상 여유를 두면 어떤 시간대 차이(최대 14시간)도 흡수
$future = date('Y-m-d H:i:s', time() + 86400 * 30);
```

**규칙:** 날짜/시각 비교가 들어가는 테스트는 버퍼를 넉넉하게(최소 24시간) 잡는다.

---

### 12-5. bootstrap.php 누적 스텁 목록

PHPUnit 개발 중 누락이 발견될 때마다 추가한 스텁들. 새 테스트 작성 전 여기서 먼저 확인한다.

| 함수/프로퍼티 | 추가 시점 | 동작 |
|-------------|---------|-----|
| `current_time('timestamp')` | RestApiTest | `time()` 반환 (기존엔 항상 string) |
| `wp_create_nonce($action)` | RestApiTest | `'test_nonce_' . md5($action)` |
| `wp_kses($data, $allowed)` | PluginTest | `$data` 그대로 반환 |
| `human_time_diff($from)` | PluginTest | 초/분/시간 단위 문자열 |
| `sanitize_key($key)` | PluginTest | lowercase + `[a-z0-9_-]`만 허용 |
| `WpdbStub::$posts` | RestApiTest | `'wp_posts'` |
| `WpdbStub::esc_like($text)` | RestApiTest | `addcslashes($text, '_%\\')` |

---

## 13. 플러그인 고도화 로드맵

> 작성 기준: v1.0.0 릴리즈 직후 (2026-06-03).  
> "지금 당장 사용자에게 가장 큰 가치를 주는 것"부터 역순으로 설계했다.

---

### Phase A — 안정화·성능 (v1.1~v1.3, 단기 1~2개월)

> **목표:** 현재 기능을 더 빠르고 안정적으로 만든다. 새 기능 없음.

#### A-1. 집계 쿼리 캐싱

`getDashboard()`와 `getNewsletters()`는 매 API 호출마다 `COUNT + SUM + GROUP BY`를 실행한다.  
발송 이력이 수백 건을 넘으면 체감 속도가 느려진다.

```php
// 대시보드 stats — 5분 캐시
$stats = get_transient('crmbiz_nl_dashboard_stats');
if ($stats === false) {
    $stats = $wpdb->get_row("SELECT COUNT(*) AS total_nl, ...");
    set_transient('crmbiz_nl_dashboard_stats', $stats, 5 * MINUTE_IN_SECONDS);
}
// 발송 완료 시 캐시 무효화
delete_transient('crmbiz_nl_dashboard_stats');
```

**측정 먼저:** `EXPLAIN ANALYZE`로 느린 쿼리 확인 후 캐시 도입 결정.

#### A-2. 발송 큐 잠금 개선

현재 `GET_LOCK()`으로 동시 Cron 실행을 차단하는데, 락 획득 실패 시 다음 Cron 사이클(1분)까지 발송이 지연된다.  
`FOR UPDATE SKIP LOCKED` 패턴으로 전환하면 여러 워커가 충돌 없이 병렬 발송 가능.

```sql
-- 현재: GET_LOCK() 전체 잠금
-- 개선: 행 단위 잠금으로 병렬 처리
SELECT id, email FROM wp_crmbiz_nl_queue
WHERE newsletter_id = %d
LIMIT %d
FOR UPDATE SKIP LOCKED
```

#### A-3. 오픈/클릭 이벤트 인덱스 최적화

`getNewsletterDetail()` 수신자별 이벤트 집계 쿼리는 `newsletter_id + email + type` 복합 조회.  
현재 `idx_nl_type (newsletter_id, type)` 인덱스가 있지만 email 컬럼이 빠져 풀스캔 가능성.

```sql
-- DB 2.1.0 마이그레이션으로 추가
ALTER TABLE wp_crmbiz_nl_events
    ADD KEY idx_nl_email_type (newsletter_id, email, type);
```

#### A-4. 재시도 만료된 큐 자동 정리

현재 `handleCleanup()`이 events 90일, ratelimit 만료를 정리하지만  
실패한 큐 아이템(`retry_count >= 3`)은 영구 잔존한다.

```php
// handleCleanup()에 추가
$wpdb->query(
    "DELETE q FROM {$wpdb->prefix}crmbiz_nl_queue q
     JOIN {$wpdb->prefix}crmbiz_newsletters n ON n.id = q.newsletter_id
     WHERE n.status IN ('failed','cancelled')"
);
```

---

### Phase B — 핵심 기능 확장 (v2.0, 중기 3~6개월)

> **목표:** 사용자가 "이게 없으면 다른 플러그인으로 넘어간다"고 느끼는 기능들.

#### B-1. 이메일 템플릿 에디터

현재는 WordPress 포스트 에디터(Gutenberg) 콘텐츠를 그대로 이메일로 변환한다.  
이메일 전용 블록 에디터를 추가하면 마케터가 코드 없이 레이아웃을 구성할 수 있다.

```
구현 방향:
- 별도 Custom Post Type (crmbiz_nl_template) 생성
- Gutenberg 블록 제한 (이메일 호환 블록만 허용)
- mjml 또는 juice로 인라인 스타일 변환
- 템플릿 라이브러리 (5~10종 프리셋)

복잡도: 높음 (6~8주)
가치: 매우 높음 — 현재 가장 큰 사용성 병목
```

#### B-2. A/B 테스트

제목 또는 발신자 이름을 두 버전으로 나눠 발송하고 오픈율로 승자를 결정한다.

```
구현 방향:
- crmbiz_newsletters에 variant/parent_id 컬럼 추가 (DB 2.1.0)
- 발송 시 수신자를 A/B 그룹으로 나눔 (50:50 또는 커스텀 비율)
- 24시간 후 오픈율 비교 → 승자 버전으로 나머지 발송
- 이력 페이지에 A/B 결과 UI
```

#### B-3. 구독 폼 + 직접 구독 관리

현재는 FluentCRM 연락처에 의존. FluentCRM 없는 환경을 위해 자체 구독 폼 지원.

```
구현 방향:
- [crmbiz_nl_subscribe] 숏코드
- 이중 확인(double opt-in) 이메일 흐름
- 구독자 DB 테이블 (crmbiz_nl_subscribers)
- FluentCRM 없으면 자체 구독자 사용, 있으면 동기화
```

#### B-4. 고급 통계 — 기기/링크별 분석

현재: 오픈/클릭 횟수만 집계.

```
추가할 통계:
- 기기별 오픈 (데스크톱/모바일/태블릿) — User-Agent 파싱
- 링크별 클릭 횟수 (어떤 URL이 많이 클릭됐는지)
- 시간대별 오픈 분포 (최적 발송 시간 추천)
- 수신거부율 추이
```

#### B-5. Webhook / 외부 연동

발송 완료, 오픈, 클릭, 수신거부 이벤트를 외부 시스템으로 전송.

```php
// 기존 이벤트 훅 확장
do_action('crmbiz_nl_email_opened',    $newsletterId, $email);
do_action('crmbiz_nl_email_clicked',   $newsletterId, $email, $url);
do_action('crmbiz_nl_unsubscribed',    $newsletterId, $email);
do_action('crmbiz_nl_send_completed',  $newsletterId, $stats);

// 관리자 UI에서 Webhook URL 등록 가능
// → Make.com, Zapier, n8n 연동
```

---

### Phase C — 스케일업 (v2.x, 장기 6~12개월)

> **목표:** 10,000명 이상 수신자, 다중 사이트 운영을 안정적으로 지원한다.

#### C-1. 대용량 발송 아키텍처

현재 배치 50건 × WP Cron은 수천 명 이상에서 한계가 있다.

```
단기 (v2.1): 배치 크기를 동적으로 조절 — 평균 발송 속도 측정 후 auto-tune
중기 (v2.2): 병렬 Cron 워커 — 여러 Cron 이벤트가 독립적으로 큐를 소비
장기 (v3.0): 전용 큐 워커 — Action Scheduler + Redis Queue 옵션
```

#### C-2. 멀티사이트 네트워크 지원 강화

현재 멀티사이트는 기본 동작하지만 네트워크 전체 통계, 사이트별 격리가 미흡.

```
- 네트워크 어드민에서 전체 발송 현황 대시보드
- 사이트별 발송 한도 설정 (네트워크 관리자)
- 공유 수신거부 목록 (네트워크 레벨 or 사이트 레벨 선택)
```

#### C-3. REST API 공개 (Developer API)

현재 REST API는 관리자 전용. 외부 앱이 뉴스레터를 프로그래밍으로 제어할 수 있도록.

```
신규 엔드포인트 (Application Password 인증):
POST /crmbiz-nl/v1/newsletters          — 뉴스레터 생성
GET  /crmbiz-nl/v1/subscribers          — 구독자 목록
POST /crmbiz-nl/v1/subscribers          — 구독자 추가
GET  /crmbiz-nl/v1/campaigns/{id}/stats — 캠페인 통계
```

#### C-4. WordPress.org 제출 준비

공개 배포를 위한 체크리스트:

```
코드:
□ PHPUnit deprecation 0개 (현재 ok)
□ PHP_CodeSniffer WPCS 100% 통과
□ JavaScript ESLint 0개 오류
□ 함수/클래스/옵션 이름 prefix 일관성 검토

UX:
□ 영문 번역 파일 (.po/.mo)
□ WordPress 플러그인 디렉터리 메타 (assets/banner, screenshot)
□ FAQ 문서화

보안:
□ HackerOne 또는 자체 보안 감사
□ WordPress Security Team 체크리스트 검토
```

---

### 고도화 판단 기준 — 언제 다음 Phase로?

각 Phase를 시작하기 전 확인한다.

```
Phase B 시작 전:
□ v1.x 버전이 2주 이상 프로덕션에서 안정적으로 동작
□ 사용자 피드백 수집 완료 (어떤 기능이 가장 필요한가?)
□ 테스트 커버리지 — PHPUnit 319개 전부 통과, E2E CI 안정 통과

Phase C 시작 전:
□ 실제 10,000명 이상 수신자를 가진 사용자 케이스 확인
□ Phase B 기능 중 사용률 상위 2개 이상 출시 및 검증 완료
□ 개발 리소스 확보 (Phase C는 B의 2배 공수)
```

---

### 기술 부채 추적 — 현재 상태 (v1.1.0 기준)

```markdown
## TODO (다음 이터레이션)

### v1.1.0에서 완료
- [x] getDashboard stats/chart 5분 캐시 (transient)
- [x] FluentCRM 비활성화 경로 큐 고아 수정
- [x] handleCleanup() 고아 큐 안전망 (JOIN DELETE)
- [x] idx_nl_email_type 커버링 인덱스 추가 (DB 2.1.0)

### v1.x 남은 것
- [ ] JavaScript ESLint 설정 추가
- [ ] EXPLAIN ANALYZE로 실제 느린 쿼리 프로파일링 (real MySQL with 1k+ rows)

### 영구 보류
- [~~FOR UPDATE SKIP LOCKED 큐 잠금~~] — GET_LOCK이 이미 올바름 (§14 참고)

### 중기 (v2.0)
- [ ] 이메일 템플릿 에디터 (Custom Post Type + 블록 제한)
- [ ] A/B 테스트 (variant/parent_id 컬럼, 승자 자동 선택)
- [ ] 구독 폼 숏코드 + double opt-in

### 장기 (v2.x+)
- [ ] 공개 REST API (Application Password)
- [ ] WordPress.org 제출 준비
- [ ] 대용량 병렬 워커
```

---

## 14. Phase A 실전 기록 — 안정화 (2026-06-03, v1.1.0)

> **무엇을 했나:** A-3(인덱스) → A-4(큐 정리) → A-1(캐싱) 순으로 구현.  
> A-2(GET_LOCK 교체)는 분석 후 영구 보류.  
> PHPUnit 319 → 322 (+3 캐시 테스트). DB 버전 2.0.0 → 2.1.0.

---

### 14-1. Phase 실행 순서를 바꾼 이유

로드맵에 적힌 순서(A-1 → A-2 → A-3 → A-4)와 실제 구현 순서(A-3 → A-4 → A-1)가 달랐다.

**규칙: 리스크가 낮고 가역성이 높은 것부터 먼저 한다.**

| 항목 | 리스크 | 가역성 | 실제 순서 |
|------|-------|-------|---------|
| A-3 인덱스 추가 | 낮음 (읽기 쿼리만 개선) | 높음 (DROP KEY 가능) | 1번 |
| A-4 큐 정리 | 낮음 (이미 삭제되어야 할 row) | 높음 (데이터 손실 없음) | 2번 |
| A-1 캐싱 | 중간 (무효화 누락 시 데이터 불일치) | 높음 (transient 삭제) | 3번 |
| A-2 잠금 교체 | **높음** (아키텍처 변경, 데이터 정합성 위험) | 낮음 | **보류** |

---

### 14-2. A-2 보류 — GET_LOCK이 이미 올바른 이유

로드맵에 "FOR UPDATE SKIP LOCKED로 병렬 처리"라고 썼지만, 구현 전 분석에서 잘못된 전제를 발견했다.

**전제 오류:**

```
로드맵 전제: "여러 워커가 같은 큐를 병렬로 처리하면 빠르다"
실제:        락 이름이 crmbiz_nl_send_{newsletterId} — 뉴스레터 단위 잠금
             → 서로 다른 뉴스레터는 이미 병렬 처리됨 (락이 겹치지 않음)
             → 같은 뉴스레터의 배치를 병렬 처리하면 이중 발송 발생
```

**SKIP LOCKED를 쓸 수 없는 근본 이유:**

```
FOR UPDATE SKIP LOCKED는 트랜잭션 내에서 행을 잠근 뒤,
처리 완료 후 COMMIT으로 잠금을 해제하는 패턴이다.

문제: sendFromRecord() 배치 루프 안에서 wp_mail()을 호출한다.
     SMTP 서버에 연결하는 외부 I/O가 수 초~수십 초 걸린다.
     트랜잭션을 열어둔 채 SMTP I/O를 처리하면:
     → MySQL 연결 타임아웃
     → 락 대기 중인 다른 쿼리 블로킹
     → 이중 발송보다 더 심각한 장애 가능

결론: 외부 I/O(이메일 발송)와 DB 트랜잭션을 섞으면 안 된다.
      GET_LOCK(세션 잠금)은 트랜잭션 없이 동작하므로 적합하다.
```

**GET_LOCK의 추가 장점:**

```php
// 세션 종료(PHP 프로세스 크래시) 시 MySQL이 자동으로 락 해제
// → 수동 RELEASE_LOCK 없이도 장애 복구 가능 (finally 블록은 보너스)
SELECT GET_LOCK('crmbiz_nl_send_42', 0);  // 이미 실행 중이면 즉시 0 반환
```

**향후 실제 병렬화가 필요해질 때 올바른 접근:**

```
지금: 뉴스레터 단위 GET_LOCK → 이미 여러 뉴스레터 병렬 처리 가능
Phase C: 같은 뉴스레터를 여러 배치로 쪼개려면 →
         queue 테이블에 claimed_at DATETIME NULL 컬럼 추가
         UPDATE queue SET claimed_at=NOW() WHERE newsletter_id=? AND claimed_at IS NULL LIMIT 50
         → 단일 원자적 UPDATE로 행 점유 (트랜잭션 불필요)
         이후 claimed_at이 5분 지난 행은 재처리 가능 (크래시 복구)
```

---

### 14-3. A-1 캐싱 — 무엇을 캐싱하고 무엇을 캐싱하지 않았나

`getDashboard()`에는 쿼리가 5개 있다. 모두 캐싱하지 않았다.

| 쿼리 | 캐싱 여부 | 이유 |
|------|---------|------|
| stats (COUNT+SUM on newsletters) | ✅ 5분 | 발송 완료/삭제 시에만 변함 |
| chart (일별 집계, days별) | ✅ 5분 | 발송 완료/삭제 시에만 변함 |
| campaigns (newsletters JOIN events) | ❌ | 오픈/클릭 추적 이벤트마다 변함 → 무효화 불가 |
| pending (status IN 집계) | ❌ | queued/sending 실시간 표시가 UX상 중요 |
| nextScheduled | ❌ | 실시간 필요 |

**캐싱 설계 원칙:**

```
1. 언제 변하는지 먼저 파악한다.
   "자주 바뀌는 데이터" vs "드물게 바뀌는 데이터"를 구분하지 않으면
   캐시가 있어도 의미 없거나, 있으면 오히려 해롭다.

2. 무효화 지점을 빠짐없이 열거한다.
   finalizeSend() ✓
   deleteNewsletter() ✓ (sent 레코드 삭제 시 total_nl 변화)
   forceDeleteNewsletter()? → deleteNewsletter()를 공유하므로 커버됨

3. 무효화 로직을 한 곳으로 모은다.
   RestApi::clearDashboardCache() — 어디서든 호출 가능한 static 헬퍼
```

---

### 14-4. transient 테스트 격리의 중요성

캐싱을 구현하자마자 PHPUnit에서 테스트 실패가 발생했다.

```
Failed asserting that 80.0 is identical to 0.
```

이 테스트는 "발송 완료 없으면 success_rate=0"을 검증하는데,  
직전 테스트가 stats transient을 채워놔서 캐시에서 80.0이 반환된 것이다.

**교훈: 전역 상태(transient, options, post_meta)는 setUp/tearDown에서 반드시 초기화한다.**

```php
// RestApiBusinessLogicTest::setUp()
$GLOBALS['_wp_transients'] = []; // ← 이 한 줄이 없으면 테스트 순서 의존성 생김

// RestApiBusinessLogicTest::tearDown()
$GLOBALS['_wp_transients'] = []; // ← tearDown도 필요 (다음 테스트 클래스로의 누출 방지)
```

**캐싱을 구현할 때마다 점검 목록:**

```
□ transient를 쓰는 코드를 추가했는가?
□ 해당 테스트 클래스의 setUp/tearDown에 _wp_transients = [] 추가했는가?
□ tearDown에도 추가했는가 (클래스 간 누출)?
□ clearDashboardCache() 직접 호출 테스트를 작성했는가?
```

---

### 14-5. 커버링 인덱스 설계 — idx_nl_email_type

`getNewsletterDetail()`이 실행하는 쿼리:

```sql
SELECT email,
       MAX(CASE WHEN type IN ('open','click') THEN 1 ELSE 0 END) AS opened,
       ...
FROM wp_crmbiz_nl_events
WHERE newsletter_id = %d
GROUP BY email
```

**기존 인덱스 `idx_nl_type (newsletter_id, type)`의 문제:**

```
1. newsletter_id로 범위를 좁힌다 → OK
2. type 필터링 (WHERE type IN ...) → idx_nl_type 활용
3. GROUP BY email → email이 인덱스에 없으므로 filesort 발생
   수신자 수가 클수록 filesort 비용이 선형 증가
```

**새 인덱스 `idx_nl_email_type (newsletter_id, email, type)`:**

```
1. newsletter_id로 범위 좁힘
2. email 순서로 이미 정렬 → GROUP BY email을 인덱스만으로 처리 (filesort 없음)
3. type도 인덱스에 있음 → CASE WHEN type IN ... 평가도 index scan
```

**마이그레이션 패턴 — idempotent 체크:**

```php
// 항상 이 패턴을 쓴다: 이미 있으면 스킵, 없으면 추가
$indexes = $wpdb->get_col("SHOW INDEX FROM {$wpdb->prefix}crmbiz_nl_events", 2);
if (is_array($indexes) && !in_array('idx_nl_email_type', $indexes, true)) {
    $wpdb->query("ALTER TABLE ... ADD KEY idx_nl_email_type (newsletter_id, email, type)");
}
```

이 패턴은 install()을 여러 번 실행해도 안전하다 (중복 인덱스 오류 없음).

---

### 14-6. Phase A 완료 체크리스트

```markdown
## Phase A 완료 확인 (v1.1.0)

✅ A-1 캐싱: stats/chart transient, clearDashboardCache(), 무효화 2곳
✅ A-3 인덱스: idx_nl_email_type DB 2.1.0, idempotent 마이그레이션
✅ A-4 큐 정리: FluentCRM 비활성화 경로 직접 fix, handleCleanup() 안전망
🚫 A-2 보류: GET_LOCK 교체 불가 (SMTP I/O + 트랜잭션 충돌). §14-2 참고.

PHPUnit: 322 tests / 565 assertions (0 failures)
E2E:     724 tests (CI 통과)
```

---

## 15. FluentCRM 연동 E2E 테스트 실전 기록 (2026-06-03, v1.1.0)

> **무엇을 했나:** `tests/e2e/specs/fluent-crm-integration.spec.js` 신규 작성.  
> FluentCRM v3.1.0 실환경 검증 4개 통과. 환경별 자동 분기 구조 완성.

---

### 15-1. 스펙 구조 — 4그룹 환경 분기

FluentCRM 설치 여부, CI/로컬 환경에 따라 실행 그룹이 자동으로 분기된다.

| 그룹 | 실행 조건 | 목적 |
|------|---------|------|
| **A** 실환경 검증 | 항상 (FCM 활성 감지 시 자동 실행) | FluentCRM 실제 연동 검증 |
| **B** 비활성화 안전 | 항상 (FCM 없을 때만 작동) | FluentCRM 없어도 플러그인 정상 동작 |
| **C** 스텁 배선 | `ENABLE_FLUENTCRM_STUB=1` | CI에서 연동 배선 단위 검증 |
| **D** Graceful degradation | `ENABLE_FLUENTCRM_STUB=1` | 비활성화↔활성화 전환 안전성 |

---

### 15-2. 핵심 발견 3가지

#### 발견 1: WordPress REST API는 X-WP-Nonce 헤더 필수

Playwright의 `request` 픽스처와 `page.request.get()`은 쿠키는 보내지만 `X-WP-Nonce` 헤더가 없어 **401**을 반환한다. WordPress 쿠키 인증은 CSRF 방지를 위해 nonce를 요구하기 때문이다.

```
잘못된 패턴 (401):
  const res = await request.get(`${API_BASE}/dashboard`)
  const res = await page.request.get(`${API_BASE}/dashboard`)

올바른 패턴:
  await page.goto(DASHBOARD)                          // 1. 페이지 로드 → CrmbizNL.nonce 확보
  await page.waitForSelector('.min-h-screen')
  const json = await page.evaluate(async (url) => {  // 2. 브라우저 컨텍스트에서 fetch
    const r = await fetch(url, {
      headers: { 'X-WP-Nonce': window.CrmbizNL?.nonce }
    })
    return r.json()
  }, `${apiBase}/dashboard`)
```

**핵심:** nonce는 `window.CrmbizNL.nonce` (대문자, REST API용 `wp_rest` nonce).  
`crmbizNl.nonce` (소문자)는 AJAX 전용 — 혼동 금지.

```php
// Plugin.php에서 localizing
wp_localize_script('crmbiz-nl-vue-dash', 'CrmbizNL', [
    'nonce' => wp_create_nonce('wp_rest'),  // ← REST API nonce
]);
```

#### 발견 2: Gutenberg이 Classic 메타박스를 hidden 컨테이너에 숨김

로컬 Gutenberg 환경에서 Classic 메타박스는 `#metaboxes` div 안에 있고 이 부모 컨테이너가 `class="hidden"`, `display: none`이다.

```
DOM 구조:
  <div id="metaboxes" class="hidden" style="display:none">  ← 부모가 display:none
    <div class="postbox" id="crmbiz_nl_metabox">
      <div class="inside">
        <div id="crmbiz-nl-metabox">  ← width:0, height:0
          ...
        </div>
      </div>
    </div>
  </div>
```

**결과:** 기존 `metabox.spec.js` 포함 **모든 메타박스 상호작용 테스트가 로컬에서 실패**한다. CI(Classic Editor 환경)에서만 통과한다.

**대응 패턴:**
```js
// 상호작용이 필요한 메타박스 테스트에 Gutenberg 감지 skip 추가
const gutenbergHidden = await page.evaluate(
  () => !!document.querySelector('#metaboxes.hidden')
)
test.skip(gutenbergHidden, 'Gutenberg hidden 모드 — CI Classic Editor 환경에서만 실행')
```

`toBeVisible()` 대신 `waitFor({ state: 'attached' })` 를 쓰면 Gutenberg hidden에서도 `attached`는 통과하지만, 이후 `check()` 등 상호작용이 60초 타임아웃으로 실패한다. **상호작용 없이 count()나 attached 확인만 하는 테스트는 로컬에서도 동작**한다.

#### 발견 3: beforeAll의 request 픽스처는 storageState 쿠키 없음

Playwright `test.beforeAll(async ({ request }) => { ... })` 안의 `request`는 브라우저 컨텍스트와 분리된 별도 `APIRequestContext`다. storageState 쿠키가 없어서 인증이 필요한 엔드포인트에 401을 받는다.

```js
// 잘못된 패턴 — beforeAll의 request는 401 반환
test.beforeAll(async ({ request }) => {
  const json = await (await request.get(`${API_BASE}/dashboard`)).json()
  fcAvailable = json.system?.fluent_crm === true  // 항상 false
})

// 올바른 패턴 — 각 테스트 내에서 page를 통해 확인
test('...', async ({ page }) => {
  const json = await fetchDashboard(page, API_BASE)  // page.evaluate로 인증된 fetch
  test.skip(json.system?.fluent_crm !== true, SKIP_MSG)
})
```

---

### 15-3. FluentCRM PHP 스텁 설계 원칙 (CI 전용)

실제 FluentCRM 없이 연동 배선을 테스트하는 최소 스텁.

**스텁 반환값 (의도적으로 실제와 다른 값 사용 — 스텁 히트 여부 확인용):**

| 호출 | 반환값 | 검증 대상 |
|------|--------|---------|
| `FluentCrmApi('contacts').getInstance().count()` | 10 | dashboard `contact_count` |
| `_StubFcTag::countByStatus()` | 5 | 태그 레이블 "스텁 태그 (5명)" |
| `_StubFcList::countByStatus()` | 3 | 리스트 레이블 "스텁 리스트 (3명)" |
| `ContactsQuery::getModel()->count()` | 8 | AJAX 수신자 수 |

**스텁 클래스 선언 규칙:**
```php
// 반드시 class_exists() 가드 — 실제 FluentCRM이 있으면 선언 건너뜀
if (!class_exists('_StubFcTag')) {
    class _StubFcTag { ... }
}

// PHP 네임스페이스 선언 시 String.raw 사용 (JS escape 방지)
const STUB_PHP = String.raw`<?php
namespace FluentCrm\App\Services {
    // \는 PHP 네임스페이스 구분자 — String.raw 없으면 JS가 escape sequence로 처리
}
`
```

---

### 15-4. 로컬 실행 결과 요약 (FluentCRM v3.1.0 활성 환경)

```
npx playwright test fluent-crm-integration --project=chromium

결과: 5 passed, 17 skipped, 0 failed

✅ Dashboard API — fluent_crm: true, contact_count ≥ 0
✅ Dashboard API — 필수 필드 구조 정상
✅ 대시보드 Vue 앱 — JS 에러 없이 정상 렌더
✅ 메타박스 — FluentCRM 비활성화 경고 없음 (count=0 검증)
- 그룹 B 5개: FCM 활성화 상태 → 올바르게 skip
- 그룹 A 메타박스 UI 3개: Gutenberg hidden → skip
- 그룹 C·D 9개: ENABLE_FLUENTCRM_STUB=1 없음 → skip
```

---

### 15-5. 향후 참고 — 로컬에서 메타박스 E2E 테스트하려면

Gutenberg 메타박스 상호작용이 필요한 테스트는 CI 환경(Classic Editor)에서만 실행 가능하다. 로컬에서 검증하려면 두 가지 방법이 있다:

```
방법 1: WordPress Classic Editor 플러그인 설치
  → Gutenberg 비활성화 → 메타박스가 직접 렌더됨 → 모든 테스트 통과

방법 2: Gutenberg 메타박스 패널 JavaScript로 강제 열기
  → page.evaluate(() => document.querySelector('#metaboxes').classList.remove('hidden'))
  → 이후 상호작용 가능 — 단, 다른 Gutenberg 상태에 의존할 수 있음
```

실제 운영 환경이 Gutenberg라면 **방법 1을 권장**한다.

---

### 15-6. 섹션 15 완료 체크리스트

```markdown
✅ fluent-crm-integration.spec.js 신규 작성 (4그룹 구조)
✅ 실환경 4개 테스트 통과 (FCM v3.1.0 + WP v7.0)
✅ WordPress REST API 인증 패턴 확립 (CrmbizNL.nonce + page.evaluate)
✅ Gutenberg hidden 메타박스 skip 패턴 문서화
✅ PHP 스텁 설계 패턴 (class_exists 가드, String.raw)
```

---

## 16. 서버 부하 E2E 테스트 실전 기록 (2026-06-03)

> **무엇을 했나:** `tests/e2e/specs/server-performance.spec.js` 신규 작성.  
> 10개 테스트 전부 통과. 현재 서버 부하 이상 없음 확인.

---

### 16-1. 테스트 구조

| 그룹 | 테스트 수 | 검증 내용 |
|------|---------|---------|
| API 응답 시간 | 4개 | 주요 엔드포인트 허용 범위 내 응답 |
| WP Cron 이중 발화 방지 | 2개 | GET_LOCK 효과, 상태 전환 안전성 |
| REST API 동시 요청 | 3개 | 병렬 요청 데이터 일관성 |

---

### 16-2. 실측 결과 (로컬, v1.1.0)

```
npx playwright test server-performance --project=chromium
결과: 10 passed, 17.3s

API 응답 시간:
  대시보드 API          ~400ms   (한도 2000ms)  ✅ 여유 있음
  뉴스레터 목록 API      993ms   (한도 1000ms)  ✅ 통과 — 주의 요망*
  대시보드 Vue 마운트    621ms   (한도 3000ms)  ✅ 여유 있음
  이력 Vue 마운트        621ms   (한도 3000ms)  ✅ 여유 있음

WP Cron 이중 발화:
  2회 연속 트리거 → success_count ≤ 1  ✅ GET_LOCK 정상
  sending 재트리거 → queued 복귀 없음   ✅ 상태 기계 정상

병렬 요청:
  대시보드 2회 동시 → stats 동일        ✅ transient 캐시 동작 확인
  목록 2회 동시 → total 동일            ✅
```

**\* 뉴스레터 목록 API 993ms**: 현재 이력 데이터가 수십 건 수준이라 통과했지만 한도 직전 수치. 이력 1,000건 이상 쌓이면 초과 가능성 있음. §13의 `EXPLAIN ANALYZE` 프로파일링 시점에 재확인 필요.

---

### 16-3. WP Cron 이중 발화 테스트 — 설계 원칙

단순 `post_id=0` 레코드로는 `sendFromRecord()`가 라인 88-91에서 즉시 `return false`(상태 변경 없음)한다. 유효한 처리가 일어나려면 실제 WordPress 포스트 + FluentCRM 태그 ID가 필요하다.

```js
// 잘못된 패턴 — post_id=0, tag_ids='' → sendFromRecord()가 상태 변경 없이 즉시 반환
wpdb->insert(..., ["post_id" => 0, "status" => "queued", "tag_ids" => ""])

// 올바른 패턴 — 실제 포스트 + 실제 태그 ID
const postId = wpEval(`echo wp_insert_post([...]);`)
nlId = wpEval(`
  $wpdb->insert(..., ["post_id" => ${postId}, "status" => "queued", "tag_ids" => "[1]"]);
`)
```

**afterEach 정리도 필수:**
```js
test.afterEach(() => {
  wpEval(`wp_delete_post(${postId}, true);`)  // 포스트 삭제
  wpEval(`$wpdb->delete(...newsletters, ["id" => ${nlId}])`)  // 레코드 삭제
})
```
포스트와 뉴스레터 레코드를 정리하지 않으면 `total_nl` stats 카운트가 오염된다.

---

### 16-4. REST API 병렬 요청 패턴

`page.request.get()`은 동시 요청 테스트에 사용할 수 없다 (§15-2: nonce 없어 401). `page.evaluate` 안에서 `Promise.all`로 병렬 fetch를 실행한다.

```js
const [res1, res2] = await page.evaluate(async ([url]) => {
  const nonce = window.CrmbizNL?.nonce
  const headers = { 'X-WP-Nonce': nonce }
  const [r1, r2] = await Promise.all([
    fetch(url, { headers }).then(r => r.json()),
    fetch(url, { headers }).then(r => r.json()),
  ])
  return [r1, r2]
}, [`${API_BASE}/dashboard`])
```

이 패턴은 같은 브라우저 탭에서 두 요청을 동시에 보내므로 서버 측 동시성을 검증할 수 있다.

---

### 16-5. 향후 부하 테스트 임계 조건

```
현재: 이력 수십 건 → 993ms
주의: 이력 1,000건 이상 → 뉴스레터 목록 API 1000ms 초과 가능성
조치: §13 TODO — EXPLAIN ANALYZE로 느린 쿼리 프로파일링
     (JOIN 쿼리 최적화 또는 per_page 기본값 조정)
```

---

## 17. WordPress 환경 안정성 E2E 테스트 실전 기록 (2026-06-03)

> **무엇을 했나:** `tests/e2e/specs/wordpress-stability.spec.js` 신규 작성.  
> 7개 테스트 전부 통과. WP Cron 상태 경고 배너 동작 완전 검증.

---

### 17-1. 검증 대상 — showCronNotice()

`Plugin::showCronNotice()`는 세 조건이 동시에 충족될 때 WP 어드민 경고 배너를 띄운다.

```
조건 1: 플러그인 전용 페이지
  → page 파라미터가 crmbiz-newsletter / crmbiz-nl-history / crmbiz-nl-settings 중 하나

조건 2: 대기 중인 뉴스레터 존재
  → crmbiz_newsletters 테이블의 status IN ('queued', 'sending') 개수 > 0

조건 3: Cron이 30분 이상 미실행 또는 한 번도 실행 안 됨
  → crmbiz_nl_last_cron_run = 0 ($never)
  → 또는 time() - crmbiz_nl_last_cron_run > 1800 ($stale)
```

세 조건 중 하나라도 빠지면 배너가 나타나지 않는다. 이 로직을 테스트로 완전히 커버했다.

---

### 17-2. 테스트 결과

```
npx playwright test wordpress-stability --project=chromium
결과: 7 passed, 13.5s

✅ 대기 뉴스레터 + Cron never → 경고 배너 표시
✅ 대기 뉴스레터 + Cron stale(33분) → 경고 배너 표시
✅ 대기 뉴스레터 없음 → 경고 없음
✅ Cron 최근 실행(5분 전) → 경고 없음
✅ 배너 dismiss 클릭 → 현재 페이지에서 사라짐
✅ 플러그인 외부 페이지(wp-admin/index.php) → 경고 없음
```

---

### 17-3. 테스트 격리 패턴 — beforeEach/afterEach 백업·복원

`crmbiz_nl_last_cron_run` 옵션을 직접 조작하므로 반드시 원래 값을 복원해야 한다. 그렇지 않으면 이 테스트가 실제 Cron 타임스탬프를 0으로 망가뜨려 다른 스펙에서 의도치 않은 경고 배너가 나타날 수 있다.

```js
test.beforeEach(() => {
  origLastRun = wpEval(`echo get_option("crmbiz_nl_last_cron_run", "0");`)
})

test.afterEach(() => {
  wpEval(`update_option("crmbiz_nl_last_cron_run", ${origLastRun}, false);`)
  // 테스트 뉴스레터 레코드도 제거
})
```

**규칙:** DB 옵션을 직접 조작하는 테스트는 반드시 beforeEach에서 백업, afterEach에서 복원한다.

---

### 17-4. WP Admin Notice 검증 패턴

WordPress 어드민 notice는 서버 사이드 렌더링이므로 `toBeVisible()`로 직접 확인할 수 있다. `page.evaluate`나 nonce 없이도 동작한다.

```js
// 경고 있어야 할 때
await expect(
  page.locator('.notice-warning:has-text("CRMBiz Newsletter")')
).toBeVisible({ timeout: 5_000 })

// 경고 없어야 할 때 — toBeHidden() 대신 count()로 즉시 확인
const warnCount = await page.locator('.notice-warning:has-text("CRMBiz Newsletter")').count()
expect(warnCount).toBe(0)

// dismiss 버튼
await notice.locator('.notice-dismiss').click()
await expect(notice).toBeHidden({ timeout: 3_000 })
```

`toBeHidden()` 대신 `count()` 를 쓰는 이유: 요소가 아예 DOM에 없을 때 `toBeHidden()`은 "없으면 hidden 취급"으로 즉시 통과하지만, count()가 더 명시적이다. 단, `toBeHidden()` 자체도 틀린 것은 아니다.

---

### 17-5. 세 스펙 파일 최종 현황 (§15~17)

| 스펙 파일 | 테스트 수 | 로컬 결과 |
|-----------|---------|---------|
| `fluent-crm-integration.spec.js` | 26개 | 5 pass / 17 skip |
| `server-performance.spec.js` | 10개 | 10 pass |
| `wordpress-stability.spec.js` | 7개 | 7 pass |
| **합계** | **43개** | **22 pass / 17 skip / 0 fail** |

skip 17개는 FluentCRM 스텁(ENABLE_FLUENTCRM_STUB=1 없음) 또는 Gutenberg hidden 모드로 인한 정상 skip이다. 실패 0개.
