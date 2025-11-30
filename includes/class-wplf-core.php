<?php
/**
 * WP Login Firewall - Core Class
 * 
 * Gestisce l'intercettazione del login e la verifica a due step
 */

// Previene accesso diretto
if (!defined('ABSPATH')) {
    exit;
}

class WPLF_Core {
    
    private $rate_limiter;
    private $logger;
    private $ip_blocker;
    
    /**
     * Costruttore
     */
    public function __construct() {
        // Carica le classi di sicurezza
        require_once WPLF_PLUGIN_DIR . 'includes/class-wplf-rate-limiter.php';
        require_once WPLF_PLUGIN_DIR . 'includes/class-wplf-logger.php';
        require_once WPLF_PLUGIN_DIR . 'includes/class-wplf-ip-blocker.php';
        
        $this->rate_limiter = new WPLF_Rate_Limiter();
        $this->logger = new WPLF_Logger();
        $this->ip_blocker = new WPLF_IP_Blocker();
        
        // Intercetta il login
        add_action('login_init', array($this, 'intercept_login'));
        
        // Handler AJAX per la verifica username
        add_action('wp_ajax_nopriv_wplf_verify_username', array($this, 'ajax_verify_username'));
    }
    
    /**
     * Intercetta l'accesso a wp-login.php
     */
    public function intercept_login() {
        // Verifica se l'IP è bloccato
        if ($this->ip_blocker->is_blocked()) {
            $this->logger->log_attempt('', 'blocked', 'IP bloccato - tentativo di accesso');
            $this->show_blocked_page();
            exit;
        }
        
        // Se c'è un token valido, lascia passare
        if (isset($_GET['wplf_token']) && $this->validate_token($_GET['wplf_token'])) {
            return;
        }
        
        // Se è logout o altre azioni specifiche, lascia passare
        if (isset($_GET['action']) && in_array($_GET['action'], array('logout', 'lostpassword', 'retrievepassword', 'resetpass', 'rp', 'register'))) {
            return;
        }
        
        // Mostra la pagina di verifica personalizzata
        $this->show_verification_page();
        exit;
    }
    
    /**
     * Mostra la pagina HTML di verifica personalizzata
     */
    private function show_verification_page() {
        // Preserva i parametri URL originali (redirect_to, reauth, ecc.)
        $redirect_to = isset($_GET['redirect_to']) ? $_GET['redirect_to'] : '';
        $reauth = isset($_GET['reauth']) ? $_GET['reauth'] : '';
        
        ?>
        <!DOCTYPE html>
        <html lang="it">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Verifica Accesso - <?php echo get_bloginfo('name'); ?></title>
            <link rel="stylesheet" href="<?php echo WPLF_PLUGIN_URL; ?>assets/css/style.css">
        </head>
        <body class="wplf-page">
            <div class="wplf-container">
                <div class="wplf-card">
                    <div class="wplf-header">
                        <h1 class="wplf-title">Verifica Accesso</h1>
                        <p class="wplf-subtitle">Inserisci il tuo username o email per continuare</p>
                    </div>
                    
                    <div id="wplf-message" class="wplf-message"></div>
                    
                    <form id="wplf-verify-form" class="wplf-form" method="post">
                        <input type="hidden" name="action" value="wplf_verify_username">
                        <input type="hidden" name="redirect_to" value="<?php echo esc_attr($redirect_to); ?>">
                        <input type="hidden" name="reauth" value="<?php echo esc_attr($reauth); ?>">
                        <?php wp_nonce_field('wplf_verify', 'wplf_nonce'); ?>
                        
                        <div class="wplf-form-group">
                            <label for="wplf-username" class="wplf-label">Username o Email</label>
                            <input 
                                type="text" 
                                id="wplf-username" 
                                name="wplf_username" 
                                class="wplf-input" 
                                autocomplete="username"
                                required
                                autofocus
                            >
                        </div>
                        
                        <button type="submit" id="wplf-submit" class="wplf-button">
                            <span class="wplf-button-text">Verifica</span>
                            <span class="wplf-spinner"></span>
                        </button>
                    </form>
                    
                    <div class="wplf-footer">
                        <a href="<?php echo home_url(); ?>" class="wplf-back-link">← Torna al sito</a>
                    </div>
                </div>
            </div>
            
            <script src="<?php echo includes_url('js/jquery/jquery.min.js'); ?>"></script>
            <script>
                var wplf = {
                    ajax_url: '<?php echo admin_url('admin-ajax.php'); ?>'
                };
            </script>
            <script src="<?php echo WPLF_PLUGIN_URL; ?>assets/js/script.js"></script>
        </body>
        </html>
        <?php
    }
    
