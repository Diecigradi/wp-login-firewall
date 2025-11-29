jQuery(document).ready(function($) {
    // Gestione AJAX per la verifica utente
    $(document).on('submit', '#pre-login-form', function(e) {
        e.preventDefault();
        
        var formData = $(this).serialize();
        
        $.ajax({
            type: 'POST',
            url: cls_ajax.ajax_url,
            data: {
                action: 'cls_verify_user',
                data: formData,
                nonce: cls_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Reindirizza al login WordPress
                    window.location.href = response.data.redirect_url;
                } else {
                    $('#error-message').text(response.data).show();
                }
            }
        });
    });
});