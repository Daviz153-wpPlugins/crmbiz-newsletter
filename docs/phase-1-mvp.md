# Phase 1: MVP — 포스트 → 뉴스레터 자동 발송 (v2.0)

> FluentCRM ContactsQuery API 기반 실제 발송 구현

---

## 목표

1. 포스트 편집 시 뉴스레터 메타박스 표시
2. FluentCRM 태그/리스트로 수신자 선택
3. 발행 즉시 `wp_mail()` 개인화 발송
4. 수신거부 링크 + HMAC 토큰 처리
5. 발송 이력 페이지

---

## 추가 파일 (Phase 1)

```
src/
├── NewsletterSender.php        ← 핵심 발송 엔진
├── EmailTemplateRenderer.php   ← 포스트 → HTML 이메일
├── UnsubscribeHandler.php      ← 수신거부 처리
└── Admin/
    ├── MetaBox.php             ← 포스트 편집 UI
    └── HistoryPage.php         ← 발송 이력
```

---

## MetaBox.php — 포스트 편집 UI

### 메타박스 구성

```
┌──────────────────────────────────────────────────────┐
│  📧 뉴스레터 발송 설정                                  │
├──────────────────────────────────────────────────────┤
│  ☑ 이 포스트를 뉴스레터로 발송                           │
│                                                      │
│  수신 태그:  [태그1 (23명)] [태그2 (45명)] [+ 더보기]    │
│  수신 리스트: [리스트1 (80명)] [+ 더보기]                │
│                                                      │
│  예상 수신자: 약 148명                                  │
│  (중복/수신거부 제외는 발송 시 처리)                      │
│                                                      │
│  발송 시점:                                           │
│    ● 즉시 발송   ○ 수동 발송   ○ 예약 발송              │
│    예약 시각: [2026-06-01] [09:00]                    │
│                                                      │
│  [HTML 미리보기]                                      │
└──────────────────────────────────────────────────────┘
```

### 핵심 AJAX — 태그/리스트 목록 로딩

```php
// 메타박스 로딩 시 AJAX로 태그·리스트 + 수신자 수 가져오기
add_action('wp_ajax_crmbiz_nl_get_recipients_data', function() {
    check_ajax_referer('crmbiz_nl_metabox', 'nonce');

    wp_send_json_success([
        'tags'  => FluentCRMBridge::getTagsForSelect(),
        'lists' => FluentCRMBridge::getListsForSelect(),
    ]);
});
```

### 수신자 수 실시간 계산 AJAX

```php
add_action('wp_ajax_crmbiz_nl_count_recipients', function() {
    check_ajax_referer('crmbiz_nl_metabox', 'nonce');

    $tagIds  = array_map('intval', (array)($_POST['tag_ids']  ?? []));
    $listIds = array_map('intval', (array)($_POST['list_ids'] ?? []));

    if (empty($tagIds) && empty($listIds)) {
        wp_send_json_success(['count' => 0]);
    }

    $query = new \FluentCrm\App\Services\ContactsQuery([
        'tags'     => $tagIds,
        'lists'    => $listIds,
        'statuses' => ['subscribed'],
    ]);

    $total = $query->getModel()->count();
    wp_send_json_success(['count' => $total]);
});
```

### 메타 저장

```php
public function savePostMeta(int $postId): void {
    if (!wp_verify_nonce($_POST['crmbiz_nl_nonce'] ?? '', 'crmbiz_nl_metabox')) return;
    if (!current_user_can('edit_post', $postId)) return;

    $enabled  = isset($_POST['crmbiz_nl_enabled']) ? 1 : 0;
    $tagIds   = array_map('intval', (array)($_POST['crmbiz_nl_tag_ids']  ?? []));
    $listIds  = array_map('intval', (array)($_POST['crmbiz_nl_list_ids'] ?? []));
    $sendMode = sanitize_key($_POST['crmbiz_nl_send_mode'] ?? 'immediate');
    $schedAt  = sanitize_text_field($_POST['crmbiz_nl_scheduled_at'] ?? '');

    update_post_meta($postId, '_crmbiz_nl_enabled',      $enabled);
    update_post_meta($postId, '_crmbiz_nl_tag_ids',      $tagIds);
    update_post_meta($postId, '_crmbiz_nl_list_ids',     $listIds);
    update_post_meta($postId, '_crmbiz_nl_send_mode',    $sendMode);
    update_post_meta($postId, '_crmbiz_nl_scheduled_at', $schedAt);
}
```

---

## NewsletterSender.php — 발송 엔진

