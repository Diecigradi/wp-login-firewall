/**
 * WP Login Firewall - Progressive Form Handler
 * Version: 2.0
 */

(function($) {
    'use strict';
    
    $(document).ready(function() {
        var $form = $('#loginform');
        var $verificationStep = $('#wplf-verification-step');
        var $usernameInput = $('#wplf-username-input');
        var $wpUsername = $('#user_login');
        var $wpPassword = $('#user_pass');
        var $tokenField = $('#wplf-token');
        var $verifyBtn = $('#wplf-verify-btn');
        var $errorDiv = $('#wplf-error');
        var $loadingDiv = $('#wplf-loading');
        
        /**
         * Click sul pulsante "Verifica Username"
         */
        $verifyBtn.on('click', function() {
            var username = $usernameInput.val().trim();
            
            if (!username) {
                showError('Inserisci un nome utente o email.');
                return;
            }
            
            verifyUsername(username);
        });
        
        /**
         * Enter nel campo username
         */
        $usernameInput.on('keypress', function(e) {
            if (e.which === 13) {
                e.preventDefault();
                $verifyBtn.click();
            }
        });
        
        /**
         * Verifica username via AJAX
         */
        function verifyUsername(username) {
            $verifyBtn.prop('disabled', true);
            $errorDiv.hide();
            $loadingDiv.show();
            
            $.ajax({
                type: 'POST',
                url: wplf_ajax.ajax_url,
                data: {
                    action: 'wplf_verify_user',
                    username: username,
                    nonce: wplf_ajax.nonce
                },
                success: function(response) {
                    $loadingDiv.hide();
                    
                    if (response.success) {
                        // Username verificato!
                        onUsernameVerified(response.data);
                    } else {
                        // Errore
                        showError(response.data);
                        $verifyBtn.prop('disabled', false);
                    }
                },
                error: function() {
                    $loadingDiv.hide();
                    showError('Errore di connessione. Riprova.');
                    $verifyBtn.prop('disabled', false);
                }
            });
        }
        
        /**
         * Callback quando username è verificato
         */
        function onUsernameVerified(data) {
            // Salva il token nel campo hidden
            $tokenField.val(data.token);
            
            // Popola il campo WordPress username
            $wpUsername.val(data.username);
            
            // Nascondi step di verifica con animazione
            $verificationStep.fadeOut(300, function() {
                // Mostra i campi WordPress
                $form.addClass('wplf-verified');
                
                // Rendi readonly il campo username
                $wpUsername.prop('readonly', true);
                
                // Focus sul campo password
                setTimeout(function() {
                    $wpPassword.focus();
                }, 100);
            });
            
            // Cambia il testo del submit button
            $form.find('.button-primary').val('Accedi');
        }
        
        /**
         * Mostra messaggio di errore
         */
        function showError(message) {
            $errorDiv.text(message).fadeIn();
            
            // Nascondi dopo 5 secondi
            setTimeout(function() {
                $errorDiv.fadeOut();
            }, 5000);
        }
        
        /**
         * Permetti di modificare username cliccando su di esso
         */
        $wpUsername.on('click', function() {
            if ($(this).prop('readonly')) {
                var change = confirm('Vuoi cambiare utente? Dovrai verificare nuovamente.');
                if (change) {
                    resetForm();
                }
            }
        });
        
        /**
         * Reset del form per cambiare utente
         */
        function resetForm() {
            $form.removeClass('wplf-verified');
            $wpUsername.prop('readonly', false).val('');
            $wpPassword.val('');
            $tokenField.val('');
            $usernameInput.val('');
            $verifyBtn.prop('disabled', false);
            $verificationStep.fadeIn();
            
            setTimeout(function() {
                $usernameInput.focus();
            }, 100);
        }
    });
    
})(jQuery);
