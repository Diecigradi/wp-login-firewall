<?php
// Previene l'accesso diretto
defined('ABSPATH') or die('Accesso negato!');

/**
 * Classe per la gestione del pannello amministratore
 */
class WPLoginFirewallAdmin {
    
    /**
     * Costruttore
     */
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_ajax_wplf_clear_logs', array($this, 'ajax_clear_logs'));
        add_action('wp_ajax_wplf_export_logs', array($this, 'ajax_export_logs'));
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
