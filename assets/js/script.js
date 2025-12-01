/**
 * WP Login Firewall - JavaScript
 */

(function($) {
    'use strict';
    
    $(document).ready(function() {
        const $form = $('#wplf-verify-form');
        const $username = $('#wplf-username');
        const $submit = $('#wplf-submit');
        const $buttonText = $('.wplf-button-text');
        const $spinner = $('.wplf-spinner');
        const $message = $('#wplf-message');
        
        $form.on('submit', function(e) {
            e.preventDefault();
            
            const username = $username.val().trim();
            
            if (!username) {
                showMessage('Inserisci un nome utente o email.', 'error');
                return;
            }
            
            // Disabilita form
            $submit.prop('disabled', true);
            $buttonText.hide();
            $spinner.show();
            $message.hide();
            
            // AJAX
            $.post(wplf.ajax_url, $form.serialize(), function(response) {
                if (response.success) {
                    showMessage(response.data.message, 'success');
                    setTimeout(function() {
                        window.location.href = response.data.redirect;
                    }, 500);
                } else {
                    // Se IP bloccato, ricarica pagina per mostrare show_blocked_page()
                    if (response.data && response.data.blocked === true) {
                        window.location.reload();
                        return;
                    }
                    
                    // Estrae messaggio (può essere stringa o oggetto)
                    const errorMsg = typeof response.data === 'object' ? response.data.message : response.data;
                    showMessage(errorMsg, 'error');
                    $submit.prop('disabled', false);
                    $buttonText.show();
                    $spinner.hide();
                }
            }).fail(function() {
                showMessage('Errore di connessione. Riprova.', 'error');
                $submit.prop('disabled', false);
                $buttonText.show();
                $spinner.hide();
            });
        });
        
        function showMessage(text, type) {
            $message
                .removeClass('wplf-error wplf-success')
                .addClass('wplf-' + type)
                .text(text)
                .fadeIn();
        }
    });
    
})(jQuery);
