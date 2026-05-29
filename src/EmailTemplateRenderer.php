<?php
namespace CRMBizNewsletter;

defined('ABSPATH') || exit;

class EmailTemplateRenderer {

    private Settings $settings;

    public function __construct(Settings $settings) {
        $this->settings = $settings;
    }

    public function render(\WP_Post $post, $subscriber, int $newsletterId = 0): string {
        // 포스트 콘텐츠 — 블록/쇼트코드 처리
        $content = apply_filters('the_content', $post->post_content);

        // FluentCRM 스마트 코드 파싱 ({{first_name}} 등)
        // FluentCRM 없을 때는 필터 핸들러가 없어 원본 그대로 반환됨
        $content = apply_filters('fluent_crm/parse_campaign_email_text', $content, $subscriber);

        $unsubscribeUrl = UnsubscribeHandler::buildUnsubscribeUrl($subscriber->email, $newsletterId);
        $recentPosts    = $this->getRecentNewsletters(3, $post->ID);
        $featuredImg    = $this->getFeaturedImageUrl($post);

        $html = $this->buildHtml([
            'post'            => $post,
            'content'         => $content,
            'subscriber'      => $subscriber,
            'unsubscribe_url' => $unsubscribeUrl,
            'recent_posts'    => $recentPosts,
            'featured_img'    => $featuredImg,
            'from_name'       => $this->settings->getFromName(),
            'site_name'       => get_bloginfo('name'),
            'site_url'        => home_url('/'),
        ]);

        // 트래킹 픽셀 + 링크 치환 (newsletterId가 있을 때만)
        if ($newsletterId > 0) {
            $html = $this->injectTracking($html, $newsletterId, $subscriber->email);
        }

        // FluentCRM Helper 로 HTML 정리 — 실패하거나 미사용 시 원본 반환
        // wp_kses_post() 는 <html>/<body>/<table> 등 이메일 구조 태그를 제거하므로 사용 불가
        if (FluentCRMBridge::isAvailable()) {
            try {
                return \FluentCrm\App\Services\Helper::sanitizeHtml($html);
            } catch (\Throwable $e) {
                // 폴백: 원본 HTML 반환
            }
        }

        return $html;
    }

    private function buildHtml(array $d): string {
        $post           = $d['post'];
        $content        = $d['content'];
        $unsubscribeUrl = esc_url($d['unsubscribe_url']);
        $recentPosts    = $d['recent_posts'];
        $siteName       = esc_html($d['site_name']);
        $siteUrl        = esc_url($d['site_url']);
        $postTitle      = esc_html(get_the_title($post));
        $postUrl        = esc_url(get_permalink($post) . '#crmbiz-web');
        $postDate       = esc_html(get_the_date('Y년 m월 d일', $post));

        $featuredSection = $d['featured_img']
            ? '<img src="' . esc_url($d['featured_img']) . '" alt="" style="width:100%;max-width:600px;height:auto;display:block;margin:0 0 24px">'
            : '';

        $logoId      = get_theme_mod('custom_logo');
        $logoSrc     = $logoId ? (wp_get_attachment_image_src($logoId, 'medium')[0] ?? '') : '';
        $headerBrand = $logoSrc
            ? '<a href="' . $siteUrl . '"><img src="' . esc_url($logoSrc) . '" alt="' . $siteName . '" style="max-height:40px;width:auto;display:block"></a>'
            : '<a href="' . $siteUrl . '" style="color:#ffffff;text-decoration:none;font-size:18px;font-weight:700">' . $siteName . '</a>';

        $recentSection = '';
        if (!empty($recentPosts)) {
            $items = '';
            foreach ($recentPosts as $rp) {
                $items .= sprintf(
                    '<li style="margin-bottom:8px"><a href="%s" style="color:#1a56db;text-decoration:none">%s</a> <span style="color:#9ca3af;font-size:13px">(%s)</span></li>',
                    esc_url(get_permalink($rp)),
                    esc_html(get_the_title($rp)),
                    esc_html(get_the_date('Y.m.d', $rp))
                );
            }
            $recentSection = '<div style="margin-top:40px;padding-top:24px;border-top:1px solid #e5e7eb">'
                . '<p style="font-size:14px;font-weight:600;color:#374151;margin:0 0 12px">최근 뉴스레터</p>'
                . '<ul style="margin:0;padding-left:18px;color:#374151;font-size:14px">' . $items . '</ul>'
                . '</div>';
        }

        ob_start();
        include CRMBIZ_NL_DIR . 'templates/email.php';
        return (string) ob_get_clean();
    }

    private function injectTracking(string $html, int $newsletterId, string $email): string {
        // 링크 치환 — unsubscribe·pixel 링크 제외
        $html = preg_replace_callback(
            '/href="(https?:\/\/[^"]+)"/i',
            function ($matches) use ($newsletterId, $email) {
                // HTML 엔티티 디코딩 (esc_url()이 & → &amp; 변환한 것을 복원)
                $url = html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
                if (strpos($url, 'crmbiz_nl_action') !== false) {
                    return $matches[0];
                }
                if (strpos($url, '#crmbiz-web') !== false) {
                    return 'href="' . esc_url(TrackingHandler::buildWebViewUrl($newsletterId, $email)) . '"';
                }
                return 'href="' . esc_url(TrackingHandler::buildClickUrl($newsletterId, $email, $url)) . '"';
            },
            $html
        );

        // 오픈 추적 픽셀 삽입
        $pixel = '<img src="' . esc_url(TrackingHandler::buildPixelUrl($newsletterId, $email)) . '" width="1" height="1" style="display:none" alt="">';
        $html  = str_replace('</body>', $pixel . '</body>', $html);

        return $html;
    }

    private function getFeaturedImageUrl(\WP_Post $post): string {
        $thumbId = get_post_thumbnail_id($post->ID);
        if (!$thumbId) {
            return '';
        }
        $src = wp_get_attachment_image_src($thumbId, 'large');
        return $src ? $src[0] : '';
    }

    private function getRecentNewsletters(int $count, int $excludePostId): array {
        global $wpdb;

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->prefix}crmbiz_newsletters
                 WHERE status = 'sent' AND post_id != %d
                 ORDER BY sent_at DESC
                 LIMIT %d",
                $excludePostId,
                $count
            )
        );

        if (empty($ids)) {
            return [];
        }

        $posts = [];
        foreach ($ids as $id) {
            $p = get_post((int) $id);
            if ($p && $p->post_status === 'publish') {
                $posts[] = $p;
            }
        }

        return $posts;
    }
}
