<?php
// Previene l'accesso diretto
defined('ABSPATH') or die('Accesso negato!');

class WPLoginFirewall {

    private const MAX_ATTEMPTS = 5;
    private const LOCKOUT_TIME = 15 * MINUTE_IN_SECONDS;
    private static $form_rendered = false;
    
    public function __construct() {
        add_action('login_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('login_init', array($this, 'handle_pre_login_logic'));
    }

    private function get_user_ip() {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        return apply_filters('wplf_get_user_ip', $ip);
    }
    
    public function enqueue_scripts() {
        wp_enqueue_style('wplf-styles', plugin_dir_url(__DIR__) . 'assets/css/pre-login-form.css');
        wp_enqueue_script('wplf-ajax', plugin_dir_url(__DIR__) . 'assets/js/ajax-handler.js', array('jquery'), '1.0', true);
        wp_localize_script('wplf-ajax', 'wplf_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wplf_nonce')
        ));
    }

    public function handle_pre_login_logic() {
        if (is_user_logged_in()) {
            wp_redirect(admin_url());
            exit;
        }

        // Se l'utente è già verificato, popola il campo username e nascondi il pre-login form
        if (isset($_GET['verified']) && $_GET['verified'] === '1' && isset($_GET['user'])) {
            add_action('login_head', function() {
                echo '<style>#pre-login-container { display: none; }</style>';
            });
            add_filter('login_username', function($username) {
                return esc_attr(rawurldecode($_GET['user']));
            });
        } else {
            // Altrimenti, nascondi il form di login standard e mostra il nostro
            add_action('login_head', function() {
                echo '<style>#loginform { display: none; } .login #nav, .login #backtoblog { display: none; } </style>';
            });
            add_action('login_message', array($this, 'show_pre_login_form'));
        }
    }
    
    public function show_pre_login_form($message) {
        // Previene la doppia renderizzazione
        if (self::$form_rendered) {
            return $message;
        }
        self::$form_rendered = true;
        
        ob_start();
        ?>
        <div id="pre-login-container">
            <div class="pre-login-form">
                <h2>Verifica Accesso</h2>
                
                <div class="error-message" id="error-message"></div>
                <div class="success-message" id="success-message"></div>
                <div class="loading" id="loading">Verifica in corso...</div>
                
                <form id="pre-login-form" method="post">
                    <div class="form-group">
                        <label for="wplf_username">Nome utente o Email</label>
                        <input type="text" id="wplf_username" name="wplf_username" required autocomplete="username">
                    </div>
                    <button type="submit" class="submit-button">Verifica Accesso</button>
                </form>
            </div>
        </div>
        <?php
        return $message . ob_get_clean();
    }
    
    public function verify_user() {
        if (!wp_verify_nonce($_POST['nonce'], 'wplf_nonce')) {
            wp_die('Security check failed');
        }

        // Rate limiting temporaneamente disabilitato per i test
        /*
        $ip = $this->get_user_ip();
        $transient_name = 'wplf_login_attempts_' . $ip;
        $attempts = get_transient($transient_name);

        if ($attempts !== false && $attempts >= self::MAX_ATTEMPTS) {
            wp_send_json_error(sprintf(
                'Hai superato il numero massimo di tentativi. Riprova tra %d minuti.',
                self::LOCKOUT_TIME / MINUTE_IN_SECONDS
            ));
            return;
        }
        */
        
        $username = sanitize_text_field($_POST['wplf_username']);
        
        // Verifica se è un'email o username
        if (is_email($username)) {
            $user = get_user_by('email', $username);
        } else {
            $user = get_user_by('login', $username);
        }
        
        if ($user) {
            // Rate limiting disabilitato per i test
            // delete_transient($transient_name);
            
            // Passiamo l'username originale per il pre-compilamento
            $login_to_pass = is_email($username) ? $user->user_login : $username;
            $redirect_url = add_query_arg('verified', '1', wp_login_url());
            $redirect_url = add_query_arg('user', rawurlencode($login_to_pass), $redirect_url);
            wp_send_json_success(array('redirect_url' => $redirect_url));
        } else {
            // Rate limiting disabilitato per i test
            /*
            $attempts = ($attempts === false) ? 1 : $attempts + 1;
            set_transient($transient_name, $attempts, self::LOCKOUT_TIME);
            */
            wp_send_json_error('Utente non trovato nel sistema.');
        }
    }
}
