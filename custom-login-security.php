<?php
/**
 * Plugin Name: WP Secure Login System
 * Description: Aggiunge un layer di sicurezza prima del login
 * Version: 1.0
 * Author: DevRoom by RoomZero Creative Solutions
 * License: GPL2
 * Text Domain: wp-secure-login
 * url: https://www.roomzero.it
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
        wp_enqueue_style('cls-styles', plugin_dir_url(__FILE__) . 'css/pre-login-form.css');
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
            <?php wp_head(); ?>
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

            <?php wp_footer(); ?>
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
            $redirect_url = add_query_arg('verified', '1', wp_login_url());
            $redirect_url = add_query_arg('user', rawurlencode($username), $redirect_url);
            wp_send_json_success(array('redirect_url' => $redirect_url));
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