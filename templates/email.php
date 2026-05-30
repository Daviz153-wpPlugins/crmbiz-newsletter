<?php defined('ABSPATH') || exit; ?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo $postTitle; ?></title>
<style>
  body { margin:0;padding:0;background:#f3f4f6; }
  img  { max-width:100%;height:auto;display:block; }

  /* 데스크탑: 외부 여백 + 중앙 정렬 카드 */
  .nl-wrap  { padding:32px 20px; background:#f3f4f6; }
  .nl-inner { width:100%;max-width:800px;background:#ffffff;border-radius:8px;overflow:hidden; }
  .nl-top   { padding:28px 32px 20px; }
  .nl-img   { padding:0; }
  .nl-body  { padding:24px 32px 36px; }
  .nl-foot  { padding:16px 32px; }
  .nl-h1    { font-size:26px; }

  /* 콘텐츠 내 표 스타일 */
  .nl-body table { border-collapse:collapse !important; width:100% !important; table-layout:fixed !important; margin:16px 0 24px !important; font-size:14px !important; }
  .nl-body table td,
  .nl-body table th { border:1px solid #d1d5db !important; padding:10px 12px !important; vertical-align:top !important; line-height:1.7 !important; word-break:break-word !important; overflow-wrap:break-word !important; color:#374151 !important; }
  .nl-body table th { background:#f3f4f6 !important; font-weight:600 !important; color:#111827 !important; }

  /* 단락·목록 간격 */
  .nl-body p  { margin:0 0 20px !important; font-size:18px !important; line-height:1.85 !important; }
  .nl-body h2 { margin:32px 0 12px !important; font-size:22px !important; font-weight:700 !important; color:#111827 !important; line-height:1.4 !important; }
  .nl-body h3 { margin:24px 0 10px !important; font-size:20px !important; font-weight:600 !important; color:#111827 !important; line-height:1.4 !important; }
  .nl-body ul,
  .nl-body ol { margin:0 0 20px !important; padding-left:24px !important; }
  .nl-body li { line-height:1.8 !important; margin-bottom:8px !important; }
  .nl-body blockquote { margin:0 0 20px !important; padding:12px 20px !important; border-left:4px solid #e5e7eb !important; color:#6b7280 !important; }

  /* 태블릿 (≤860px): 외부 여백 0, 컨텐츠 좌우 20px */
  @media only screen and (max-width:860px) {
    .nl-wrap  { padding:0 !important; background:#ffffff !important; }
    .nl-inner { border-radius:0 !important; }
    .nl-top   { padding:20px 20px 16px !important; }
    .nl-body  { padding:20px 14px 28px !important; }
    .nl-foot  { padding:14px 20px !important; }
    .nl-h1    { font-size:22px !important; }
    .nl-body table td,
    .nl-body table th { padding:6px 8px !important; font-size:12px !important; }
  }

  /* 시그니처 — 본문 테이블 테두리 규칙 예외 처리 */
  .nl-body table.nl-sig,
  .nl-body table.nl-sig td,
  .nl-body table.nl-sig th { border:none !important; }
  .nl-body table.nl-sig .nl-sig-photo-td,
  .nl-body table.nl-sig .nl-sig-text-td { padding:0 !important; width:auto !important; font-size:inherit !important; }

  /* 시그니처 모바일 스택 */
  @media only screen and (max-width:480px) {
    .nl-sig-photo-td { display:block !important; padding:0 0 16px 0 !important; text-align:center !important; }
    .nl-sig-photo-td img { margin:0 auto !important; }
    .nl-sig-text-td  { display:block !important; text-align:center !important; }
  }

  /* 모바일 (≤480px) */
  @media only screen and (max-width:480px) {
    .nl-top   { padding:20px 20px 16px !important; }
    .nl-body  { padding:16px 20px 24px !important; }
    .nl-foot  { padding:12px 20px !important; }
    .nl-h1    { font-size:20px !important; }
    .nl-body h2 { font-size:18px !important; }
    .nl-body table td,
    .nl-body table th { padding:6px 8px !important; font-size:12px !important; }
  }
</style>
</head>
<!--[if mso]><center><table width="<?php echo $contentWidth; ?>"><tr><td><![endif]-->
<body style="margin:0;padding:0;background:<?php echo $outerBg; ?>;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif">
<table width="100%" cellpadding="0" cellspacing="0" class="nl-wrap" style="background:<?php echo $outerBg; ?>;padding:32px 20px">
<tr><td align="center">
<table cellpadding="0" cellspacing="0" class="nl-inner" style="width:100%;max-width:<?php echo $contentWidth; ?>px;background:<?php echo $headerBg; ?>;border-radius:8px;overflow:hidden">

  <!-- 타이틀 / 날짜 / 웹에서 보기 -->
  <tr>
    <td class="nl-top" style="padding:28px 32px 20px;border-bottom:1px solid #e5e7eb;background:<?php echo $headerBg; ?>">
      <p style="margin:0 0 10px;font-size:12px;color:<?php echo $headerColor; ?>;opacity:.6"><?php echo $siteName; ?></p>
      <h1 class="nl-h1" style="margin:0 0 10px;font-size:26px;font-weight:700;color:<?php echo $headerColor; ?>;line-height:1.35"><?php echo $postTitle; ?></h1>
      <?php if ($showDate): ?>
      <p style="margin:0 0 8px;font-size:13px;color:<?php echo $headerColor; ?>;opacity:.5"><?php echo $postDate; ?></p>
      <?php endif; ?>
      <?php if ($showWebView): ?>
      <a href="<?php echo $postUrl; ?>" style="font-size:13px;color:<?php echo $accentColor; ?>;text-decoration:underline">웹에서 보기</a>
      <?php endif; ?>
    </td>
  </tr>

  <!-- 대표 이미지 (풀폭) -->
  <?php if ($featuredSection): ?>
  <tr>
    <td class="nl-img" style="padding:0">
      <?php echo $featuredSection; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
    </td>
  </tr>
  <?php endif; ?>

  <!-- 본문 콘텐츠 -->
  <tr>
    <td class="nl-body" style="padding:24px 32px 36px">
      <div style="font-size:16px;line-height:1.85;color:#374151">
        <?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
      </div>

      <?php if (!empty($sig) && $sig['enabled'] && ($sig['photo_url'] || $sig['name'] || $sig['bio'])): ?>
      <?php
        $hex  = ltrim($sig['bg_color'] ?? '#eef2ff', '#');
        $er   = hexdec(substr($hex, 0, 2));
        $eg   = hexdec(substr($hex, 2, 2));
        $eb   = hexdec(substr($hex, 4, 2));
        $ea   = ($sig['bg_opacity'] ?? 100) / 100;
        // rgba → 흰 배경에 알파 합성 (이메일 클라이언트 호환)
        $fr   = (int) round($ea * $er + (1 - $ea) * 255);
        $fg   = (int) round($ea * $eg + (1 - $ea) * 255);
        $fb   = (int) round($ea * $eb + (1 - $ea) * 255);
        $solidHex   = sprintf('#%02x%02x%02x', $fr, $fg, $fb);
        $sigBgAttr  = ($ea > 0) ? $solidHex : '';  // bgcolor 속성용
        $sigPos = $sig['photo_position'] ?? 'left';
        $photoHtml = '';
        if ($sig['photo_url']) {
            $bhex  = ltrim($sig['border_color'], '#');
            $bra   = round(($sig['border_opacity'] ?? 100) / 100, 2);
            $bRgba = 'rgba(' . hexdec(substr($bhex,0,2)) . ',' . hexdec(substr($bhex,2,2)) . ',' . hexdec(substr($bhex,4,2)) . ',' . $bra . ')';
            $photoAlign = ($sigPos === 'top') ? 'margin:0 auto;' : '';
            $photoHtml = '<img src="' . esc_url($sig['photo_url']) . '" width="64" height="64" class="nl-sig-photo" alt=""'
                . ' style="width:64px;height:64px;border-radius:50%;border:3px solid ' . esc_attr($bRgba) . ';display:block;object-fit:cover;' . $photoAlign . '">';
        }
        $tAlign   = ($sigPos === 'top') ? 'text-align:center;' : '';
        $photoGap = (int) ($sig['photo_gap'] ?? 16);
        $textGap  = (int) ($sig['text_gap']  ?? 8);
        $textHtml = '';
        if (!empty($sig['show_name']) && $sig['name']) $textHtml .= '<p style="margin:0 0 ' . $textGap . 'px;font-size:15px;font-weight:700;color:#111827;line-height:1.4;' . $tAlign . '">' . esc_html($sig['name']) . '</p>';
        if (!empty($sig['show_bio'])  && $sig['bio'])  $textHtml .= '<p style="margin:0;font-size:14px;color:#374151;line-height:1.75;' . $tAlign . '">' . $sig['bio'] . '</p>'; // phpcs:ignore
      ?>
      <table width="100%" border="0" cellpadding="0" cellspacing="0" class="nl-sig" style="margin:32px 0 0">
      <tr>
        <td <?php if ($sigBgAttr): ?>bgcolor="<?php echo esc_attr($sigBgAttr); ?>"<?php endif; ?>
            style="<?php if ($sigBgAttr): ?>background-color:<?php echo esc_attr($solidHex); ?>;<?php endif; ?>border-radius:12px;padding:20px 24px">
          <?php if ($sigPos === 'top'): ?>
          <table width="100%" border="0" cellpadding="0" cellspacing="0" class="nl-sig">
            <?php if ($photoHtml): ?>
            <tr><td align="center" class="nl-sig-photo-td">
              <?php echo $photoHtml; // phpcs:ignore ?>
            </td></tr>
            <tr><td height="<?php echo $photoGap; ?>" style="font-size:0;line-height:0;padding:0 !important">&nbsp;</td></tr>
            <?php endif; ?>
            <tr><td align="center" class="nl-sig-text-td" style="text-align:center"><?php echo $textHtml; // phpcs:ignore ?></td></tr>
          </table>
          <?php else: ?>
          <table border="0" cellpadding="0" cellspacing="0" class="nl-sig">
          <tr>
            <?php if ($photoHtml && $sigPos !== 'right'): ?>
            <td width="<?php echo $photoGap; ?>" valign="middle" class="nl-sig-photo-td" style="padding:0 !important">
              <?php echo $photoHtml; // phpcs:ignore ?>
            </td>
            <td width="<?php echo $photoGap; ?>" style="font-size:0;line-height:0;padding:0 !important">&nbsp;</td>
            <?php endif; ?>
            <td valign="middle" class="nl-sig-text-td"><?php echo $textHtml; // phpcs:ignore ?></td>
            <?php if ($photoHtml && $sigPos === 'right'): ?>
            <td width="<?php echo $photoGap; ?>" style="font-size:0;line-height:0;padding:0 !important">&nbsp;</td>
            <td width="84" valign="middle" class="nl-sig-photo-td" style="padding:0 !important">
              <?php echo $photoHtml; // phpcs:ignore ?>
            </td>
            <?php endif; ?>
          </tr>
          </table>
          <?php endif; ?>
        </td>
      </tr>
      </table>
      <?php endif; ?>

      <?php if ($recentSection): ?>
        <?php echo $recentSection; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
      <?php endif; ?>
    </td>
  </tr>

  <!-- 푸터 -->
  <tr>
    <td class="nl-foot" style="background:#f9fafb;padding:16px 32px;border-top:1px solid #e5e7eb">
      <p style="margin:0;font-size:12px;color:#9ca3af;text-align:center">
        이 이메일은 <strong><?php echo $siteName; ?></strong> 뉴스레터 구독자에게 발송됩니다.<br>
        더 이상 받지 않으시려면
        <a href="<?php echo $unsubscribeUrl; ?>" style="color:<?php echo $accentColor; ?>;text-decoration:underline">수신거부</a>를 클릭하세요.
      </p>
    </td>
  </tr>

</table>
</td></tr>
</table>
<!--[if mso]></td></tr></table></center><![endif]-->
</body>
</html>
