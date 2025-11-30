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
            <h1><span class="dashicons dashicons-shield"></span> WP Login Firewall - Dashboard</h1>
            
            <div class="wplf-stats-grid">
                <div class="wplf-stat-card">
                    <div class="wplf-stat-icon dashicons dashicons-chart-line"></div>
                    <div class="wplf-stat-content">
                        <div class="wplf-stat-value"><?php echo number_format($stats['total_attempts']); ?></div>
                        <div class="wplf-stat-label">Tentativi Totali</div>
                    </div>
                </div>
                
                <div class="wplf-stat-card wplf-success">
                    <div class="wplf-stat-icon dashicons dashicons-yes-alt"></div>
                    <div class="wplf-stat-content">
                        <div class="wplf-stat-value"><?php echo number_format($stats['successful']); ?></div>
                        <div class="wplf-stat-label">Accessi Riusciti</div>
                    </div>
                </div>
                
                <div class="wplf-stat-card wplf-error">
                    <div class="wplf-stat-icon dashicons dashicons-dismiss"></div>
                    <div class="wplf-stat-content">
                        <div class="wplf-stat-value"><?php echo number_format($stats['failed']); ?></div>
                        <div class="wplf-stat-label">Tentativi Falliti</div>
                    </div>
                </div>
                
                <div class="wplf-stat-card wplf-warning">
                    <div class="wplf-stat-icon dashicons dashicons-shield-alt"></div>
                    <div class="wplf-stat-content">
                        <div class="wplf-stat-value"><?php echo number_format($stats['blocked'] + $stats['rate_limited']); ?></div>
                        <div class="wplf-stat-label">Accessi Bloccati</div>
                    </div>
                </div>
            </div>
            
            <div class="wplf-stats-grid">
                <div class="wplf-stat-card">
                    <div class="wplf-stat-icon dashicons dashicons-clock"></div>
                    <div class="wplf-stat-content">
                        <div class="wplf-stat-value"><?php echo number_format($stats['last_24h']); ?></div>
                        <div class="wplf-stat-label">Ultime 24 Ore</div>
                    </div>
                </div>
                
                <div class="wplf-stat-card">
                    <div class="wplf-stat-icon dashicons dashicons-calendar"></div>
                    <div class="wplf-stat-content">
                        <div class="wplf-stat-value"><?php echo number_format($stats['last_7d']); ?></div>
                        <div class="wplf-stat-label">Ultimi 7 Giorni</div>
                    </div>
                </div>
                
                <div class="wplf-stat-card">
                    <div class="wplf-stat-icon dashicons dashicons-location"></div>
                    <div class="wplf-stat-content">
                        <div class="wplf-stat-value"><?php echo number_format($stats['unique_ips']); ?></div>
                        <div class="wplf-stat-label">IP Unici</div>
                    </div>
                </div>
                
                <div class="wplf-stat-card">
                    <div class="wplf-stat-icon dashicons dashicons-lock"></div>
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
        $logs = $this->logger->get_logs(100);
        
        ?>
        <div class="wrap wplf-admin">
            <h1><span class="dashicons dashicons-list-view"></span> Log Accessi</h1>
            
            <div class="wplf-actions">
                <button id="wplf-export-logs" class="button button-secondary">
                    <span class="dashicons dashicons-download"></span> Esporta CSV
                </button>
                <button id="wplf-clear-logs" class="button button-secondary">
                    <span class="dashicons dashicons-trash"></span> Cancella Log
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
            <h1><span class="dashicons dashicons-lock"></span> IP Bloccati</h1>
            
            <?php if (empty($blocked_ips)): ?>
                <div class="wplf-empty-state">
                    <span class="dashicons dashicons-yes-alt"></span>
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
                        <?php foreach ($blocked_ips as $ip => $data): ?>
                            <tr>
                                <td><code><?php echo esc_html($ip); ?></code></td>
                                <td><?php echo esc_html($data['blocked_at']); ?></td>
                                <td><?php echo esc_html($this->ip_blocker->get_formatted_time_remaining($ip)); ?></td>
                                <td><?php echo esc_html($data['reason']); ?></td>
                                <td><?php echo esc_html($data['attempts']); ?></td>
                                <td>
                                    <button class="button button-small wplf-unblock" data-ip="<?php echo esc_attr($ip); ?>">
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
                <span class="dashicons dashicons-info"></span>
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
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?php echo esc_html($log['timestamp']); ?></td>
                            <td><code><?php echo esc_html($log['ip']); ?></code></td>
                            <td><strong><?php echo esc_html($log['username']); ?></strong></td>
                            <td><?php echo $this->format_status($log['status']); ?></td>
                            <td><?php echo esc_html($log['message']); ?></td>
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
            <h1><span class="dashicons dashicons-info"></span> Come Funziona WP Login Firewall</h1>
            
            <div class="wplf-help-section">
                <div class="wplf-help-card">
                    <h2>🎯 Obiettivo del Plugin</h2>
                    <p>WP Login Firewall protegge il tuo sito WordPress implementando un sistema di verifica a due step prima del login, impedendo attacchi brute force e tentativi di accesso non autorizzati.</p>
                </div>
                
                <div class="wplf-help-card">
                    <h2>🔐 Come Funziona il Sistema</h2>
                    
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
                    <h2>🛡️ Livelli di Protezione</h2>
                    
                    <div class="wplf-protection-level">
                        <h3><span class="dashicons dashicons-clock"></span> Rate Limiting</h3>
                        <ul>
                            <li><strong>Limite:</strong> Massimo 5 tentativi ogni 15 minuti per IP</li>
                            <li><strong>Scopo:</strong> Rallenta gli attacchi automatici</li>
                            <li><strong>Reset:</strong> Automatico dopo 15 minuti o dopo verifica riuscita</li>
                        </ul>
                    </div>
                    
                    <div class="wplf-protection-level">
                        <h3><span class="dashicons dashicons-lock"></span> Blocco IP Automatico</h3>
                        <ul>
                            <li><strong>Soglia:</strong> 10 tentativi falliti nell'ultima ora</li>
                            <li><strong>Durata:</strong> Blocco di 24 ore</li>
                            <li><strong>Effetto:</strong> Impossibilità totale di accedere alla verifica</li>
                            <li><strong>Sblocco:</strong> Manuale dal pannello admin o automatico dopo 24h</li>
                        </ul>
                    </div>
                    
                    <div class="wplf-protection-level">
                        <h3><span class="dashicons dashicons-admin-users"></span> Whitelist Amministratori</h3>
                        <ul>
                            <li><strong>Protezione:</strong> Gli admin non vengono mai bloccati</li>
                            <li><strong>Sblocco:</strong> Automatico se un admin era bloccato</li>
                            <li><strong>Rate Limit:</strong> Azzerato automaticamente per admin</li>
                            <li><strong>Scopo:</strong> Prevenire lockout accidentali</li>
                        </ul>
                    </div>
                    
                    <div class="wplf-protection-level">
                        <h3><span class="dashicons dashicons-visibility"></span> Logging Completo</h3>
                        <ul>
                            <li><strong>Registra:</strong> Tutti i tentativi (riusciti e falliti)</li>
                            <li><strong>Informazioni:</strong> IP, username, timestamp, user agent, status</li>
                            <li><strong>Statistiche:</strong> Dashboard con metriche in tempo reale</li>
                            <li><strong>Esportazione:</strong> Download log in formato CSV</li>
                        </ul>
                    </div>
                </div>
                
                <div class="wplf-help-card">
                    <h2>📊 Status dei Log</h2>
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
                    <h2>⚙️ Configurazione Tecnica</h2>
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
                
                <div class="wplf-help-card">
                    <h2>🎨 Personalizzazione</h2>
                    <p>Puoi personalizzare l'aspetto della pagina di verifica modificando le CSS variables in:</p>
                    <p><code>assets/css/style.css</code></p>
                    <p>Variabili disponibili:</p>
                    <ul>
                        <li><code>--wplf-primary</code> - Colore primario</li>
                        <li><code>--wplf-secondary</code> - Colore secondario</li>
                        <li><code>--wplf-background</code> - Sfondo</li>
                        <li><code>--wplf-text-primary</code> - Colore testo principale</li>
                        <li>...e oltre 40 variabili configurabili</li>
                    </ul>
                </div>
                
                <div class="wplf-help-card wplf-warning-card">
                    <h2>⚠️ Note Importanti</h2>
                    <ul>
                        <li><strong>Backup:</strong> Effettua sempre un backup prima di modificare i file del plugin</li>
                        <li><strong>Admin Lockout:</strong> Gli amministratori sono automaticamente esclusi dal blocco IP</li>
                        <li><strong>Cache:</strong> Se usi un plugin di cache, svuotalo dopo l'installazione</li>
                        <li><strong>CDN:</strong> Assicurati che il CDN passi correttamente l'IP reale del visitatore</li>
                        <li><strong>Performance:</strong> I log sono limitati a 1000 entries per non appesantire il database</li>
                    </ul>
                </div>
                
                <div class="wplf-help-card wplf-info-card">
                    <h2>📝 Informazioni Plugin</h2>
                    <p><strong>Versione:</strong> <?php echo WPLF_VERSION; ?></p>
                    <p><strong>Autore:</strong> DevRoom by RoomZero Creative Solutions</p>
                    <p><strong>Repository:</strong> <a href="https://github.com/Diecigradi/wp-login-firewall" target="_blank">GitHub</a></p>
                </div>
            </div>
        </div>
        <?php
    }
}
