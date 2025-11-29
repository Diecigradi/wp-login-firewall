<?php
/**
 * Plugin Name: Custom Login Security
 * Description: Aggiunge un layer di sicurezza prima del login
 * Version: 1.0
 * Author: Il tuo nome
 */

// Previene l'accesso diretto
defined('ABSPATH') or die('Accesso negato!');

class CustomLoginSecurity {
    
    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('login_init', array($this, 'handle_pre_login'));
        add_filter('login_url', array($this, 'modify_login_url'), 10, 3);
    }
    
    public function init() {
        // Aggiunge gli script e stili necessari
        add_action('login_enqueue_scripts', array($this, 'enqueue_scripts'));
    }
    
    public function enqueue_scripts() {
        wp_enqueue_script('jquery');
        wp_enqueue_script('cls-ajax', plugin_dir_url(__FILE__) . 'js/ajax-handler.js', array('jquery'), '1.0', true);
        wp_localize_script('cls-ajax', 'cls_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('cls_nonce')
        ));
    }
    
    public function handle_pre_login() {
        // Se è già loggato, reindirizza
        if (is_user_logged_in()) {
            wp_redirect(admin_url());
            exit;
        }
        
        // Se non è la richiesta di verifica, mostra il form pre-login
        if (!isset($_POST['cls_username']) && !isset($_GET['verified'])) {
            $this->show_pre_login_form();
            exit;
        }
        
        // Gestisce la verifica dell'utente
        if (isset($_POST['cls_username'])) {
            $this->verify_user();
        }
    }
    
    private function show_pre_login_form() {
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title>Verifica Accesso - <?php echo get_bloginfo('name'); ?></title>
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <style>
                body {
                    background: #f0f0f1;
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
                    margin: 0;
                    padding: 0;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    min-height: 100vh;
                }
                .pre-login-container {
                    background: #fff;
                    padding: 40px;
                    border-radius: 8px;
                    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                    width: 100%;
                    max-width: 400px;
                }
                .pre-login-form h2 {
                    text-align: center;
                    color: #3c434a;
                    margin-bottom: 30px;
                }
                .form-group {
                    margin-bottom: 20px;
                }
                .form-group label {
                    display: block;
                    margin-bottom: 8px;
                    font-weight: 600;
                    color: #3c434a;
                }
                .form-group input {
                    width: 100%;
                    padding: 12px;
                    border: 1px solid #dcdcde;
                    border-radius: 4px;
                    font-size: 16px;
                    box-sizing: border-box;
                }
                .form-group input:focus {
                    border-color: #2271b1;
                    outline: none;
                    box-shadow: 0 0 0 2px rgba(34, 113, 177, 0.2);
                }
                .submit-button {
                    width: 100%;
                    padding: 12px;
                    background: #2271b1;
                    color: white;
                    border: none;
                    border-radius: 4px;
                    font-size: 16px;
                    cursor: pointer;
                }
                .submit-button:hover {
                    background: #135e96;
                }
                .error-message {
                    color: #d63638;
                    background: #ffeaea;
                    padding: 10px;
                    border-radius: 4px;
                    margin-bottom: 15px;
                    display: none;
                }
                .loading {
                    text-align: center;
                    display: none;
                }
                .success-message {
                    color: #00a32a;
                    background: #f0f7f0;
                    padding: 10px;
                    border-radius: 4px;
                    margin-bottom: 15px;
                    display: none;
                }
            </style>
        </head>
        <body>
            <div class="pre-login-container">
                <div class="pre-login-form">
                    <h2>Verifica Accesso</h2>
                    
                    <div class="error-message" id="error-message"></div>
                    <div class="success-message" id="success-message"></div>
                    <div class="loading" id="loading">Verifica in corso...</div>
                    
                    <form id="pre-login-form">
                        <div class="form-group">
                            <label for="cls_username">Nome utente o Email</label>
                            <input type="text" id="cls_username" name="cls_username" required autocomplete="username">
                        </div>
                        <button type="submit" class="submit-button">Verifica Accesso</button>
                    </form>
                </div>
            </div>

            <script>
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
                                    window.location.href = '<?php echo wp_login_url(); ?>?verified=1&user=' + encodeURIComponent(username);
                                }, 1000);
                            } else {
                                errorDiv.text(response.data).show();
                            }
                        },
                        error: function() {
                            loadingDiv.hide();
                            errorDiv.text('Errore di connessione. Riprova.').show();
                        }
                    });
                });
            });
            </script>
        </body>
        </html>
        <?php
    }
    
    public function verify_user() {
        if (!wp_verify_nonce($_POST['nonce'], 'cls_nonce')) {
            wp_die('Security check failed');
        }
        
        $username = sanitize_text_field($_POST['username']);
        
        // Verifica se è un'email o username
        if (is_email($username)) {
            $user = get_user_by('email', $username);
        } else {
            $user = get_user_by('login', $username);
        }
        
        if ($user) {
            wp_send_json_success();
        } else {
            wp_send_json_error('Utente non trovato nel sistema.');
        }
    }
    
    public function modify_login_url($login_url, $redirect, $force_reauth) {
        // Aggiunge un parametro per bypassare il pre-login se necessario
        return add_query_arg('bypass_prelogin', '1', $login_url);
    }
}

// Inizializza il plugin
new CustomLoginSecurity();

// Hook AJAX
add_action('wp_ajax_nopriv_cls_verify_user', array(new CustomLoginSecurity(), 'verify_user'));
?>