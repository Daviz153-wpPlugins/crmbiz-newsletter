<?php
namespace CRMBizNewsletter\Admin;

defined('ABSPATH') || exit;

class HistoryPage {

    public function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die('권한이 없습니다.');
        }

        $allowed_per  = [20, 50, 100];
        $per_page_raw = (int) ($_GET['per_page'] ?? 20);
        $per_page     = in_array($per_page_raw, $allowed_per, true) ? $per_page_raw : 20;
        $paged        = max(1, (int) ($_GET['paged'] ?? 1));
        $search      = sanitize_text_field($_GET['s'] ?? '');

        [$total, $rows] = $this->fetchRows($search, $per_page, ($paged - 1) * $per_page);
        $total_pages = max(1, (int) ceil($total / $per_page));
        $paged       = min($paged, $total_pages);
        ?>
        <div class="wrap crmbiz-wrap">

            <!-- 헤더 -->
            <div class="crmbiz-page-header">
                <h1 class="crmbiz-page-title">
                    뉴스레터 이력
                    <?php if ($total > 0): ?>
                    <span class="crmbiz-page-title-count">(총 <?php echo esc_html(number_format($total)); ?>개)</span>
                    <?php endif; ?>
                </h1>
            </div>

            <!-- 검색 -->
            <form method="get" action="">
                <input type="hidden" name="page" value="crmbiz-nl-history">
                <div class="crmbiz-search-wrap">
                    <div class="crmbiz-search-inner">
                        <span class="crmbiz-search-icon dashicons dashicons-search"></span>
                        <input type="text"
                               name="s"
                               id="crmbiz-search"
                               value="<?php echo esc_attr($search); ?>"
                               placeholder="제목으로 검색..."
                               class="crmbiz-search-input"
                               autocomplete="off">
                    </div>
                    <button type="submit" class="crmbiz-btn crmbiz-btn--primary">검색</button>
                    <?php if ($search !== ''): ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=crmbiz-nl-history')); ?>"
                       class="crmbiz-search-clear">초기화 ✕</a>
                    <?php endif; ?>
                </div>
            </form>

            <?php if (empty($rows)): ?>
                <div class="crmbiz-empty">
                    <?php if ($search !== ''): ?>
                        <div class="crmbiz-empty-icon dashicons dashicons-search"></div>
                        <p>"<?php echo esc_html($search); ?>"에 해당하는 이력이 없습니다.</p>
                    <?php else: ?>
                        <div class="crmbiz-empty-icon dashicons dashicons-email-alt"></div>
                        <p>발송 이력이 없습니다. 포스트를 발행하면 여기에 기록됩니다.</p>
                    <?php endif; ?>
                </div>
            <?php else: ?>

            <!-- 테이블 -->
            <div class="crmbiz-card">
                <table class="crmbiz-table" id="crmbiz-history-table">
                    <thead>
                        <tr>
                            <th>제목</th>
                            <th class="cn-center" style="width:80px">상태</th>
                            <th style="width:155px">발송 일시</th>
                            <th class="cn-right" style="width:60px">수신자</th>
                            <th class="cn-right" style="width:75px">오픈률</th>
                            <th class="cn-right" style="width:75px">클릭률</th>
                            <th class="cn-right" style="width:96px"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row):
                            $sent      = (int) $row->success_count;
                            $openRate  = $sent > 0 ? round((int)$row->open_count  / $sent * 100, 1) : 0;
                            $clickRate = $sent > 0 ? round((int)$row->click_count / $sent * 100, 1) : 0;
                            $title     = $row->post_title ?: '(삭제된 포스트)';
                        ?>
                        <tr class="crmbiz-row"
                            data-title="<?php echo esc_attr(mb_strtolower($title)); ?>"
                            data-status="<?php echo esc_attr($row->status); ?>"
                            data-nl-id="<?php echo esc_attr($row->id); ?>">

                            <!-- 제목 -->
                            <td>
                                <div style="display:flex;align-items:center;gap:8px">
                                    <button type="button"
                                            class="crmbiz-toggle-row"
                                            data-id="<?php echo esc_attr($row->id); ?>"
                                            title="상세 보기">▶</button>
                                    <a href="<?php echo esc_url(get_permalink((int)$row->post_id)); ?>"
                                       target="_blank"
                                       style="font-size:13px;font-weight:500;color:var(--cn-primary);text-decoration:none;line-height:1.4">
                                        <?php echo esc_html($title); ?>
                                    </a>
                                </div>
                            </td>

                            <!-- 상태 -->
                            <td style="text-align:center"><?php echo $this->statusBadge($row); ?></td>

                            <!-- 발송 일시 -->
                            <td style="font-size:12px;color:var(--cn-muted);white-space:nowrap">
                                <?php echo esc_html($this->formatDate($row)); ?>
                            </td>

                            <!-- 수신자 -->
                            <td style="text-align:right;font-size:13px;color:var(--cn-text);font-weight:500">
                                <?php echo esc_html(number_format((int)$row->recipient_count)); ?>
                            </td>

                            <!-- 오픈률 -->
                            <td style="text-align:right">
                                <?php if ($sent > 0): ?>
                                <span class="cn-metric-green"><?php echo esc_html($openRate); ?>%</span>
                                <?php else: ?>
                                <span class="cn-metric-dash">—</span>
                                <?php endif; ?>
                            </td>

                            <!-- 클릭률 -->
                            <td style="text-align:right">
                                <?php if ($sent > 0): ?>
                                <span class="cn-metric-blue"><?php echo esc_html($clickRate); ?>%</span>
                                <?php else: ?>
                                <span class="cn-metric-dash">—</span>
                                <?php endif; ?>
                            </td>

                            <!-- 액션 -->
                            <td style="text-align:right;white-space:nowrap">
                                <a href="<?php echo esc_url(add_query_arg([
                                    'action'  => 'crmbiz_nl_preview_email',
                                    'post_id' => $row->post_id,
                                    'nonce'   => wp_create_nonce('crmbiz_nl_preview_' . $row->post_id),
                                ], admin_url('admin-ajax.php'))); ?>"
                                   target="_blank"
                                   title="미리보기"
                                   class="crmbiz-icon-btn crmbiz-icon-btn--gray">
                                    <span class="dashicons dashicons-visibility"></span>
                                </a>

                                <?php if ($row->status === 'draft'): ?>
                                <button type="button" class="crmbiz-icon-btn crmbiz-icon-btn--blue crmbiz-manual-send"
                                        data-id="<?php echo esc_attr($row->id); ?>" title="발송">▶</button>
                                <?php endif; ?>

                                <?php if (in_array($row->status, ['queued', 'sending'], true)): ?>
                                <button type="button" class="crmbiz-icon-btn crmbiz-icon-btn--green crmbiz-force-send"
                                        data-id="<?php echo esc_attr($row->id); ?>" title="지금 즉시 발송">
                                    <span class="dashicons dashicons-controls-play"></span>
                                </button>
                                <?php endif; ?>

                                <?php if (in_array($row->status, ['queued', 'sending', 'scheduled'], true)): ?>
                                <button type="button" class="crmbiz-icon-btn crmbiz-icon-btn--red crmbiz-cancel-send"
                                        data-id="<?php echo esc_attr($row->id); ?>" title="발송 취소">✕</button>
                                <?php endif; ?>

                                <?php if (in_array($row->status, ['sent', 'failed'], true)): ?>
                                <button type="button" class="crmbiz-icon-btn crmbiz-icon-btn--gray crmbiz-resend"
                                        data-id="<?php echo esc_attr($row->id); ?>" title="재발송">↺</button>
                                <?php endif; ?>

                                <?php $deletable = $row->status !== 'sending'; ?>
                                <button type="button"
                                        class="crmbiz-icon-btn crmbiz-icon-btn--red crmbiz-delete-newsletter"
                                        data-id="<?php echo esc_attr($row->id); ?>"
                                        title="<?php echo $deletable ? '삭제' : '발송 중 — 취소 후 삭제 가능'; ?>"
                                        <?php echo $deletable ? '' : 'disabled'; ?>>
                                    <span class="dashicons dashicons-trash"></span>
                                </button>
                            </td>
                        </tr>

                        <!-- 상세 확장 행 -->
                        <tr id="crmbiz-detail-row-<?php echo esc_attr($row->id); ?>" style="display:none">
                            <td colspan="7" style="padding:0;border-bottom:2px solid var(--cn-border)">
                                <div id="crmbiz-detail-<?php echo esc_attr($row->id); ?>"></div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- 페이지네이션 -->
            <?php echo $this->renderPagination($total, $paged, $per_page, $total_pages, $search); ?>

            <?php endif; ?>
        </div>

        <?php
    }

    private function fetchRows(string $search, int $perPage, int $offset): array {
        global $wpdb;

        $ec_sub = "(SELECT newsletter_id,
            COUNT(DISTINCT CASE WHEN type = 'open'  THEN email END) AS open_count,
            COUNT(DISTINCT CASE WHEN type = 'click' THEN email END) AS click_count
         FROM {$wpdb->prefix}crmbiz_nl_events
         WHERE type IN ('open','click')
         GROUP BY newsletter_id) ec";

        $select = "SELECT n.*, p.post_title,
            COALESCE(ec.open_count, 0)  AS open_count,
            COALESCE(ec.click_count, 0) AS click_count";

        $from = "FROM {$wpdb->prefix}crmbiz_newsletters n
                 LEFT JOIN {$wpdb->posts} p ON p.ID = n.post_id
                 LEFT JOIN $ec_sub ON ec.newsletter_id = n.id";

        if ($search !== '') {
            $like  = '%' . $wpdb->esc_like($search) . '%';
            $total = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}crmbiz_newsletters n
                 LEFT JOIN {$wpdb->posts} p ON p.ID = n.post_id
                 WHERE p.post_title LIKE %s",
                $like
            ));
            $rows = $wpdb->get_results($wpdb->prepare(
                "$select $from WHERE p.post_title LIKE %s ORDER BY n.created_at DESC LIMIT %d OFFSET %d",
                $like, $perPage, $offset
            ));
        } else {
            $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}crmbiz_newsletters");
            $rows  = $wpdb->get_results($wpdb->prepare(
                "$select $from ORDER BY n.created_at DESC LIMIT %d OFFSET %d",
                $perPage, $offset
            ));
        }

        return [$total, $rows ?: []];
    }

    private function renderPagination(int $total, int $paged, int $perPage, int $totalPages, string $search): string {
        if ($totalPages <= 1 && $total <= 20) {
            return '';
        }

        $base = admin_url('admin.php');
        $args = ['page' => 'crmbiz-nl-history'];
        if ($search !== '') {
            $args['s'] = $search;
        }

        ob_start();
        ?>
        <div style="display:flex;align-items:center;justify-content:space-between;margin-top:16px;font-size:13px;color:#6b7280;flex-wrap:wrap;gap:10px">

            <!-- 표시 건수 선택 -->
            <form method="get" action="" style="display:flex;align-items:center;gap:6px">
                <input type="hidden" name="page" value="crmbiz-nl-history">
                <?php if ($search !== ''): ?>
                <input type="hidden" name="s" value="<?php echo esc_attr($search); ?>">
                <?php endif; ?>
                <label style="font-size:12px">페이지당
                    <select name="per_page" onchange="this.form.submit()"
                            style="border:1px solid #e5e7eb;border-radius:6px;padding:4px 6px;font-size:12px;color:#374151;background:#fff;cursor:pointer;margin:0 2px">
                        <?php foreach ([20, 50, 100] as $opt): ?>
                        <option value="<?php echo $opt; ?>" <?php selected($perPage, $opt); ?>><?php echo $opt; ?>개</option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <span style="color:#9ca3af">· 총 <?php echo esc_html(number_format($total)); ?>개</span>
            </form>

            <!-- 페이지 버튼 -->
            <div style="display:flex;align-items:center;gap:4px">
                <?php
                $btn_style  = 'display:inline-flex;align-items:center;justify-content:center;min-width:32px;height:32px;padding:0 8px;border:1px solid #e5e7eb;border-radius:6px;font-size:12px;text-decoration:none;';
                $active_sty = $btn_style . 'background:#111827;color:#fff;border-color:#111827;font-weight:600;';
                $normal_sty = $btn_style . 'background:#fff;color:#374151;';
                $disable_sty = $btn_style . 'background:#f9fafb;color:#d1d5db;pointer-events:none;';

                // 이전 버튼
                if ($paged > 1) {
                    echo '<a href="' . esc_url(add_query_arg(array_merge($args, ['paged' => $paged - 1]), $base)) . '" style="' . $normal_sty . '">◀</a>';
                } else {
                    echo '<span style="' . $disable_sty . '">◀</span>';
                }

                // 페이지 번호
                $range = 2;
                $pages = array_unique(array_filter(array_merge(
                    [1],
                    range(max(2, $paged - $range), min($totalPages - 1, $paged + $range)),
                    [$totalPages]
                )));
                sort($pages);

                $prev = 0;
                foreach ($pages as $p) {
                    if ($prev && $p - $prev > 1) {
                        echo '<span style="' . $disable_sty . '">…</span>';
                    }
                    $sty = ($p === $paged) ? $active_sty : $normal_sty;
                    echo '<a href="' . esc_url(add_query_arg(array_merge($args, ['paged' => $p]), $base)) . '" style="' . esc_attr($sty) . '">' . $p . '</a>';
                    $prev = $p;
                }

                // 다음 버튼
                if ($paged < $totalPages) {
                    echo '<a href="' . esc_url(add_query_arg(array_merge($args, ['paged' => $paged + 1]), $base)) . '" style="' . $normal_sty . '">▶</a>';
                } else {
                    echo '<span style="' . $disable_sty . '">▶</span>';
                }
                ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public function renderLogPublic(int $newsletterId): string {
        return $this->renderLog($newsletterId);
    }

    private function renderLog(int $newsletterId): string {
        global $wpdb;

        $nl = $wpdb->get_row($wpdb->prepare(
            "SELECT n.*, p.post_title
             FROM {$wpdb->prefix}crmbiz_newsletters n
             LEFT JOIN {$wpdb->posts} p ON p.ID = n.post_id
             WHERE n.id = %d",
            $newsletterId
        ));

        if (!$nl) {
            return '<p style="margin:12px 16px;font-size:12px;color:#888">뉴스레터를 찾을 수 없습니다.</p>';
        }

        /* 수신자별 상태 집계 — DB에서 직접 GROUP BY (이벤트 전체 로드 제거) */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT
                email,
                MAX(CASE WHEN type = 'open'        THEN 1 ELSE 0 END) AS opened,
                MAX(CASE WHEN type = 'click'       THEN 1 ELSE 0 END) AS clicked,
                MAX(CASE WHEN type = 'fail'        THEN 1 ELSE 0 END) AS failed,
                MAX(CASE WHEN type = 'unsubscribe' THEN 1 ELSE 0 END) AS unsubscribed,
                MAX(CASE WHEN type = 'send'        THEN occurred_at END) AS sent_at,
                MAX(CASE WHEN type = 'open'        THEN occurred_at END) AS open_at,
                MAX(CASE WHEN type = 'click'       THEN occurred_at END) AS click_at,
                MAX(CASE WHEN type = 'unsubscribe' THEN occurred_at END) AS unsub_at
             FROM {$wpdb->prefix}crmbiz_nl_events
             WHERE newsletter_id = %d
             GROUP BY email",
            $newsletterId
        ));

        $contactMap = [];
        foreach ($rows as $r) {
            $contactMap[$r->email] = [
                'opened'       => (bool) $r->opened,
                'clicked'      => (bool) $r->clicked,
                'failed'       => (bool) $r->failed,
                'unsubscribed' => (bool) $r->unsubscribed,
                'sent_at'      => $r->sent_at  ?? '',
                'open_at'      => $r->open_at  ?? '',
                'click_at'     => $r->click_at ?? '',
                'unsub_at'     => $r->unsub_at ?? '',
            ];
        }

        /* FluentCRM에서 수신자 이름 일괄 조회 */
        $nameMap = [];
        if (!empty($contactMap) && \CRMBizNewsletter\FluentCRMBridge::isAvailable()) {
            $emails       = array_keys($contactMap);
            $placeholders = implode(',', array_fill(0, count($emails), '%s'));
            $fcRows       = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT email, first_name, last_name FROM {$wpdb->prefix}fc_subscribers
                     WHERE email IN ($placeholders)",
                    ...$emails
                )
            );
            foreach ($fcRows as $fr) {
                $name = trim($fr->first_name . ' ' . $fr->last_name);
                if ($name !== '') {
                    $nameMap[$fr->email] = $name;
                }
            }
        }

        /* 통계 */
        $total      = (int) $nl->recipient_count;
        $sent       = (int) $nl->success_count;
        $fails      = (int) $nl->fail_count;
        $opens      = count(array_filter($contactMap, fn($c) => $c['opened'] || $c['clicked']));
        $clicks     = count(array_filter($contactMap, fn($c) => $c['clicked']));
        $unsubs     = count(array_filter($contactMap, fn($c) => $c['unsubscribed']));
        $unopened   = max(0, $sent - $opens - $unsubs);
        $openRate   = $sent  > 0 ? round($opens  / $sent  * 100, 1) : 0;
        $clickRate  = $sent  > 0 ? round($clicks / $sent  * 100, 1) : 0;
        $failRate   = $total > 0 ? round($fails  / $total * 100, 1) : 0;
        $unsubRate  = $sent  > 0 ? round($unsubs / $sent  * 100, 1) : 0;
        $ctr        = $opens > 0 ? round($clicks / $opens * 100, 1) : 0;

        /* 발송자 설정 */
        $settings  = (array) get_option('crmbiz_nl_settings', []);
        $fromName  = $settings['from_name']  ?? get_bloginfo('name');
        $fromEmail = $settings['from_email'] ?? (string) get_option('admin_email');
        $sentAt    = $this->formatDate($nl);
        $previewUrl = add_query_arg([
            'action'  => 'crmbiz_nl_preview_email',
            'post_id' => $nl->post_id,
            'nonce'   => wp_create_nonce('crmbiz_nl_preview_' . $nl->post_id),
        ], admin_url('admin-ajax.php'));

        ob_start();
        ?>
        <div class="crmbiz-nl-panel" style="padding:16px;background:#f9fafb">

            <!-- ── 통계 카드 2열 ── -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px">

                <!-- 캠페인 성과 -->
                <div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:14px">
                    <div style="font-size:11px;font-weight:700;color:#9ca3af;margin-bottom:10px;text-transform:uppercase;letter-spacing:.6px">캠페인 성과</div>
                    <table style="width:100%;border-collapse:collapse">
                        <?php foreach ([
                            ['발송된 이메일', (string)$sent,                                 '#111827'],
                            ['오픈률',       $opens  . ' (' . $openRate  . '%)',             '#0f5132'],
                            ['클릭률',       $clicks . ' (' . $clickRate . '%)',             '#1d4ed8'],
                            ['클릭/오픈률',  $ctr . '%',                                    '#7c3aed'],
                            ['구독 취소',    $unsubs . ' (' . $unsubRate . '%)',             '#f97316'],
                        ] as [$lbl, $val, $clr]): ?>
                        <tr>
                            <td style="font-size:12px;color:#6b7280;padding:4px 0"><?php echo esc_html($lbl); ?></td>
                            <td style="font-size:12px;font-weight:600;color:<?php echo esc_attr($clr); ?>;text-align:right"><?php echo esc_html($val); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                </div>

                <!-- 이메일 통계 -->
                <div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:14px">
                    <div style="font-size:11px;font-weight:700;color:#9ca3af;margin-bottom:10px;text-transform:uppercase;letter-spacing:.6px">이메일 통계</div>
                    <?php foreach ([
                        ['전송됨',    $sent,   $total > 0 ? round($sent/$total*100,1) : 0, '#3b82f6'],
                        ['열림',      $opens,  $openRate,   '#10b981'],
                        ['클릭됨',    $clicks, $clickRate,  '#6366f1'],
                        ['구독 취소', $unsubs, $unsubRate,  '#f97316'],
                        ['실패',      $fails,  $failRate,   '#ef4444'],
                    ] as [$lbl, $cnt, $rt, $clr]): ?>
                    <div style="margin-bottom:9px">
                        <div style="display:flex;justify-content:space-between;font-size:11px;margin-bottom:3px">
                            <span style="color:#374151;font-weight:500"><?php echo esc_html($lbl); ?></span>
                            <span style="color:#9ca3af"><?php echo esc_html($cnt . ' · ' . $rt . '%'); ?></span>
                        </div>
                        <div style="background:#e5e7eb;border-radius:3px;height:6px;overflow:hidden">
                            <div style="background:<?php echo esc_attr($clr); ?>;height:100%;width:<?php echo esc_attr(min((float)$rt,100)); ?>%;border-radius:3px"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- ── 탭 카드 ── -->
            <div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden">

                <!-- 탭 헤더 -->
                <div style="border-bottom:1px solid #e5e7eb;display:flex;padding:0 4px">
                    <button class="crmbiz-nl-tab is-active" data-tab="details">캠페인 세부 정보</button>
                    <button class="crmbiz-nl-tab" data-tab="recipients">수신자 <span style="background:#f3f4f6;color:#374151;border-radius:10px;padding:1px 7px;font-size:11px;margin-left:4px"><?php echo esc_html($sent); ?></span></button>
                </div>

                <!-- 탭: 세부 정보 -->
                <div class="crmbiz-nl-tab-body" data-tab="details" style="padding:20px 24px">
                    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:20px;margin-bottom:24px">
                        <div>
                            <div style="font-size:11px;color:#9ca3af;margin-bottom:4px;font-weight:600;text-transform:uppercase;letter-spacing:.5px">주제</div>
                            <div style="font-size:13px;color:#111827;font-weight:500;line-height:1.4"><?php echo esc_html($nl->post_title ?: '—'); ?></div>
                        </div>
                        <div>
                            <div style="font-size:11px;color:#9ca3af;margin-bottom:4px;font-weight:600;text-transform:uppercase;letter-spacing:.5px">총 수신자</div>
                            <div style="font-size:13px;color:#111827;font-weight:500"><?php echo esc_html(number_format($total)); ?></div>
                        </div>
                        <div>
                            <div style="font-size:11px;color:#9ca3af;margin-bottom:4px;font-weight:600;text-transform:uppercase;letter-spacing:.5px">발송 일시</div>
                            <div style="font-size:13px;color:#111827;font-weight:500"><?php echo esc_html($sentAt); ?></div>
                        </div>
                        <div>
                            <div style="font-size:11px;color:#9ca3af;margin-bottom:4px;font-weight:600;text-transform:uppercase;letter-spacing:.5px">발송자</div>
                            <div style="font-size:13px;color:#111827;font-weight:500"><?php echo esc_html($fromName); ?> <span style="color:#9ca3af">(<?php echo esc_html($fromEmail); ?>)</span></div>
                        </div>
                        <div>
                            <div style="font-size:11px;color:#9ca3af;margin-bottom:4px;font-weight:600;text-transform:uppercase;letter-spacing:.5px">발송 방식</div>
                            <div style="font-size:13px;color:#111827;font-weight:500"><?php echo esc_html($this->sendModeLabel($nl->send_mode)); ?></div>
                        </div>
                        <div>
                            <div style="font-size:11px;color:#9ca3af;margin-bottom:4px;font-weight:600;text-transform:uppercase;letter-spacing:.5px">이메일 미리보기</div>
                            <a href="<?php echo esc_url($previewUrl); ?>"
                               target="_blank"
                               style="font-size:13px;color:#2563eb;text-decoration:none">열기 ↗</a>
                        </div>
                    </div>
                </div>

                <!-- 탭: 수신자 -->
                <div class="crmbiz-nl-tab-body" data-tab="recipients" style="display:none;padding:16px">

                    <!-- 필터 버튼 -->
                    <div style="display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap">
                        <button class="crmbiz-nl-filter is-active" data-filter="all">모두 (<?php echo esc_html($sent); ?>)</button>
                        <button class="crmbiz-nl-filter" data-filter="click">클릭 (<?php echo esc_html($clicks); ?>)</button>
                        <button class="crmbiz-nl-filter" data-filter="open">보기 (<?php echo esc_html($opens); ?>)</button>
                        <button class="crmbiz-nl-filter" data-filter="unopened">미열람 (<?php echo esc_html($unopened); ?>)</button>
                        <button class="crmbiz-nl-filter" data-filter="unsubscribed">구독 취소 (<?php echo esc_html($unsubs); ?>)</button>
                    </div>

                    <?php if (empty($contactMap)): ?>
                    <p style="text-align:center;color:#9ca3af;font-size:13px;padding:24px 0;margin:0">수신자 데이터 없음</p>
                    <?php else: ?>

                    <!-- 수신자 테이블 -->
                    <table class="crmbiz-sub-table">
                        <thead>
                            <tr>
                                <th>이메일</th>
                                <th class="cn-center" style="width:70px">열람</th>
                                <th class="cn-center" style="width:70px">클릭</th>
                                <th class="cn-right" style="width:150px">마지막 활동</th>
                                <th class="cn-right" style="width:50px">액션</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($contactMap as $email => $c):
                            if ($c['unsubscribed'])   { $status = 'unsubscribed'; }
                            elseif ($c['clicked'])    { $status = 'clicked'; }
                            elseif ($c['opened'])     { $status = 'opened'; }
                            elseif ($c['failed'])     { $status = 'failed'; }
                            else                      { $status = 'unopened'; }
                            $lastAt  = $c['unsub_at'] ?: $c['click_at'] ?: $c['open_at'] ?: $c['sent_at'];
                            $name    = $nameMap[$email] ?? '';
                            $initial = $name ? strtoupper(mb_substr($name, 0, 1)) : strtoupper(mb_substr($email, 0, 1));
                        ?>
                        <tr class="crmbiz-nl-recipient" data-status="<?php echo esc_attr($status); ?>">
                            <td>
                                <div class="crmbiz-recipient-row">
                                    <div class="crmbiz-avatar"><?php echo esc_html($initial); ?></div>
                                    <div>
                                        <?php if ($name): ?>
                                        <div class="crmbiz-recipient-name"><?php echo esc_html($name); ?></div>
                                        <div class="crmbiz-recipient-email"><?php echo esc_html($email); ?></div>
                                        <?php else: ?>
                                        <div class="crmbiz-recipient-email--only"><?php echo esc_html($email); ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($c['failed']): ?>
                                    <span class="crmbiz-tag-fail">실패</span>
                                    <?php endif; ?>
                                    <?php if ($c['unsubscribed']): ?>
                                    <span class="crmbiz-tag-unsub">구독 취소</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td style="text-align:center">
                                <?php if ($c['opened'] || $c['clicked']): ?>
                                <span class="cn-metric-green" style="font-size:16px" title="열람함">✓</span>
                                <?php else: ?>
                                <span class="cn-metric-dash">—</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:center">
                                <?php if ($c['clicked']): ?>
                                <span class="cn-metric-blue" style="font-size:16px" title="클릭함">✓</span>
                                <?php else: ?>
                                <span class="cn-metric-dash">—</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:right;font-size:11px;color:var(--cn-light);white-space:nowrap">
                                <?php echo esc_html($lastAt ?: '—'); ?>
                            </td>
                            <td style="text-align:right">
                                <button type="button"
                                        class="crmbiz-resend-btn crmbiz-resend-single"
                                        data-nl-id="<?php echo esc_attr($newsletterId); ?>"
                                        data-email="<?php echo esc_attr($email); ?>"
                                        title="재발송">↺</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>

                    <!-- 페이지네이션 -->
                    <div class="crmbiz-nl-pager" style="display:flex;align-items:center;gap:10px;margin-top:14px;padding-top:12px;border-top:1px solid var(--cn-bg-muted);font-size:12px;color:var(--cn-muted);flex-wrap:wrap">
                        <span>페이지 <strong class="cp-cur">1</strong> of <strong class="cp-tot">1</strong></span>
                        <select class="cp-per" style="border:1px solid var(--cn-border);border-radius:var(--cn-radius);padding:4px 8px;font-size:12px;color:var(--cn-text);background:#fff;cursor:pointer">
                            <option value="20">20 / page</option>
                            <option value="50">50 / page</option>
                            <option value="100">100 / page</option>
                        </select>
                        <span>총계 <strong class="cp-cnt"><?php echo esc_html($sent); ?></strong></span>
                        <div style="margin-left:auto;display:flex;gap:4px">
                            <button class="cp-prev crmbiz-btn crmbiz-btn--secondary" style="padding:4px 10px;font-size:12px" disabled>◀</button>
                            <button class="cp-next crmbiz-btn crmbiz-btn--secondary" style="padding:4px 10px;font-size:12px" disabled>▶</button>
                        </div>
                    </div>

                    <?php endif; ?>
                </div><!-- /recipients -->

            </div><!-- /tab card -->

        </div><!-- /crmbiz-nl-panel -->
        <?php
        return ob_get_clean();
    }

    private function formatDate($row): string {
        if ($row->status === 'scheduled' && $row->scheduled_at) {
            return '⏰ ' . $this->localDate($row->scheduled_at);
        }
        if ($row->status === 'queued') return '대기 중';
        if ($row->status === 'draft')  return '—';
        $ts = $row->sent_at ?? $row->created_at;
        return $ts ? $this->localDate($ts) : '—';
    }

    private function localDate(string $dt): string {
        try {
            // DB dates stored as WP local time (current_time('mysql'))
            $d = new \DateTime($dt, wp_timezone());
            return $d->format('Y년 m월 d일 G:i');
        } catch (\Throwable $e) {
            return $dt;
        }
    }

    private function statusBadge(object $row): string {
        $status = $row->status;
        $labels = [
            'draft'     => '대기',
            'queued'    => '발송 예약',
            'sending'   => '발송 중',
            'sent'      => '완료',
            'failed'    => '실패',
            'scheduled' => '예약',
            'cancelled' => '취소됨',
        ];
        $label = $labels[$status] ?? $status;
        $badge = sprintf(
            '<span class="crmbiz-badge crmbiz-badge--%s">%s</span>',
            esc_attr($status),
            esc_html($label)
        );

        if ($status === 'sending') {
            $done  = (int) $row->success_count + (int) $row->fail_count;
            $total = (int) $row->recipient_count;
            $pct   = $total > 0 ? min(100, round($done / $total * 100)) : 0;
            $badge .= sprintf(
                '<div class="crmbiz-progress-text" style="color:var(--cn-blue-dark)">%s / %s</div>' .
                '<div class="crmbiz-progress-bar">' .
                '<div class="crmbiz-progress-fill" style="background:var(--cn-blue-dark);width:%s%%"></div></div>',
                number_format($done),
                number_format($total),
                esc_attr((string) $pct)
            );
        }

        return $badge;
    }

    private function sendModeLabel(string $mode): string {
        return ['immediate' => '즉시', 'manual' => '수동', 'scheduled' => '예약'][$mode] ?? $mode;
    }
}
