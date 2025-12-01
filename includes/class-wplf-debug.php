<?php
/**
 * WP Login Firewall - Debug Logger
 * 
 * Gestisce il logging di debug per troubleshooting
 */

// Previene accesso diretto
if (!defined('ABSPATH')) {
    exit;
}

class WPLF_Debug {
    
    private static $instance = null;
    
    /**
     * Singleton pattern
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Costruttore
     */
    private function __construct() {
        // Hook per login fallito
        add_action('wp_login_failed', array($this, 'log_login_failed'));
        
        // Hook per redirect
        add_filter('wp_redirect', array($this, 'log_redirect'), 10, 2);
    }
    
    /**
     * Verifica se debug mode è abilitato
     */
    public function is_enabled() {
        return get_option('wplf_debug_mode', 0) == 1;
    }
    
    /**
     * Log intercettazione login
     */
    public function log_intercept() {
        if (!$this->is_enabled()) {
            return;
        }
        
        $debug_info = array(
            'timestamp' => current_time('H:i:s'),
            'REQUEST_METHOD' => $_SERVER['REQUEST_METHOD'],
            'REQUEST_URI' => $_SERVER['REQUEST_URI'],
            'has_cookie' => isset($_COOKIE['wplf_token']),
            'cookie_value' => isset($_COOKIE['wplf_token']) ? substr($_COOKIE['wplf_token'], 0, 8) . '...' : 'none',
            'has_POST_log' => isset($_POST['log']),
            'has_POST_pwd' => isset($_POST['pwd']),
            'POST_log_value' => isset($_POST['log']) ? $_POST['log'] : 'none',
            'GET_action' => isset($_GET['action']) ? $_GET['action'] : 'none',
            'GET_login' => isset($_GET['login']) ? $_GET['login'] : 'none',
            'GET_redirect_to' => isset($_GET['redirect_to']) ? substr($_GET['redirect_to'], 0, 30) . '...' : 'none',
        );
        
        $this->save_log($debug_info);
    }
    
    /**
     * Aggiunge messaggio azione all'ultimo log
     */
    public function add_action($message) {
        if (!$this->is_enabled()) {
            return;
        }
        
        $existing_logs = get_transient('wplf_debug_logs') ?: array();
        if (!empty($existing_logs)) {
            $existing_logs[count($existing_logs) - 1]['action'] = $message;
            set_transient('wplf_debug_logs', $existing_logs, 300);
        }
    }
    
    /**
     * Log creazione token
     */
    public function log_token_created($username, $token, $lifetime_seconds) {
        if (!$this->is_enabled()) {
            return;
        }
        
        $log_entry = array(
            'timestamp' => current_time('H:i:s'),
            'event' => 'TOKEN_CREATED',
            'username' => $username,
            'token_preview' => substr($token, 0, 8) . '...',
            'lifetime_minutes' => ceil($lifetime_seconds / 60),
            'expires_at' => date('H:i:s', time() + $lifetime_seconds),
        );
        
        $this->save_log($log_entry);
    }
    
    /**
     * Log login fallito
     */
    public function log_login_failed($username) {
        if (!$this->is_enabled()) {
            return;
        }
        
        $log_entry = array(
            'timestamp' => current_time('H:i:s'),
            'event' => 'WP_LOGIN_FAILED',
            'username' => $username,
            'has_cookie' => isset($_COOKIE['wplf_token']),
            'cookie_value' => isset($_COOKIE['wplf_token']) ? substr($_COOKIE['wplf_token'], 0, 8) . '...' : 'none',
        );
        
        $this->save_log($log_entry);
    }
    
    /**
     * Log redirect
     */
    public function log_redirect($location, $status) {
        if (!$this->is_enabled()) {
            return $location;
        }
        
        $log_entry = array(
            'timestamp' => current_time('H:i:s'),
            'event' => 'WP_REDIRECT',
            'location' => $location,
            'status' => $status,
            'has_cookie' => isset($_COOKIE['wplf_token']),
            'cookie_value' => isset($_COOKIE['wplf_token']) ? substr($_COOKIE['wplf_token'], 0, 8) . '...' : 'none',
        );
        
        $this->save_log($log_entry);
        
        // Preserva cookie durante redirect
        if (isset($_COOKIE['wplf_token'])) {
            $token = $_COOKIE['wplf_token'];
            $token_data = get_transient('wplf_token_' . $token);
            
            if ($token_data) {
                $token_lifetime = get_option('wplf_token_lifetime', 5) * MINUTE_IN_SECONDS;
                $elapsed = current_time('timestamp') - $token_data['timestamp'];
                $remaining = $token_lifetime - $elapsed;
                
                if ($remaining > 0) {
                    setcookie(
                        'wplf_token',
                        $token,
                        array(
                            'expires' => time() + $remaining,
                            'path' => COOKIEPATH,
                            'domain' => COOKIE_DOMAIN,
                            'secure' => is_ssl(),
                            'httponly' => true,
                            'samesite' => 'Lax'
                        )
                    );
                }
            }
        }
        
        return $location;
    }
    
