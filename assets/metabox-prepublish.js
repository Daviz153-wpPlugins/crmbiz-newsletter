(function() {
    if (!window.wp || !wp.plugins || !wp.editPost || !wp.element) return;

    var el                    = wp.element.createElement;
    var registerPlugin        = wp.plugins.registerPlugin;
    var PluginPrePublishPanel = wp.editPost.PluginPrePublishPanel;

    function getInfo() {
        var enabledEl = document.getElementById('crmbiz_nl_enabled');
        if (!enabledEl || !enabledEl.checked) return null;

        var modeEl = document.querySelector('[name="crmbiz_nl_send_mode"]:checked');
        if (!modeEl) return null;

        var mode     = modeEl.value;
        var countEl  = document.getElementById('crmbiz-count-value');
        var count    = countEl ? countEl.textContent.trim() : '?';
        var schedAt  = '';

        if (mode === 'scheduled') {
            var schedEl = document.querySelector('[name="crmbiz_nl_scheduled_at"]');
            schedAt = schedEl ? schedEl.value : '';
        }

        return { mode: mode, count: count, schedAt: schedAt };
    }

    function formatScheduledAt(schedAt) {
        if (!schedAt) return '';
        try {
            var d = new Date(schedAt);
            return d.toLocaleString('ko-KR', {
                year: 'numeric', month: 'long', day: 'numeric',
                hour: '2-digit', minute: '2-digit', hour12: false
            });
        } catch (e) {
            return schedAt;
        }
    }

    function CrmBizNLPrePublishPanel() {
        var info = getInfo();
        if (!info) return null;

        var title, mainText, subText;

        if (info.mode === 'immediate') {
            title    = '뉴스레터 즉시 발송';
            mainText = '발행과 동시에 ' + info.count + '명에게 뉴스레터가 즉시 발송됩니다.';
            subText  = '발송을 원하지 않으면 사이드바에서 즉시 발송을 해제한 뒤 발행하세요.';
        } else if (info.mode === 'scheduled') {
            var timeStr = formatScheduledAt(info.schedAt);
            title    = '뉴스레터 예약 발송';
            mainText = timeStr
                ? timeStr + '에 ' + info.count + '명에게 예약 발송됩니다.'
                : info.count + '명에게 예약 발송됩니다.';
            subText  = '시각을 변경하려면 사이드바에서 예약 발송 시각을 수정하세요.';
        } else {
            return null;
        }

        return el(
            PluginPrePublishPanel,
            { name: 'crmbiz-nl-prepublish', title: title, initialOpen: true },
            el('p', { style: { margin: '0 0 6px', fontSize: '13px', color: '#374151' } }, mainText),
            el('p', { style: { margin: 0, fontSize: '12px', color: '#6b7280' } }, subText)
        );
    }

    registerPlugin('crmbiz-nl-prepublish', { render: CrmBizNLPrePublishPanel });
})();