    /**
     * Handler AJAX per la verifica username
     */
    public function ajax_verify_username() {
        // Verifica se l'IP è bloccato
        if ($this->ip_blocker->is_blocked()) {
            $this->logger->log_attempt('', 'blocked', 'Tentativo AJAX da IP bloccato');
            $time_remaining = $this->ip_blocker->get_formatted_time_remaining();
            wp_send_json_error('Il tuo IP è stato bloccato. Riprova tra ' . $time_remaining);
        }
        
        // Verifica nonce
        if (!isset($_POST['wplf_nonce']) || !wp_verify_nonce($_POST['wplf_nonce'], 'wplf_verify')) {
            $this->logger->log_attempt('', 'failed', 'Nonce non valido');
            wp_send_json_error('Richiesta non valida');
        }
        
        $username = sanitize_text_field($_POST['wplf_username']);
        
        if (empty($username)) {
            wp_send_json_error('Inserisci un username o email');
        }
        
        // Verifica rate limiting
        if ($this->rate_limiter->is_rate_limited()) {
            $this->logger->log_attempt($username, 'rate_limited', 'Rate limit superato');
            
            // Verifica se bloccare IP anche per rate limiting
            $client_ip = $this->get_client_ip();
            if ($this->ip_blocker->check_and_block($client_ip, $this->logger)) {
                wp_send_json_error('Troppi tentativi falliti. Il tuo IP è stato bloccato per ' . 
                    get_option('wplf_ip_block_duration', 24) . ' ore.');
            }
            
            $time_remaining = $this->rate_limiter->get_formatted_time_remaining();
            wp_send_json_error('Troppi tentativi. Riprova tra ' . $time_remaining);
        }
        
        // Verifica se l'utente esiste
        $user = get_user_by('login', $username);
        if (!$user) {
            $user = get_user_by('email', $username);
        }
        
        // Ottiene IP per logging e controlli
        $client_ip = $this->get_client_ip();
        
        if (!$user) {
            // Log tentativo fallito PRIMA di incrementare rate limiter
            $this->logger->log_attempt($username, 'failed', 'Username/email non trovato');
            
            // Incrementa tentativi rate limiter
            $this->rate_limiter->increment_attempts();
            
            // Verifica se bloccare IP (conta tutti i failed + rate_limited nell'ultima ora)
            if ($this->ip_blocker->check_and_block($client_ip, $this->logger)) {
                wp_send_json_error('Troppi tentativi falliti. Il tuo IP è stato bloccato per ' . 
                    get_option('wplf_ip_block_duration', 24) . ' ore.');
            }
            
            wp_send_json_error('Credenziali non valide');
        }
        
        // Verifica se l'utente è admin - whitelist automatica
        if (user_can($user, 'manage_options')) {
            // Admin: resetta rate limit e non bloccare mai
            $this->rate_limiter->reset_rate_limit();
            
            // Se era bloccato, sbloccalo
            $client_ip = $this->get_client_ip();
            if ($this->ip_blocker->is_blocked($client_ip)) {
                $this->ip_blocker->unblock_ip($client_ip);
            }
            
            // Log successo admin
            $this->logger->log_attempt($username, 'success', 'Admin verificato (whitelist automatica)');
        } else {
            // Utente normale: resetta rate limit
            $this->rate_limiter->reset_rate_limit();
            
            // Log successo
            $this->logger->log_attempt($username, 'success', 'Username verificato con successo');
        }
        
        
        // Genera token sicuro
        $token = wp_generate_password(32, false);
        
        // Salva il token come transient (5 minuti)
        set_transient('wplf_token_' . $token, array(
            'user_id' => $user->ID,
            'username' => $username,
            'timestamp' => current_time('timestamp')
        ), 5 * MINUTE_IN_SECONDS);
        
        // Costruisci URL di redirect con token
        $redirect_url = wp_login_url();
        $redirect_url = add_query_arg('wplf_token', $token, $redirect_url);
        
        // Preserva parametri originali
        if (!empty($_POST['redirect_to'])) {
            $redirect_url = add_query_arg('redirect_to', urlencode($_POST['redirect_to']), $redirect_url);
        }
        if (!empty($_POST['reauth'])) {
            $redirect_url = add_query_arg('reauth', $_POST['reauth'], $redirect_url);
        }
        
        wp_send_json_success(array(
            'message' => 'Verifica completata!',
            'redirect' => $redirect_url
        ));
    }
    
    /**
     * Valida il token
     */
    private function validate_token($token) {
        $token_data = get_transient('wplf_token_' . $token);
        
        if (!$token_data) {
            return false;
        }
        
        // Elimina il token (one-time use)
        delete_transient('wplf_token_' . $token);
        
        return true;
    }
    
    /**
     * Ottiene l'IP del client
     */
    private function get_client_ip() {
        $ip = '';
        
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }
    
    /**
     * Mostra la pagina di IP bloccato
     */
    private function show_blocked_page() {
        $time_remaining = $this->ip_blocker->get_formatted_time_remaining();
        
        ?>
        <!DOCTYPE html>
        <html lang="it">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Accesso Bloccato - <?php echo get_bloginfo('name'); ?></title>
            <link rel="stylesheet" href="<?php echo WPLF_PLUGIN_URL; ?>assets/css/style.css">
        </head>
        <body class="wplf-page">
            <div class="wplf-container">
                <div class="wplf-card">
                    <div class="wplf-header">
                        <div class="wplf-icon">🚫</div>
                        <h1 class="wplf-title">Accesso Temporaneamente Bloccato</h1>
                        <p class="wplf-subtitle">Il tuo indirizzo IP è stato bloccato per motivi di sicurezza</p>
                    </div>
                    
                    <div class="wplf-message wplf-error" style="display: block;">
                        Troppi tentativi di accesso falliti. Riprova tra <strong><?php echo esc_html($time_remaining); ?></strong>.
                    </div>
                    
                    <div class="wplf-footer">
                        <a href="<?php echo home_url(); ?>" class="wplf-back-link">← Torna al sito</a>
                    </div>
                </div>
            </div>
        </body>
        </html>
        <?php
    }
}
