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
  .nl-outer  { padding:24px 12px !important; }
  .nl-inner  { width:100% !important;max-width:600px !important; }
  .nl-hd-td  { padding:14px 20px !important; }
  .nl-body   { padding:24px 20px !important; }
  .nl-foot   { padding:16px 20px !important; }
  .nl-h1     { font-size:22px !important; }
  .nl-body img, .nl-body table img { max-width:100% !important;height:auto !important; }
  .nl-body table { max-width:100% !important; }
  @media only screen and (max-width:620px) {
    .nl-outer  { padding:0 !important; }
    .nl-inner  { border-radius:0 !important; }
    .nl-hd-td  { padding:12px 16px !important; }
    .nl-body   { padding:20px 16px !important; }
    .nl-foot   { padding:14px 16px !important; }
    .nl-h1     { font-size:20px !important; }
  }
</style>
</head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif">
<table width="100%" cellpadding="0" cellspacing="0" class="nl-outer" style="background:#f3f4f6;padding:32px 16px">
<tr><td align="center">
<table cellpadding="0" cellspacing="0" class="nl-inner" style="max-width:600px;width:100%;background:#ffffff;border-radius:8px;overflow:hidden">

  <!-- 헤더 -->
  <tr>
    <td class="nl-hd-td" style="background:#1a1a2e;padding:16px 32px">
      <table width="100%" cellpadding="0" cellspacing="0">
        <tr>
          <td><?php echo $headerBrand; ?></td>
          <td align="right" class="nl-web">
            <a href="<?php echo $postUrl; ?>" style="color:#a5b4fc;text-decoration:none;font-size:12px">웹에서 보기 →</a>
          </td>
        </tr>
      </table>
    </td>
  </tr>

  <!-- 본문 -->
  <tr>
    <td class="nl-body" style="padding:32px">
      <?php if ($featuredSection): ?>
        <?php echo $featuredSection; ?>
      <?php endif; ?>

      <h1 class="nl-h1" style="margin:0 0 8px;font-size:26px;font-weight:700;color:#111827;line-height:1.3"><?php echo $postTitle; ?></h1>
      <p style="margin:0 0 24px;font-size:13px;color:#9ca3af"><?php echo $postDate; ?></p>

      <div style="font-size:16px;line-height:1.7;color:#374151">
        <?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
      </div>

      <?php if ($recentSection): ?>
        <?php echo $recentSection; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
      <?php endif; ?>
    </td>
  </tr>

  <!-- 푸터 -->
  <tr>
    <td class="nl-foot" style="background:#f9fafb;padding:20px 32px;border-top:1px solid #e5e7eb">
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