```php
namespace CRMBizNewsletter;

use FluentCrm\App\Services\ContactsQuery;

class NewsletterSender {

    private Settings $settings;
    private EmailTemplateRenderer $renderer;

    public function sendForPost(int $postId): void {
        $post = get_post($postId);
        if (!$post) return;

        $tagIds   = (array) get_post_meta($postId, '_crmbiz_nl_tag_ids',  true);
        $listIds  = (array) get_post_meta($postId, '_crmbiz_nl_list_ids', true);

        if (empty($tagIds) && empty($listIds)) return;

        // 수신자 조회 — FluentCRM ContactsQuery 사용
        $subscribers = $this->getSubscribers($tagIds, $listIds);
        if ($subscribers->isEmpty()) return;

        // DB에 뉴스레터 레코드 생성
        $newsletterId = $this->createNewsletterRecord($postId, $tagIds, $listIds, $subscribers->count());

        // 개별 발송
        $success = 0;
        $fail    = 0;

        foreach ($subscribers as $subscriber) {
            // 수신거부 목록 교차 확인
            if ($this->isUnsubscribed($subscriber->email)) {
                continue;
            }

            $result = $this->sendToSubscriber($post, $subscriber);
            $result ? $success++ : $fail++;
        }

        $this->updateNewsletterRecord($newsletterId, $success, $fail);
    }

    private function getSubscribers(array $tagIds, array $listIds): \Illuminate\Support\Collection {
        $query = new ContactsQuery([
            'tags'     => array_filter($tagIds),
            'lists'    => array_filter($listIds),
            'statuses' => ['subscribed'],
        ]);

        return $query->get();
    }

    private function sendToSubscriber(\WP_Post $post, $subscriber): bool {
        $html = $this->renderer->render($post, $subscriber);

        // 컴플라이언스 검증 — 수신거부 링크 포함 여부
        if (!\FluentCrm\App\Services\Helper::hasComplianceText($html)) {
            // 수신거부 링크 자동 추가됨 (renderer에서 처리해야 함)
        }

        if ($this->settings->isDryRun()) {
            \FluentCrm\App\Services\Helper::debugLog(
                'CRMBiz Newsletter',
                'DRY-RUN: To=' . $subscriber->email . ', Post=' . $post->ID
            );
            return true;
        }

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $this->settings->getFromName() . ' <' . $this->settings->getFromEmail() . '>',
        ];

        return wp_mail(
            $subscriber->email,
            get_the_title($post),
            $html,
            $headers
        );
    }

    private function isUnsubscribed(string $email): bool {
        global $wpdb;
        return (bool) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}crmbiz_nl_unsubscribers WHERE email = %s LIMIT 1",
                $email
            )
        );
    }
}
```

---

## EmailTemplateRenderer.php — HTML 이메일 생성

### 렌더링 파이프라인

```
get_post() → post_content 추출
     ↓
apply_filters('the_content', ...) → 쇼트코드/블록 처리
     ↓
FluentCRM 스마트 코드 파싱
apply_filters('fluent_crm/parse_campaign_email_text', $html, $subscriber)
     ↓
수신거부 링크 + HMAC 토큰 삽입
     ↓
최근 뉴스레터 목록 섹션 추가 (선택)
     ↓
Helper::sanitizeHtml($html) → 최종 HTML
```

### 핵심 코드

```php
public function render(\WP_Post $post, $subscriber): string {
    $content = apply_filters('the_content', $post->post_content);

    // FluentCRM 머지 태그 파싱 {{first_name}}, {{email}} 등
    $content = apply_filters(
        'fluent_crm/parse_campaign_email_text',
        $content,
        $subscriber
    );

    $unsubscribeUrl = $this->buildUnsubscribeUrl($subscriber->email);
    $footer         = $this->buildFooter($unsubscribeUrl);

    $html = $this->wrapInTemplate([
        'post'             => $post,
        'content'          => $content,
        'subscriber'       => $subscriber,
        'footer'           => $footer,
        'unsubscribe_url'  => $unsubscribeUrl,
        'recent_posts'     => $this->getRecentNewsletters(3),
    ]);

    return \FluentCrm\App\Services\Helper::sanitizeHtml($html);
}

private function buildUnsubscribeUrl(string $email): string {
    $token = hash_hmac('sha256', $email, wp_salt('auth'));
    return add_query_arg([
        'crmbiz_nl_action' => 'unsubscribe',
        'email'            => rawurlencode($email),
        'token'            => $token,
    ], home_url('/'));
}
```

---

## UnsubscribeHandler.php — 수신거부 처리

