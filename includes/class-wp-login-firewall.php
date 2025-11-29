<?php
/**
 * WP Login Firewall - Main Class
 * Version: 2.0 - Progressive Form Approach
 */

defined('ABSPATH') or die('Accesso negato!');

class WPLoginFirewall {

    private const MAX_ATTEMPTS = 5;
    private const LOCKOUT_TIME = 15 * MINUTE_IN_SECONDS;
    private const TOKEN_LIFETIME = 5 * MINUTE_IN_SECONDS;
    
    public function __construct() {
        add_action('login_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('login_init', array($this, 'customize_login_page'));
        add_filter('authenticate', array($this, 'verify_token_before_auth'), 1, 3);
        add_action('wp_ajax_nopriv_wplf_verify_user', array($this, 'verify_user'));
        add_action('wp_login_failed', array($this, 'log_failed_login'));
    }

    /**
     * Carica CSS e JavaScript
     */
    public function enqueue_scripts() {
        wp_enqueue_style('wplf-styles', plugin_dir_url(__DIR__) . 'assets/css/pre-login-form.css', array(), '2.0');
        wp_enqueue_script('wplf-ajax', plugin_dir_url(__DIR__) . 'assets/js/ajax-handler.js', array('jquery'), '2.0', true);
        wp_localize_script('wplf-ajax', 'wplf_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wplf_nonce')
        ));
    }

    /**
     * Personalizza la pagina di login
     */
    public function customize_login_page() {
        if (is_user_logged_in()) {
            wp_redirect(admin_url());
            exit;
        }
        
        add_action('login_head', function() {
            echo '<style>.login h1 { display: none; }</style>';
        });
        
        add_action('login_form', array($this, 'inject_verification_ui'));
    }
    
    /**
     * Inietta l'interfaccia di verifica nel form di login
     */
    public function inject_verification_ui() {
        ?>
        <!-- Step 1: Verifica Username -->
        <div id="wplf-verification-step" class="wplf-step wplf-active">
            <div class="wplf-header">
                <div class="wplf-icon">🔒</div>
                <h2>Verifica Accesso</h2>
                <p class="wplf-subtitle">Inserisci il tuo nome utente per continuare</p>
            </div>
            
            <div class="wplf-messages">
                <div id="wplf-error" class="wplf-message wplf-error" style="display:none;"></div>
                <div id="wplf-loading" class="wplf-loading" style="display:none;">
                    <span class="wplf-spinner"></span> Verifica in corso...
                </div>
            </div>
            
            <div class="wplf-form-group">
                <label for="wplf-username-input">Nome utente o Email</label>
                <input type="text" id="wplf-username-input" class="wplf-input" placeholder="Inserisci username o email" autocomplete="username">
            </div>
            
            <button type="button" id="wplf-verify-btn" class="wplf-button wplf-button-primary">
                Verifica Username
            </button>
        </div>
        
        <!-- Campo hidden per il token -->
        <input type="hidden" name="wplf_token" id="wplf-token" value="">
        
        <style>
            /* Nascondi inizialmente i campi WordPress */
            #loginform p:has(#user_login),
            #loginform p:has(#user_pass),
            #loginform .forgetmenot,
            #loginform .submit {
                display: none;
                transition: all 0.3s ease;
            }
            
            /* Mostra campi quando verificato */
            #loginform.wplf-verified p:has(#user_pass),
            #loginform.wplf-verified .forgetmenot,
            #loginform.wplf-verified .submit {
                display: block !important;
                animation: slideIn 0.3s ease;
            }
            
            #loginform.wplf-verified p:has(#user_login) {
                display: block !important;
            }
            
            #loginform.wplf-verified #user_login {
                background: #f0f0f0;
                color: #666;
            }
            
            @keyframes slideIn {
                from {
                    opacity: 0;
                    transform: translateY(-10px);
                }
                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }
        </style>
        
        <script>
            // Sincronizza il campo username WordPress con il nostro
            document.addEventListener('DOMContentLoaded', function() {
                var wpUsername = document.getElementById('user_login');
                var ourUsername = document.getElementById('wplf-username-input');
                
                if (wpUsername && ourUsername) {
                    ourUsername.addEventListener('input', function() {
                        wpUsername.value = this.value;
                    });
                }
            });
        </script>
        <?php
    }

    /**
     * AJAX: Verifica che l'username esista
     */
    public function verify_user() {
        check_ajax_referer('wplf_nonce', 'nonce');
        
        $username = sanitize_text_field($_POST['username']);
        
        if (empty($username)) {
            wp_send_json_error('Inserisci un nome utente o email.');
        }
        
        // Verifica se è un'email o username
        if (is_email($username)) {
            $user = get_user_by('email', $username);
        } else {
            $user = get_user_by('login', $username);
        }
        
        if (!$user) {
            wp_send_json_error('Utente non trovato nel sistema.');
        }
        
        // Genera token di sicurezza temporaneo
        $token = $this->generate_security_token($user->user_login);
        
        wp_send_json_success(array(
            'token' => $token,
            'username' => $user->user_login,
            'message' => 'Username verificato! Inserisci la password.'
        ));
    }
    
    /**
     * Genera un token di sicurezza temporaneo
     */
    private function generate_security_token($username) {
        $token = wp_hash($username . microtime() . wp_rand(), 'nonce');
        $transient_name = 'wplf_token_' . md5($username);
        set_transient($transient_name, $token, self::TOKEN_LIFETIME);
        return $token;
    }
    
    /**
     * Filtro authenticate: Verifica il token prima di permettere il login
     */
    public function verify_token_before_auth($user, $username, $password) {
        if (empty($username) || empty($password)) {
            return $user;
        }
        
        $token = isset($_POST['wplf_token']) ? sanitize_text_field($_POST['wplf_token']) : '';
        
        if (empty($token)) {
            $this->log_bypass_attempt($username, 'missing_token');
            return new WP_Error('wplf_no_verification', '<strong>ERRORE</strong>: Verifica username richiesta.');
        }
        
        $transient_name = 'wplf_token_' . md5($username);
        $stored_token = get_transient($transient_name);
        
        if ($stored_token !== $token) {
            $this->log_bypass_attempt($username, 'invalid_token');
            return new WP_Error('wplf_invalid_token', '<strong>ERRORE</strong>: Token non valido o scaduto.');
        }
        
        delete_transient($transient_name);
        return $user;
    }
    
    /**
     * Registra tentativi di bypass
     */
    private function log_bypass_attempt($username, $reason) {
        $log_entry = array(
            'timestamp' => current_time('mysql'),
            'ip' => $this->get_user_ip(),
            'username' => $username,
            'reason' => $reason,
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : 'Unknown'
        );
        
        $log = get_option('wplf_bypass_attempts_log', array());
        array_unshift($log, $log_entry);
        $log = array_slice($log, 0, 100);
        update_option('wplf_bypass_attempts_log', $log, false);
        
        $this->maybe_send_alert_email($log_entry);
    }
    
    /**
     * Registra login falliti
     */
    public function log_failed_login($username) {
        $token = isset($_POST['wplf_token']) ? sanitize_text_field($_POST['wplf_token']) : '';
        if (empty($token)) {
            $this->log_bypass_attempt($username, 'login_without_token');
        }
    }
    
    /**
     * Invia email di allerta
     */
    private function maybe_send_alert_email($log_entry) {
        $ip = $log_entry['ip'];
        $log = get_option('wplf_bypass_attempts_log', array());
        $recent_attempts = 0;
        $one_minute_ago = strtotime('-1 minute');
        
        foreach ($log as $entry) {
            if ($entry['ip'] === $ip && strtotime($entry['timestamp']) > $one_minute_ago) {
                $recent_attempts++;
            }
        }
        
        if ($recent_attempts >= 3) {
            $alert_sent_key = 'wplf_alert_sent_' . md5($ip);
            if (false === get_transient($alert_sent_key)) {
                $admin_email = get_option('admin_email');
                $subject = '[' . get_bloginfo('name') . '] Tentativo di bypass del Login Firewall';
                $message = sprintf(
                    "Rilevati %d tentativi di bypass nell'ultimo minuto.\n\nIP: %s\nUsername: %s\nMotivo: %s\nOra: %s\n",
                    $recent_attempts, $log_entry['ip'], $log_entry['username'], $log_entry['reason'], $log_entry['timestamp']
                );
                wp_mail($admin_email, $subject, $message);
                set_transient($alert_sent_key, true, 5 * MINUTE_IN_SECONDS);
            }
        }
    }
    
    /**
     * Ottiene l'IP dell'utente
     */
    private function get_user_ip() {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            return $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return $_SERVER['HTTP_X_FORWARDED_FOR'];
        }
        return $_SERVER['REMOTE_ADDR'];
    }
}
