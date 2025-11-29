<?php
// Previene l'accesso diretto
defined('ABSPATH') or die('Accesso negato!');

class WPLoginFirewall {

    private const MAX_ATTEMPTS = 5;
    private const LOCKOUT_TIME = 15 * MINUTE_IN_SECONDS;
    private const TOKEN_LIFETIME = 5 * MINUTE_IN_SECONDS; // Token valido per 5 minuti
    private static $form_rendered = false;
    
    public function __construct() {
        add_action('login_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('login_init', array($this, 'handle_pre_login_logic'));
        add_filter('authenticate', array($this, 'verify_token_before_auth'), 1, 3);
        add_action('wp_login_failed', array($this, 'log_failed_bypass_attempt'));
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
        // Carica lo script nel footer per assicurarsi che il form sia già presente
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
            // Aggiungi script per precompilare e gestire il campo username
            add_action('login_footer', function() {
                $username = esc_js(rawurldecode($_GET['user']));
                ?>
                <script type="text/javascript">
                jQuery(document).ready(function($) {
                    var $userLogin = $('#user_login');
                    
                    // Debug: verifica lo stato del campo
                    console.log('Username field exists:', $userLogin.length);
                    console.log('Readonly:', $userLogin.prop('readonly'));
                    console.log('Disabled:', $userLogin.prop('disabled'));
                    console.log('Attributes:', $userLogin[0] ? $userLogin[0].attributes : 'none');
                    
                    // Precompila il campo username
                    $userLogin.val('<?php echo $username; ?>');
                    
                    // Rimuovi tutti gli attributi che potrebbero bloccare l'input
                    $userLogin.prop('readonly', false)
                             .prop('disabled', false)
                             .removeAttr('readonly')
                             .removeAttr('disabled')
                             .removeAttr('autocomplete');
                    
                    // Test se possiamo scrivere
                    $userLogin.on('keydown keypress keyup', function(e) {
                        console.log('Key event:', e.type, e.key);
                    });
                    
                    // Focus automatico sul campo password
                    setTimeout(function() {
                        $('#user_pass').focus();
                    }, 100);
                });
                </script>
                <?php
            });
        } else {
            // Altrimenti, nascondi il form di login standard e mostra il nostro
            add_action('login_head', function() {
                echo '<style>#loginform { display: none; } .login #nav, .login #backtoblog { display: none; } </style>';
            });
            add_filter('login_message', array($this, 'show_pre_login_form'));
        }
    }
    
