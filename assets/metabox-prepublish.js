(function() {
    if (!window.wp || !wp.plugins || !wp.editPost || !wp.element) return;

    var el          = wp.element.createElement;
    var registerPlugin     = wp.plugins.registerPlugin;
    var PluginPrePublishPanel = wp.editPost.PluginPrePublishPanel;

    function getNewsletterSendInfo() {
        var enabledEl = document.getElementById('crmbiz_nl_enabled');
        if (!enabledEl || !enabledEl.checked) return null;

        var modeEl = document.querySelector('[name="crmbiz_nl_send_mode"]:checked');
        if (!modeEl || modeEl.value !== 'immediate') return null;

        var countEl = document.getElementById('crmbiz-count-value');
        return countEl ? countEl.textContent.trim() : '?';
    }

    function CrmBizNLPrePublishPanel() {
        var count = getNewsletterSendInfo();
        if (!count) return null;

        return el(
            PluginPrePublishPanel,
            { name: 'crmbiz-nl-prepublish', title: '뉴스레터 즉시 발송', initialOpen: true },
            el('p', { style: { margin: '0 0 6px', fontSize: '13px', color: '#374151' } },
                '발행과 동시에 ' + count + '명에게 뉴스레터가 즉시 발송됩니다.'
            ),
            el('p', { style: { margin: 0, fontSize: '12px', color: '#6b7280' } },
                '발송을 원하지 않으면 사이드바에서 즉시 발송을 해제한 뒤 발행하세요.'
            )
        );
    }

    registerPlugin('crmbiz-nl-prepublish', { render: CrmBizNLPrePublishPanel });
})();
