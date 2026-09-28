/* global jQuery, ajaxurl, provesrcAdmin */
jQuery(function ($) {
    var $form = $('#ps-settings form');
    var $submit = $('#submit');
    var $importOrders = $('#import_orders_button');

    function toggleButtons() {
        var tosChecked = $('#tos_checkbox').is(':checked');
        var apiKey = $('#provesrc_api_key').val();
        var webhookSecret = $.trim($('#provesrc_webhook_secret').val());
        // the API key and webhook secret are optional, the Connect button fills them
        $submit.prop('disabled', !tosChecked);
        // the import uses the saved key, so it also needs the saved key to be valid
        $importOrders.prop('disabled', !(apiKey && webhookSecret && tosChecked && provesrcAdmin.validApiKey));
    }

    function setLoading($btn, text) {
        var original = $btn.is('input') ? $btn.val() : $btn.text();
        $btn.addClass('ps-loading');
        if ($btn.is('input')) {
            $btn.val(text);
        } else {
            $btn.text(text).prepend('<span class="ps-spinner"></span>');
        }
        return function () {
            $btn.removeClass('ps-loading');
            if ($btn.is('input')) {
                $btn.val(original);
            } else {
                $btn.text(original);
            }
        };
    }

    function errorText(xhr, error) {
        return xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data : error;
    }

    $('#tos_checkbox').on('change', toggleButtons);
    $('#provesrc_api_key, #provesrc_webhook_secret').on('input', toggleButtons);
    toggleButtons();

    $form.on('submit', function () {
        var reset = setLoading($submit, 'Saving...');
        // the page reloads on success, reset the button in case it does not
        setTimeout(reset, 10000);
    });

    $('#download_debug_log').on('click', function (e) {
        e.preventDefault();
        $.post(ajaxurl, { action: 'provesrc_debug_log', security: provesrcAdmin.debugLogNonce })
            .done(function (response) {
                if (!response.success) {
                    alert('Failed to download debug log: ' + response.data);
                    return;
                }
                var downloadUrl = URL.createObjectURL(new Blob([response.data], { type: 'text/plain' }));
                var a = document.createElement('a');
                a.href = downloadUrl;
                a.download = 'debug.log';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(downloadUrl);
            })
            .fail(function (xhr, status, error) {
                alert('An error occurred: ' + error);
            });
    });

    $importOrders.on('click', function () {
        var reset = setLoading($importOrders.prop('disabled', true), 'Importing...');
        $.post(ajaxurl, { action: 'provesrc_import_orders', security: provesrcAdmin.importOrdersNonce })
            .done(function (response) {
                alert(response.success ? 'Orders imported successfully!' : response.data);
            })
            .fail(function (xhr, status, error) {
                alert(errorText(xhr, error));
            })
            .always(function () {
                reset();
                $importOrders.prop('disabled', false);
            });
    });

    $('#provesrc_import_reviews_button').on('click', function () {
        var $btn = $(this).prop('disabled', true).addClass('ps-loading');
        var $result = $('#provesrc_import_reviews_result').text('');
        $.post(ajaxurl, { action: 'provesrc_import_reviews', security: provesrcAdmin.importReviewsNonce })
            .done(function (response) {
                $result.text(response.data);
            })
            .fail(function (xhr, status, error) {
                $result.text(errorText(xhr, error));
            })
            .always(function () {
                $btn.prop('disabled', false).removeClass('ps-loading');
            });
    });
});
