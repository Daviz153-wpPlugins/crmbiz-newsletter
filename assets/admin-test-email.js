(function($) {
    var ajaxUrl = crmbizNLDiag.ajaxUrl;
    var nonce   = crmbizNLDiag.nonce;

    $('#crmbiz-send-test').on('click', function() {
        var email   = $('#crmbiz-test-email').val().trim();
        var $result = $('#crmbiz-test-result');

        if (!email) {
            $result.css({'background':'#fff3cd','border':'1px solid #ffc107','color':'#856404'})
                   .text('이메일 주소를 입력하세요.')
                   .show();
            return;
        }

        var $btn = $(this).prop('disabled', true).text('발송 중...');

        $.post(ajaxUrl, {
            action:     'crmbiz_nl_test_email',
            nonce:      nonce,
            test_email: email
        }, function(res) {
            $btn.prop('disabled', false).text('테스트 발송');

            if (res.success) {
                var msg = (res.data && res.data.dry_run)
                    ? 'Dry-run: 실제 발송 건너뜀 (' + res.data.to + ')'
                    : (res.data && res.data.message ? res.data.message : '발송 성공');

                $result.css({'background':'#d1e7dd','border':'1px solid #0f5132','color':'#0f5132'})
                       .text(msg).show();
            } else {
                var errMsg = (res.data && res.data.message) ? res.data.message : '발송 실패';
                $result.css({'background':'#f8d7da','border':'1px solid #842029','color':'#842029'})
                       .text(errMsg).show();
            }
        }).fail(function() {
            $btn.prop('disabled', false).text('테스트 발송');
            $result.css({'background':'#f8d7da','border':'1px solid #842029','color':'#842029'})
                   .text('AJAX 요청 실패. 서버 오류를 확인하세요.').show();
        });
    });
})(jQuery);
