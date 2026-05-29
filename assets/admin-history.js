(function($) {
    var ajaxUrl           = crmbizNL.ajaxUrl;
    var nonce             = crmbizNL.nonce;
    var logNonce          = crmbizNL.logNonce;
    var singleResendNonce = crmbizNL.singleResendNonce;

    /* ── 발송 중 진행률 폴링 ── */
    (function() {
        var pollingRows = {};
        $('.crmbiz-row').each(function() {
            var status = $(this).data('status');
            if (status === 'sending' || status === 'queued') {
                var id = $(this).data('nlId');
                if (id) pollingRows[id] = status;
            }
        });

        if (!Object.keys(pollingRows).length) return;

        $('<style>@keyframes crmbiz-pulse{0%,100%{opacity:1}50%{opacity:.3}}</style>').appendTo('head');
        var $indicator = $('<div style="' +
            'position:fixed;bottom:20px;right:20px;z-index:9999;' +
            'background:#1d4ed8;color:#fff;font-size:12px;padding:10px 16px;' +
            'border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,.15);' +
            'display:flex;align-items:center;gap:8px">' +
            '<span style="display:inline-block;width:8px;height:8px;background:#93c5fd;' +
            'border-radius:50%;animation:crmbiz-pulse 1.2s infinite"></span>' +
            '<span>발송 중 — 자동 업데이트 중</span>' +
            '</div>').appendTo('body');

        function fmtNum(n) {
            return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        }

        function poll() {
            var ids = Object.keys(pollingRows);
            if (!ids.length) { $indicator.remove(); return; }

            $.post(ajaxUrl, {
                action: 'crmbiz_nl_progress',
                nonce:  crmbizNL.progressNonce,
                ids:    ids
            }, function(res) {
                if (!res.success) return;

                var needsReload = false;
                $.each(res.data, function(_, item) {
                    var prev = pollingRows[item.id];
                    if (!prev) return;

                    if (prev !== item.status) {
                        needsReload = true;
                        return false;
                    }

                    if (item.status === 'sending') {
                        var $row = $('.crmbiz-row[data-nl-id="' + item.id + '"]');
                        $row.find('.crmbiz-progress-text').text(fmtNum(item.done) + ' / ' + fmtNum(item.recipient_count));
                        $row.find('.crmbiz-progress-fill').css('width', item.percent + '%');
                    }

                    if (item.status !== 'sending' && item.status !== 'queued') {
                        needsReload = true;
                        return false;
                    }
                });

                if (needsReload) {
                    clearInterval(pollTimer);
                    location.reload();
                }
            });
        }

        var pollTimer = setInterval(poll, 5000);
    })();

    /* ── 행 클릭으로 상세 토글 (버튼·링크 클릭 제외) ── */
    $(document).on('click', '#crmbiz-history-table tbody .crmbiz-row td', function(e) {
        if ($(e.target).closest('button, a').length) return;
        $(this).closest('tr').find('.crmbiz-toggle-row').first().trigger('click');
    });

    /* ── 상세행 토글 ── */
    $(document).on('click', '.crmbiz-toggle-row', function() {
        var id     = $(this).data('id');
        var $wrap  = $('#crmbiz-detail-' + id);
        var $row   = $('#crmbiz-detail-row-' + id);
        var $btns  = $('.crmbiz-toggle-row[data-id="' + id + '"]');

        if ($row.is(':visible')) {
            $row.hide();
            $btns.removeClass('is-open');
            return;
        }

        if ($wrap.data('loaded')) {
            $row.show();
            $btns.addClass('is-open');
            return;
        }

        $btns.css('opacity', '.4');
        $.post(ajaxUrl, {
            action:        'crmbiz_nl_get_log',
            nonce:         logNonce,
            newsletter_id: id
        }, function(res) {
            $btns.css('opacity', '1');
            var html = res.success ? res.data.html : '<p style="margin:12px 16px;color:#842029;font-size:12px">로드 실패</p>';
            $wrap.html(html).data('loaded', true);
            $row.show();
            $btns.addClass('is-open');
            crmbizInitPanel($wrap.find('.crmbiz-nl-panel'));
        }).fail(function() {
            $btns.css('opacity', '1');
        });
    });

    /* ── 탭 전환 ── */
    $(document).on('click', '.crmbiz-nl-tab', function() {
        var $panel = $(this).closest('.crmbiz-nl-panel');
        var tab    = $(this).data('tab');
        $panel.find('.crmbiz-nl-tab').removeClass('is-active');
        $(this).addClass('is-active');
        $panel.find('.crmbiz-nl-tab-body').hide();
        $panel.find('.crmbiz-nl-tab-body[data-tab="' + tab + '"]').show();
    });

    /* ── 필터 ── */
    $(document).on('click', '.crmbiz-nl-filter', function() {
        var $panel  = $(this).closest('.crmbiz-nl-panel');
        var filter  = $(this).data('filter');
        $panel.find('.crmbiz-nl-filter').removeClass('is-active');
        $(this).addClass('is-active');
        $panel.find('.crmbiz-nl-recipient').each(function() {
            var s   = $(this).data('status');
            var vis = filter === 'all'
                   || (filter === 'click'        && s === 'clicked')
                   || (filter === 'open'         && (s === 'clicked' || s === 'opened'))
                   || (filter === 'unopened'     && s === 'unopened')
                   || (filter === 'unsubscribed' && s === 'unsubscribed');
            $(this).data('fv', vis);
        });
        $panel.find('.crmbiz-nl-pager').data('pg', 1);
        crmbizPaginate($panel);
    });

    /* ── 페이지네이션 ── */
    function crmbizPaginate($panel) {
        var $pager  = $panel.find('.crmbiz-nl-pager');
        var perPage = parseInt($pager.find('.cp-per').val() || 20);
        var page    = $pager.data('pg') || 1;

        var $all = $panel.find('.crmbiz-nl-recipient');
        var $vis = $all.filter(function() { return $(this).data('fv') !== false; });

        var total      = $vis.length;
        var totalPages = Math.max(1, Math.ceil(total / perPage));
        page = Math.max(1, Math.min(page, totalPages));

        $all.hide();
        $vis.slice((page - 1) * perPage, page * perPage).show();

        $pager.find('.cp-cur').text(page);
        $pager.find('.cp-tot').text(totalPages);
        $pager.find('.cp-cnt').text(total);
        $pager.data('pg', page);
        $pager.find('.cp-prev').prop('disabled', page <= 1);
        $pager.find('.cp-next').prop('disabled', page >= totalPages);
    }

    $(document).on('click', '.cp-prev', function() {
        var $panel = $(this).closest('.crmbiz-nl-panel');
        var $pager = $panel.find('.crmbiz-nl-pager');
        $pager.data('pg', Math.max(1, ($pager.data('pg') || 1) - 1));
        crmbizPaginate($panel);
    });
    $(document).on('click', '.cp-next', function() {
        var $panel = $(this).closest('.crmbiz-nl-panel');
        var $pager = $panel.find('.crmbiz-nl-pager');
        $pager.data('pg', ($pager.data('pg') || 1) + 1);
        crmbizPaginate($panel);
    });
    $(document).on('change', '.cp-per', function() {
        var $panel = $(this).closest('.crmbiz-nl-panel');
        $panel.find('.crmbiz-nl-pager').data('pg', 1);
        crmbizPaginate($panel);
    });

    function crmbizInitPanel($panel) {
        if (!$panel.length) return;
        $panel.find('.crmbiz-nl-recipient').data('fv', true);
        crmbizPaginate($panel);
    }

    /* ── 재발송 ── */
    $(document).on('click', '.crmbiz-resend', function() {
        var $btn = $(this), id = $btn.data('id');
        if (!confirm('같은 수신자에게 다시 발송합니다. 계속하시겠습니까?')) return;
        $btn.prop('disabled', true).text('…');
        $.post(ajaxUrl, { action: 'crmbiz_nl_resend', nonce: nonce, newsletter_id: id }, function(res) {
            if (res.success) { alert(res.data.message || '재발송 완료'); location.reload(); }
            else { alert('오류: ' + (res.data && res.data.message ? res.data.message : '실패')); $btn.prop('disabled', false).text('↺'); }
        }).fail(function() { alert('서버 오류'); $btn.prop('disabled', false).text('↺'); });
    });

    /* ── 개별 수신자 재발송 ── */
    $(document).on('click', '.crmbiz-resend-single', function() {
        var $btn  = $(this);
        var nlId  = $btn.data('nl-id');
        var email = $btn.data('email');
        if (!confirm(email + ' 에게 재발송합니다. 계속하시겠습니까?')) return;
        $btn.prop('disabled', true).text('…');
        $.post(ajaxUrl, {
            action:        'crmbiz_nl_resend_single',
            nonce:         singleResendNonce,
            newsletter_id: nlId,
            email:         email
        }, function(res) {
            if (res.success) {
                $btn.text('✓').css('color', '#0f5132');
                setTimeout(function() { $btn.prop('disabled', false).text('↺').css('color', '#6b7280'); }, 2000);
            } else {
                alert('오류: ' + (res.data && res.data.message ? res.data.message : '실패'));
                $btn.prop('disabled', false).text('↺');
            }
        }).fail(function() {
            alert('서버 오류');
            $btn.prop('disabled', false).text('↺');
        });
    });

    /* ── 강제 즉시 발송 ── */
    $(document).on('click', '.crmbiz-force-send', function(e) {
        e.stopPropagation();
        var $btn = $(this), id = $btn.data('id');
        if (!confirm('WP Cron을 기다리지 않고 지금 즉시 발송합니다. 계속하시겠습니까?')) return;
        $btn.prop('disabled', true).text('…');
        $.post(ajaxUrl, { action: 'crmbiz_nl_force_send', nonce: nonce, newsletter_id: id }, function(res) {
            if (res.success) { location.reload(); }
            else { alert('오류: ' + (res.data && res.data.message ? res.data.message : '실패')); $btn.prop('disabled', false).text('⚡'); }
        }).fail(function() { alert('서버 오류'); $btn.prop('disabled', false).text('⚡'); });
    });

    /* ── 발송 취소 ── */
    $(document).on('click', '.crmbiz-cancel-send', function(e) {
        e.stopPropagation();
        var $btn = $(this), id = $btn.data('id');
        if (!confirm('발송을 취소하시겠습니까?\n이미 발송된 이메일은 되돌릴 수 없습니다.')) return;
        $btn.prop('disabled', true).text('…');
        $.post(ajaxUrl, { action: 'crmbiz_nl_cancel_send', nonce: nonce, newsletter_id: id }, function(res) {
            if (res.success) { location.reload(); }
            else { alert('오류: ' + (res.data && res.data.message ? res.data.message : '실패')); $btn.prop('disabled', false).text('✕'); }
        }).fail(function() { alert('서버 오류'); $btn.prop('disabled', false).text('✕'); });
    });

    /* ── 수동 발송 ── */
    $(document).on('click', '.crmbiz-manual-send', function() {
        var $btn = $(this), id = $btn.data('id');
        if (!confirm('이 뉴스레터를 지금 발송하시겠습니까?')) return;
        $btn.prop('disabled', true).text('…');
        $.post(ajaxUrl, { action: 'crmbiz_nl_manual_send', nonce: nonce, newsletter_id: id }, function(res) {
            if (res.success) { alert(res.data.message || '발송 완료'); location.reload(); }
            else { alert('오류: ' + (res.data && res.data.message ? res.data.message : '실패')); $btn.prop('disabled', false).text('▶'); }
        }).fail(function() { alert('서버 오류'); $btn.prop('disabled', false).text('▶'); });
    });


    /* ── 발송 이력 삭제 ── */
    $(document).on('click', '.crmbiz-delete-newsletter', function(e) {
        e.stopPropagation();
        var $btn = $(this), id = $btn.data('id');
        if (!confirm('이 발송 이력을 삭제하시겠습니까?\n(관련 이벤트 데이터도 함께 삭제됩니다)')) return;
        $btn.prop('disabled', true).text('…');
        $.post(ajaxUrl, { action: 'crmbiz_nl_delete_newsletter', nonce: nonce, newsletter_id: id }, function(res) {
            if (res.success) {
                var $row = $('.crmbiz-row[data-nl-id="' + id + '"]');
                $('#crmbiz-detail-row-' + id).remove();
                $row.remove();
            } else {
                alert('오류: ' + (res.data && res.data.message ? res.data.message : '실패'));
                $btn.prop('disabled', false).text('🗑');
            }
        }).fail(function() {
            alert('서버 오류');
            $btn.prop('disabled', false).text('🗑');
        });
    });

})(jQuery);
