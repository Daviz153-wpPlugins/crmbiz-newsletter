# Progress Archive

---

## 2026-05-31 (2차) — 코드 품질 + 버그 수정 + 테스트

### 버전: 0.9.0 → 0.9.1

### 코드 품질 개선

| 항목 | 이전 | 이후 |
|------|------|------|
| `renderSignaturePreview` div anti-pattern | 다른 메서드에서 연 `</div>` 직접 닫음 | `render()`가 자신이 연 div를 직접 닫도록 수정 |
| SettingsPage.php 파일 크기 | 731줄 (단일 파일 과다 책임) | 444줄 + `SettingsSignatureTrait.php` 291줄 분리 |
| script handle 불일치 | `'crmbiz-nl-diagnostics'` → 파일은 `admin-test-email.js` | `'crmbiz-nl-test-email'` 통일 |
| CSV 날짜 함수 | `date()` (서버 TZ 의존) | `gmdate()` (WP 코딩 표준) |
| sig bio 출력 | `echo $sig['bio']` (phpcs:ignore) | `wp_kses()` 화이트리스트 처리 |
| SQL 패턴 | `prepare()` 결과를 문자열 연결로 재삽입 (비표준) | 각 쿼리에 `prepare()` 직접 적용, LIMIT/OFFSET 파라미터화 |
| 불필요 파일 | StatCard.vue, StatusBadge.vue (orphan), archive/, docs/, PLAN.md | 13개 파일 제거 (2,291줄 삭제) |
| Vue 페이지 .wrap 누락 | DashboardPage/HistoryPage에 `.wrap` 없음 → top 패딩 10px 차이 | `.wrap` 추가 → 4개 페이지 top/left 완전 동일 |

### 시뮬레이션 테스트에서 발견된 버그 3개

| 버그 | 현상 | 수정 |
|------|------|------|
| `RestApi.php date()` | 서버 TZ 의존 → 차트 날짜 오차 발생 가능 | `gmdate()`로 교체 |
| 수신자 0명 → 'sent' 오표시 | 발송 안 됐는데 완료 표시 | `populateQueue()`에서 즉시 `'failed'` 처리 |
| FluentCRM 비활성화 시 'queued' 영구 고착 | 관리자가 원인 알 수 없음 | `sendFromRecord()` 초입에서 상태 `'failed'`로 전환 |

### 테스트

- 기존 29개 → **42개** 테스트로 확장
- 신규 `NewsletterSenderEdgeCaseTest` (13 cases):
  - finalize 상태 전이 논리 (success/fail 조합별)
  - `parseScheduledAt` 엣지 케이스 (빈값, 과거, 미래, 잘못된 형식)
  - 헤더 인젝션 방지 (개행 제거 확인)
  - `populateQueue` 청크 분할 검증 (200개 단위)
  - 성공률 계산 및 PHP 8 float 타입 처리
- **42 tests, 63 assertions — 전부 통과**

### PM 최종 검수 점수

| 항목 | 점수 | 비고 |
|------|------|------|
| 보안 | 7.5/10 | AES-256 추적 URL, nonce, prepare() — 기준 이상 |
| 코딩 품질 | 7/10 | div anti-pattern 수정, trait 분리, CSS 기술부채 잔존 |
| UI/UX | 6.5/10 | Vue/PHP 이중 구조 근본 한계, WP admin 충돌 패치 |
| **종합** | **7/10** | 시제품 출시 가능 |

### 서버 부하 분석 요약

- 일반 방문자: 사실상 0 (GET 파라미터 확인 1회 후 종료)
- 발송 중: 50개/배치, MySQL GET_LOCK 동시실행 방지
- 정리 Cron: 1일 1회, 90일 이벤트 데이터 삭제
- 위험 구간: 수신자 수만 명 이상 시 WP Cron 지연 가능 (Action Scheduler 연동으로 대응 가능)

### 커밋 이력 (2차)

