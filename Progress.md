# Progress Archive

---

## 2026-05-31 작업 내역

### 해결된 이슈

| # | 문제 | 원인 | 해결 |
|---|------|------|------|
| 1 | 대시보드 stat 카드 단일 열 표시 | Tailwind v4 `@layer utilities`가 WP admin unlayered CSS에 덮어씌워짐 | `admin.css`에서 grid/flex/gap/mb/p 유틸리티 전체 `!important` 강제 |
| 2 | Vue/PHP 페이지 들여쓰기 불일치 (20px 차이) | Vue `p-6`(24px) + `.wrap`(20px) = 44px vs PHP `.crmbiz-admin-page`(4px) + `.wrap`(20px) = 24px | `.crmbiz-admin-page` 좌우 패딩 4px → 24px 변경 |
| 3 | MetaBox `발송 시점` 라디오 버튼 한 줄 붙음 | WP admin이 `label { display: inline }` 덮어씌움 | `#crmbiz-nl-metabox` 스코핑 + `!important` |
| 4 | MetaBox 테스트 발송 영역 간격 없음 | `.crmbiz-mb-row` flex가 WP admin에 덮어씌워짐 | 입력 → 버튼 수직 레이아웃으로 재구성 + margin 인라인 적용 |
| 5 | 시그니처 섹션 중복 ("시그니처 사용" 토글 두 번) | 섹션 헤드 + 첫 번째 toggle-row가 같은 내용 | 헤드에 토글 통합, `renderSignatureFields`에서 중복 제거 |
| 6 | 수신거부 검색창에만 "검색" 버튼 있음 | History(Vue)는 버튼 없음, Unsubscribe(PHP)는 폼 제출용 버튼 있음 | Unsubscribe 검색 버튼 제거 (Enter로 제출 가능) |
| 7 | 메뉴 띄어쓰기 불일치 | "발송 이력"(공백 있음) vs "수신거부"(공백 없음) | `Plugin.php`에서 "수신 거부"로 통일 |
| 8 | 설정 프리셋 이메일 미리보기 미반영 | "실제 이메일 전체보기"는 저장된 설정 기준 렌더링. 저장 없이 클릭 → 이전값 표시 | 버튼 클릭 시 현재 폼 값 자동 저장 후 미리보기 탭 열기 |
| 9 | SettingsPage 인라인 스타일 70+ 곳 | 유지보수 어렵고 코드 리뷰 시 미완성으로 보임 | CSS 클래스로 전환 (`crmbiz-btn--form`, `crmbiz-preset-btn`, `crmbiz-color-field-body` 등) |
| 10 | `renderStyleTab()` 데드코드 154줄 | customize 탭으로 대체됐지만 제거 안 됨 | 완전 제거 |
| 11 | 설정 페이지 max-width 780px (너무 좁음) | 타 페이지 대비 협소 | 1200px으로 확대 |
| 12 | PHP 페이지 헤더 타이틀 22px | Vue 페이지 `text-2xl`(24px)과 불일치 | 24px으로 통일 |
| 13 | 자간(letter-spacing) 불일치 | WP admin이 Vue 앱 내 텍스트 자간 덮어씌움 | `#crmbiz-dashboard-app, #crmbiz-history-app { letter-spacing: 0 }` |

### 커밋 이력

```
f95ab7e fix: auto-save before opening email preview
1890a2b fix: align PHP page left padding with Vue pages (4px → 24px)
94d799d fix: add !important to all refactored CSS classes, fix MetaBox layout
c9f8f86 refactor: remove dead code and replace inline styles with CSS classes
eff200b fix: force Tailwind v4 layout utilities against WP admin CSS override
fd32256 fix: unify layout across all admin pages
```

### 핵심 기술 이슈 (Tailwind v4 + WP Admin 충돌)

**근본 원인:**
- Tailwind v4는 모든 유틸리티를 `@layer utilities` 안에 생성
- WP admin CSS는 언레이어드(unlayered) — cascade 우선순위 더 높음
- 결과: Tailwind 클래스가 WP admin에 덮어씌워짐

**대응 방법:**
- `admin.css`에서 핵심 유틸리티를 `#crmbiz-dashboard-app .클래스 { property: value !important }` 패턴으로 재선언
- PHP 페이지 전용 클래스는 `#crmbiz-nl-metabox`, `.crmbiz-settings-wrap` 등 스코프 셀렉터 + `!important` 사용
- 인라인 스타일로 작성된 동적 값(배경색, 테두리색 등)은 인라인 유지 (WP admin 오버라이드 불가)

### 미해결 / 향후 검토

- 현재 PHP 페이지(설정, 수신거부)는 전통적 PHP 렌더링, Vue 페이지(대시보드, 이력)는 SPA 구조 — 근본적으로 다른 HTML 셸로 완전 통일은 Vue 전환 없이 불가
- 보안 개선 여지: `sig['bio']` unescaped 출력 → `wp_kses()` 적용 권장, CSV export `date()` → `gmdate()` 교체 권장

---

## 2026-05-30 작업 내역 (이전)

### 해결된 이슈 (코드 리뷰 완료)
- 버그 6건 수정: SQL, 타입 안전성, 엣지 케이스 등
- 품질 4건 개선: UI 리팩토링, 상수 추출 등

### 미해결 과제 (당시)

#### 🔴 1. 페이지 헤딩 패딩값 불일치
Vue `p-6` vs PHP `crmbiz-admin-page` 좌우 4px 차이 → **2026-05-31 해결**

#### 🟠 2. SettingsPage / MetaBox inline style 미전환
`style="..."` 속성 미전환 → **2026-05-31 해결**

#### 🟡 3. 검색 아이콘 pill 이탈 재발 가능성
WP Admin CSS override 구조적 문제 → **2026-05-31 근본 대응 (Tailwind v4 @layer override 체계 구축)**
