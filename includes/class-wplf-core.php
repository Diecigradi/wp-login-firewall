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
        require_once WPLF_PLUGIN_DIR . 'includes/class-wplf-login-enhancer.php';
        
        $this->rate_limiter = new WPLF_Rate_Limiter();
        $this->logger = new WPLF_Logger();
        $this->ip_blocker = new WPLF_IP_Blocker();
        
        // Inizializza login enhancer (countdown token)
        new WPLF_Login_Enhancer();
        
        // Intercetta il login
        add_action('login_init', array($this, 'intercept_login'));
        
        // Hook authenticate per tracking tentativi password falliti (priorità 30, dopo validazione WP)
        add_filter('authenticate', array($this, 'track_password_attempts'), 30, 3);
        
        // Handler AJAX per la verifica username
        add_action('wp_ajax_nopriv_wplf_verify_username', array($this, 'ajax_verify_username'));
        
        // Handler AJAX per cancellare i log di debug
        add_action('wp_ajax_nopriv_wplf_clear_debug', array($this, 'ajax_clear_debug'));
        add_action('wp_ajax_wplf_clear_debug', array($this, 'ajax_clear_debug'));
        
        // Elimina token dopo login riuscito
        add_action('wp_login', array($this, 'cleanup_token_after_login'), 10, 2);
    }
    
    /**
     * Intercetta l'accesso a wp-login.php
     */
    public function intercept_login() {
        // DEBUG: Log dello stato corrente
        if (get_option('wplf_debug_mode', 0)) {
            WPLF_Debug::get_instance()->log_intercept();
        }
        
        // Verifica se l'IP è bloccato
        if ($this->ip_blocker->is_blocked()) {
            $this->logger->log_attempt('', 'blocked', 'IP bloccato - tentativo di accesso');
            if (get_option('wplf_debug_mode', 0)) {
                WPLF_Debug::get_instance()->add_action('IP bloccato, mostra pagina blocco');
            }
            $this->show_blocked_page();
            exit;
        }
        
        // Se c'è un token valido nel cookie, lascia passare
        if (isset($_COOKIE['wplf_token']) && $this->validate_token($_COOKIE['wplf_token'])) {
            if (get_option('wplf_debug_mode', 0)) {
                WPLF_Debug::get_instance()->add_action('Token cookie valido, lascia passare');
            }
            return;
        }
        
        // Backward compatibility: leggi anche da URL (deprecato)
        if (isset($_GET['wplf_token']) && $this->validate_token($_GET['wplf_token'])) {
            if (get_option('wplf_debug_mode', 0)) {
                WPLF_Debug::get_instance()->add_action('Token URL valido, lascia passare');
            }
            return;
        }
        
        // Se è logout o altre azioni specifiche, lascia passare
        if (isset($_GET['action']) && in_array($_GET['action'], array('logout', 'lostpassword', 'retrievepassword', 'resetpass', 'rp', 'register'))) {
            if (get_option('wplf_debug_mode', 0)) {
                WPLF_Debug::get_instance()->add_action('Azione speciale: ' . $_GET['action']);
            }
            return;
        }
        
        // Mostra la pagina di verifica personalizzata
        if (get_option('wplf_debug_mode', 0)) {
            WPLF_Debug::get_instance()->add_action('Nessun token valido, mostra verifica');
        }
        $this->show_verification_page();
        exit;
    }
    
    /**
     * Aggiunge un messaggio al log di debug
     */
    private function add_debug_log($message) {
        // Deprecato: ora gestito da WPLF_Debug
        if (get_option('wplf_debug_mode', 0)) {
            WPLF_Debug::get_instance()->add_action($message);
        }
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
                        <h1 class="wplf-title">VERIFICA ACCESSO</h1>
                        <h2 class="wplf-subtitle">Login a due fattori - <strong><?php echo get_bloginfo('name'); ?></strong></h2>
                        <p class="wplf-subtitle">L'accesso a questo sito è protetto.<br>Per accedere all'area di amministrazione è necessario verificare la tua identità.<br>Inserisci il tuo <strong>username o email</strong> per procedere.</p>
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
                    
                    <?php 
                    // Renderizza pannello debug se abilitato
                    if (get_option('wplf_debug_mode', 0)) {
                        WPLF_Debug::get_instance()->render_panel();
                    }
                    ?>
                    
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
        
        // Ottiene IP per logging e controlli
        $client_ip = $this->get_client_ip();
        
        // RATE LIMIT DEDICATO PER VERIFICA USERNAME (previene loop infiniti)
        $verify_limit_attempts = get_option('wplf_verify_limit_attempts', 3);
        $verify_limit_minutes = get_option('wplf_verify_limit_minutes', 15);
        $verify_rate_key = 'wplf_verify_rate_' . md5($client_ip);
        $verify_attempts = get_transient($verify_rate_key);
        
        if ($verify_attempts === false) {
            $verify_attempts = 0;
        }
        
        // Verifica se l'utente esiste PRIMA del rate limiting (per whitelist admin)
        $user = get_user_by('login', $username);
        if (!$user) {
            $user = get_user_by('email', $username);
        }
        
        // Verifica se whitelist admin è abilitata
        $admin_whitelist_enabled = get_option('wplf_admin_whitelist', 1);
        
        // Se NON è admin O whitelist disabilitata, applica rate limit verifica
        if (!($user && user_can($user, 'manage_options') && $admin_whitelist_enabled)) {
            if ($verify_attempts >= $verify_limit_attempts) {
                $ttl = $this->get_transient_ttl($verify_rate_key);
                $minutes_remaining = ceil($ttl / 60);
                
                $this->logger->log_attempt($username, 'rate_limited', 'Rate limit verifica username superato');
                wp_send_json_error('Troppi tentativi di verifica. Riprova tra ' . $minutes_remaining . ' minut' . ($minutes_remaining == 1 ? 'o' : 'i'));
            }
        }
        
        // Verifica se whitelist admin è abilitata
        $admin_whitelist_enabled = get_option('wplf_admin_whitelist', 1);
        
        // Se è admin E whitelist abilitata, bypass completo
        if ($user && user_can($user, 'manage_options') && $admin_whitelist_enabled) {
            // Admin: resetta rate limit e non bloccare mai
            $this->rate_limiter->reset_rate_limit();
            
            // Se era bloccato, sbloccalo
            if ($this->ip_blocker->is_blocked($client_ip)) {
                $this->ip_blocker->unblock_ip($client_ip);
            }
            
            // Log successo admin
            $this->logger->log_attempt($username, 'success', 'Admin verificato (whitelist automatica)');
            
            // Genera token sicuro con random_bytes (crittograficamente sicuro)
            $token = bin2hex(random_bytes(32));
            
            // Ottiene scadenza token configurabile (default: 5 minuti)
            $token_lifetime = get_option('wplf_token_lifetime', 5) * MINUTE_IN_SECONDS;
            $max_password_attempts = get_option('wplf_max_password_attempts', 5);
            
            // Salva il token come transient
            set_transient('wplf_token_' . $token, array(
                'user_id' => $user->ID,
                'username' => $username,
                'timestamp' => current_time('timestamp'),
                'failed_attempts' => 0,
                'max_attempts' => $max_password_attempts
            ), $token_lifetime);
            
            // DEBUG: Log creazione token
            if (get_option('wplf_debug_mode', 0)) {
                WPLF_Debug::get_instance()->log_token_created($username, $token, $token_lifetime);
            }
            
            // Imposta cookie sicuro invece di passare token in URL
            setcookie(
                'wplf_token',
                $token,
                array(
                    'expires' => time() + $token_lifetime,
                    'path' => COOKIEPATH,
                    'domain' => COOKIE_DOMAIN,
                    'secure' => is_ssl(), // Solo HTTPS se disponibile
                    'httponly' => true, // Non accessibile via JavaScript
                    'samesite' => 'Lax' // Protezione CSRF
                )
            );
            
            // Redirect senza token in URL
            $redirect_url = wp_login_url();
            
            // Preserva parametri originali
            if (!empty($_POST['redirect_to'])) {
                $redirect_url = add_query_arg('redirect_to', urlencode($_POST['redirect_to']), $redirect_url);
            }
            if (!empty($_POST['reauth'])) {
                $redirect_url = add_query_arg('reauth', $_POST['reauth'], $redirect_url);
            }
            
            wp_send_json_success(array(
                'message' => 'Verifica completata! Accesso admin in corso...',
                'redirect' => $redirect_url
            ));
        }
        
        // Se non è admin, verifica rate limiting
        if ($this->rate_limiter->is_rate_limited()) {
            $this->logger->log_attempt($username, 'rate_limited', 'Rate limit superato');
            
            // Verifica se bloccare IP anche per rate limiting
            if ($this->ip_blocker->check_and_block($client_ip, $this->logger)) {
                wp_send_json_error('Troppi tentativi falliti. Il tuo IP è stato bloccato per ' . 
                    get_option('wplf_ip_block_duration', 24) . ' ore.');
            }
            
            $time_remaining = $this->rate_limiter->get_formatted_time_remaining();
            wp_send_json_error('Troppi tentativi. Riprova tra ' . $time_remaining);
        }
        
        // Se l'utente non esiste
        if (!$user) {
            // Incrementa contatore verifica username
            set_transient($verify_rate_key, $verify_attempts + 1, $verify_limit_minutes * MINUTE_IN_SECONDS);
            
            // Log tentativo fallito PRIMA di incrementare rate limiter
            $this->logger->log_attempt($username, 'failed', 'Username/email non trovato');
            
            // Incrementa tentativi rate limiter
            $this->rate_limiter->increment_attempts();
            
            // Delay randomizzato per prevenire timing attack (200-500ms)
            usleep(rand(200000, 500000));
            
            // Verifica se bloccare IP (conta tutti i failed + rate_limited nell'ultima ora)
            if ($this->ip_blocker->check_and_block($client_ip, $this->logger)) {
                // Messaggio generico (non rivela se username esiste)
                wp_send_json_error('Credenziali non valide. Riprova più tardi.');
            }
            
            // Messaggio generico uguale sia per user non esistente che per altri errori
            wp_send_json_error('Credenziali non valide');
        }
        
        // Utente normale (non admin): resetta rate limit e procedi
        $this->rate_limiter->reset_rate_limit();
        
        // Resetta anche contatore verifica username
        delete_transient($verify_rate_key);
        
        // Log successo
        $this->logger->log_attempt($username, 'success', 'Username verificato con successo');
        
        // Genera token sicuro con random_bytes (crittograficamente sicuro)
        $token = bin2hex(random_bytes(32));
        
        // Ottiene scadenza token configurabile (default: 5 minuti)
        $token_lifetime = get_option('wplf_token_lifetime', 5) * MINUTE_IN_SECONDS;
        $max_password_attempts = get_option('wplf_max_password_attempts', 5);
        
        // Salva il token come transient
        set_transient('wplf_token_' . $token, array(
            'user_id' => $user->ID,
            'username' => $username,
            'timestamp' => current_time('timestamp'),
            'failed_attempts' => 0,
            'max_attempts' => $max_password_attempts
        ), $token_lifetime);
        
        // DEBUG: Log creazione token
        if (get_option('wplf_debug_mode', 0)) {
            WPLF_Debug::get_instance()->log_token_created($username, $token, $token_lifetime);
        }
        
        // Imposta cookie sicuro invece di passare token in URL
        setcookie(
            'wplf_token',
            $token,
            array(
                'expires' => time() + $token_lifetime,
                'path' => COOKIEPATH,
                'domain' => COOKIE_DOMAIN,
                'secure' => is_ssl(), // Solo HTTPS se disponibile
                'httponly' => true, // Non accessibile via JavaScript
                'samesite' => 'Lax' // Protezione CSRF
            )
        );
        
        // Redirect senza token in URL
        $redirect_url = wp_login_url();
        
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
     * Valida il token dal cookie (NON elimina, solo verifica)
     */
    private function validate_token($token = null) {
        // Se non passato come parametro, leggi dal cookie
        if ($token === null) {
            $token = isset($_COOKIE['wplf_token']) ? $_COOKIE['wplf_token'] : '';
        }
        
        if (empty($token)) {
            return false;
        }
        
        // Verifica se il token esiste ed è ancora valido
        $token_data = get_transient('wplf_token_' . $token);
        
        if (!$token_data) {
            return false;
        }
        
        // Token valido: NON eliminare qui
        // Sarà eliminato dopo login effettivo o scadenza naturale
        return true;
    }
    
    /**
     * Elimina token e cookie dopo login riuscito
     * 
     * @param string $user_login Username dell'utente
     * @param WP_User $user Oggetto utente
     */
    public function cleanup_token_after_login($user_login, $user) {
        // Log login riuscito
        $this->logger->log_attempt(
            $user_login,
            'login_success',
            'Login WordPress completato con successo'
        );
        
        // Leggi token dal cookie
        $token = isset($_COOKIE['wplf_token']) ? $_COOKIE['wplf_token'] : '';
        
        if (empty($token)) {
            return;
        }
        
        // Elimina transient token
        delete_transient('wplf_token_' . $token);
        
        // Elimina cookie
        setcookie(
            'wplf_token',
            '',
            array(
                'expires' => time() - 3600,
                'path' => COOKIEPATH,
                'domain' => COOKIE_DOMAIN,
                'secure' => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax'
            )
        );
    }
    
    /**
     * Traccia i tentativi di password falliti e brucia il token se supera il limite
     * 
     * Hook: authenticate (priorità 30, dopo validazione WordPress)
     * 
     * @param WP_User|WP_Error|null $user Utente autenticato o errore
     * @param string $username Username fornito
     * @param string $password Password fornita
     * @return WP_User|WP_Error
     */
    public function track_password_attempts($user, $username, $password) {
        // Se non c'è token, skip (login diretto senza verifica)
        if (!isset($_COOKIE['wplf_token']) || empty($_COOKIE['wplf_token'])) {
            return $user;
        }
        
        // IMPORTANTE: traccia SOLO se c'è stato un submit del form (POST)
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($username) || empty($password)) {
            return $user;
        }
        
        $token = sanitize_text_field($_COOKIE['wplf_token']);
        $token_data = get_transient('wplf_token_' . $token);
        
        // Token non valido o scaduto
        if (!$token_data) {
            return $user;
        }
        
        // Se login riuscito, non fare nulla (cleanup gestito da wp_login hook)
        if (!is_wp_error($user) && $user instanceof WP_User) {
            return $user;
        }
        
        // Se è un errore di WordPress (password sbagliata, ecc.)
        if (is_wp_error($user)) {
            // Incrementa contatore tentativi falliti
            $token_data['failed_attempts']++;
            
            // Log tentativo password fallito
            $this->logger->log_attempt(
                $token_data['username'],
                'password_failed',
                'Password errata - Tentativo ' . $token_data['failed_attempts'] . ' di ' . $token_data['max_attempts']
            );
            
            // Verifica se ha raggiunto il limite
            if ($token_data['failed_attempts'] >= $token_data['max_attempts']) {
                // Log token bruciato
                $this->logger->log_attempt(
                    $token_data['username'],
                    'token_burned',
                    'Token invalidato dopo ' . $token_data['max_attempts'] . ' tentativi password falliti'
                );
                
                // TOKEN BRUCIATO: elimina token e cookie
                delete_transient('wplf_token_' . $token);
                setcookie(
                    'wplf_token',
                    '',
                    array(
                        'expires' => time() - 3600,
                        'path' => COOKIEPATH,
                        'domain' => COOKIE_DOMAIN,
                        'secure' => is_ssl(),
                        'httponly' => true,
                        'samesite' => 'Lax'
                    )
                );
                
                // Log evento token bruciato
                if (get_option('wplf_debug_mode', 0)) {
                    WPLF_Debug::get_instance()->add_action('Token bruciato: troppi tentativi password (' . $token_data['failed_attempts'] . '/' . $token_data['max_attempts'] . ')');
                }
                
                // Reindirizza a verifica con messaggio errore
                $redirect_url = wp_login_url();
                $redirect_url = add_query_arg('wplf_error', 'token_burned', $redirect_url);
                
                // Forza redirect (interrompe autenticazione)
                wp_redirect($redirect_url);
                exit;
            }
            
            // Aggiorna token con nuovo contatore
            $token_lifetime = get_option('wplf_token_lifetime', 5) * MINUTE_IN_SECONDS;
            $elapsed = current_time('timestamp') - $token_data['timestamp'];
            $remaining = $token_lifetime - $elapsed;
            
            if ($remaining > 0) {
                set_transient('wplf_token_' . $token, $token_data, $remaining);
            }
            
            // Debug log
            if (get_option('wplf_debug_mode', 0)) {
                WPLF_Debug::get_instance()->add_action('Password errata: tentativo ' . $token_data['failed_attempts'] . '/' . $token_data['max_attempts']);
            }
        }
        
        return $user;
    }
    
    /**
     * Ottiene il TTL rimanente di un transient
     * 
     * @param string $transient_key Chiave del transient
     * @return int TTL in secondi
     */
    private function get_transient_ttl($transient_key) {
        $timeout_key = '_transient_timeout_' . $transient_key;
        $timeout = get_option($timeout_key);
        
        if ($timeout === false) {
            return 0;
        }
        
        $remaining = $timeout - time();
        return max(0, $remaining);
    }
    
    /**
     * Handler AJAX per cancellare i log di debug
     */
    public function ajax_clear_debug() {
        if (get_option('wplf_debug_mode', 0)) {
            WPLF_Debug::get_instance()->clear_logs();
        }
        wp_send_json_success();
    }
    
    /**
     * Ottiene l'IP del client in modo sicuro
     * 
     * Usa solo REMOTE_ADDR per prevenire IP spoofing.
     * Se dietro proxy/CDN affidabile (Cloudflare, ecc), decommentare la logica X-Forwarded-For
     */
    private function get_client_ip() {
        // Lista proxy/CDN affidabili (opzionale - da configurare se necessario)
        $trusted_proxies = array(
            // Esempio Cloudflare: '103.21.244.0/22', '103.22.200.0/22', ecc.
            // Per abilitare, decommentare e aggiungere range IP del tuo proxy
        );
        
        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
        
        // Se hai un proxy/CDN affidabile, valida X-Forwarded-For
        if (!empty($trusted_proxies) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $remote_ip = $_SERVER['REMOTE_ADDR'];
            
            // Verifica se REMOTE_ADDR è un proxy affidabile
            foreach ($trusted_proxies as $proxy_range) {
                // Controllo CIDR semplificato - in produzione usare libreria IP
                if ($this->ip_in_range($remote_ip, $proxy_range)) {
                    // Proxy affidabile: usa primo IP in X-Forwarded-For
                    $forwarded_ips = array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']));
                    $ip = $forwarded_ips[0];
                    break;
                }
            }
        }
        
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }
    
    /**
     * Verifica se un IP è in un range CIDR
     * 
     * @param string $ip IP da verificare
     * @param string $range Range CIDR (es: 192.168.1.0/24)
     * @return bool
     */
    private function ip_in_range($ip, $range) {
        if (strpos($range, '/') === false) {
            return $ip === $range;
        }
        
        list($subnet, $mask) = explode('/', $range);
        
        $ip_long = ip2long($ip);
        $subnet_long = ip2long($subnet);
        $mask_long = -1 << (32 - (int)$mask);
        
        return ($ip_long & $mask_long) === ($subnet_long & $mask_long);
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
