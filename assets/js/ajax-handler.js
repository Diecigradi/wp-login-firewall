(function($) {
    'use strict';
    
    $(document).ready(function() {
        console.log('WP Login Firewall: Script loaded');
        
        var preLoginForm = $('#pre-login-form');
        
        if (preLoginForm.length === 0) {
            console.log('WP Login Firewall: Form not found');
            return;
        }
        
        console.log('WP Login Firewall: Form found, attaching event listener');
        
        // Funzione per ottenere parametri GET dall'URL corrente
        function getUrlParameter(name) {
            name = name.replace(/[\[]/, '\\[').replace(/[\]]/, '\\]');
            var regex = new RegExp('[\\?&]' + name + '=([^&#]*)');
            var results = regex.exec(location.search);
            return results === null ? '' : decodeURIComponent(results[1].replace(/\+/g, ' '));
        }
        
        preLoginForm.on('submit', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            console.log('WP Login Firewall: Form submitted');
            
            var username = $('#wplf_username').val().trim();
            var errorDiv = $('#error-message');
            var successDiv = $('#success-message');
            var loadingDiv = $('#loading');
            var submitBtn = $(this).find('button[type="submit"]');
            
            // Validazione
            if (!username) {
                errorDiv.text('Inserisci un nome utente o email valido.').show();
                return false;
            }
            
            // Reset messaggi
            errorDiv.hide();
            successDiv.hide();
            loadingDiv.show();
            submitBtn.prop('disabled', true);
            
            console.log('WP Login Firewall: Sending AJAX request');
            
            // Raccogli i parametri GET attuali per passarli al server
            var currentParams = {
                redirect_to: getUrlParameter('redirect_to'),
                reauth: getUrlParameter('reauth')
            };
            
            $.ajax({
                type: 'POST',
                url: wplf_ajax.ajax_url,
                data: {
                    action: 'wplf_verify_user',
                    wplf_username: username,
                    nonce: wplf_ajax.nonce,
                    current_params: currentParams
                },
                success: function(response) {
                    console.log('WP Login Firewall: Response received', response);
                    loadingDiv.hide();
                    submitBtn.prop('disabled', false);
                    
                    if (response.success) {
                        successDiv.text('Utente verificato! Reindirizzamento al login...').show();
                        setTimeout(function() {
                            window.location.href = response.data.redirect_url;
                        }, 1000);
                    } else {
                        errorDiv.text(response.data).show();
                        if (response.data && response.data.includes('tentativi')) {
                            submitBtn.prop('disabled', true);
                        }
                    }
                },
                error: function(xhr, status, error) {
                    console.error('WP Login Firewall: AJAX error', status, error);
                    loadingDiv.hide();
                    submitBtn.prop('disabled', false);
                    errorDiv.text('Errore di connessione. Riprova.').show();
                }
            });
            
            return false;
        });
    });
})(jQuery);