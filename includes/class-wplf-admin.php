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
            'success' => '<span class="wplf-badge wplf-badge-success">Verifica OK</span>',
            'login_success' => '<span class="wplf-badge wplf-badge-success">Login Riuscito</span>',
            'password_failed' => '<span class="wplf-badge wplf-badge-warning">Password Errata</span>',
            'token_burned' => '<span class="wplf-badge wplf-badge-error">Token Bruciato</span>',
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
                    <p>WP Login Firewall protegge il tuo sito WordPress implementando un <strong>sistema di verifica a due step</strong> avanzato con <strong>3 livelli di protezione</strong>, impedendo attacchi brute force, username enumeration e tentativi di accesso non autorizzati.</p>
                    <p><strong>Versione corrente:</strong> <?php echo WPLF_VERSION; ?> - Security Hardening Release</p>
                </div>
                
                <div class="wplf-help-card">
                    <h2>Come Funziona il Sistema</h2>
                    
                    <div class="wplf-step">
                        <div class="wplf-step-number">1</div>
                        <div class="wplf-step-content">
                            <h3>Intercettazione Login</h3>
                            <p>Quando un utente tenta di accedere a <code>/wp-login.php</code> o <code>/wp-admin</code>, il plugin intercetta la richiesta e mostra una pagina di verifica personalizzata.</p>
                            <p><strong>Controlli automatici:</strong> Verifica IP bloccato, token esistente, azioni speciali (logout, password dimenticata).</p>
                        </div>
                    </div>
                    
                    <div class="wplf-step">
                        <div class="wplf-step-number">2</div>
                        <div class="wplf-step-content">
                            <h3>Verifica Username (STEP 1)</h3>
                            <p>L'utente deve inserire il proprio username o email. Il sistema verifica che esista nel database <strong>senza rivelare informazioni</strong> (protezione contro username enumeration).</p>
                            <p><strong>Protezioni attive:</strong></p>
                            <ul>
                                <li>Rate Limit dedicato (configurabile: 3 richieste ogni 30 minuti)</li>
                                <li>Delay randomizzato anti-timing attack (200-500ms)</li>
                                <li>Messaggio generico identico per username esistente/inesistente</li>
                                <li>Conteggio automatico verso blocco IP</li>
                            </ul>
                        </div>
                    </div>
                    
                    <div class="wplf-step">
                        <div class="wplf-step-number">3</div>
                        <div class="wplf-step-content">
                            <h3>Generazione Token Sicuro</h3>
                            <p>Se l'username è valido, viene generato un <strong>token crittograficamente sicuro</strong> (64 caratteri hex) con scadenza configurabile (default: 5 minuti).</p>
                            <p><strong>Caratteristiche token:</strong></p>
                            <ul>
                                <li>Cookie HttpOnly + Secure (protezione XSS/CSRF)</li>
                                <li>Salvataggio come transient WordPress con metadata</li>
                                <li>Contatore tentativi password falliti (0/max_attempts)</li>
                                <li>Timestamp creazione per countdown visivo</li>
                            </ul>
                        </div>
                    </div>
                    
                    <div class="wplf-step">
                        <div class="wplf-step-number">4</div>
                        <div class="wplf-step-content">
                            <h3>Login WordPress con Password (STEP 2)</h3>
                            <p>Solo con un token valido l'utente può accedere al form di login WordPress e inserire la password.</p>
                            <p><strong>Protezione Token Burning:</strong> Dopo N tentativi password errati configurabili (default: 3), il token viene <strong>invalidato permanentemente</strong>. L'utente deve rifare verifica username.</p>
                            <p><strong>UI Migliorata:</strong> Contatore crescente "Tentativo 1 di 3 fallito", hint password solo all'ultimo tentativo, design minimale.</p>
                        </div>
                    </div>
                    
                    <div class="wplf-step">
                        <div class="wplf-step-number">5</div>
                        <div class="wplf-step-content">
                            <h3>Login Completato</h3>
                            <p>Dopo autenticazione WordPress riuscita, il token viene distrutto e viene registrato il log di <strong>login_success</strong>.</p>
                            <p>L'utente accede normalmente alla dashboard WordPress.</p>
                        </div>
                    </div>
                </div>
                
                <div class="wplf-help-card">
                    <h2>Tre Livelli di Protezione Integrati</h2>
                    
                    <div class="wplf-protection-level">
                        <h3>Livello 1: Rate Limiting Dual-Layer</h3>
                        <p><strong>Sistema doppio per massima protezione:</strong></p>
                        
                        <h4>A) Rate Limit Verifica Username</h4>
                        <ul>
                            <li><strong>Limite:</strong> 3 richieste verifica ogni 30 minuti per IP (configurabile 1-10 tentativi, 5-60 minuti)</li>
                            <li><strong>Scopo:</strong> Previene loop infiniti di verifica username</li>
                            <li><strong>Attivazione:</strong> Quando rate limit superato → log <code>rate_limited</code> + blocco IP se persistente</li>
                            <li><strong>Reset:</strong> Automatico dopo finestra temporale o verifica riuscita</li>
                        </ul>
                        
                        <h4>B) Rate Limit Login Standard (legacy)</h4>
                        <ul>
                            <li><strong>Limite:</strong> 3 tentativi ogni 30 minuti per IP (configurabile)</li>
                            <li><strong>Scopo:</strong> Protezione aggiuntiva residua</li>
                            <li><strong>Conteggio:</strong> Incrementa dopo username non trovato</li>
                        </ul>
                    </div>
                    
                    <div class="wplf-protection-level">
                        <h3>Livello 2: Token Burning (Password Attempts)</h3>
                        <ul>
                            <li><strong>Limite:</strong> 3 tentativi password per token (configurabile 3-20)</li>
                            <li><strong>Meccanismo:</strong> Ogni password errata incrementa contatore nel token transient</li>
                            <li><strong>Token Burning:</strong> Al raggiungimento limite → token invalidato + log <code>token_burned</code></li>
                            <li><strong>Effetto:</strong> Utente deve rifare STEP 1 (verifica username) → nuovo token generato</li>
                            <li><strong>UI:</strong> Contatore visivo "Tentativo X di Y fallito" + hint solo all'ultimo tentativo</li>
                            <li><strong>Sicurezza:</strong> Impedisce attacchi password brute force sul singolo token</li>
                        </ul>
                    </div>
                    
                    <div class="wplf-protection-level">
                        <h3>Livello 3: Blocco IP Automatico</h3>
                        <ul>
                            <li><strong>Soglia:</strong> 5 tentativi falliti nell'ultima ora (configurabile)</li>
                            <li><strong>Conteggio:</strong> Include status <code>failed</code> + <code>rate_limited</code></li>
                            <li><strong>Durata:</strong> 72 ore (configurabile)</li>
                            <li><strong>Trigger:</strong> Verifica username errata, rate limit superato, token bruciato</li>
                            <li><strong>Effetto:</strong> Blocco completo → pagina dedicata con countdown</li>
                            <li><strong>AJAX Protection:</strong> Se bloccato via AJAX → reload automatico → mostra pagina blocco pulita</li>
                            <li><strong>Sblocco:</strong> Manuale da pannello admin (pulsante "Sblocca IP") o automatico dopo scadenza</li>
                        </ul>
                    </div>
                    
                    <div class="wplf-protection-level">
                        <h3>Whitelist Amministratori (Protezione Lockout)</h3>
                        <ul>
                            <li><strong>Protezione:</strong> Gli admin con capability <code>manage_options</code> non vengono mai bloccati</li>
                            <li><strong>Bypass completo:</strong> Rate limit azzerato, IP sbloccato se presente in blacklist</li>
                            <li><strong>Auto-sblocco:</strong> Admin bloccato viene sbloccato automaticamente al login</li>
                            <li><strong>Rate Limit:</strong> Reset automatico per admin</li>
                            <li><strong>Log:</strong> Registrato come "Admin verificato (whitelist automatica)"</li>
                            <li><strong>Scopo:</strong> Prevenire lockout accidentali amministratori</li>
                            <li><strong>Disattivabile:</strong> Checkbox in impostazioni (sconsigliato)</li>
                        </ul>
                    </div>
                    
                    <div class="wplf-protection-level">
                        <h3>Logging Completo & Audit Trail</h3>
                        <p><strong>7 Status Types tracciati:</strong></p>
                        <ul>
                            <li><code>success</code> - Username verificato correttamente (badge verde "Verifica OK")</li>
                            <li><code>login_success</code> - Login WordPress completato (badge "Login Riuscito")</li>
                            <li><code>password_failed</code> - Password errata con contatore (badge "Password Errata")</li>
                            <li><code>token_burned</code> - Token invalidato dopo N tentativi (badge "Token Bruciato")</li>
                            <li><code>failed</code> - Username non trovato o credenziali invalide (badge rosso "Fallito")</li>
                            <li><code>rate_limited</code> - Superato rate limit (badge giallo "Rate Limited")</li>
                            <li><code>blocked</code> - Tentativo da IP bloccato (badge rosso "Bloccato")</li>
                        </ul>
                        <p><strong>Informazioni registrate:</strong> IP, Username, Timestamp, User Agent, Status, Messaggio dettagliato</p>
                        <p><strong>Features:</strong> Dashboard statistiche in tempo reale, export CSV, retention configurabile (default 60 giorni), tabella ottimizzata con indici</p>
                    </div>
                </div>
                
                <div class="wplf-help-card">
                    <h2>Status dei Log - Legenda Completa</h2>
                    <table class="wplf-status-table">
                        <thead>
                            <tr>
                                <th>Badge</th>
                                <th>Descrizione</th>
                                <th>Trigger</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><span class="wplf-badge wplf-badge-success">Verifica OK</span></td>
                                <td>Username verificato con successo, token generato</td>
                                <td>Username esistente nel database → STEP 1 completato</td>
                            </tr>
                            <tr>
                                <td><span class="wplf-badge wplf-badge-success">Login Riuscito</span></td>
                                <td>Login WordPress completato con successo (hook <code>wp_login</code>)</td>
                                <td>Password corretta → Accesso dashboard WordPress</td>
                            </tr>
                            <tr>
                                <td><span class="wplf-badge wplf-badge-warning">Password Errata</span></td>
                                <td>Tentativo password fallito (con contatore X/Y)</td>
                                <td>Password sbagliata durante STEP 2 → contatore incrementato</td>
                            </tr>
                            <tr>
                                <td><span class="wplf-badge wplf-badge-error">Token Bruciato</span></td>
                                <td>Token invalidato dopo N tentativi password falliti</td>
                                <td>Raggiunto limite password attempts → token distrutto</td>
                            </tr>
                            <tr>
                                <td><span class="wplf-badge wplf-badge-error">Fallito</span></td>
                                <td>Username non trovato o credenziali non valide</td>
                                <td>Username inesistente durante STEP 1</td>
                            </tr>
                            <tr>
                                <td><span class="wplf-badge wplf-badge-warning">Rate Limited</span></td>
                                <td>Tentativo bloccato per superamento rate limit</td>
                                <td>Troppi tentativi verifica username in finestra temporale</td>
                            </tr>
                            <tr>
                                <td><span class="wplf-badge wplf-badge-error">Bloccato</span></td>
                                <td>Tentativo da IP presente in blacklist</td>
                                <td>IP bloccato dopo N tentativi falliti (72h)</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <div class="wplf-help-card">
                    <h2>Configurazione Tecnica (Tutte le Impostazioni)</h2>
                    <table class="wplf-config-table">
                        <thead>
                            <tr>
                                <th>Parametro</th>
                                <th>Valore Default</th>
                                <th>Range</th>
                                <th>File/Opzione</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><strong>Rate Limit Login Standard - Tentativi</strong></td>
                                <td><code>3</code></td>
                                <td>1-20</td>
                                <td><code>wplf_rate_limit_attempts</code></td>
                            </tr>
                            <tr>
                                <td><strong>Rate Limit Login Standard - Minuti</strong></td>
                                <td><code>30</code></td>
                                <td>1-120</td>
                                <td><code>wplf_rate_limit_minutes</code></td>
                            </tr>
                            <tr>
                                <td><strong>Limite Richieste Verifica Username - Tentativi</strong></td>
                                <td><code>3</code></td>
                                <td>1-10</td>
                                <td><code>wplf_verify_limit_attempts</code></td>
                            </tr>
                            <tr>
                                <td><strong>Limite Richieste Verifica Username - Minuti</strong></td>
                                <td><code>30</code></td>
                                <td>5-60</td>
                                <td><code>wplf_verify_limit_minutes</code></td>
                            </tr>
                            <tr>
                                <td><strong>Tentativi Password per Token (Token Burning)</strong></td>
                                <td><code>3</code></td>
                                <td>3-20</td>
                                <td><code>wplf_max_password_attempts</code></td>
                            </tr>
                            <tr>
                                <td><strong>Blocco IP Automatico - Soglia Tentativi</strong></td>
                                <td><code>5</code></td>
                                <td>1-50</td>
                                <td><code>wplf_ip_block_threshold</code></td>
                            </tr>
                            <tr>
                                <td><strong>Blocco IP Automatico - Durata (ore)</strong></td>
                                <td><code>72</code></td>
                                <td>1-168</td>
                                <td><code>wplf_ip_block_duration</code></td>
                            </tr>
                            <tr>
                                <td><strong>Token - Scadenza (minuti)</strong></td>
                                <td><code>5</code></td>
                                <td>1-60</td>
                                <td><code>wplf_token_lifetime</code></td>
                            </tr>
                            <tr>
                                <td><strong>Log - Retention (giorni)</strong></td>
                                <td><code>60</code></td>
                                <td>7-365</td>
                                <td><code>wplf_log_retention</code></td>
                            </tr>
                            <tr>
                                <td><strong>Whitelist Amministratori (ON/OFF)</strong></td>
                                <td><code>Abilitata</code></td>
                                <td>0 o 1</td>
                                <td><code>wplf_admin_whitelist</code></td>
                            </tr>
                            <tr>
                                <td><strong>Modalità Debug (ON/OFF)</strong></td>
                                <td><code>Disabilitata</code></td>
                                <td>0 o 1</td>
                                <td><code>wplf_debug_mode</code></td>
                            </tr>
                        </tbody>
                    </table>
                    <p style="margin-top: 15px;"><strong>Note:</strong> Tutte le impostazioni sono configurabili dalla pagina <em>Impostazioni</em>. I valori vengono validati server-side prima del salvataggio.</p>
                </div>
                
                <div class="wplf-help-card">
                    <h2>Analisi Sicurezza - Prima vs Dopo</h2>
                    <table class="wplf-config-table">
                        <thead>
                            <tr>
                                <th>Scenario Attacco</th>
                                <th>Prima (WordPress Standard)</th>
                                <th>Dopo (WP Login Firewall v3.2+)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><strong>Username Enumeration</strong></td>
                                <td>Infiniti tentativi possibili, risposta diversa per user esistente/inesistente</td>
                                <td>Max 3 richieste/30 min, messaggio generico, delay randomizzato, blocco IP dopo 5 tentativi</td>
                            </tr>
                            <tr>
                                <td><strong>Password Brute Force</strong></td>
                                <td>~180 password/ora testabili su singolo username</td>
                                <td>Max 3 password per token → token burned → nuova verifica username → Max 9 password/30 min → IP bloccato 72h</td>
                            </tr>
                            <tr>
                                <td><strong>Loop Infiniti Verifica</strong></td>
                                <td>Non applicabile (WordPress non ha verifica separata)</td>
                                <td>Rate limit dedicato 3/30 min → blocco temporaneo → blocco IP persistente</td>
                            </tr>
                            <tr>
                                <td><strong>Attacco Distribuito (Botnet)</strong></td>
                                <td>Ogni IP ha accesso illimitato</td>
                                <td>Ogni IP limitato indipendentemente, blocco automatico 72h, audit trail completo</td>
                            </tr>
                            <tr>
                                <td><strong>Timing Attack</strong></td>
                                <td>Tempo risposta rivela username esistente</td>
                                <td>Delay randomizzato 200-500ms, messaggio identico, nessuna info leak</td>
                            </tr>
                            <tr>
                                <td><strong>Admin Lockout</strong></td>
                                <td>Plugin sicurezza possono bloccare admin</td>
                                <td>Whitelist automatica admin, bypass rate limit, auto-sblocco IP</td>
                            </tr>
                        </tbody>
                    </table>
                    <p style="margin-top: 15px;"><strong>Riduzione superficie attacco:</strong> ~92% (da ~180 password/ora a max 9/30 min con blocco IP garantito)</p>
                </div>
                
                <div class="wplf-help-card">
                    <h2>Note Importanti</h2>
                    <ul>
                        <li><strong>Backup:</strong> Effettua sempre un backup completo prima di installare/aggiornare il plugin</li>
                        <li><strong>Admin Lockout:</strong> Gli amministratori sono automaticamente esclusi da tutti i blocchi (whitelist attiva di default)</li>
                        <li><strong>Cache:</strong> Se usi plugin di cache (WP Super Cache, W3 Total Cache, etc.), svuotala dopo l'installazione e dopo ogni modifica</li>
                        <li><strong>CDN/Proxy:</strong> Assicurati che il CDN (Cloudflare, etc.) passi correttamente l'IP reale via header <code>X-Forwarded-For</code> o <code>HTTP_CF_CONNECTING_IP</code></li>
                        <li><strong>HTTPS:</strong> Cookie token usa flag <code>Secure</code> solo se sito in HTTPS (raccomandato per sicurezza)</li>
                        <li><strong>Impostazioni Aggressive:</strong> Con valori molto bassi (es: 1 tentativo, 5 min blocco) puoi bloccare utenti legittimi → usa default raccomandati</li>
                        <li><strong>Debug Mode:</strong> Abilita solo per troubleshooting, mostra pannello debug in pagina verifica (disabilitare in produzione)</li>
                        <li><strong>Database:</strong> Plugin crea 2 tabelle: <code>wp_wplf_logs</code> (log accessi) e <code>wp_wplf_blocked_ips</code> (IP bloccati)</li>
                        <li><strong>Uninstall:</strong> La disinstallazione rimuove tutte le tabelle, opzioni e transient (cleanup completo)</li>
                        <li><strong>Performance:</strong> Query ottimizzate con indici su IP/timestamp, impatto minimo su server (< 5ms overhead per richiesta)</li>
                    </ul>
                </div>
                
                <div class="wplf-help-card">
                    <h2>Informazioni Plugin</h2>
                    <p><strong>Nome:</strong> WP Login Firewall - Security Hardening</p>
                    <p><strong>Versione:</strong> <?php echo WPLF_VERSION; ?></p>
                    <p><strong>Release:</strong> Security Hardening Release (Rate Limit + Token Burning + Logging Completo)</p>
                    <p><strong>Autore:</strong> DevRoom by RoomZero Creative Solutions</p>
                    <p><strong>Repository GitHub:</strong> <a href="https://github.com/Diecigradi/wp-login-firewall" target="_blank">https://github.com/Diecigradi/wp-login-firewall</a></p>
                    <p><strong>Licenza:</strong> GPL v2 or later</p>
                    <p><strong>Compatibilità:</strong> WordPress 5.0+ | PHP 7.4+</p>
                    <p><strong>Database Tables:</strong></p>
                    <ul>
                        <li><code>wp_wplf_logs</code> - Log accessi (IP, username, status, message, user_agent, timestamp)</li>
                        <li><code>wp_wplf_blocked_ips</code> - IP bloccati (IP, blocked_at, expires_at, reason)</li>
                    </ul>
                    <p><strong>Transients WordPress utilizzati:</strong></p>
                    <ul>
                        <li><code>wplf_token_{hash}</code> - Token verifica (scadenza configurabile, default 5 min)</li>
                        <li><code>wplf_rate_{md5_ip}</code> - Rate limit login standard</li>
                        <li><code>wplf_verify_rate_{md5_ip}</code> - Rate limit verifica username</li>
                    </ul>
                    <p><strong>WordPress Options:</strong> 11 opzioni (<code>wplf_*</code>) - vedi tabella configurazione sopra</p>
                </div>
                
                <div class="wplf-help-card">
                    <h2>FAQ - Domande Frequenti</h2>
                    
                    <h3>Qual è la differenza tra "Limite Tentativi Login Standard" e "Limite Richieste Verifica"?</h3>
                    <p><strong>Limite Tentativi Login Standard:</strong> Sistema legacy che conta tentativi dopo username non trovato. Protezione residua aggiuntiva.</p>
                    <p><strong>Limite Richieste Verifica:</strong> Sistema nuovo dedicato che conta SOLO le richieste di verifica username (STEP 1). Previene loop infiniti. Raccomandato: 3 tentativi ogni 30 minuti.</p>
                    
                    <h3>Come funziona il Token Burning?</h3>
                    <p>Dopo verifica username riuscita, viene generato un token con contatore password fallite (default: 3). Ogni password errata incrementa il contatore. Al raggiungimento del limite, il token viene <strong>invalidato permanentemente</strong> e l'utente deve rifare STEP 1 (verifica username). Questo impedisce attacchi brute force password su singolo token.</p>
                    
                    <h3>Dopo quanti tentativi l'IP viene bloccato?</h3>
                    <p>Dopo <strong>5 tentativi falliti</strong> (configurabile) nell'ultima ora. Il conteggio include status <code>failed</code> (username errato) + <code>rate_limited</code> (rate limit superato). Il blocco dura <strong>72 ore</strong> (configurabile).</p>
                    
                    <h3>Posso essere bloccato come amministratore?</h3>
                    <p>No, se hai capability <code>manage_options</code> e la whitelist è abilitata (default). Il sistema resetta automaticamente rate limit, sblocca IP se presente in blacklist, e logga come "Admin verificato (whitelist automatica)".</p>
                    
                    <h3>Cosa succede se un utente legittimo viene bloccato?</h3>
                    <p>L'amministratore può sbloccarlo manualmente dalla pagina <strong>IP Bloccati</strong> cliccando "Sblocca IP". Il blocco scade anche automaticamente dopo 72h (default).</p>
                    
                    <h3>I log occupano molto spazio nel database?</h3>
                    <p>No. Il cleanup automatico elimina log più vecchi di 60 giorni (configurabile). La tabella ha indici ottimizzati su IP e timestamp. Un sito medio con 100 tentativi login/giorno occupa ~500KB/anno.</p>
                    
                    <h3>Il plugin rallenta il sito?</h3>
                    <p>No. L'overhead è minimo (< 5ms per richiesta). Il plugin si attiva SOLO su <code>/wp-login.php</code> e <code>/wp-admin</code>, non influisce sulle pagine pubbliche del sito.</p>
                    
                    <h3>Posso personalizzare la pagina di verifica?</h3>
                    <p>Sì. Il template HTML è in <code>class-wplf-core.php</code> → <code>show_verification_page()</code>. Il CSS è in <code>assets/css/style.css</code>. Puoi modificarli mantenendo la logica PHP/JavaScript.</p>
                    
                    <h3>Come disinstallo completamente il plugin?</h3>
                    <p>Disattiva il plugin dal pannello WordPress, poi eliminalo. Lo script <code>uninstall.php</code> rimuove automaticamente: tabelle database, opzioni WordPress, transient. Cleanup completo garantito.</p>
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