```php
namespace CRMBizNewsletter;

class UnsubscribeHandler {

    public function init(): void {
        add_action('template_redirect', [$this, 'handleUnsubscribeRequest']);

        // FluentCRM 연락처 삭제 시 수신거부 레코드 정리
        add_action('fluentcrm_after_subscribers_deleted', [$this, 'cleanupOnDelete']);

        // FluentCRM에서 재구독 시 우리 수신거부 테이블에서 제거
        add_action('fluentcrm_subscriber_status_to_subscribed', [$this, 'removeOnResubscribe'], 10, 2);
    }

    public function handleUnsubscribeRequest(): void {
        if (($_GET['crmbiz_nl_action'] ?? '') !== 'unsubscribe') return;

        $email = sanitize_email(rawurldecode($_GET['email'] ?? ''));
        $token = sanitize_text_field($_GET['token'] ?? '');

        if (!$email || !$this->verifyToken($email, $token)) {
            wp_die('유효하지 않은 수신거부 링크입니다.', '수신거부 오류', ['response' => 403]);
        }

        $this->processUnsubscribe($email);

        // FluentCRM 연락처 상태도 업데이트
        $contact = FluentCRMBridge::getContactsApi()?->getContact($email);
        if ($contact) {
            $contact->updateStatus('unsubscribed');
        }

        wp_redirect(add_query_arg('crmbiz_nl_unsub', '1', home_url('/')));
        exit;
    }

    private function verifyToken(string $email, string $token): bool {
        $expected = hash_hmac('sha256', $email, wp_salt('auth'));
        return hash_equals($expected, $token);
    }

    private function processUnsubscribe(string $email): void {
        global $wpdb;
        $wpdb->replace(
            $wpdb->prefix . 'crmbiz_nl_unsubscribers',
            ['email' => $email, 'unsubscribed_at' => current_time('mysql')],
            ['%s', '%s']
        );
    }

    public function cleanupOnDelete(array $subscriberIds): void {
        // FluentCRM에서 연락처 삭제 시 — 필요시 레코드 정리
        // (현재는 수신거부 레코드 유지가 더 안전)
    }

    public function removeOnResubscribe($subscriber, string $previousStatus): void {
        // FluentCRM에서 재구독 → 우리 수신거부 테이블에서 제거
        global $wpdb;
        $wpdb->delete(
            $wpdb->prefix . 'crmbiz_nl_unsubscribers',
            ['email' => $subscriber->email],
            ['%s']
        );
    }
}
```

---

## Plugin.php — 훅 등록 (Phase 0+1)

```php
namespace CRMBizNewsletter;

class Plugin {

    private static ?self $instance = null;
    private Settings $settings;

    public static function getInstance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {
        $this->settings = new Settings();
        $this->registerHooks();
    }

    private function registerHooks(): void {
        // 활성화/비활성화
        register_activation_hook(CRMBIZ_NL_FILE, [Database::class, 'install']);

        // FluentCRM 준비 후 초기화 (의존성 보장)
        add_action('fluentcrm_loaded', [$this, 'onFluentCRMLoaded']);

        // 포스트 발행 훅
        add_action('transition_post_status', [$this, 'onPostPublished'], 10, 3);

        // 수신거부
        (new UnsubscribeHandler())->init();

        // 관리자 페이지
        if (is_admin()) {
            add_action('add_meta_boxes', [new Admin\MetaBox($this->settings), 'register']);
            add_action('save_post',      [new Admin\MetaBox($this->settings), 'savePostMeta']);
            add_action('admin_menu',     [$this, 'registerAdminPages']);
        }
    }

    public function onFluentCRMLoaded(): void {
        // FluentCRM이 로드된 후 안전하게 실행 가능한 초기화
    }

    public function onPostPublished(string $newStatus, string $oldStatus, \WP_Post $post): void {
        if ($newStatus !== 'publish' || $oldStatus === 'publish') return;
        if (!get_post_meta($post->ID, '_crmbiz_nl_enabled', true)) return;

        $sendMode = get_post_meta($post->ID, '_crmbiz_nl_send_mode', true) ?: 'immediate';

        if ($sendMode === 'immediate') {
            (new NewsletterSender($this->settings))->sendForPost($post->ID);
        }
        // scheduled, manual 은 Phase 2에서 처리
    }
}
```

---

## Phase 1 완료 기준

- [ ] 포스트 편집 시 뉴스레터 메타박스 표시됨
- [ ] 태그/리스트 드롭다운에 FluentCRM 데이터 로딩됨
- [ ] 예상 수신자 수 실시간 표시
- [ ] 포스트 발행 시 수신자 개인화 이메일 발송됨 (`{{first_name}}` 치환 확인)
- [ ] 발송 이력이 DB에 기록됨
- [ ] 수신거부 링크 클릭 시 토큰 검증 후 처리됨
- [ ] FluentCRM 연락처 상태도 `unsubscribed`로 업데이트됨
- [ ] 재구독 시 수신거부 테이블에서 자동 제거됨
