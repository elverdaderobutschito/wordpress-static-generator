jQuery(function ($) {
    'use strict';

    var i18n = (typeof wpStaticDeploySingle !== 'undefined' && wpStaticDeploySingle.i18n) ? wpStaticDeploySingle.i18n : {};

    $('#wpstatic-deploy-single-btn').on('click', function () {
        var $btn = $(this);
        var $status = $('#wpstatic-deploy-single-status');
        var postId = $btn.data('post-id');

        $btn.prop('disabled', true).text(i18n.deploying);
        $status.text('');

        $.post(wpStaticDeploySingle.ajaxUrl, {
            action: 'wpstatic_deploy_single',
            nonce: wpStaticDeploySingle.nonce,
            post_id: postId
        }).done(function (response) {
            if (response.success) {
                var data = response.data || {};
                var url = data.deploy_url;

                if (url) {
                    $status.html((data.message || i18n.done) + '<br><a href="' + url + '" target="_blank" rel="noopener">' + url + '</a>');
                } else {
                    $status.text(data.message || i18n.done);
                }
            } else {
                $status.text(i18n.error + ' ' + ((response.data && response.data.message) || i18n.unknownError));
            }
        }).fail(function () {
            $status.text(i18n.requestError);
        }).always(function () {
            $btn.prop('disabled', false).text(i18n.deploy);
        });
    });
});
