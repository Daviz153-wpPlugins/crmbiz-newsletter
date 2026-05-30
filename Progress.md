# 미해결 과제

## 🔴 1. 페이지 헤딩 패딩값 불일치

**현상:** 페이지마다 헤딩 영역 패딩값이 다름 (사용자 직접 확인)

**관련 파일:**
- `resources/js/dashboard/Dashboard.vue` — Tailwind `p-6` (24px)
- `resources/js/history/History.vue` — Tailwind `p-6` (24px)
- `src/Admin/UnsubscribePage.php` — `.crmbiz-admin-page` → `padding: 24px 4px 40px`
- `src/Admin/SettingsPage.php` — `.crmbiz-admin-page` → `padding: 24px 4px 40px`

**다음 작업:** 브라우저 DevTools로 각 페이지 h1 영역의 실제 computed padding 비교 후 통일

---

## 🟠 2. SettingsPage / MetaBox inline style 미전환

**현상:** 일부 `style="..."` 속성이 CSS 클래스로 미전환 상태

**관련 파일:**
- `src/Admin/SettingsPage.php`
- `src/Admin/MetaBox.php`

---

## 🟡 3. 검색 아이콘 pill 이탈 재발 가능성

**현상:** 오늘 수정 (`assets/admin.css` L557) — `.crmbiz-wrap .crmbiz-search-input`에서 border/border-radius 제거로 일단 해결

**재발 패턴:** WP Admin CSS override 규칙이 기본 디자인 규칙을 덮어쓰는 구조적 문제 존재. 추후 CSS 계층 정리 필요.

---

*2026-05-30*
