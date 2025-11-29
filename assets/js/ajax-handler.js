jQuery(document).ready(function($) {
    $('#pre-login-form').on('submit', function(e) {
        e.preventDefault();
        
        var username = $('#cls_username').val();
        var errorDiv = $('#error-message');
        var successDiv = $('#success-message');
        var loadingDiv = $('#loading');
        
        // Reset messaggi
        errorDiv.hide();
        successDiv.hide();
        loadingDiv.show();
        
        $.ajax({
            type: 'POST',
            url: cls_ajax.ajax_url,
            data: {
                action: 'cls_verify_user',
                username: username,
                nonce: cls_ajax.nonce
            },
            success: function(response) {
                loadingDiv.hide();
                
                if (response.success) {
                    successDiv.text('Utente verificato! Reindirizzamento al login...').show();
                    setTimeout(function() {
                        window.location.href = response.data.redirect_url;
                    }, 1000);
                } else {
                    errorDiv.text(response.data).show();
                    if (response.data.includes('tentativi')) {
                        $('#pre-login-form button').prop('disabled', true);
                    }
                }
            },
            error: function() {
                loadingDiv.hide();
                errorDiv.text('Errore di connessione. Riprova.').show();
            }
        });
    });
});