    /**
     * Mostra il form di pre-login
     */
    public function show_pre_login_form($message) {
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
        
        // Recupera i parametri GET passati dal JavaScript
        $current_params = isset($_POST['current_params']) ? $_POST['current_params'] : array();
        
        // Verifica se è un'email o username
        if (is_email($username)) {
            $user = get_user_by('email', $username);
        } else {
            $user = get_user_by('login', $username);
        }
        
        if ($user) {
            // Rate limiting disabilitato per i test
            // delete_transient($transient_name);
            
            // Genera un token di sicurezza temporaneo
            $token = $this->generate_security_token($user->user_login);
            
            // Passiamo l'username originale per il pre-compilamento
            $login_to_pass = is_email($username) ? $user->user_login : $username;
            
            // Costruisci l'URL mantenendo tutti i parametri originali
            $redirect_url = wp_login_url();
            
            // Mantieni i parametri passati dal JavaScript (dalla pagina corrente)
            if (!empty($current_params['redirect_to'])) {
                $redirect_url = add_query_arg('redirect_to', urlencode($current_params['redirect_to']), $redirect_url);
            }
            if (!empty($current_params['reauth'])) {
                $redirect_url = add_query_arg('reauth', sanitize_text_field($current_params['reauth']), $redirect_url);
            }
            
            // Aggiungi i nostri parametri
            $redirect_url = add_query_arg('verified', '1', $redirect_url);
            $redirect_url = add_query_arg('user', rawurlencode($login_to_pass), $redirect_url);
            $redirect_url = add_query_arg('wplf_token', $token, $redirect_url);
            
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
    
    /**
     * Genera un token di sicurezza temporaneo per l'utente
     */
    private function generate_security_token($username) {
        $token = wp_hash($username . microtime() . wp_rand(), 'nonce');
        $transient_name = 'wplf_token_' . md5($username);
        
        set_transient($transient_name, $token, self::TOKEN_LIFETIME);
        
        return $token;
    }
    
    /**
     * Verifica il token prima di permettere l'autenticazione
     */
    public function verify_token_before_auth($user, $username, $password) {
        // Ignora se non è un tentativo di login reale
        if (empty($username) || empty($password)) {
            return $user;
        }
        
        // Verifica se c'è un token valido
        $token_provided = isset($_GET['wplf_token']) ? sanitize_text_field($_GET['wplf_token']) : '';
        
        if (empty($token_provided)) {
            $this->log_bypass_attempt($username, 'missing_token');
            return new WP_Error(
                'wplf_no_verification',
                '<strong>ERRORE</strong>: Devi prima verificare la tua identità. <a href="' . wp_login_url() . '">Torna al form di verifica</a>'
            );
        }
        
        // Verifica la validità del token
        $transient_name = 'wplf_token_' . md5($username);
        $stored_token = get_transient($transient_name);
        
        if ($stored_token !== $token_provided) {
            $this->log_bypass_attempt($username, 'invalid_token');
            return new WP_Error(
                'wplf_invalid_token',
                '<strong>ERRORE</strong>: Token di verifica non valido o scaduto. <a href="' . wp_login_url() . '">Torna al form di verifica</a>'
            );
        }
        
        // Token valido, rimuovilo per prevenire riutilizzo
        delete_transient($transient_name);
        
        return $user;
    }
    
    /**
     * Registra i tentativi di bypass del sistema di verifica
     */
    private function log_bypass_attempt($username, $reason) {
        $ip = $this->get_user_ip();
        $log_entry = array(
            'timestamp' => current_time('mysql'),
            'ip' => $ip,
            'username' => $username,
            'reason' => $reason,
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : 'Unknown'
        );
        
        // Recupera il log esistente
        $log = get_option('wplf_bypass_attempts_log', array());
        
        // Aggiungi il nuovo tentativo
        array_unshift($log, $log_entry);
        
        // Mantieni solo gli ultimi 100 tentativi
        $log = array_slice($log, 0, 100);
        
        // Salva il log
        update_option('wplf_bypass_attempts_log', $log, false);
        
        // Opzionale: invia notifica email all'amministratore
        $this->maybe_send_alert_email($log_entry);
    }
    
    /**
     * Registra i tentativi di login falliti (possibili tentativi di bypass)
     */
    public function log_failed_bypass_attempt($username) {
        // Verifica se il tentativo era senza token
        $token_provided = isset($_GET['wplf_token']) ? sanitize_text_field($_GET['wplf_token']) : '';
        
        if (empty($token_provided)) {
            $this->log_bypass_attempt($username, 'login_without_token');
        }
    }
    
    /**
     * Invia email di allerta all'amministratore dopo multipli tentativi sospetti
     */
    private function maybe_send_alert_email($log_entry) {
        // Conta i tentativi dall'ultimo minuto dallo stesso IP
        $ip = $log_entry['ip'];
        $log = get_option('wplf_bypass_attempts_log', array());
        $recent_attempts = 0;
        $one_minute_ago = strtotime('-1 minute');
        
        foreach ($log as $entry) {
            if ($entry['ip'] === $ip && strtotime($entry['timestamp']) > $one_minute_ago) {
                $recent_attempts++;
            }
        }
        
        // Invia email se ci sono più di 3 tentativi nell'ultimo minuto
        if ($recent_attempts >= 3) {
            $admin_email = get_option('admin_email');
            $subject = '[' . get_bloginfo('name') . '] Tentativo di bypass del Login Firewall';
            $message = sprintf(
                "Sono stati rilevati %d tentativi sospetti di bypass del sistema di verifica login nell'ultimo minuto.\n\n" .
                "Dettagli dell'ultimo tentativo:\n" .
                "IP: %s\n" .
                "Username: %s\n" .
                "Motivo: %s\n" .
                "Ora: %s\n" .
                "User Agent: %s\n\n" .
                "Si consiglia di verificare i log completi nel pannello di amministrazione.",
                $recent_attempts,
                $log_entry['ip'],
                $log_entry['username'],
                $log_entry['reason'],
                $log_entry['timestamp'],
                $log_entry['user_agent']
            );
            
            // Usa un transient per evitare di inviare troppe email
            $alert_sent_key = 'wplf_alert_sent_' . md5($ip);
            if (false === get_transient($alert_sent_key)) {
                wp_mail($admin_email, $subject, $message);
                set_transient($alert_sent_key, true, 5 * MINUTE_IN_SECONDS);
            }
        }
    }
}
