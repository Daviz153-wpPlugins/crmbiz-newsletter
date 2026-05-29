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
  .nl-wrap    { padding:32px 16px !important; }
  .nl-inner   { width:100% !important;max-width:800px !important;margin:0 auto !important; }
  .nl-top     { padding:28px 28px 20px !important; }
  .nl-img     { padding:0 !important; }
  .nl-body    { padding:24px 28px 32px !important; }
  .nl-foot    { padding:16px 28px !important; }
  .nl-h1      { font-size:26px !important; }
  .nl-body img, .nl-body table img { max-width:100% !important;height:auto !important; }
  .nl-body table { max-width:100% !important; }
  @media only screen and (max-width:860px) {
    .nl-wrap  { padding:16px 16px !important; }
    .nl-inner { border-radius:0 !important; }
    .nl-top   { padding:20px 20px 16px !important; }
    .nl-body  { padding:20px 20px 24px !important; }
    .nl-foot  { padding:14px 20px !important; }
    .nl-h1    { font-size:22px !important; }
  }
  @media only screen and (max-width:480px) {
    .nl-wrap  { padding:0 !important; }
    .nl-top   { padding:20px 20px 16px !important; }
    .nl-body  { padding:16px 20px 24px !important; }
    .nl-foot  { padding:14px 20px !important; }
    .nl-h1    { font-size:20px !important; }
  }
</style>
</head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif">
<table width="100%" cellpadding="0" cellspacing="0" class="nl-wrap" style="background:#f3f4f6;padding:32px 16px">
<tr><td align="center">
<table cellpadding="0" cellspacing="0" class="nl-inner" style="width:100%;max-width:800px;background:#ffffff;border-radius:8px;overflow:hidden">

  <!-- 타이틀 / 날짜 / 웹에서 보기 -->
  <tr>
    <td class="nl-top" style="padding:28px 28px 20px;border-bottom:1px solid #e5e7eb">
      <p style="margin:0 0 10px;font-size:12px;color:#9ca3af"><?php echo $siteName; ?></p>
      <h1 class="nl-h1" style="margin:0 0 10px;font-size:26px;font-weight:700;color:#111827;line-height:1.35"><?php echo $postTitle; ?></h1>
      <p style="margin:0 0 8px;font-size:13px;color:#9ca3af"><?php echo $postDate; ?></p>
      <a href="<?php echo $postUrl; ?>" style="font-size:13px;color:#6b7280;text-decoration:underline">웹에서 보기</a>
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
    <td class="nl-body" style="padding:24px 28px 32px">
      <div style="font-size:16px;line-height:1.8;color:#374151">
        <?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
      </div>
      <?php if ($recentSection): ?>
        <?php echo $recentSection; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
      <?php endif; ?>
    </td>
  </tr>

  <!-- 푸터 -->
  <tr>
    <td class="nl-foot" style="background:#f9fafb;padding:16px 28px;border-top:1px solid #e5e7eb">
      <p style="margin:0;font-size:12px;color:#9ca3af;text-align:center">
        이 이메일은 <strong><?php echo $siteName; ?></strong> 뉴스레터 구독자에게 발송됩니다.<br>
        더 이상 받지 않으시려면
        <a href="<?php echo $unsubscribeUrl; ?>" style="color:#6b7280;text-decoration:underline">수신거부</a>를 클릭하세요.
      </p>
    </td>
  </tr>

</table>
</td></tr>
</table>
</body>
</html>
