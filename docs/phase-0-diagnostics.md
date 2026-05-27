# Phase 0: 기반 구조 + 이메일 진단 (v2.0)

> "이메일이 실제로 전달되는가?"를 검증하는 단계

---

## 목표

1. 플러그인 활성화/설치 동작 확인
2. FluentCRM 의존성 감지 (공식 API 사용)
3. FluentSMTP 연결 상태 확인
4. 단일 테스트 이메일 발송 성공

---

## 파일 구조 (Phase 0 완성 시)

```
crmbiz-newsletter/
├── crmbiz-newsletter.php
├── autoload.php
└── src/
    ├── Plugin.php
    ├── Settings.php
    ├── Database.php
    ├── FluentCRMBridge.php        ← Phase 0 핵심
    └── Admin/
        ├── SettingsPage.php
        └── DiagnosticsPage.php    ← Phase 0 핵심
```

---

## FluentCRMBridge.php — FluentCRM 의존성 체크

```php
namespace CRMBizNewsletter;

class FluentCRMBridge {

    public static function isAvailable(): bool {
        return defined('FLUENTCRM') && function_exists('FluentCrmApi');
    }

    public static function getContactsApi() {
        if (!self::isAvailable()) {
            return null;
        }
        return FluentCrmApi('contacts');
    }

    public static function getTagsApi() {
        return self::isAvailable() ? FluentCrmApi('tags') : null;
    }

    public static function getListsApi() {
        return self::isAvailable() ? FluentCrmApi('lists') : null;
    }

    // Phase 0 진단: 태그/리스트 목록 조회 테스트
    public static function getTagsForSelect(): array {
        if (!self::isAvailable()) return [];

        $tags = FluentCrmApi('tags')->all()->get();
        return array_map(fn($t) => [
            'id'    => $t->id,
            'label' => $t->title . ' (' . $t->countByStatus('subscribed') . '명)',
        ], $tags->toArray());
    }

    public static function getListsForSelect(): array {
        if (!self::isAvailable()) return [];

        $lists = FluentCrmApi('lists')->all()->get();
        return array_map(fn($l) => [
            'id'    => $l->id,
            'label' => $l->title . ' (' . $l->countByStatus('subscribed') . '명)',
        ], $lists->toArray());
    }
}
```

---

## Settings.php — 타입 안전 설정 래퍼

```php
namespace CRMBizNewsletter;

class Settings {

    private const OPTION_KEY = 'crmbiz_nl_settings';

    private array $data;

    public function __construct() {
        $this->data = get_option(self::OPTION_KEY, []);
    }

    public function get(string $key, $default = null) {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, $value): void {
        $this->data[$key] = $value;
        update_option(self::OPTION_KEY, $this->data);
    }

    // 발신자 설정 (FluentCRM 전역 설정 우선 사용)
    public function getFromName(): string {
        $custom = $this->get('from_name');
        if ($custom) return $custom;

        if (FluentCRMBridge::isAvailable()) {
            $fcSettings = \FluentCrm\App\Services\Helper::getGlobalEmailSettings();
            return $fcSettings['from_name'] ?? get_bloginfo('name');
        }
        return get_bloginfo('name');
    }

    public function getFromEmail(): string {
        $custom = $this->get('from_email');
        if ($custom) return $custom;

        if (FluentCRMBridge::isAvailable()) {
            $fcSettings = \FluentCrm\App\Services\Helper::getGlobalEmailSettings();
            return $fcSettings['from_email'] ?? get_option('admin_email');
        }
        return get_option('admin_email');
    }

    public function isDryRun(): bool {
        return (bool) $this->get('dry_run', false);
    }

    public function isDebugMode(): bool {
        return (bool) $this->get('debug_mode', false);
    }
}
```

---

## DiagnosticsPage.php — 진단 대시보드

### 체크 항목

| 항목 | 확인 방법 |
|---|---|
| FluentCRM 활성 여부 | `defined('FLUENTCRM')` |
| FluentSMTP 활성 여부 | `defined('FLUENTMAIL')` 또는 `class_exists('FluentMail\...')` |
| FluentCRM 연락처 수 | `FluentCrmApi('contacts')->getInstance()->count()` |
| 태그 목록 조회 | `FluentCRMBridge::getTagsForSelect()` |
| 테스트 이메일 발송 | AJAX → `wp_mail()` 직접 호출 |
| Dry-run 모드 표시 | `Settings::isDryRun()` |

### AJAX 테스트 이메일 핸들러

```php
// Plugin.php 훅 등록
add_action('wp_ajax_crmbiz_nl_test_email', [$this, 'handleTestEmail']);

public function handleTestEmail(): void {
    check_ajax_referer('crmbiz_nl_diagnostics', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error('권한 없음');
    }

    $to      = sanitize_email($_POST['test_email'] ?? '');
    $subject = '[테스트] CRMBiz Newsletter 이메일 발송 테스트';
    $body    = '<h1>테스트 성공</h1><p>FluentSMTP를 통해 정상 발송됨.</p>';
    $headers = ['Content-Type: text/html; charset=UTF-8'];

    if ($this->settings->isDryRun()) {
        \FluentCrm\App\Services\Helper::debugLog(
            'CRMBiz Newsletter',
            'DRY-RUN: 테스트 이메일 건너뜀. To: ' . $to
        );
        wp_send_json_success(['dry_run' => true, 'to' => $to]);
        return;
    }

    $result = wp_mail($to, $subject, $body, $headers);
    wp_send_json($result
        ? ['success' => true,  'message' => '발송 성공: ' . $to]
        : ['success' => false, 'message' => '발송 실패. FluentSMTP 설정을 확인하세요.']
    );
}
```

---

## Database.php — 테이블 생성

```php
namespace CRMBizNewsletter;

class Database {

    public static function install(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$wpdb->prefix}crmbiz_newsletters (
            id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            post_id         BIGINT UNSIGNED NOT NULL,
            status          VARCHAR(20) NOT NULL DEFAULT 'draft',
            send_mode       VARCHAR(20) NOT NULL DEFAULT 'immediate',
            scheduled_at    DATETIME NULL,
            sent_at         DATETIME NULL,
            recipient_count INT UNSIGNED DEFAULT 0,
            success_count   INT UNSIGNED DEFAULT 0,
            fail_count      INT UNSIGNED DEFAULT 0,
            tag_ids         TEXT,
            list_ids        TEXT,
            error_log       TEXT,
            created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_post_id (post_id),
            INDEX idx_status  (status)
        ) $charset;

        CREATE TABLE {$wpdb->prefix}crmbiz_nl_unsubscribers (
            id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            email           VARCHAR(191) NOT NULL,
            unsubscribed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            token_used      VARCHAR(64),
            UNIQUE KEY uq_email (email)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        update_option('crmbiz_nl_db_version', '1.0.0');
    }
}
```

---

## Phase 0 완료 기준

- [ ] 플러그인 활성화 시 오류 없이 테이블 생성됨
- [ ] 진단 페이지에서 FluentCRM 상태 초록불
- [ ] 진단 페이지에서 FluentSMTP 상태 초록불
- [ ] 테스트 이메일이 Mailtrap에 수신됨
- [ ] Dry-run 모드에서 실제 발송 없이 로그 기록됨
- [ ] 설정 저장/불러오기 정상 동작