```
c381828 chore: bump version to 0.9.1
5055eec fix: resolve 3 bugs found in scenario simulation + add edge case tests
b0e6c4d refactor: improve coding quality — trait extraction, div fix, handle/date fixes
c246bb7 fix: security improvements before prototype release
b1ad532 chore: remove unused files and outdated documentation
0a6c945 fix: add .wrap to Vue pages to match PHP page top margin
1890a2b fix: align PHP page left padding with Vue pages (4px → 24px)
```

### 유료 판매 전 필요 작업 (향후)

1. **영어 국제화** — `__()`, `_e()` 다국어 함수 적용 + `.pot` 파일 생성 (글로벌 판매 필수)
2. **라이선스 시스템** — Freemius SDK 연동 (라이선스 키, 자동 업데이트 통합)
3. **온보딩 UX** — 처음 활성화 시 설정 가이드 또는 마법사
4. **사용자 문서** — FAQ, 트러블슈팅 가이드

---

## 2026-05-31 (1차) — UI 레이아웃 통일 + 보안 개선

### 버전: 0.8.1 → 0.9.0

### 해결된 이슈

| # | 문제 | 원인 | 해결 |
|---|------|------|------|
| 1 | 대시보드 stat 카드 단일 열 표시 | Tailwind v4 `@layer utilities`가 WP admin unlayered CSS에 덮어씌워짐 | `admin.css`에서 grid/flex/gap/mb/p 유틸리티 전체 `!important` 강제 |
| 2 | Vue/PHP 페이지 들여쓰기 불일치 (20px 차이) | Vue `p-6`(24px) + `.wrap`(20px) = 44px vs PHP `.crmbiz-admin-page`(4px) + `.wrap`(20px) = 24px | `.crmbiz-admin-page` 좌우 패딩 4px → 24px 변경 |
| 3 | MetaBox `발송 시점` 라디오 버튼 한 줄 붙음 | WP admin이 `label { display: inline }` 덮어씌움 | `#crmbiz-nl-metabox` 스코핑 + `!important` |
| 4 | MetaBox 테스트 발송 영역 간격 없음 | `.crmbiz-mb-row` flex가 WP admin에 덮어씌워짐 | 입력 → 버튼 수직 레이아웃으로 재구성 |
| 5 | 시그니처 섹션 중복 | 섹션 헤드 + toggle-row 동일 내용 | 헤드에 토글 통합 |
| 6 | 수신거부 검색창 버튼 불일치 | History(Vue) 버튼 없음, Unsubscribe(PHP) 있음 | Unsubscribe 검색 버튼 제거 |
| 7 | 메뉴 띄어쓰기 불일치 | "발송 이력" vs "수신거부" | "수신 거부"로 통일 |
| 8 | 프리셋 이메일 미리보기 미반영 | 저장 없이 클릭 → 이전값 표시 | 클릭 시 자동 저장 후 미리보기 열기 |
| 9 | SettingsPage 인라인 스타일 70+ 곳 | 유지보수 어려움 | CSS 클래스로 전환 |
| 10 | `renderStyleTab()` 데드코드 154줄 | customize 탭으로 대체됐지만 잔존 | 완전 제거 |
| 11 | 설정 페이지 max-width 780px | 타 페이지 대비 협소 | 1200px으로 확대 |
| 12 | PHP 페이지 헤더 타이틀 22px | Vue `text-2xl`(24px) 불일치 | 24px 통일 |
| 13 | 자간(letter-spacing) 불일치 | WP admin 덮어씌움 | `letter-spacing: 0` 리셋 |

### 핵심 기술 이슈 (Tailwind v4 + WP Admin 충돌)

- Tailwind v4는 모든 유틸리티를 `@layer utilities` 안에 생성
- WP admin CSS는 언레이어드(unlayered) — cascade 우선순위 더 높음
- 대응: `admin.css`에서 `#crmbiz-dashboard-app .클래스 { property: value !important }` 패턴

---

## 2026-05-30 — 코드 리뷰 + 버그 수정

- 버그 6건 수정: SQL, 타입 안전성, 엣지 케이스 등
- 품질 4건 개선: UI 리팩토링, 상수 추출 등
- 당시 미해결 → 이후 전부 해결됨
