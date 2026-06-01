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

        // h2 앞에 구분선 삽입
        $hr      = '<hr style="border:none;border-top:1px solid #e5e7eb;margin:0 0 0">';
        $content = preg_replace('/<h2(\s)/i', $hr . '<h2$1', $content);

        $content = $this->inlineStylesForEmail($content);

        // h2 / h3 / p 인라인 폰트 강제 (Gmail 모바일 CSS 무시 대응)
        $content = preg_replace('/<h2(\s)/i', '<h2 style="font-size:28px;font-weight:700;color:#111827;line-height:1.3;margin:36px 0 14px"$1', $content);
        $content = preg_replace('/<h3(\s)/i', '<h3 style="font-size:22px;font-weight:600;color:#111827;line-height:1.4;margin:28px 0 10px"$1', $content);
        $content = preg_replace('/<p(\s)/i',  '<p style="font-size:16px;line-height:1.85;margin:0 0 20px;color:#374151"$1', $content);

        $unsubscribeUrl = UnsubscribeHandler::buildUnsubscribeUrl($subscriber->email, $newsletterId);
        $recentPosts    = $this->getRecentNewsletters(3, $post->ID);
        $featuredImg    = $this->getFeaturedImageUrl($post);

        $style         = $this->settings->getEmailStyle();
        $recentPosts   = $style['show_recent']   ? $recentPosts : [];
        $featuredImg   = $style['show_featured'] ? $featuredImg : '';

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
            'sig'             => $this->settings->getSignature(),
            'style'           => $style,
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

    private function inlineStylesForEmail(string $content): string {
        // figure: 고정 width 제거 (WordPress 블록 이미지)
        $content = preg_replace_callback('/<figure([^>]*)>/i', function ($m) {
            $attrs = preg_replace('/\s*style\s*=\s*["\'][^"\']*["\']/i', '', $m[1]);
            return '<figure' . $attrs . ' style="margin:0 0 16px;padding:0">';
        }, $content);

        // img: 반응형 강제 + 고정 width/height 제거
        $content = preg_replace_callback('/<img([^>]*?)>/i', function ($m) {
            $attrs = $m[1];
            $attrs = preg_replace('/\s*(width|height)\s*=\s*["\']?\d+["\']?/i', '', $attrs);
            $attrs = preg_replace('/\s*style\s*=\s*["\'][^"\']*["\']/i', '', $attrs);
            return '<img' . $attrs . ' style="max-width:100%;width:100%;height:auto;display:block">';
        }, $content);

        // table: 인라인 스타일 강제
        $content = preg_replace_callback('/<table([^>]*)>/i', function ($m) {
            $attrs = $m[1];
            if (strpos($attrs, 'nl-sig') !== false) return $m[0];
            $attrs = preg_replace('/\s*style\s*=\s*["\'][^"\']*["\']/i', '', $attrs);
            return '<table' . $attrs . ' style="border-collapse:collapse;width:100%;table-layout:fixed;font-size:14px">';
        }, $content);

        // td / th: 인라인 스타일 강제
        $content = preg_replace_callback('/<(td|th)([^>]*)>/i', function ($m) {
            $tag   = $m[1];
            $attrs = $m[2];
            if (strpos($attrs, 'nl-sig') !== false) return $m[0];
            // 기존 style 추출 후 병합
            $existing = '';
            if (preg_match('/style\s*=\s*"([^"]*)"/i', $attrs, $sm)) {
                $existing = rtrim($sm[1], ';') . ';';
                $attrs    = str_replace($sm[0], '', $attrs);
            }
            $base = 'border:1px solid #d1d5db;padding:8px 10px;vertical-align:top;line-height:1.6;word-break:break-word;overflow-wrap:break-word;';
            if ($tag === 'th') {
                $base .= 'background:#f3f4f6;font-weight:600;color:#111827;text-align:left;';
            } else {
                $base .= 'color:#374151;';
            }
            return '<' . $tag . $attrs . ' style="' . $base . $existing . '">';
        }, $content);

        return $content;
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
        $postDate       = esc_html(get_the_date('Y년 m월 d일 H:i', $post));

        $sig            = $d['sig'];
        $style          = $d['style'];
        $outerBg        = esc_attr($style['outer_bg']);
        $headerBg       = esc_attr($style['header_bg']);
        $headerColor    = esc_attr($style['header_color']);
        $accentColor    = esc_attr($style['accent_color']);
        $contentWidth   = (int) $style['content_width'];
        $showWebView    = $style['show_web_view'];
        $showDate       = $style['show_date'];

        $featuredSection = $d['featured_img']
            ? '<img src="' . esc_url($d['featured_img']) . '" alt="" style="width:100%;height:auto;display:block">'
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

        $posts = get_posts([
            'post__in'            => array_map('intval', $ids),
            'posts_per_page'      => count($ids),
            'post_status'         => 'publish',
            'orderby'             => 'post__in',
            'ignore_sticky_posts' => true,
        ]);

        // sent_at DESC 순서 복원 (post__in 정렬 기준)
        $order = array_flip(array_map('intval', $ids));
        usort($posts, fn($a, $b) => ($order[$a->ID] ?? 0) <=> ($order[$b->ID] ?? 0));

        return $posts;
    }
}
