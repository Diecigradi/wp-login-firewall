<?php
/**
 * WP Login Firewall - Admin Panel
 * 
 * Gestisce il pannello di amministrazione
 */

// Previene accesso diretto
if (!defined('ABSPATH')) {
    exit;
}

class WPLF_Admin {
    
    private $logger;
    private $ip_blocker;
    private $rate_limiter;
    
    /**
     * Costruttore
     */
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // AJAX handlers
        add_action('wp_ajax_wplf_unblock_ip', array($this, 'ajax_unblock_ip'));
        add_action('wp_ajax_wplf_clear_logs', array($this, 'ajax_clear_logs'));
        add_action('wp_ajax_wplf_export_logs', array($this, 'ajax_export_logs'));
        add_action('wp_ajax_wplf_save_settings', array($this, 'ajax_save_settings'));
        add_action('wp_ajax_wplf_block_ip', array($this, 'ajax_block_ip'));
    }
    
    /**
     * Aggiunge il menu amministratore
     */
    public function add_admin_menu() {
        add_menu_page(
            'WP Login Firewall',
            'Login Firewall',
            'manage_options',
            'wp-login-firewall',
            array($this, 'render_dashboard'),
            'dashicons-shield',
            80
        );
        
        add_submenu_page(
            'wp-login-firewall',
            'Dashboard',
            'Dashboard',
            'manage_options',
            'wp-login-firewall',
            array($this, 'render_dashboard')
        );
        
        add_submenu_page(
            'wp-login-firewall',
            'Log Accessi',
            'Log Accessi',
            'manage_options',
            'wplf-logs',
            array($this, 'render_logs')
        );
        
        add_submenu_page(
            'wp-login-firewall',
            'IP Bloccati',
            'IP Bloccati',
            'manage_options',
            'wplf-blocked',
            array($this, 'render_blocked_ips')
        );
        
        add_submenu_page(
            'wp-login-firewall',
            'Come Funziona',
            'Come Funziona',
            'manage_options',
            'wplf-help',
            array($this, 'render_help_page')
        );
        
        add_submenu_page(
            'wp-login-firewall',
            'Impostazioni',
            'Impostazioni',
            'manage_options',
            'wplf-settings',
            array($this, 'render_settings')
        );
    }
    
    /**
     * Carica gli script admin
     */
    public function enqueue_admin_scripts($hook) {
        if (strpos($hook, 'wp-login-firewall') === false && strpos($hook, 'wplf-') === false) {
            return;
        }
        
        wp_enqueue_style('wplf-admin', WPLF_PLUGIN_URL . 'assets/css/admin.css', array(), WPLF_VERSION);
    }
    
    /**
     * Renderizza la dashboard
     */
    public function render_dashboard() {
        $this->logger = new WPLF_Logger();
        $this->ip_blocker = new WPLF_IP_Blocker();
        
        $stats = $this->logger->get_statistics();
        $blocked_ips = $this->ip_blocker->get_all_blocked_ips();
        
        ?>
        <div class="wrap wplf-admin">
            <h1>WP Login Firewall - Dashboard</h1>
            
            <div class="wplf-stats-grid">
                <div class="wplf-stat-card">
                    <div class="wplf-stat-content">
                        <div class="wplf-stat-value"><?php echo number_format($stats['total_attempts']); ?></div>
                        <div class="wplf-stat-label">Tentativi Totali</div>
                    </div>
                </div>
                
                <div class="wplf-stat-card wplf-success">
                    <div class="wplf-stat-content">
                        <div class="wplf-stat-value"><?php echo number_format($stats['successful']); ?></div>
                        <div class="wplf-stat-label">Accessi Riusciti</div>
                    </div>
                </div>
                
                <div class="wplf-stat-card wplf-error">
                    <div class="wplf-stat-content">
                        <div class="wplf-stat-value"><?php echo number_format($stats['failed']); ?></div>
                        <div class="wplf-stat-label">Tentativi Falliti</div>
                    </div>
                </div>
                
                <div class="wplf-stat-card wplf-warning">
                    <div class="wplf-stat-content">
                        <div class="wplf-stat-value"><?php echo number_format($stats['blocked'] + $stats['rate_limited']); ?></div>
                        <div class="wplf-stat-label">Accessi Bloccati</div>
                    </div>
                </div>
            </div>
            
            <div class="wplf-stats-grid">
                <div class="wplf-stat-card">
                    <div class="wplf-stat-content">
                        <div class="wplf-stat-value"><?php echo number_format($stats['last_24h']); ?></div>
                        <div class="wplf-stat-label">Ultime 24 Ore</div>
                    </div>
                </div>
                
                <div class="wplf-stat-card">
                    <div class="wplf-stat-content">
                        <div class="wplf-stat-value"><?php echo number_format($stats['last_7d']); ?></div>
                        <div class="wplf-stat-label">Ultimi 7 Giorni</div>
                    </div>
                </div>
                
                <div class="wplf-stat-card">
                    <div class="wplf-stat-content">
                        <div class="wplf-stat-value"><?php echo number_format($stats['unique_ips']); ?></div>
                        <div class="wplf-stat-label">IP Unici</div>
                    </div>
                </div>
                
                <div class="wplf-stat-card">
                    <div class="wplf-stat-content">
                        <div class="wplf-stat-value"><?php echo number_format(count($blocked_ips)); ?></div>
                        <div class="wplf-stat-label">IP Bloccati</div>
                    </div>
                </div>
            </div>
            
            <div class="wplf-section">
                <h2>Ultimi Accessi</h2>
                <?php $this->render_recent_logs(10); ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Renderizza la pagina dei log
     */
    public function render_logs() {
        $this->logger = new WPLF_Logger();
        $this->ip_blocker = new WPLF_IP_Blocker();
        $logs = $this->logger->get_logs(100);
        
        ?>
        <div class="wrap wplf-admin">
            <h1>Log Accessi</h1>
            
            <div class="wplf-actions">
                <button id="wplf-export-logs" class="button button-secondary">
                    Esporta CSV
                </button>
                <button id="wplf-clear-logs" class="button button-secondary">
                    Cancella Log
                </button>
            </div>
            
            <?php $this->render_logs_table($logs); ?>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#wplf-clear-logs').on('click', function() {
                if (!confirm('Sei sicuro di voler cancellare tutti i log?')) return;
                
                $.post(ajaxurl, {
                    action: 'wplf_clear_logs',
                    nonce: '<?php echo wp_create_nonce('wplf_clear_logs'); ?>'
                }, function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('Errore durante la cancellazione.');
                    }
                });
            });
            
            $('#wplf-export-logs').on('click', function() {
                window.location.href = ajaxurl + '?action=wplf_export_logs&nonce=<?php echo wp_create_nonce('wplf_export_logs'); ?>';
            });
            
            // Handler per blocco manuale IP
            $('.wplf-block-ip').on('click', function() {
                var $btn = $(this);
                var ip = $btn.data('ip');
                var username = $btn.data('username');
                
                if (!confirm('Bloccare l\'IP ' + ip + ' (username: ' + username + ') per ' + 
                    '<?php echo get_option("wplf_ip_block_duration", 24); ?> ore?')) {
                    return;
                }
                
                $btn.prop('disabled', true).text('Blocco...');
                
                $.post(ajaxurl, {
                    action: 'wplf_block_ip',
                    ip: ip,
                    nonce: '<?php echo wp_create_nonce("wplf_block_ip"); ?>'
                }, function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('Errore: ' + (response.data || 'Impossibile bloccare IP'));
                        $btn.prop('disabled', false).text('Blocca IP');
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * Renderizza la pagina degli IP bloccati
     */
    public function render_blocked_ips() {
        $this->ip_blocker = new WPLF_IP_Blocker();
        $blocked_ips = $this->ip_blocker->get_all_blocked_ips();
        
        ?>
        <div class="wrap wplf-admin">
            <h1>IP Bloccati</h1>
            
            <?php if (empty($blocked_ips)): ?>
                <div class="wplf-empty-state">
                    <h2>Nessun IP Bloccato</h2>
                    <p>Non ci sono IP attualmente bloccati.</p>
                </div>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Indirizzo IP</th>
                            <th>Bloccato Il</th>
                            <th>Scade Tra</th>
                            <th>Motivo</th>
                            <th>Tentativi</th>
                            <th>Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($blocked_ips as $block): ?>
                            <tr>
                                <td><code><?php echo esc_html($block['ip']); ?></code></td>
                                <td><?php echo esc_html($block['blocked_at']); ?></td>
                                <td><?php echo esc_html($this->ip_blocker->get_formatted_time_remaining($block['ip'])); ?></td>
                                <td><?php echo esc_html($block['reason']); ?></td>
                                <td><?php echo esc_html($block['attempts']); ?></td>
                                <td>
                                    <button class="button button-small wplf-unblock" data-ip="<?php echo esc_attr($block['ip']); ?>">
                                        Sblocca
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('.wplf-unblock').on('click', function() {
                var ip = $(this).data('ip');
                
                if (!confirm('Sbloccare l\'IP ' + ip + '?')) return;
                
                $.post(ajaxurl, {
                    action: 'wplf_unblock_ip',
                    ip: ip,
                    nonce: '<?php echo wp_create_nonce('wplf_unblock_ip'); ?>'
                }, function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('Errore durante lo sblocco.');
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * Renderizza gli ultimi log
     */
    private function render_recent_logs($limit = 10) {
        $logs = $this->logger->get_logs($limit);
        $this->render_logs_table($logs);
    }
    
    /**
     * Renderizza la tabella dei log
     */
    private function render_logs_table($logs) {
        if (empty($logs)): ?>
            <div class="wplf-empty-state">
                <p>Nessun log disponibile.</p>
            </div>
        <?php else: ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Data/Ora</th>
                        <th>IP</th>
                        <th>Username</th>
                        <th>Status</th>
                        <th>Messaggio</th>
                        <th>Azioni</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): 
                        $is_blocked = $this->ip_blocker->is_blocked($log['ip']);
                    ?>
                        <tr>
                            <td><?php echo esc_html($log['timestamp']); ?></td>
                            <td><code><?php echo esc_html($log['ip']); ?></code></td>
                            <td><strong><?php echo esc_html($log['username']); ?></strong></td>
                            <td><?php echo $this->format_status($log['status']); ?></td>
                            <td><?php echo esc_html($log['message']); ?></td>
                            <td>
                                <?php if ($is_blocked): ?>
                                    <span class="wplf-badge wplf-badge-error">IP Bloccato</span>
                                <?php else: ?>
                                    <button class="button button-small wplf-block-ip" 
                                            data-ip="<?php echo esc_attr($log['ip']); ?>"
                                            data-username="<?php echo esc_attr($log['username']); ?>">
                                        Blocca IP
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif;
    }
    
    /**
     * Formatta lo status con badge colorato
     */
    private function format_status($status) {
        $badges = array(
            'success' => '<span class="wplf-badge wplf-badge-success">Successo</span>',
            'failed' => '<span class="wplf-badge wplf-badge-error">Fallito</span>',
            'blocked' => '<span class="wplf-badge wplf-badge-error">Bloccato</span>',
            'rate_limited' => '<span class="wplf-badge wplf-badge-warning">Rate Limited</span>'
        );
        
        return isset($badges[$status]) ? $badges[$status] : '<span class="wplf-badge">' . esc_html($status) . '</span>';
    }
    
    /**
     * AJAX: Sblocca IP
     */
    public function ajax_unblock_ip() {
        check_ajax_referer('wplf_unblock_ip', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permessi insufficienti');
        }
        
        $ip = sanitize_text_field($_POST['ip']);
        $blocker = new WPLF_IP_Blocker();
        
        if ($blocker->unblock_ip($ip)) {
            wp_send_json_success('IP sbloccato');
        } else {
            wp_send_json_error('Errore durante lo sblocco');
        }
    }
    
    /**
     * AJAX: Cancella log
     */
    public function ajax_clear_logs() {
        check_ajax_referer('wplf_clear_logs', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permessi insufficienti');
        }
        
        $logger = new WPLF_Logger();
        
        if ($logger->clear_logs()) {
            wp_send_json_success('Log cancellati');
        } else {
            wp_send_json_error('Errore durante la cancellazione');
        }
    }
    
    /**
     * AJAX: Esporta log
     */
    public function ajax_export_logs() {
        check_ajax_referer('wplf_export_logs', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Permessi insufficienti');
        }
        
        $logger = new WPLF_Logger();
        $csv = $logger->export_to_csv();
        
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="wplf-logs-' . date('Y-m-d') . '.csv"');
        
        echo $csv;
        exit;
    }
    
    /**
     * Renderizza la pagina di aiuto
     */
    public function render_help_page() {
        ?>
        <div class="wrap wplf-admin wplf-help-page">
            <h1>Come Funziona WP Login Firewall</h1>
            
            <div class="wplf-help-section">
                <div class="wplf-help-card">
                    <h2>Obiettivo del Plugin</h2>
                    <p>WP Login Firewall protegge il tuo sito WordPress implementando un sistema di verifica a due step prima del login, impedendo attacchi brute force e tentativi di accesso non autorizzati.</p>
                </div>
                
                <div class="wplf-help-card">
                    <h2>Come Funziona il Sistema</h2>
                    
                    <div class="wplf-step">
                        <div class="wplf-step-number">1</div>
                        <div class="wplf-step-content">
                            <h3>Intercettazione Login</h3>
                            <p>Quando un utente tenta di accedere a <code>/wp-login.php</code> o <code>/wp-admin</code>, il plugin intercetta la richiesta e mostra una pagina di verifica personalizzata.</p>
                        </div>
                    </div>
                    
                    <div class="wplf-step">
                        <div class="wplf-step-number">2</div>
                        <div class="wplf-step-content">
                            <h3>Verifica Username</h3>
                            <p>L'utente deve inserire il proprio username o email. Il sistema verifica che esista nel database senza rivelare informazioni (protezione contro username enumeration).</p>
                        </div>
                    </div>
                    
                    <div class="wplf-step">
                        <div class="wplf-step-number">3</div>
                        <div class="wplf-step-content">
                            <h3>Generazione Token</h3>
                            <p>Se l'username è valido, viene generato un token sicuro monouso con scadenza di 5 minuti. L'utente viene reindirizzato al form di login WordPress standard con il token.</p>
                        </div>
                    </div>
                    
                    <div class="wplf-step">
                        <div class="wplf-step-number">4</div>
                        <div class="wplf-step-content">
                            <h3>Login WordPress</h3>
                            <p>Solo con un token valido l'utente può accedere al form di login di WordPress e inserire la password. Il token viene distrutto dopo il primo utilizzo.</p>
                        </div>
                    </div>
                </div>
                
                <div class="wplf-help-card">
                    <h2>Livelli di Protezione</h2>
                    
                    <div class="wplf-protection-level">
                        <h3>Rate Limiting</h3>
                        <ul>
                            <li><strong>Limite:</strong> Massimo 5 tentativi ogni 15 minuti per IP</li>
                            <li><strong>Scopo:</strong> Rallenta gli attacchi automatici</li>
                            <li><strong>Reset:</strong> Automatico dopo 15 minuti o dopo verifica riuscita</li>
                        </ul>
                    </div>
                    
                    <div class="wplf-protection-level">
                        <h3>Blocco IP Automatico</h3>
                        <ul>
                            <li><strong>Soglia:</strong> 10 tentativi falliti nell'ultima ora</li>
                            <li><strong>Durata:</strong> Blocco di 24 ore</li>
                            <li><strong>Effetto:</strong> Impossibilità totale di accedere alla verifica</li>
                            <li><strong>Sblocco:</strong> Manuale dal pannello admin o automatico dopo 24h</li>
                        </ul>
                    </div>
                    
                    <div class="wplf-protection-level">
                        <h3>Whitelist Amministratori</h3>
                        <ul>
                            <li><strong>Protezione:</strong> Gli admin non vengono mai bloccati</li>
                            <li><strong>Sblocco:</strong> Automatico se un admin era bloccato</li>
                            <li><strong>Rate Limit:</strong> Azzerato automaticamente per admin</li>
                            <li><strong>Scopo:</strong> Prevenire lockout accidentali</li>
                        </ul>
                    </div>
                    
                    <div class="wplf-protection-level">
                        <h3>Logging Completo</h3>
                        <ul>
                            <li><strong>Registra:</strong> Tutti i tentativi (riusciti e falliti)</li>
                            <li><strong>Informazioni:</strong> IP, username, timestamp, user agent, status</li>
                            <li><strong>Statistiche:</strong> Dashboard con metriche in tempo reale</li>
                            <li><strong>Esportazione:</strong> Download log in formato CSV</li>
                        </ul>
                    </div>
                </div>
                
                <div class="wplf-help-card">
                    <h2>Status dei Log</h2>
                    <table class="wplf-status-table">
                        <tr>
                            <td><span class="wplf-badge wplf-badge-success">success</span></td>
                            <td>Username verificato con successo, token generato</td>
                        </tr>
                        <tr>
                            <td><span class="wplf-badge wplf-badge-error">failed</span></td>
                            <td>Username non trovato o credenziali non valide</td>
                        </tr>
                        <tr>
                            <td><span class="wplf-badge wplf-badge-warning">rate_limited</span></td>
                            <td>Tentativo bloccato per superamento rate limit</td>
                        </tr>
                        <tr>
                            <td><span class="wplf-badge wplf-badge-error">blocked</span></td>
                            <td>Tentativo da IP bloccato</td>
                        </tr>
                    </table>
                </div>
                
                <div class="wplf-help-card">
                    <h2>Configurazione Tecnica</h2>
                    <table class="wplf-config-table">
                        <tr>
                            <th>Parametro</th>
                            <th>Valore</th>
                            <th>File</th>
                        </tr>
                        <tr>
                            <td>Rate Limit - Max Tentativi</td>
                            <td><code>5</code></td>
                            <td>class-wplf-rate-limiter.php</td>
                        </tr>
                        <tr>
                            <td>Rate Limit - Finestra Temporale</td>
                            <td><code>15 minuti</code></td>
                            <td>class-wplf-rate-limiter.php</td>
                        </tr>
                        <tr>
                            <td>Blocco IP - Soglia Tentativi</td>
                            <td><code>10</code></td>
                            <td>class-wplf-ip-blocker.php</td>
                        </tr>
                        <tr>
                            <td>Blocco IP - Durata</td>
                            <td><code>24 ore</code></td>
                            <td>class-wplf-ip-blocker.php</td>
                        </tr>
                        <tr>
                            <td>Token - Scadenza</td>
                            <td><code>5 minuti</code></td>
                            <td>class-wplf-core.php</td>
                        </tr>
                        <tr>
                            <td>Log - Max Entries</td>
                            <td><code>1000</code></td>
                            <td>class-wplf-logger.php</td>
                        </tr>
                    </table>
                </div>
                
                <div class="wplf-help-card wplf-warning-card">
                    <h2>Note Importanti</h2>
                    <ul>
                        <li><strong>Backup:</strong> Effettua sempre un backup prima di modificare i file del plugin</li>
                        <li><strong>Admin Lockout:</strong> Gli amministratori sono automaticamente esclusi dal blocco IP</li>
                        <li><strong>Cache:</strong> Se usi un plugin di cache, svuotalo dopo l'installazione</li>
                        <li><strong>CDN:</strong> Assicurati che il CDN passi correttamente l'IP reale del visitatore</li>
                        <li><strong>Impostazioni:</strong> Personalizza i parametri di sicurezza dalla pagina Impostazioni</li>
                    </ul>
                </div>
                
                <div class="wplf-help-card wplf-info-card">
                    <h2>Informazioni Plugin</h2>
                    <p><strong>Versione:</strong> <?php echo WPLF_VERSION; ?></p>
                    <p><strong>Autore:</strong> DevRoom by RoomZero Creative Solutions</p>
                    <p><strong>Repository:</strong> <a href="https://github.com/Diecigradi/wp-login-firewall" target="_blank">GitHub</a></p>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Renderizza la pagina impostazioni
     */
    public function render_settings() {
        // Carica impostazioni correnti
        $settings = $this->get_settings();
        
        ?>
        <div class="wrap wplf-admin">
            <h1>Impostazioni WP Login Firewall</h1>
            
            <form id="wplf-settings-form">
                <table class="form-table">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <label>Limite Tentativi Login Standard</label>
                            </th>
                            <td>
                                <fieldset>
                                    <label>
                                        <input type="number" name="rate_limit_attempts" 
                                               value="<?php echo esc_attr($settings['rate_limit_attempts']); ?>" 
                                               min="1" max="20" style="width: 80px;">
                                        tentativi ogni
                                    </label>
                                    <label>
                                        <input type="number" name="rate_limit_minutes" 
                                               value="<?php echo esc_attr($settings['rate_limit_minutes']); ?>" 
                                               min="1" max="60" style="width: 80px;">
                                        minuti
                                    </label>
                                    <p class="description">
                                        Protegge dal login diretto (senza passare per verifica username). Se disabiliti la verifica a due step, questa è l'unica protezione attiva.
                                    </p>
                                </fieldset>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label>Blocco IP Automatico</label>
                            </th>
                            <td>
                                <fieldset>
                                    <label>
                                        Blocca IP dopo
                                        <input type="number" name="ip_block_threshold" 
                                               value="<?php echo esc_attr($settings['ip_block_threshold']); ?>" 
                                               min="3" max="50" style="width: 80px;">
                                        tentativi falliti
                                    </label>
                                    <p class="description">
                                        Gli IP vengono bloccati automaticamente dopo questo numero di tentativi falliti nell'ultima ora.
                                    </p>
                                </fieldset>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label>Durata Blocco IP</label>
                            </th>
                            <td>
                                <fieldset>
                                    <label>
                                        <input type="number" name="ip_block_duration" 
                                               value="<?php echo esc_attr($settings['ip_block_duration']); ?>" 
                                               min="1" max="168" style="width: 80px;">
                                        ore
                                    </label>
                                    <p class="description">
                                        Durata del blocco IP dopo il superamento della soglia (max 168 ore = 7 giorni).
                                    </p>
                                </fieldset>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label>Retention Log</label>
                            </th>
                            <td>
                                <fieldset>
                                    <label>
                                        Mantieni log per
                                        <input type="number" name="log_retention_days" 
                                               value="<?php echo esc_attr($settings['log_retention_days']); ?>" 
                                               min="7" max="365" style="width: 80px;">
                                        giorni
                                    </label>
                                    <p class="description">
                                        I log più vecchi verranno automaticamente eliminati dopo questo periodo.
                                    </p>
                                </fieldset>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label>Scadenza Token</label>
                            </th>
                            <td>
                                <fieldset>
                                    <label>
                                        Il token scade dopo
                                        <input type="number" name="token_lifetime" 
                                               value="<?php echo esc_attr($settings['token_lifetime']); ?>" 
                                               min="1" max="60" style="width: 80px;">
                                        minuti
                                    </label>
                                    <p class="description">
                                        Tempo di validità del token di verifica. Dopo questo periodo l'utente dovrà reinserire lo username. <strong>Default: 5 minuti</strong>
                                    </p>
                                </fieldset>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label>Tentativi Password per Token</label>
                            </th>
                            <td>
                                <fieldset>
                                    <label>
                                        <input type="number" name="max_password_attempts" 
                                               value="<?php echo esc_attr($settings['max_password_attempts']); ?>" 
                                               min="3" max="20" style="width: 80px;">
                                        tentativi
                                    </label>
                                    <p class="description">
                                        Numero massimo di password sbagliate prima che il token venga invalidato. Range: 3-20. <strong>Default: 5</strong>
                                    </p>
                                </fieldset>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label>Limite Richieste Verifica</label>
                            </th>
                            <td>
                                <fieldset>
                                    <label>
                                        <input type="number" name="verify_limit_attempts" 
                                               value="<?php echo esc_attr($settings['verify_limit_attempts']); ?>" 
                                               min="1" max="10" style="width: 80px;">
                                        verifiche ogni
                                    </label>
                                    <label>
                                        <input type="number" name="verify_limit_minutes" 
                                               value="<?php echo esc_attr($settings['verify_limit_minutes']); ?>" 
                                               min="5" max="60" style="width: 80px;">
                                        minuti
                                    </label>
                                    <p class="description">
                                        Previene loop infiniti di richiesta codice verifica. Limita quante volte un IP può richiedere un nuovo token. <strong>Default: 3 ogni 15 minuti</strong>
                                    </p>
                                </fieldset>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label>Whitelist Amministratori</label>
                            </th>
                            <td>
                                <fieldset>
                                    <label>
                                        <input type="checkbox" name="admin_whitelist" value="1" 
                                               <?php checked($settings['admin_whitelist'], 1); ?>>
                                        Abilita whitelist automatica per amministratori
                                    </label>
                                    <p class="description">
                                        Se abilitato, gli amministratori bypassano sempre rate limiting e blocchi IP. <strong>Disabilitare solo se si è certi di ricordare le credenziali di accesso.</strong>
                                    </p>
                                </fieldset>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label>Modalità Debug</label>
                            </th>
                            <td>
                                <fieldset>
                                    <label>
                                        <input type="checkbox" name="debug_mode" value="1" 
                                               <?php checked($settings['debug_mode'], 1); ?>>
                                        Abilita log di debug nella pagina di verifica
                                    </label>
                                    <p class="description">
                                        Se abilitato, mostra un pannello debug nella pagina di verifica con log in tempo reale degli accessi, token e redirect. <strong>Disabilitare in produzione.</strong>
                                    </p>
                                </fieldset>
                            </td>
                        </tr>
                    </tbody>
                </table>
                
                <p class="submit">
                    <button type="submit" class="button button-primary">Salva Modifiche</button>
                    <button type="button" id="wplf-reset-settings" class="button">Ripristina Predefiniti</button>
                </p>
            </form>
            
            <div id="wplf-settings-message" style="display: none; margin-top: 20px;"></div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#wplf-settings-form').on('submit', function(e) {
                e.preventDefault();
                
                var $form = $(this);
                var $btn = $form.find('button[type="submit"]');
                var $msg = $('#wplf-settings-message');
                
                $btn.prop('disabled', true).text('Salvataggio...');
                
                $.post(ajaxurl, {
                    action: 'wplf_save_settings',
                    nonce: '<?php echo wp_create_nonce('wplf_save_settings'); ?>',
                    settings: $form.serialize()
                }, function(response) {
                    $btn.prop('disabled', false).text('Salva Modifiche');
                    
                    if (response.success) {
                        $msg.removeClass('notice-error').addClass('notice notice-success')
                            .html('<p>Impostazioni salvate con successo!</p>').show();
                    } else {
                        $msg.removeClass('notice-success').addClass('notice notice-error')
                            .html('<p>Errore: ' + (response.data || 'Impossibile salvare le impostazioni') + '</p>').show();
                    }
                    
                    setTimeout(function() {
                        $msg.fadeOut();
                    }, 3000);
                });
            });
            
            $('#wplf-reset-settings').on('click', function() {
                if (!confirm('Ripristinare le impostazioni predefinite?')) return;
                
                $('input[name="rate_limit_attempts"]').val(5);
                $('input[name="rate_limit_minutes"]').val(15);
                $('input[name="ip_block_threshold"]').val(10);
                $('input[name="ip_block_duration"]').val(24);
                $('input[name="log_retention_days"]').val(30);
                $('input[name="token_lifetime"]').val(5);
                $('input[name="max_password_attempts"]').val(5);
                $('input[name="verify_limit_attempts"]').val(3);
                $('input[name="verify_limit_minutes"]').val(15);
                $('input[name="admin_whitelist"]').prop('checked', true);
                $('input[name="debug_mode"]').prop('checked', false);
                
                $('#wplf-settings-form').submit();
            });
        });
        </script>
        <?php
    }
    
    /**
     * Ottiene le impostazioni correnti
     */
    private function get_settings() {
        return array(
            'rate_limit_attempts' => get_option('wplf_rate_limit_attempts', 5),
            'rate_limit_minutes' => get_option('wplf_rate_limit_minutes', 15),
            'ip_block_threshold' => get_option('wplf_ip_block_threshold', 10),
            'ip_block_duration' => get_option('wplf_ip_block_duration', 24),
            'log_retention_days' => get_option('wplf_log_retention_days', 30),
            'token_lifetime' => get_option('wplf_token_lifetime', 5),
            'max_password_attempts' => get_option('wplf_max_password_attempts', 5),
            'verify_limit_attempts' => get_option('wplf_verify_limit_attempts', 3),
            'verify_limit_minutes' => get_option('wplf_verify_limit_minutes', 15),
            'admin_whitelist' => get_option('wplf_admin_whitelist', 1),
            'debug_mode' => get_option('wplf_debug_mode', 0)
        );
    }
    
    /**
     * AJAX: Salva impostazioni
     */
    public function ajax_save_settings() {
        check_ajax_referer('wplf_save_settings', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permessi insufficienti');
        }
        
        parse_str($_POST['settings'], $settings);
        
        // Valida e salva ogni impostazione
        $rate_limit_attempts = absint($settings['rate_limit_attempts']);
        if ($rate_limit_attempts < 1 || $rate_limit_attempts > 20) {
            wp_send_json_error('Tentativi rate limit deve essere tra 1 e 20');
        }
        update_option('wplf_rate_limit_attempts', $rate_limit_attempts);
        
        $rate_limit_minutes = absint($settings['rate_limit_minutes']);
        if ($rate_limit_minutes < 1 || $rate_limit_minutes > 60) {
            wp_send_json_error('Minuti rate limit deve essere tra 1 e 60');
        }
        update_option('wplf_rate_limit_minutes', $rate_limit_minutes);
        
        $ip_block_threshold = absint($settings['ip_block_threshold']);
        if ($ip_block_threshold < 3 || $ip_block_threshold > 50) {
            wp_send_json_error('Soglia blocco IP deve essere tra 3 e 50');
        }
        update_option('wplf_ip_block_threshold', $ip_block_threshold);
        
        $ip_block_duration = absint($settings['ip_block_duration']);
        if ($ip_block_duration < 1 || $ip_block_duration > 168) {
            wp_send_json_error('Durata blocco deve essere tra 1 e 168 ore');
        }
        update_option('wplf_ip_block_duration', $ip_block_duration);
        
        $log_retention_days = absint($settings['log_retention_days']);
        if ($log_retention_days < 7 || $log_retention_days > 365) {
            wp_send_json_error('Retention log deve essere tra 7 e 365 giorni');
        }
        update_option('wplf_log_retention_days', $log_retention_days);
        
        // Token lifetime (1-60 minuti)
        $token_lifetime = absint($settings['token_lifetime']);
        if ($token_lifetime < 1 || $token_lifetime > 60) {
            wp_send_json_error('Scadenza token deve essere tra 1 e 60 minuti');
        }
        update_option('wplf_token_lifetime', $token_lifetime);
        
        // Max password attempts (3-20)
        $max_password_attempts = absint($settings['max_password_attempts']);
        if ($max_password_attempts < 3 || $max_password_attempts > 20) {
            wp_send_json_error('Tentativi password deve essere tra 3 e 20');
        }
        update_option('wplf_max_password_attempts', $max_password_attempts);
        
        // Verify limit attempts (1-10)
        $verify_limit_attempts = absint($settings['verify_limit_attempts']);
        if ($verify_limit_attempts < 1 || $verify_limit_attempts > 10) {
            wp_send_json_error('Tentativi verifica deve essere tra 1 e 10');
        }
        update_option('wplf_verify_limit_attempts', $verify_limit_attempts);
        
        // Verify limit minutes (5-60)
        $verify_limit_minutes = absint($settings['verify_limit_minutes']);
        if ($verify_limit_minutes < 5 || $verify_limit_minutes > 60) {
            wp_send_json_error('Minuti verifica deve essere tra 5 e 60');
        }
        update_option('wplf_verify_limit_minutes', $verify_limit_minutes);
        
        // Admin whitelist (checkbox: 1 o 0)
        $admin_whitelist = isset($settings['admin_whitelist']) ? 1 : 0;
        update_option('wplf_admin_whitelist', $admin_whitelist);
        
        // Debug mode (checkbox: 1 o 0)
        $debug_mode = isset($settings['debug_mode']) ? 1 : 0;
        update_option('wplf_debug_mode', $debug_mode);
        
        wp_send_json_success();
    }
    
    /**
     * AJAX: Blocca IP manualmente
     */
    public function ajax_block_ip() {
        check_ajax_referer('wplf_block_ip', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permessi insufficienti');
        }
        
        $ip = sanitize_text_field($_POST['ip']);
        
        if (empty($ip) || !filter_var($ip, FILTER_VALIDATE_IP)) {
            wp_send_json_error('IP non valido');
        }
        
        $blocker = new WPLF_IP_Blocker();
        $logger = new WPLF_Logger();
        
        // Blocca IP con durata configurata
        $duration = get_option('wplf_ip_block_duration', 24) * 3600;
        if ($blocker->block_ip($ip, 'Blocco manuale da admin', $duration)) {
            $logger->log_attempt('', 'blocked', 'IP ' . $ip . ' bloccato manualmente da admin');
            wp_send_json_success('IP bloccato con successo');
        } else {
            wp_send_json_error('Errore durante il blocco');
        }
    }
}
