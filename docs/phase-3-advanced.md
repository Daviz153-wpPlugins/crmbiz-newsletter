# Phase 3: 고급 기능 (v2.0)

> 오픈 추적, 클릭 추적, 재구독 폼

---

## 목표

1. 이메일 오픈 추적 (트래킹 픽셀)
2. 클릭 추적 (URL 래핑)
3. 재구독 폼
4. 발송 통계 대시보드

---

## 오픈 추적 — FluentCRM Helper 활용

FluentCRM의 `Helper::injectTrackerPixel()` 재사용:

```php
// EmailTemplateRenderer.php에 추가
use FluentCrm\App\Services\Helper;

private function injectOpenTracker(string $html, string $emailHash): string {
    // FluentCRM 내장 픽셀 인젝터 재사용
    // Helper::injectTrackerPixel($html, $hash, $emailId) 참고
    $pixelUrl = home_url('/?crmbiz_nl_open=' . $emailHash);
    $pixel    = '<img src="' . esc_url($pixelUrl) . '" width="1" height="1" style="display:none" />';

    return str_replace('</body>', $pixel . '</body>', $html);
}

// 픽셀 요청 처리
add_action('template_redirect', function() {
    $hash = sanitize_text_field($_GET['crmbiz_nl_open'] ?? '');
    if (!$hash) return;

    // 오픈 기록 DB 업데이트
    global $wpdb;
    $wpdb->query(
        $wpdb->prepare(
            "UPDATE {$wpdb->prefix}crmbiz_nl_queue
             SET opened_at = %s
             WHERE email_hash = %s AND opened_at IS NULL",
            current_time('mysql'),
            $hash
        )
    );

    // 1x1 투명 GIF 반환
    header('Content-Type: image/gif');
    echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    exit;
});
```

---

## 클릭 추적 — URL 래핑

FluentCRM의 `Helper::attachUrls()` 방식 참고:

```php
private function wrapLinksForTracking(string $html, int $newsletterId, string $subscriberEmail): string {
    return preg_replace_callback(
        '/<a([^>]+)href=["\']([^"\']+)["\']([^>]*)>/i',
        function($matches) use ($newsletterId, $subscriberEmail) {
            $originalUrl  = $matches[2];
            $trackingHash = hash_hmac('sha256', $originalUrl . $subscriberEmail, wp_salt());
            $trackingUrl  = add_query_arg([
                'crmbiz_nl_click' => $trackingHash,
                'nl_id'           => $newsletterId,
            ], home_url('/'));

            // 트래킹 URL을 DB에 저장
            $this->storeTrackingUrl($newsletterId, $trackingHash, $originalUrl);

            return "<a{$matches[1]}href=\"{$trackingUrl}\"{$matches[3]}>";
        },
        $html
    );
}

// 클릭 리다이렉트 처리
add_action('template_redirect', function() {
    $hash = sanitize_text_field($_GET['crmbiz_nl_click'] ?? '');
    if (!$hash) return;

    global $wpdb;
    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT original_url FROM {$wpdb->prefix}crmbiz_nl_url_tracking
             WHERE tracking_hash = %s",
            $hash
        )
    );

    if ($row) {
        // 클릭 기록
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}crmbiz_nl_url_tracking
             SET click_count = click_count + 1, last_clicked_at = %s
             WHERE tracking_hash = %s",
            current_time('mysql'), $hash
        ));

        wp_redirect(esc_url_raw($row->original_url));
        exit;
    }

    wp_redirect(home_url('/'));
    exit;
});
```

---

## 재구독 폼

```php
// 숏코드: [crmbiz_nl_resubscribe]
add_shortcode('crmbiz_nl_resubscribe', function() {
    $email   = sanitize_email($_GET['email'] ?? '');
    $message = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_admin_referer('crmbiz_nl_resubscribe');
        $email = sanitize_email($_POST['email'] ?? '');

        if ($email && is_email($email)) {
            global $wpdb;
            $wpdb->delete(
                $wpdb->prefix . 'crmbiz_nl_unsubscribers',
                ['email' => $email],
                ['%s']
            );

            // FluentCRM 상태도 subscribed로 복구
            $contact = FluentCRMBridge::getContactsApi()?->getContact($email);
            if ($contact) {
                $contact->updateStatus('subscribed');
            }

            $message = '<p class="success">재구독 완료되었습니다.</p>';
        }
    }

    ob_start(); ?>
    <form method="post">
        <?php wp_nonce_field('crmbiz_nl_resubscribe'); ?>
        <input type="email" name="email" value="<?php echo esc_attr($email); ?>" required />
        <button type="submit">다시 구독하기</button>
    </form>
    <?php echo wp_kses_post($message);
    return ob_get_clean();
});
```

---

## 통계 대시보드 추가 테이블

```sql
-- 클릭 추적 테이블 (Phase 3 추가)
CREATE TABLE wp_crmbiz_nl_url_tracking (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    newsletter_id   BIGINT UNSIGNED NOT NULL,
    tracking_hash   VARCHAR(64) NOT NULL,
    original_url    TEXT NOT NULL,
    click_count     INT UNSIGNED DEFAULT 0,
    last_clicked_at DATETIME NULL,
    UNIQUE KEY uq_hash (tracking_hash),
    INDEX idx_newsletter (newsletter_id)
);

-- opened_at 컬럼 추가 (wp_crmbiz_nl_queue)
ALTER TABLE wp_crmbiz_nl_queue
    ADD COLUMN opened_at DATETIME NULL,
    ADD COLUMN email_hash VARCHAR(64) NULL;
```

---

## Phase 3 완료 기준

- [ ] 이메일 오픈 추적 픽셀 삽입됨
- [ ] 오픈율이 이력 페이지에 표시됨
- [ ] 링크 클릭 추적 작동
- [ ] 클릭률이 이력 페이지에 표시됨
- [ ] `[crmbiz_nl_resubscribe]` 숏코드 동작
- [ ] 재구독 시 FluentCRM 상태 자동 복구