    /**
     * Salva entry nel log
     */
    private function save_log($entry) {
        $existing_logs = get_transient('wplf_debug_logs') ?: array();
        $existing_logs[] = $entry;
        $existing_logs = array_slice($existing_logs, -20); // Mantieni ultimi 20
        set_transient('wplf_debug_logs', $existing_logs, 300);
    }
    
    /**
     * Recupera tutti i log
     */
    public function get_logs() {
        if (!$this->is_enabled()) {
            return array();
        }
        
        return get_transient('wplf_debug_logs') ?: array();
    }
    
    /**
     * Cancella tutti i log
     */
    public function clear_logs() {
        delete_transient('wplf_debug_logs');
    }
    
    /**
     * Renderizza il pannello debug
     */
    public function render_panel() {
        if (!$this->is_enabled()) {
            return;
        }
        
        $debug_logs = $this->get_logs();
        
        if (empty($debug_logs)) {
            return;
        }
        
        ?>
        <div class="wplf-debug-logs">
            <strong>🐛 Debug Log (ultimi accessi):</strong>
            <?php foreach (array_reverse($debug_logs) as $log): ?>
            <div class="wplf-debug-log">
                <?php if (isset($log['event']) && $log['event'] === 'WP_LOGIN_FAILED'): ?>
                    <strong style="color: #dc3232;">⚠️ <?php echo esc_html($log['timestamp']); ?> - LOGIN FAILED</strong><br>
                    Username: <?php echo esc_html($log['username']); ?><br>
                    Cookie: <?php echo $log['has_cookie'] ? '✓' : '✗'; ?> 
                    (<?php echo esc_html($log['cookie_value']); ?>)
                <?php elseif (isset($log['event']) && $log['event'] === 'WP_REDIRECT'): ?>
                    <strong style="color: #00a0d2;">↗️ <?php echo esc_html($log['timestamp']); ?> - REDIRECT</strong><br>
                    Location: <?php echo esc_html(substr($log['location'], 0, 50)); ?><br>
                    Status: <?php echo esc_html($log['status']); ?><br>
                    Cookie: <?php echo $log['has_cookie'] ? '✓' : '✗'; ?> 
                    (<?php echo esc_html($log['cookie_value']); ?>)
                <?php elseif (isset($log['event']) && $log['event'] === 'TOKEN_CREATED'): ?>
                    <strong style="color: #46b450;">✓ <?php echo esc_html($log['timestamp']); ?> - TOKEN CREATED</strong><br>
                    Username: <?php echo esc_html($log['username']); ?><br>
                    Token: <?php echo esc_html($log['token_preview']); ?><br>
                    Lifetime: <?php echo esc_html($log['lifetime_minutes']); ?> minuti<br>
                    Scade alle: <?php echo esc_html($log['expires_at']); ?>
                <?php else: ?>
                    <strong><?php echo esc_html($log['timestamp']); ?></strong> - 
                    <?php echo esc_html($log['REQUEST_METHOD']); ?><br>
                    URI: <?php echo esc_html(isset($log['REQUEST_URI']) ? $log['REQUEST_URI'] : 'N/A'); ?><br>
                    Cookie: <?php echo $log['has_cookie'] ? '✓' : '✗'; ?> 
                    (<?php echo esc_html($log['cookie_value']); ?>)<br>
                    POST: log=<?php echo $log['has_POST_log'] ? '✓' : '✗'; ?>
                    <?php if (isset($log['POST_log_value']) && $log['POST_log_value'] !== 'none'): ?>
                    (<?php echo esc_html($log['POST_log_value']); ?>)
                    <?php endif; ?>, 
                    pwd=<?php echo $log['has_POST_pwd'] ? '✓' : '✗'; ?><br>
                    GET: action=<?php echo esc_html($log['GET_action']); ?>, 
                    login=<?php echo esc_html($log['GET_login']); ?>
                    <?php if (isset($log['GET_redirect_to']) && $log['GET_redirect_to'] !== 'none'): ?>
                    , redirect_to=<?php echo esc_html($log['GET_redirect_to']); ?>
                    <?php endif; ?><br>
                    <?php if (isset($log['action'])): ?>
                    <strong>→ <?php echo esc_html($log['action']); ?></strong>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <button class="wplf-debug-clear" onclick="fetch('<?php echo admin_url('admin-ajax.php'); ?>?action=wplf_clear_debug').then(() => location.reload())">Cancella Log</button>
        </div>
        
        <style>
            .wplf-debug-logs {
                margin-top: 20px;
                padding: 15px;
                background: #f9f9f9;
                border: 1px solid #ddd;
                border-radius: 4px;
                max-height: 300px;
                overflow-y: auto;
                font-family: 'Courier New', monospace;
                font-size: 11px;
            }
            .wplf-debug-log {
                margin-bottom: 10px;
                padding: 8px;
                background: white;
                border-left: 3px solid #0073aa;
                line-height: 1.6;
            }
            .wplf-debug-log strong {
                color: #0073aa;
            }
            .wplf-debug-clear {
                margin-top: 10px;
                padding: 5px 12px;
                font-size: 11px;
                background: #dc3232;
                color: white;
                border: none;
                border-radius: 3px;
                cursor: pointer;
            }
            .wplf-debug-clear:hover {
                background: #a00;
            }
        </style>
        <?php
    }
}
