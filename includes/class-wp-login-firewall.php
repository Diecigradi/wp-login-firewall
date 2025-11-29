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
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_ajax_wplf_clear_logs', array($this, 'ajax_clear_logs'));
        add_action('wp_ajax_wplf_export_logs', array($this, 'ajax_export_logs'));
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
            
            // Genera un token di sicurezza temporaneo
            $token = $this->generate_security_token($user->user_login);
            
            // Passiamo l'username originale per il pre-compilamento
            $login_to_pass = is_email($username) ? $user->user_login : $username;
            $redirect_url = add_query_arg('verified', '1', wp_login_url());
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
    
    /**
     * Aggiunge il menu nel pannello di amministrazione
     */
    public function add_admin_menu() {
        add_menu_page(
            'WP Login Firewall',
            'Login Firewall',
            'manage_options',
            'wp-login-firewall',
            array($this, 'render_admin_page'),
            'dashicons-shield',
            80
        );
    }
    
    /**
     * Carica gli script per il pannello amministratore
     */
    public function enqueue_admin_scripts($hook) {
        if ('toplevel_page_wp-login-firewall' !== $hook) {
            return;
        }
        
        wp_enqueue_style('wplf-admin-styles', plugin_dir_url(__DIR__) . 'assets/css/admin-style.css', array(), '1.0');
    }
    
    /**
     * Renderizza la pagina di amministrazione
     */
    public function render_admin_page() {
        $logs = get_option('wplf_bypass_attempts_log', array());
        $total_attempts = count($logs);
        
        // Statistiche
        $stats = $this->calculate_statistics($logs);
        
        ?>
        <div class="wrap wplf-admin-wrap">
            <h1>
                <span class="dashicons dashicons-shield"></span>
                WP Login Firewall - Tentativi di Bypass
            </h1>
            
            <div class="wplf-stats-grid">
                <div class="wplf-stat-card">
                    <div class="wplf-stat-icon dashicons dashicons-warning"></div>
                    <div class="wplf-stat-content">
                        <div class="wplf-stat-value"><?php echo esc_html($total_attempts); ?></div>
                        <div class="wplf-stat-label">Tentativi Totali</div>
                    </div>
                </div>
                
                <div class="wplf-stat-card">
                    <div class="wplf-stat-icon dashicons dashicons-clock"></div>
                    <div class="wplf-stat-content">
                        <div class="wplf-stat-value"><?php echo esc_html($stats['last_24h']); ?></div>
                        <div class="wplf-stat-label">Ultime 24 Ore</div>
                    </div>
                </div>
                
                <div class="wplf-stat-card">
                    <div class="wplf-stat-icon dashicons dashicons-location"></div>
                    <div class="wplf-stat-content">
                        <div class="wplf-stat-value"><?php echo esc_html($stats['unique_ips']); ?></div>
                        <div class="wplf-stat-label">IP Unici</div>
                    </div>
                </div>
                
                <div class="wplf-stat-card">
                    <div class="wplf-stat-icon dashicons dashicons-admin-users"></div>
                    <div class="wplf-stat-content">
                        <div class="wplf-stat-value"><?php echo esc_html($stats['unique_users']); ?></div>
                        <div class="wplf-stat-label">Username Tentati</div>
                    </div>
                </div>
            </div>
            
            <div class="wplf-actions">
                <button id="wplf-clear-logs" class="button button-secondary">
                    <span class="dashicons dashicons-trash"></span>
                    Cancella Tutti i Log
                </button>
                <button id="wplf-export-logs" class="button button-secondary">
                    <span class="dashicons dashicons-download"></span>
                    Esporta Log (CSV)
                </button>
                <button id="wplf-refresh-logs" class="button button-secondary" onclick="location.reload()">
                    <span class="dashicons dashicons-update"></span>
                    Aggiorna
                </button>
            </div>
            
            <?php if (empty($logs)): ?>
                <div class="wplf-empty-state">
                    <span class="dashicons dashicons-yes-alt"></span>
                    <h2>Nessun Tentativo di Bypass Registrato</h2>
                    <p>Il sistema di sicurezza è attivo e funzionante. Quando verranno rilevati tentativi di bypass, appariranno qui.</p>
                </div>
            <?php else: ?>
                <div class="wplf-table-container">
                    <table class="wp-list-table widefat fixed striped wplf-logs-table">
                        <thead>
                            <tr>
                                <th class="column-timestamp">Data/Ora</th>
                                <th class="column-ip">Indirizzo IP</th>
                                <th class="column-username">Username</th>
                                <th class="column-reason">Motivo</th>
                                <th class="column-useragent">User Agent</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td class="column-timestamp">
                                        <?php echo esc_html(date('d/m/Y H:i:s', strtotime($log['timestamp']))); ?>
                                    </td>
                                    <td class="column-ip">
                                        <code><?php echo esc_html($log['ip']); ?></code>
                                    </td>
                                    <td class="column-username">
                                        <strong><?php echo esc_html($log['username']); ?></strong>
                                    </td>
                                    <td class="column-reason">
                                        <?php echo $this->format_reason($log['reason']); ?>
                                    </td>
                                    <td class="column-useragent" title="<?php echo esc_attr($log['user_agent']); ?>">
                                        <?php echo esc_html($this->truncate_user_agent($log['user_agent'])); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('#wplf-clear-logs').on('click', function() {
                if (!confirm('Sei sicuro di voler cancellare tutti i log? Questa azione non può essere annullata.')) {
                    return;
                }
                
                $.post(ajaxurl, {
                    action: 'wplf_clear_logs',
                    nonce: '<?php echo wp_create_nonce('wplf_clear_logs'); ?>'
                }, function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('Errore durante la cancellazione dei log.');
                    }
                });
            });
            
            $('#wplf-export-logs').on('click', function() {
                window.location.href = ajaxurl + '?action=wplf_export_logs&nonce=<?php echo wp_create_nonce('wplf_export_logs'); ?>';
            });
        });
        </script>
        <?php
    }
    
    /**
     * Calcola statistiche dai log
     */
    private function calculate_statistics($logs) {
        $stats = array(
            'last_24h' => 0,
            'unique_ips' => array(),
            'unique_users' => array()
        );
        
        $yesterday = strtotime('-24 hours');
        
        foreach ($logs as $log) {
            if (strtotime($log['timestamp']) > $yesterday) {
                $stats['last_24h']++;
            }
            
            $stats['unique_ips'][$log['ip']] = true;
            $stats['unique_users'][$log['username']] = true;
        }
        
        $stats['unique_ips'] = count($stats['unique_ips']);
        $stats['unique_users'] = count($stats['unique_users']);
        
        return $stats;
    }
    
    /**
     * Formatta il motivo del blocco per la visualizzazione
     */
    private function format_reason($reason) {
        $reasons = array(
            'missing_token' => '<span class="wplf-reason-badge wplf-reason-danger">Token Mancante</span>',
            'invalid_token' => '<span class="wplf-reason-badge wplf-reason-warning">Token Invalido</span>',
            'login_without_token' => '<span class="wplf-reason-badge wplf-reason-danger">Login Senza Token</span>',
        );
        
        return isset($reasons[$reason]) ? $reasons[$reason] : '<span class="wplf-reason-badge">' . esc_html($reason) . '</span>';
    }
    
    /**
     * Tronca lo user agent per la visualizzazione
     */
    private function truncate_user_agent($user_agent) {
        if (strlen($user_agent) > 50) {
            return substr($user_agent, 0, 50) . '...';
        }
        return $user_agent;
    }
    
    /**
     * AJAX: Cancella tutti i log
     */
    public function ajax_clear_logs() {
        check_ajax_referer('wplf_clear_logs', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permessi insufficienti');
        }
        
        delete_option('wplf_bypass_attempts_log');
        wp_send_json_success();
    }
    
    /**
     * AJAX: Esporta i log in formato CSV
     */
    public function ajax_export_logs() {
        check_ajax_referer('wplf_export_logs', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Permessi insufficienti');
        }
        
        $logs = get_option('wplf_bypass_attempts_log', array());
        
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="wp-login-firewall-logs-' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        // Header CSV
        fputcsv($output, array('Data/Ora', 'Indirizzo IP', 'Username', 'Motivo', 'User Agent'));
        
        // Dati
        foreach ($logs as $log) {
            fputcsv($output, array(
                $log['timestamp'],
                $log['ip'],
                $log['username'],
                $log['reason'],
                $log['user_agent']
            ));
        }
        
        fclose($output);
        exit;
    }
}
