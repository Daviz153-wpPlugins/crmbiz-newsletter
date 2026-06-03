# 개발자 플레이북 — 프로젝트 계획 수립 & 단계별 실행 가이드

> 주니어 개발자를 위한 실전 지침서.  
> "왜 이 순서인가"를 이해하면 어떤 프로젝트에도 적용할 수 있다.

---

## 목차

1. [이 문서가 생긴 이유](#1-이-문서가-생긴-이유)
2. [전문 개발자의 사고방식](#2-전문-개발자의-사고방식)
3. [프로젝트 5단계 프레임워크](#3-프로젝트-5단계-프레임워크)
4. [각 기능의 완성 기준 (Definition of Done)](#4-각-기능의-완성-기준-definition-of-done)
5. [버전 관리, 브랜치 & PR 워크플로우](#5-버전-관리-브랜치--pr-워크플로우)
6. [반드시 피해야 할 패턴](#6-반드시-피해야-할-패턴)
7. [프로젝트 시작 체크리스트](#7-프로젝트-시작-체크리스트)
8. [자주 쓰는 설계 질문 목록](#8-자주-쓰는-설계-질문-목록)
9. [테스트 전략 — PHPUnit (단위 테스트)](#9-테스트-전략--phpunit-단위-테스트)
10. [테스트 전략 — E2E & 실전 기록](#10-테스트-전략--e2e--실전-기록)
11. [CI/CD & 코드 품질](#11-cicd--코드-품질)
12. [플러그인 로드맵 & 기술 부채](#12-플러그인-로드맵--기술-부채)
13. [성능 & 호환성 실전 기록](#13-성능--호환성-실전-기록)

---

## 1. 이 문서가 생긴 이유

실제 프로젝트(crmbiz-newsletter, v0.1 → v1.2)를 돌아보면서 반복된 문제 패턴이 있었다.

```
feat: MVP 발송 구현              ← 기능 먼저
fix: MVP 보안 버그 4건           ← 보안 나중에
Fix O(n²) performance           ← 성능도 나중에
refactor(ui): Phase 1           ← UI 일관성도 나중에
```

**보안, 성능, UI 일관성이 전부 사후 수습이었다.**  
이 문서는 그 반성에서 시작한다.

---

## 2. 전문 개발자의 사고방식

### "뒤에서 앞으로 설계, 앞에서 뒤로 구현"

```
완성 상태를 먼저 그린다
    → 그걸 달성하려면 무엇이 필요한가?
        → 그 전에 무엇이 필요한가?
            → 이게 Phase 0의 출발점
```

### 나중에 고치기 어려운 순서

```
1위. DB 스키마          — 데이터가 쌓인 뒤 구조 변경은 마이그레이션 지옥
2위. 인증/보안 모델     — 나중에 끼워 넣으면 구조 자체를 바꿔야 함
3위. 핵심 아키텍처      — 레이어 분리, 의존성 방향
4위. 외부 API 계약      — 버전 바꾸면 연동된 것 모두 깨짐
5위. 퍼블릭 인터페이스  — 다른 코드가 의존하기 시작하면 변경 비용 폭증
```

### "임시"는 없다

> "일단 임시로 만들고 나중에 고치자"

이 생각이 기술 부채의 90%를 만든다. 임시 코드는 **반드시 영구 코드가 된다.**  
만들 시간이 없으면 → 처음부터 범위에 넣지 않는다.

---

## 3. 프로젝트 5단계 프레임워크

### Phase 0: Discovery & Design (착수 전)

> **목표:** 무엇을 만들지 명확히 한다. 코드는 한 줄도 쓰지 않는다.

**문제 정의** → 누가, 어떤 상황에서, 무엇이 불편한가?

**성공 기준 (측정 가능하게)**
```
나쁜 예: "빠르게 작동한다"
좋은 예: "1,000명 수신자에게 5분 내에 발송 완료"
```

**범위 결정 — Out-of-scope를 명시하는 것이 더 중요하다**
```markdown
## 이번 버전에서 만드는 것
- [ ] 기능 A

## 이번 버전에서 만들지 않는 것
- 기능 B (다음 버전)
```

**기술 스택 결정 (근거 있게)**
```markdown
| 항목     | 선택      | 이유                        | 대안과 비교           |
|--------|---------|---------------------------|------------------|
| 언어     | PHP 8.2 | WordPress 플러그인 필수          | —                |
| 프론트엔드  | Vue 3   | 반응형 UI 필요                  | React (학습 비용 높음) |
| 이메일 발송 | FluentCRM | 이미 설치된 의존성 활용          | 자체 구현 (과도한 범위)  |
```

**보안 요구사항**
- 어떤 데이터가 민감한가? 누가 무엇에 접근할 수 있나?
- 외부에 노출되는 엔드포인트는? 어떤 보안 메커니즘이 필요한가?

---

### Phase 1: Foundation (기반 구축)

> **목표:** 나중에 고치기 어려운 것들을 먼저 잡는다.

**이 순서로 진행한다:**

1. **프로젝트 구조 확정**
   ```
   src/       핵심 비즈니스 로직 (프레임워크 의존성 없게)
   src/Admin/ 관리자 화면
   templates/ 뷰 레이어
   tests/     테스트 (이 시점에 만든다)
   ```

2. **설정 파일** — `.gitignore`, `.editorconfig`, `composer.json`, `package.json`, CI/CD

3. **DB 스키마 & 마이그레이션** — 실제 SQL 수준까지. 이후 변경은 마이그레이션으로만.

4. **보안 레이어** — nonce, 권한 확인 위치, CSRF 전략

5. **에러 처리 & 로깅** — 사용자에게 보여주는 에러 vs 내부 로그 분리

6. **테스트 환경** — 첫 테스트 1개라도 통과하는 상태, CI 자동 실행 연결

---

### Phase 2: Core MVP (핵심 기능)

> **목표:** 가장 중요한 기능 1개를 **완전하게** 만든다.

각 기능을 만들 때 이 순서:
```
1. 인터페이스 먼저 정의 (함수 시그니처)
2. 테스트 작성 (아직 구현 없음, 실패 상태)
3. 구현
4. 테스트 통과
5. 입력 검증 & 권한 확인 추가
6. 에러 처리 추가
```

```
나쁜 예:
  commit: feat: add delete handler
  commit: fix: add nonce check       ← 보안이 뒤에

좋은 예:
  commit: feat: newsletter delete — handler, AJAX, button, nonce, test
```

---

### Phase 3: Iteration (반복 확장)

**기능 추가 전 확인**
```
□ 현재 기능이 안정적인가? (최근 2주 버그가 없었나?)
□ 테스트가 충분한가?
□ 성능이 수용 가능한가?
→ 세 가지 모두 Yes일 때 다음 기능으로
```

**리팩토링과 기능 추가를 섞지 않는다.** 하나의 PR/커밋은 하나의 목적만.

---

### Phase 4: Polish & Release (완성도)

```markdown
**성능**
- [ ] 추측 말고 측정 (EXPLAIN ANALYZE)
- [ ] N+1 쿼리 없는지 확인

**문서화 & 릴리즈**
- [ ] README, CHANGELOG 최신화
- [ ] 버전 번호 확정 (Semantic Versioning)
- [ ] git tag v1.0.0
```

---

## 4. 각 기능의 완성 기준 (Definition of Done)

```
□ Happy path 작동한다
□ Edge case 처리된다
  - null, 빈 값, 0, 음수
  - 권한 없는 사용자가 접근하면?
  - 동시에 두 번 클릭하면?
□ 보안 검토 완료
  - 입력 검증, SQL 인젝션, XSS, CSRF(nonce), 권한 확인
□ 에러 처리
  - 사용자에게 의미 있는 에러 메시지
  - 내부 로그에 디버깅 가능한 정보
□ 테스트 1개 이상 통과
□ 코드를 다시 읽었을 때 이해된다
```

이 중 하나라도 빠지면 → 완성이 아니다.

---

## 5. 버전 관리, 브랜치 & PR 워크플로우

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
  ci       CI/CD 설정
```

**좋은 예:**
```
feat: batch send with WP Cron — prevents timeout on large lists
fix: guard null in finalizeSend() — fatal error when record deleted mid-send
```

**나쁜 예:** `fix: bug fix` / `update: changes`

### Semantic Versioning

```
PATCH (0.0.x): 버그 수정 — 기존 사용자 영향 없음
MINOR (0.x.0): 새 기능 추가 — 하위 호환 유지
MAJOR (x.0.0): 구조 변경 — 하위 비호환, 마이그레이션 필요
```

### 브랜치 명명 규칙

```
main        — 언제나 배포 가능한 상태 (직접 커밋 금지)
feature/*   — 새 기능 추가
fix/*       — 버그 수정
hotfix/*    — 배포된 버전 긴급 수정
docs/*      — 문서만 수정
```

### PR 워크플로우 — 매번 이 순서로

```
1. 브랜치 생성
   git checkout -b fix/버그명

2. 작업 후 커밋
   git add <파일>
   git commit -m "fix: 설명"

3. GitHub에 push
   git push origin fix/버그명

4. PR 생성 (Claude가 대신 실행)
   gh pr create --title "fix: 설명" --body "..."

5. GitHub 웹사이트에서 Merge pull request 클릭

6. 로컬 main 업데이트
   git checkout main && git pull origin main
```

**CI 결과 해석:**
- 초록 체크(✅) → 통과, Merge 가능
- 노란 점(🟡) → 검사 진행 중
- 빨간 X(❌) → 실패, 코드 수정 필요

---

## 6. 반드시 피해야 할 패턴

### Anti-Pattern 1: 기능 폭포 (Feature Cascade)
```
feat: add button → feat: add handler → fix: add nonce  ← 보안이 마지막
```
→ **대신:** 기능 하나를 처음부터 끝까지 완성하고 다음으로 넘어간다.

### Anti-Pattern 2: 무한 리팩토링
```
refactor: Phase 1 → refactor: Phase 2 → refactor: Phase 3...
```
→ **대신:** 처음에 CSS 설계 시스템(디자인 토큰, 클래스 규칙)을 잡는다.

### Anti-Pattern 3: 임시 코드
```php
// TODO: 나중에 고치자
$sql = "SELECT * FROM " . $table;  // 일단 다 가져옴
```
→ **대신:** 만들 시간이 없으면 그 기능은 이번 버전에서 빼라.

### Anti-Pattern 4: 범위 크리프 (Scope Creep)
> "어차피 여기까지 했으니 이것도 추가하자"  
→ **대신:** Out-of-scope 목록을 문서로 관리하고, 이번 버전은 계획대로 완료한다.

### Anti-Pattern 5: 테스트 없는 리팩토링
→ **대신:** 리팩토링 전에 기존 동작을 테스트로 고정하고, 후에 테스트 통과 확인.

---

## 7. 프로젝트 시작 체크리스트

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
- [ ] 보안 요구사항 목록
```

### 첫 코드 작성 시

```markdown
## Foundation
- [ ] 폴더 구조 확정
- [ ] .gitignore, .editorconfig
- [ ] 패키지 매니저 설정
- [ ] 테스트 환경 구축 (첫 테스트 1개 통과 상태)
- [ ] CI/CD 기본 구성 (lint + test 자동 실행)
- [ ] DB 마이그레이션 코드
- [ ] 에러 로깅 유틸리티
- [ ] 보안 레이어 (nonce, 권한 확인 위치)
```

### 각 기능 구현 시

```markdown
## Feature Checklist
- [ ] 인터페이스 먼저 정의
- [ ] 테스트 작성 (구현 전)
- [ ] 구현 → 테스트 통과
- [ ] 입력 검증, 권한 확인, 에러 처리 추가
- [ ] Happy path & Edge case 직접 테스트
```

### 릴리즈 전

```markdown
## Release Checklist
- [ ] 모든 테스트 통과
- [ ] 성능 측정 (프로파일링)
- [ ] 보안 리뷰 (입력 검증, SQL, XSS, CSRF)
- [ ] README & CHANGELOG 최신화
- [ ] 버전 번호 확정 → git tag
```

---

## 8. 자주 쓰는 설계 질문 목록

### 데이터
- 어떤 데이터가 민감한가? 1년 후 레코드 수는?
- 어떤 쿼리가 자주 실행되나? 인덱스가 필요한가?
- 스키마가 바뀌면 기존 데이터는 어떻게 되나?

### 보안
- 외부에서 접근 가능한 엔드포인트는 어디인가?
- 사용자 입력이 어디에서 어디로 흐르나?
- 어떤 값을 암호화/해시해야 하나?

### 성능
- 가장 자주 실행되는 코드 경로는?
- 외부 API 호출이 있는가? 실패하면 어떻게 되나?
- 대량 처리가 필요한 작업이 있는가?

### 에러
- 각 컴포넌트가 실패하면 사용자 경험은?
- 재시도 로직이 필요한 작업은?

### 유지보수
- 6개월 후 다른 개발자가 이 코드를 보면 이해할 수 있나?
- 의존하는 외부 라이브러리가 사라지면 어떻게 되나?

---

## 9. 테스트 전략 — PHPUnit (단위 테스트)

> WordPress 플러그인에는 두 종류의 테스트가 필요하다.

### 9-1. 두 가지 테스트 레이어

| 검증 대상 | 사용할 테스트 |
|---------|-----------|
| 함수 로직, 계산, 검증 | PHPUnit |
| DB 쿼리 결과 | PHPUnit (WpdbStub) |
| 보안 토큰, HMAC | PHPUnit |
| 페이지가 렌더되는가 | Playwright |
| 버튼 클릭 → 결과 | Playwright |
| API → UI 연동 | Playwright |

```
PHPUnit: WordPress 없이 인메모리로 실행. 빠름 (전체 ~0.02초)
E2E:     실제 브라우저 + 실제 WordPress. 느림 (~17초)
```

### 9-2. WordPress 스텁 패턴 (`tests/bootstrap.php`)

WordPress 함수들은 실제 WP 환경 없이 스텁으로 대체한다.

```php
// 핵심 원칙: bootstrap.php에 스텁을 모아둔다
define('ABSPATH', dirname(__DIR__) . '/');

function get_option(string $key, $default = false) {
    return $GLOBALS['_wp_options'][$key] ?? $default;
}
function current_time(string $type): string {
    return date('Y-m-d H:i:s');
}

// $wpdb 스텁 — 인메모리 배열로 구현
class WpdbStub {
    public string $prefix = 'wp_';
    public int $insert_id = 0;      // ← 반드시 public
    private int $last_insert_id = 0;

    public function insert(string $table, array $data, array $formats = []): int {
        $id = count($GLOBALS['_wpdb_newsletters']) + 1;
        $data['id'] = $id;
        $GLOBALS['_wpdb_newsletters'][] = $data;
        $this->last_insert_id = $id;
        $this->insert_id      = $id; // ← 두 곳 모두 동기화
        return 1;
    }
}
$GLOBALS['wpdb'] = new WpdbStub();
```

**자주 빠뜨리는 스텁:**
- `$wpdb->insert_id` → WpdbStub에 `public int $insert_id` 필요
- `wp_timezone()` → `new DateTimeZone('Asia/Seoul')` 반환
- `apply_filters()` → 첫 번째 인자 그대로 반환

### 9-3. WpdbStub 서브클래스 패턴 (복잡한 쿼리)

`bootstrap.php`의 WpdbStub은 전체 테스트가 공유하므로 건드리지 않는다.  
새 쿼리 패턴이 필요하면 **테스트 파일 안에서 서브클래스**를 만든다.

```php
class RestApiWpdbStub extends WpdbStub {
    public function get_results(string $sql, string $output = 'OBJECT'): array {
        if (preg_match("/crmbiz_newsletters WHERE id IN \(([0-9, ]+)\)/i", $sql, $m)) {
            $ids = array_map('intval', explode(',', $m[1]));
            $rows = array_filter(
                $GLOBALS['_wpdb_newsletters'],
                fn($r) => in_array((int)($r['id'] ?? 0), $ids, true)
            );
            return array_map(fn($r) => (object)$r, array_values($rows));
        }
        return parent::get_results($sql, $output);
    }
}

// setUp()에서 교체, tearDown()에서 복원
$GLOBALS['wpdb'] = new RestApiWpdbStub();
```

### 9-4. 코드 패턴별 주의사항

**Reflection API (PHP 8.1+)**
```php
// PHP 8.1+ 부터 setAccessible(true) 불필요 (8.5에서 deprecated)
$prop = $ref->getProperty('settings');
$prop->setValue($obj, $val);  // setAccessible 없어도 동작
```

**싱글톤 테스트**
```php
protected function setUp(): void {
    $GLOBALS['_wp_options'][Database::DB_VERSION_OPTION] = Database::DB_VERSION;
    $this->plugin = Plugin::getInstance();
}

protected function tearDown(): void {
    (new \ReflectionClass(Plugin::class))
        ->getProperty('instance')
        ->setValue(null, null); // 다음 테스트에서 새 인스턴스 생성 가능
}
```

**날짜/시각 비교 — 버퍼 넉넉하게**
```php
// 나쁜 예 — 시차(최대 14시간)로 과거가 될 수 있음
$future = date('Y-m-d H:i:s', time() + 3600);

// 좋은 예 — 30일 여유
$future = date('Y-m-d H:i:s', time() + 86400 * 30);
```

**Transient 테스트 격리 필수**
```php
// 캐싱 구현 후 반드시 setUp/tearDown에 추가
protected function setUp(): void {
    $GLOBALS['_wp_transients'] = []; // ← 없으면 테스트 순서 의존성 생김
}
protected function tearDown(): void {
    $GLOBALS['_wp_transients'] = []; // ← 다음 클래스로의 누출 방지
}
```

**타임존 혼용 금지 (WordPress + MySQL)**
```php
// 나쁜 예: MySQL NOW()는 서버 timezone, sent_at은 WP 로컬
"SELECT ... WHERE sent_at >= DATE_SUB(NOW(), INTERVAL %d DAY)"

// 좋은 예: PHP에서 WP timezone 기준으로 계산
$since = date('Y-m-d 00:00:00', current_time('timestamp') - ($days * DAY_IN_SECONDS));
"SELECT ... WHERE sent_at >= %s"
```
**규칙:** `current_time('mysql')`로 저장한 컬럼은 `current_time('timestamp')` 기준으로 범위 계산. `NOW()`와 섞지 않는다.

**DI 우회 금지**
```php
// 나쁜 예: 생성자 주입 있는데 내부에서 또 new
public function forceSend(): void {
    $settings = new Settings();  // ← DI 우회
    (new NewsletterSender($settings))->send();
}

// 좋은 예
public function __construct(private Settings $settings) {}
public function forceSend(): void {
    (new NewsletterSender($this->settings))->send();
}
```

### 9-5. 상태 전환 테스트 설계

**핵심:** API 메서드마다 허용/차단 상태를 *전부* 테스트한다.

```
send        : draft만 허용 → queued      (나머지 → 400)
cancel      : queued/sending/scheduled → cancelled  (나머지 → 400)
force-send  : queued/sending만 허용     (나머지 → 400)
delete      : sending이면 차단          (나머지 → 200)
```

```php
// 반환값만 확인하지 말고 DB에도 반영됐는지 확인
$sendRes = $this->api->sendNewsletter($req);
$this->assertSame('queued', $GLOBALS['_wpdb_newsletters'][0]['status']); // DB 확인
```

### 9-6. 이메일 헤더 인젝션 테스트

```
공격: "Legit Name\r\nBcc: attacker@evil.com"
→ 방어 안 되면: SMTP가 Bcc: 를 별도 헤더로 해석
```

```php
// 나쁜 어설션 — str_replace 후에도 Bcc: 텍스트는 남을 수 있음
$this->assertStringNotContainsString('Bcc:', $fromHeader);

// 올바른 어설션 — 방어의 본질은 CRLF 제거
$this->assertStringNotContainsString("\r\n", $fromHeader);
$this->assertStringNotContainsString("\r",   $fromHeader);
$this->assertStringNotContainsString("\n",   $fromHeader);
```

새 발송 경로 추가 시마다 `str_replace(["\r", "\n"], '', $fromName)` 반드시 적용.

### 9-7. PHPUnit 마이그레이션 — `@dataProvider` → `#[DataProvider]`

```php
// 나쁜 예 (PHPUnit 11 deprecation 경고)
/** @dataProvider nonDraftStatuses */
public function test_send_non_draft(): void { ... }

// 좋은 예
use PHPUnit\Framework\Attributes\DataProvider;

#[DataProvider('nonDraftStatuses')]
public function test_send_non_draft(): void { ... }
```

### 9-8. 테스트 우선순위 — 이 순서로

```bash
# 즉시 (코드 수정 직후 항상)
./vendor/bin/phpunit   # 전체, 0.02초

# 새 API 엔드포인트 추가 시
# 1. 상태 전환 테스트 (허용/차단 전부)
# 2. 비존재 ID 케이스 (9999 → 404)

# 이메일 발송 경로 추가/수정 시
# 3. 헤더 인젝션 4패턴 (\r\n, \n, \r, 다중)

# 보안 코드 수정 시
# 4. 토큰/HMAC 테스트
# 5. 권한 확인 테스트 (current_user_can false → wp_die)

# 대규모 변경(리팩토링) 후
# 6. 전체 PHPUnit → E2E 순서로
npx playwright test --project=chromium
npx playwright test  # 전체 브라우저
```

**최소 기준 (빠른 작업 시):**
```
□ 성공 케이스 1개 (happy path)
□ 실패 케이스 1개 (없는 ID 또는 잘못된 입력)
□ DB 상태 변경이 있는 경우 — 변경 전/후 직접 확인
```

---

## 10. 테스트 전략 — E2E & 실전 기록

### 10-1. 이미 커버된 E2E 영역

| 스펙 파일 | 커버 범위 |
|-----------|-----------|
| `access-control.spec.js` | 비인증 REST 403 / 로그인 리다이렉트 |
| `accessibility.spec.js` | WCAG 2.1 AA, tab 네비게이션 |
| `dashboard.spec.js` | 페이지 로드, 차트 토글, 페이지네이션 |
| `email-template.spec.js` | 미리보기, 다크/라이트 테마 |
| `error-recovery.spec.js` | API 500 / 네트워크 오류 시 빈 화면 없음 |
| `history-actions.spec.js` | 슬라이드오버, send/cancel/resend, 토스트 |
| `lifecycle.spec.js` | 활성화·비활성화·삭제, DB 마이그레이션 |
| `metabox.spec.js` | 메타박스 렌더, 체크박스, 예약 입력 |
| `pagination.spec.js` | per-page 선택, 다음/이전 버튼 |
| `post-publish.spec.js` | 발행 → 레코드 생성 |
| `public-endpoints.spec.js` | 트래킹 픽셀, 클릭 스킴, 수신거부 구조 |
| `settings.spec.js` | 설정 저장 성공 |
| `unsubscribers.spec.js` | 수신거부 관리 UI |
| `fluent-crm-integration.spec.js` | FCM 연동 (4그룹 환경 분기) |
| `server-performance.spec.js` | API 응답 시간, Cron 이중 발화 방지 |
| `wordpress-stability.spec.js` | WP Cron 경고 배너 3조건 |
| `migration.spec.js` | 업그레이드 경로 14개 |

### 10-2. 핵심 E2E 패턴

**baseURL — 가장 흔한 실수**
```js
// playwright.config.js
baseURL: 'http://localhost:8888/wordpress/',  // ← 반드시 trailing slash

// spec 파일
const DASHBOARD = 'wp-admin/admin.php?page=crmbiz-newsletter'  // ← leading slash 없음
await page.goto(DASHBOARD)
```

**로그인 상태 재사용 (storageState)**
```js
// tests/e2e/auth.setup.js
setup('WP 관리자 로그인 저장', async ({ page }) => {
    await page.goto(base + '/wp-login.php', { waitUntil: 'domcontentloaded', timeout: 60_000 })
    await page.fill('#user_login', process.env.WP_ADMIN_USER)
    await page.fill('#user_pass',  process.env.WP_ADMIN_PASS)
    await page.click('#wp-submit')
    await page.waitForURL(/wp-admin/, { timeout: 30_000 })
    await page.context().storageState({ path: 'tests/e2e/.auth/admin.json' })
})
```

**Soft Assertion Anti-Pattern**
```js
// 나쁜 예 — 데이터가 없어도 통과
if (await link.count() > 0) { await link.click() }

// 좋은 예
await expect(link).toBeVisible()
await link.click()
await expect(page).toHaveURL(/history/)
```

**WordPress REST API 인증 — X-WP-Nonce 필수**
```js
// request 픽스처는 쿠키는 보내지만 nonce가 없어 401 반환
// 올바른 방법: page.evaluate 안에서 fetch
const json = await page.evaluate(async (url) => {
    const r = await fetch(url, {
        headers: { 'X-WP-Nonce': window.CrmbizNL?.nonce }
    })
    return r.json()
}, `${apiBase}/dashboard`)
```
`CrmbizNL.nonce` = REST API용 (대문자). `crmbizNl.nonce` (소문자) = AJAX 전용. 혼동 금지.

**Gutenberg hidden 메타박스 skip**
```js
const gutenbergHidden = await page.evaluate(
    () => !!document.querySelector('#metaboxes.hidden')
)
test.skip(gutenbergHidden, 'Gutenberg hidden 모드 — CI Classic Editor 환경에서만 실행')
```

**beforeAll의 request 픽스처는 storageState 쿠키 없음**
```js
// 나쁜 패턴 — beforeAll의 request는 401 반환
test.beforeAll(async ({ request }) => {
    const json = await (await request.get(`${API_BASE}/dashboard`)).json()
})

// 올바른 패턴 — 각 테스트 내에서 page.evaluate 사용
test('...', async ({ page }) => {
    const json = await fetchDashboard(page, API_BASE)
})
```

**REST API 병렬 요청 패턴**
```js
const [res1, res2] = await page.evaluate(async ([url]) => {
    const nonce = window.CrmbizNL?.nonce
    const [r1, r2] = await Promise.all([
        fetch(url, { headers: { 'X-WP-Nonce': nonce } }).then(r => r.json()),
        fetch(url, { headers: { 'X-WP-Nonce': nonce } }).then(r => r.json()),
    ])
    return [r1, r2]
}, [`${API_BASE}/dashboard`])
```

### 10-3. wpEval 사용 규칙

```js
// 나쁜 예 — Deprecated 경고 필터 없음
return execSync(`wp eval '...' --path=${WP_PATH}`, { encoding: 'utf-8' }).trim()

// 올바른 예
return execSync(`wp eval '...' --path=${WP_PATH}`, { encoding: 'utf-8' })
    .trim()
    .replace(/^(PHP Deprecated|Deprecated):.*\n?/gm, '')
    .trim()
```

**wpEval 안에 `//` 주석 금지:**  
`replace(/\s+/g, ' ')`로 평탄화할 때 `//` 뒤 코드가 모두 주석 처리된다.  
설명이 필요하면 JS 변수명 또는 JS 주석(템플릿 리터럴 바깥)을 사용한다.

**wp eval마다 Plugin::init()이 실행된다 — 버전 업그레이드 테스트 활용**
```js
// 버전 낮추기 + 인덱스 삭제를 같은 wp eval에서 (Plugin::init() 재실행 방지)
wpEval(`
    $wpdb->query("ALTER TABLE ... DROP KEY idx_nl_email_type");
    update_option('crmbiz_nl_db_version', '2.0.0');
`)
// 다음 wp eval에서 Plugin::init() → 버전 불일치 → install() 자동 실행
const versionAfter = wpEval(`echo get_option('crmbiz_nl_db_version');`) // '2.1.0'
```

**SHOW INDEX — 복합 인덱스는 컬럼마다 한 행**
```js
// idx_nl_email_type (newsletter_id, email, type) → 3컬럼 = 3행
expect(indexCount).toBe(3)  // newsletter_id + email + type
```

### 10-4. DB 옵션 직접 조작 테스트 격리

```js
test.beforeEach(() => {
    origLastRun = wpEval(`echo get_option("crmbiz_nl_last_cron_run", "0");`)
})

test.afterEach(() => {
    wpEval(`update_option("crmbiz_nl_last_cron_run", ${origLastRun}, false);`)
})
```
**규칙:** DB 옵션을 직접 조작하는 테스트는 반드시 beforeEach 백업, afterEach 복원.

### 10-5. 우선순위 높은 미완성 E2E

| 우선순위 | 작업 | 파일 |
|---------|------|------|
| 🔴 P1 | 수신거부 완전 흐름 (토큰 → 완료 → DB 확인) | `unsubscribe-flow.spec.js` 신규 |
| 🔴 P1 | 오픈/클릭 트래킹 카운트 반영 | `public-endpoints.spec.js` 추가 |
| 🔴 P1 | 발송 진행률 폴링 UI | `history-progress.spec.js` 신규 |
| 🟡 P2 | 이력 필터/검색 결과 검증 | `history.spec.js` 추가 |
| 🟡 P2 | 설정 저장 → API 반영 확인 | `settings.spec.js` 추가 |
| 🟠 P3 | 수신거부 Rate Limit (11번째 → 429) | `public-endpoints.spec.js` 추가 |
| 🔵 P4 | 모바일 반응형 (iPhone 14) | `responsive.spec.js` 신규 |

---

## 11. CI/CD & 코드 품질

### 11-1. GitHub Actions 현황

이 프로젝트의 CI는 PR 생성 및 main push 시 자동 실행된다.

```
.github/workflows/
├── ci.yml       — PHP 문법 검사 + PHPUnit + Vue 빌드 (신규, 2026-06-04)
├── test.yml     — PHP 단위 테스트 (기존)
├── e2e.yml      — Playwright E2E (MySQL + WP 환경, 최대 20분)
└── release.yml  — 릴리즈 자동화
```

**ci.yml 구조 (3단계):**
```yaml
jobs:
  php-syntax:   # PHP 8.2 문법 오류 검사 (화이트스크린 방지)
  phpunit:      # composer install → vendor/bin/phpunit
  vue-build:    # npm ci → npm run build
```

**E2E 테스트가 오래 걸리는 이유:**  
MySQL → WordPress 설치 → 플러그인 설치 → 시딩 → 서버 기동 → 브라우저 테스트  
총 20분 타임아웃. PR Merge 시 이전 실행이 자동 취소(cancelled)되는 것은 정상이다.

### 11-2. E2E CI 핵심 설정

**MySQL 서비스 컨테이너 — 포트 매핑 필수**
```yaml
services:
  mysql:
    image: mysql:8.0
    ports:
      - 3306:3306  # ← 없으면 127.0.0.1로 접근 불가
    options: >-
      --health-cmd="mysqladmin ping -h 127.0.0.1 -u root -proot"
      --health-interval=10s
```

**WordPress 설치 후 퍼머링크 활성화 필수**
```yaml
- run: |
    wp core install ...
    wp rewrite structure '/%postname%/' --hard --path=/tmp/wordpress
    wp rewrite flush --hard --path=/tmp/wordpress
    # ← 없으면 REST API(/wp-json/...)가 404 반환
```

**`wp server` 사용 (php -S 금지)**
```yaml
- run: |
    wp server --host=localhost --port=8080 --path=/tmp/wordpress &
    timeout 15 bash -c 'until curl -sf http://localhost:8080/wp-login.php; do sleep 1; done'
```

**시딩 주의:** 기본 per-page보다 1개 더 시딩해야 페이지네이션 UI가 렌더된다.

**Node.js 경고 제거:**
```yaml
env:
  FORCE_JAVASCRIPT_ACTIONS_TO_NODE24: true  # 모든 workflow yml에 각각 추가
```

### 11-3. ESLint 설정 (v1.2.0, 2026-06-03)

```js
// eslint.config.js — flat config, Vue3 + E2E 분리
const vueConfigs = pluginVue.configs['flat/essential'].map(cfg => ({
    ...cfg,
    files: ['resources/js/**/*.{js,vue}'],
}))

export default [
    ...vueConfigs,
    {
        files: ['resources/js/**/*.{js,vue}'],
        rules: {
            'no-unused-vars': ['error', { argsIgnorePattern: '^_' }],
            'no-empty': ['error', { allowEmptyCatch: true }],
        },
    },
    {
        files: ['tests/e2e/**/*.js'],
        languageOptions: {
            globals: {
                ...globals.node,
                window: 'readonly',    // page.evaluate() 안 브라우저 globals
                document: 'readonly',
            },
        },
    },
]
```

**`package.json`에 `"type": "module"` 추가 필수** — 없으면 CommonJS 파싱 오류.

**`flat/essential` 선택 이유:** `recommended`는 포맷팅 규칙까지 포함해 기존 코드에서 수백 건 경고. `essential`은 버그 유발 규칙만 포함.

---

## 12. 플러그인 로드맵 & 기술 부채

> 작성 기준: v1.2.1 (2026-06-04)

### 12-1. 기술 부채 현황

```markdown
## v1.1.0에서 완료
- [x] getDashboard stats/chart 5분 캐시 (transient)
- [x] FluentCRM 비활성화 경로 큐 고아 수정
- [x] idx_nl_email_type 커버링 인덱스 추가 (DB 2.1.0)

## v1.2.0에서 완료
- [x] ESLint 설정 + CI job
- [x] 데드코드 제거 (fluent_crm 필터)
- [x] 배치 크기 50 → 30 (공유 호스팅 30초 한도 안전 마진)

## v1.x 남은 것
- [ ] 실 SMTP(SendGrid/SES) 환경에서 배치 시간 측정

## 영구 보류
- [~~FOR UPDATE SKIP LOCKED~~] GET_LOCK이 이미 올바름 (아래 참고)

## 중기 (v2.0)
- [ ] 이메일 템플릿 에디터 (Custom Post Type + 블록 제한)
- [ ] A/B 테스트 (variant/parent_id 컬럼, 승자 자동 선택)
- [ ] 구독 폼 숏코드 + double opt-in

## 장기 (v2.x+)
- [ ] 공개 REST API (Application Password)
- [ ] WordPress.org 제출 준비
```

### 12-2. GET_LOCK을 FOR UPDATE SKIP LOCKED로 교체하면 안 되는 이유

```
로드맵 전제: "여러 워커가 병렬 처리하면 빠르다"
실제:        락 이름이 crmbiz_nl_send_{newsletterId} — 뉴스레터 단위 잠금
             → 서로 다른 뉴스레터는 이미 병렬 처리됨
             → 같은 뉴스레터를 병렬 처리하면 이중 발송 발생

FOR UPDATE SKIP LOCKED의 문제:
  트랜잭션 안에서 wp_mail() (외부 SMTP I/O, 수 초~수십 초) 를 호출해야 함
  → MySQL 연결 타임아웃 → 락 대기 블로킹 → 이중 발송보다 심각한 장애
  
GET_LOCK의 장점:
  세션 종료(PHP 크래시) 시 MySQL이 자동 해제 → 수동 복구 불필요
```

**Phase C에서 진짜 병렬화가 필요하면:**
```sql
-- queue 테이블에 claimed_at DATETIME NULL 추가
UPDATE queue SET claimed_at=NOW()
WHERE newsletter_id=? AND claimed_at IS NULL LIMIT 30
-- 원자적 UPDATE로 행 점유, 트랜잭션 불필요
-- claimed_at이 5분 지난 행은 재처리 가능 (크래시 복구)
```

### 12-3. 캐싱 설계 원칙

```
1. 언제 변하는지 먼저 파악한다.

2. 무효화 지점을 빠짐없이 열거한다.
   finalizeSend() ✓
   deleteNewsletter() ✓

3. 무효화 로직을 한 곳으로 모은다.
   RestApi::clearDashboardCache() — 어디서든 호출 가능한 static 헬퍼
```

| 쿼리 | 캐싱 여부 | 이유 |
|------|---------|------|
| stats (COUNT+SUM) | ✅ 5분 | 발송 완료/삭제 시에만 변함 |
| chart (일별 집계) | ✅ 5분 | 발송 완료/삭제 시에만 변함 |
| campaigns (JOIN events) | ❌ | 오픈/클릭마다 변함 → 무효화 불가 |
| pending (실시간) | ❌ | queued/sending 실시간 표시 중요 |

### 12-4. 커버링 인덱스 — idx_nl_email_type

`getNewsletterDetail()`의 이벤트 집계 쿼리:
```sql
SELECT email, MAX(CASE WHEN type IN ('open','click') THEN 1 ELSE 0 END) AS opened
FROM wp_crmbiz_nl_events
WHERE newsletter_id = %d
GROUP BY email
```

- **기존 인덱스 `idx_nl_type (newsletter_id, type)`:** email 없어서 filesort 발생
- **새 인덱스 `idx_nl_email_type (newsletter_id, email, type)`:** GROUP BY email을 인덱스만으로 처리

**마이그레이션 — idempotent 패턴:**
```php
$indexes = $wpdb->get_col("SHOW INDEX FROM {$wpdb->prefix}crmbiz_nl_events", 2);
if (is_array($indexes) && !in_array('idx_nl_email_type', $indexes, true)) {
    $wpdb->query("ALTER TABLE ... ADD KEY idx_nl_email_type (newsletter_id, email, type)");
}
```

### 12-5. Phase 실행 순서 원칙

**리스크가 낮고 가역성이 높은 것부터 먼저 한다.**

| 항목 | 리스크 | 가역성 | 순서 |
|------|-------|-------|------|
| 인덱스 추가 | 낮음 | 높음 (DROP KEY 가능) | 1번 |
| 큐 정리 | 낮음 | 높음 | 2번 |
| 캐싱 추가 | 중간 | 높음 (transient 삭제) | 3번 |
| 아키텍처 변경 | **높음** | 낮음 | **보류** |

---

## 13. 성능 & 호환성 실전 기록

### 13-1. 1001명 부하 테스트 결과 (v1.2.0, Mailpit 환경)

| 항목 | 수치 |
|------|------|
| 수신자 수 | 1,001명 |
| 성공률 | 100% (0건 실패) |
| 총 소요 시간 | 34초 (Mailpit — 로컬 메모리 SMTP) |
| 배치 수 | 21배치 × 50건 |

**실 운영 환경 환산:**
```
WP Cron 1분 간격 → 1001명 = 21배치 → 총 21분
실 SMTP(SES, SendGrid): 배치당 2~5초 추가 → 총 25~30분 예상
```

**SMTP는 이 플러그인의 책임 범위 밖이다:**
```
이 플러그인 → wp_mail() → WP Mail SMTP → SMTP 서버
FluentCRM: 연락처 목록/태그 조회 전용 (SMTP 관여 없음)
```

**FluentCRM 연락처 직접 삽입 시:**
```sql
-- fc_subscriber_pivot의 object_type 컬럼 반드시 지정
INSERT INTO wp_fc_subscriber_pivot
    (subscriber_id, object_id, object_type, ...)
VALUES (1, 1, 'FluentCrm\\App\\Models\\Tag', ...)
-- object_type이 빈 문자열이면 FluentCRM API 조회에서 해당 연락처를 못 찾음
```

### 13-2. 배치 크기 50 → 30 변경 근거 (공유 호스팅 30초 제한)

| 배치 크기 | PHP 오버헤드 | SMTP 예산/건 | SMTP 0.5초/건 기준 총 소요 |
|---------|------------|------------|----------------------|
| 50건 | 0.47초 | 0.59초 | 25.47초 — 한도 직전 ⚠️ |
| **30건** | **0.28초** | **0.99초** | **15.28초 — 여유 충분 ✅** |

느린 SMTP(0.8초/건)에서도 0.28 + 24 = 24.28초 → 30초 한도 안.

### 13-3. 도구 선택 원칙 — PHPUnit vs E2E

PHPUnit에서 `sendFromRecord()` 호출 시 FluentCRMBridge::isAvailable() → false → 즉시 return.  
wp_mail() 스텁도 no-op. **시간 측정 = PHP 코드 진입 오버헤드만 (~0.001초)**  
→ 발송 시간/메모리 측정은 반드시 E2E(WP-CLI)로 해야 의미 있다.

| 테스트 | 도구 |
|--------|------|
| Transient eviction | PHPUnit ✅ |
| DISABLE_WP_CRON | E2E ✅ |
| 배치 시간·메모리 | E2E (WP-CLI) ✅ |

**do_action vs wp cron event run:**
```js
// wp cron event run은 WP HTTP 루프백이 이미 소비해버려 "Invalid cron event" 오류 발생
// 올바른 방법: do_action() 직접 호출 (내부 동작이 동일)
function triggerSend(nlId) {
    return wpEval(`do_action("crmbiz_nl_send_newsletter", ${nlId});`)
}
```

### 13-4. EXPLAIN ANALYZE — getNewsletters() 쿼리 프로파일링

**쿼리 실행 시간 (순수 DB):**

| 데이터 규모 | 평균 (warm) | API 총 응답 |
|------------|------------|------------|
| 1,000건 | 11ms | ~391ms |
| 10,000건 | 27ms | ~407ms |

**993ms의 실체:** cold start 측정값이었다. WordPress 부트스트랩이 ~380ms를 차지.  
DB 쿼리를 최적화해도 API 응답을 400ms 아래로 내리기 어렵다.

**EXPLAIN ANALYZE 결과 (10,000건):**
```
→ Covering index lookup on e using idx_nl_email_type   ← 이미 최적
→ ORDER BY COALESCE(sent_at, ...)                      ← 함수식 → filesort
→ GROUP BY n.id                                        ← 임시 테이블 필요
```

**스케일링 예측:**
```
10,000건:  ~407ms  ✅
100,000건: ~650ms  ✅
500,000건: ~1730ms ❌ (한도 초과)
```

**50만 건 이상 시 적용할 최적화 (DB 2.2.0):**
```sql
ALTER TABLE wp_crmbiz_newsletters
    ADD COLUMN sort_date DATETIME
        GENERATED ALWAYS AS (COALESCE(sent_at, scheduled_at, created_at)) STORED,
    ADD KEY idx_sort_date (sort_date);
```

### 13-5. WP Cron 경고 배너 — showCronNotice() 조건

**세 조건이 동시에 충족될 때만 경고 표시:**
```
조건 1: 플러그인 전용 페이지 (crmbiz-newsletter / crmbiz-nl-history / crmbiz-nl-settings)
조건 2: 대기 중인 뉴스레터 존재 (status IN 'queued', 'sending')
조건 3: Cron이 30분 이상 미실행 또는 한 번도 실행 안 됨
```

하나라도 빠지면 배너가 나타나지 않는다.

### 13-6. 발송 중 업그레이드 안전성

| 시나리오 | 결과 |
|---------|------|
| `sending` 상태 뉴스레터 업그레이드 | status 그대로 유지 ✅ |
| 큐 아이템 (7건) 업그레이드 | 전량 보존 ✅ |
| 업그레이드 후 `do_action` 재실행 | 정상 재개 ✅ |

**안전한 이유:** `Database::install()`은 `dbDelta` + 인덱스 추가만 한다. 데이터를 건드리지 않는다.  
배치 루프 도중 프로세스 종료 → 그 배치의 이미 발송된 이메일이 재발송될 수 있음 (SMTP 타임아웃 관련).

---

*이 문서는 crmbiz-newsletter 프로젝트(v0.1~v1.2.1) 개발 경험을 바탕으로 작성됨.*  
*최초 작성: 2026-05-31 / 최종 정리: 2026-06-04*
