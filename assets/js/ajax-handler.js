(function($) {
    'use strict';
    
    // Funzione per creare e inserire il form se non esiste
    function createFormIfNeeded() {
        // Non creare il form se l'utente è già verificato
        if (window.location.search.indexOf('verified=1') !== -1) {
            console.log('WP Login Firewall: User already verified, skipping form creation');
            return false;
        }
        
        if ($('#pre-login-container').length > 0) {
            console.log('WP Login Firewall: Form already exists in DOM');
            return true;
        }
        
        console.log('WP Login Firewall: Form not found, creating it dynamically');
        
        // Crea il form HTML
        var formHtml = '<div id="pre-login-container">' +
            '<div class="pre-login-form">' +
            '<h2>Verifica Accesso</h2>' +
            '<div class="error-message" id="error-message"></div>' +
            '<div class="success-message" id="success-message"></div>' +
            '<div class="loading" id="loading">Verifica in corso...</div>' +
            '<form id="pre-login-form" method="post">' +
            '<div class="form-group">' +
            '<label for="wplf_username">Nome utente o Email</label>' +
            '<input type="text" id="wplf_username" name="wplf_username" required autocomplete="username">' +
            '</div>' +
            '<button type="submit" class="submit-button">Verifica Accesso</button>' +
            '</form>' +
            '</div>' +
            '</div>';
        
        // Inserisci il form prima del loginform
        var loginForm = $('#loginform');
        if (loginForm.length > 0) {
            loginForm.before(formHtml);
            console.log('WP Login Firewall: Form inserted before #loginform');
            return true;
        }
        
        // Se loginform non esiste, inserisci dopo il login div
        var loginDiv = $('#login');
        if (loginDiv.length > 0) {
            loginDiv.prepend(formHtml);
            console.log('WP Login Firewall: Form inserted inside #login');
            return true;
        }
        
        console.error('WP Login Firewall: Could not find insertion point for form');
        return false;
    }
    
    // Funzione per inizializzare il form
    function initializeForm() {
        console.log('WP Login Firewall: Script loaded');
        console.log('WP Login Firewall: Looking for form #pre-login-form');
        console.log('WP Login Firewall: All forms found:', $('form').length);
        console.log('WP Login Firewall: Pre-login container exists:', $('#pre-login-container').length);
        
        // Crea il form se non esiste
        if (!createFormIfNeeded()) {
            return false;
        }
        
        var preLoginForm = $('#pre-login-form');
        
        if (preLoginForm.length === 0) {
            console.log('WP Login Firewall: Form not found after creation attempt');
            return false;
        }
        
        console.log('WP Login Firewall: Form found, attaching event listener');
        
        // Funzione per ottenere parametri GET dall'URL corrente
        function getUrlParameter(name) {
            name = name.replace(/[\[]/, '\\[').replace(/[\]]/, '\\]');
            var regex = new RegExp('[\\?&]' + name + '=([^&#]*)');
            var results = regex.exec(location.search);
            return results === null ? '' : decodeURIComponent(results[1].replace(/\+/g, ' '));
        }
        
        // Debug: mostra l'URL corrente e i parametri
        console.log('WP Login Firewall: Current URL:', window.location.href);
        console.log('WP Login Firewall: redirect_to param:', getUrlParameter('redirect_to'));
        console.log('WP Login Firewall: reauth param:', getUrlParameter('reauth'));
        
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
        
        return true; // Form inizializzato con successo
    }
    
    // Prova a inizializzare il form immediatamente
    $(document).ready(function() {
        var attempts = 0;
        var maxAttempts = 3; // Ridotto perché ora creiamo il form dinamicamente
        
        function tryInit() {
            if (initializeForm()) {
                console.log('WP Login Firewall: Initialized successfully');
                return;
            }
            
            attempts++;
            if (attempts < maxAttempts) {
                setTimeout(tryInit, 100);
            } else {
                console.error('WP Login Firewall: Failed to initialize after', maxAttempts, 'attempts');
            }
        }
        
        tryInit();
    });
})(jQuery);