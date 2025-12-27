<?php
/**
 * RoomZero License Manager Client
 * 
 * Gestisce validazione e attivazione licenze con RoomZero License Manager
 * Include pagina amministrazione per gestione licenza
 * 
 * @package RoomZero
 * @version 1.0.0
 * @author RoomZero
 * @link https://roomzero.net
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class RoomZero_License_Manager {
    
    /**
     * URL base API
     * @var string
     */
    private $api_url = 'https://licensemanager.roomzero.net/api/';
    
    /**
     * Slug del prodotto
     * @var string
     */
    private $product_slug;
    
    /**
     * Nome del prodotto (per UI)
     * @var string
     */
    private $product_name;
    
    /**
     * Nome opzione per license key
     * @var string
     */
    private $option_license_key;
    
    /**
     * Nome opzione per stato licenza
     * @var string
     */
    private $option_license_status;
    
    /**
     * Cache durata (24 ore)
     * @var int
     */
    private $cache_duration = 86400;
    
    /**
     * Ultimo errore
     * @var string
     */
    private $last_error = '';
    
    /**
     * Costruttore
     * 
     * @param string $product_slug Slug prodotto nel License Manager
     * @param string $product_name Nome prodotto per UI
     */
    public function __construct($product_slug, $product_name = '') {
        $this->product_slug = $product_slug;
        $this->product_name = $product_name ?: ucwords(str_replace('-', ' ', $product_slug));
        
        $this->option_license_key = $product_slug . '_license_key';
        $this->option_license_status = $product_slug . '_license_status';
        
        // Hook per verifica giornaliera
        add_action('init', array($this, 'schedule_daily_check'));
        add_action($product_slug . '_daily_license_check', array($this, 'validate_license'));
        
        // Hook per avvisi admin
        add_action('admin_notices', array($this, 'show_license_notices'));
    }
    
    /**
     * Verifica se la licenza è valida
     * Controlla cache prima di chiamare API
     * 
     * @return bool True se licenza valida
     */
    public function is_license_valid() {
        $status = get_option($this->option_license_status, array());
        
        if (empty($status) || !isset($status['valid'])) {
            return false;
        }
        
        // Verifica se cache è scaduta (oltre 24 ore)
        if (isset($status['checked_at']) && (time() - $status['checked_at']) > $this->cache_duration) {
            // Cache scaduta, forza validazione
            $this->validate_license();
            $status = get_option($this->option_license_status, array());
        }
        
        return !empty($status['valid']);
    }
    
    /**
     * Attiva la licenza sul dominio corrente
     * 
     * @param string $license_key Chiave licenza
     * @return bool True se attivazione riuscita
     */
    public function activate_license($license_key) {
        $license_key = sanitize_text_field($license_key);
        
        if (empty($license_key)) {
            $this->last_error = 'Chiave licenza vuota';
            return false;
        }
        
        // Chiamata API activate
        $response = wp_remote_post($this->api_url . 'activate', array(
            'body' => json_encode(array(
                'license_key'  => $license_key,
                'product_slug' => $this->product_slug,
                'domain'       => $this->get_domain(),
                'server_info'  => $this->get_server_info()
            )),
            'headers' => array(
                'Content-Type' => 'application/json'
            ),
            'timeout' => 15
        ));
        
        if (is_wp_error($response)) {
            $this->last_error = 'Errore connessione: ' . $response->get_error_message();
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (empty($data['success'])) {
            $this->last_error = isset($data['message']) ? $data['message'] : 'Attivazione fallita';
            return false;
        }
        
        // Salva license key
        update_option($this->option_license_key, $license_key);
        
        // Valida per ottenere info complete
        $this->validate_license();
        
        return true;
    }
    
    /**
     * Disattiva la licenza dal dominio corrente
     * 
     * @return bool True se disattivazione riuscita
     */
    public function deactivate_license() {
        $license_key = get_option($this->option_license_key);
        
        if (empty($license_key)) {
            return true; // Nessuna licenza da disattivare
        }
        
        // Chiamata API deactivate
        $response = wp_remote_post($this->api_url . 'deactivate', array(
            'body' => json_encode(array(
                'license_key'  => $license_key,
                'product_slug' => $this->product_slug,
                'domain'       => $this->get_domain()
            )),
            'headers' => array(
                'Content-Type' => 'application/json'
            ),
            'timeout' => 15
        ));
        
        if (is_wp_error($response)) {
            $this->last_error = 'Errore connessione: ' . $response->get_error_message();
            return false;
        }
        
        // Pulisci dati locali
        delete_option($this->option_license_key);
        delete_option($this->option_license_status);
        
        return true;
    }
    
    /**
     * Valida la licenza (controllo stato attuale)
     * 
     * @return bool True se licenza valida
     */
    public function validate_license() {
        $license_key = get_option($this->option_license_key);
        
        if (empty($license_key)) {
            return false;
        }
        
        // Chiamata API validate
        $response = wp_remote_post($this->api_url . 'validate', array(
            'body' => json_encode(array(
                'license_key'  => $license_key,
                'product_slug' => $this->product_slug,
                'domain'       => $this->get_domain(),
                'server_info'  => $this->get_server_info()
            )),
            'headers' => array(
                'Content-Type' => 'application/json'
            ),
            'timeout' => 15
        ));
        
        if (is_wp_error($response)) {
            $this->update_license_status(false, 'error', 'Errore connessione al server licenze');
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (empty($data['success'])) {
            $message = isset($data['message']) ? $data['message'] : 'Licenza non valida';
            $this->update_license_status(false, 'invalid', $message);
            return false;
        }
        
        // Salva stato licenza
        $license_data = $data['data'];
        $is_valid = isset($license_data['status']) && $license_data['status'] === 'active';
        
        $this->update_license_status($is_valid, $license_data['status'], 'Licenza verificata');
        
        // Salva dati completi
        update_option($this->product_slug . '_license_data', $license_data);
        
        return $is_valid;
    }
    
    /**
     * Aggiorna stato licenza in cache
     * 
     * @param bool   $valid   Licenza valida
     * @param string $status  Stato (active, expired, invalid, error)
     * @param string $message Messaggio
     */
    private function update_license_status($valid, $status, $message) {
        update_option($this->option_license_status, array(
            'valid'      => $valid,
            'status'     => $status,
            'message'    => $message,
            'checked_at' => time()
        ));
    }
    
    /**
     * Ottieni stato licenza
     * 
     * @return array Stato licenza
     */
    public function get_license_status() {
        return get_option($this->option_license_status, array(
            'valid'   => false,
            'status'  => 'inactive',
            'message' => 'Licenza non attivata'
        ));
    }
    
    /**
     * Ottieni dati licenza completi
     * 
     * @return array|false Dati licenza o false
     */
    public function get_license_data() {
        return get_option($this->product_slug . '_license_data', false);
    }
    
    /**
     * Ottieni chiave licenza
     * 
     * @return string Chiave licenza
     */
    public function get_license_key() {
        return get_option($this->option_license_key, '');
    }
    
    /**
     * Ottieni ultimo errore
     * 
     * @return string Messaggio errore
     */
    public function get_last_error() {
        return $this->last_error;
    }
    
    /**
     * Schedula verifica giornaliera automatica
     */
    public function schedule_daily_check() {
        $hook = $this->product_slug . '_daily_license_check';
        
        if (!wp_next_scheduled($hook)) {
            wp_schedule_event(time(), 'daily', $hook);
        }
    }
    
    /**
     * Mostra avvisi admin se licenza non valida
     */
    public function show_license_notices() {
        $status = $this->get_license_status();
        
        // Solo se non siamo nella pagina licenza stessa
        $screen = get_current_screen();
        if ($screen && strpos($screen->id, $this->product_slug) !== false) {
            return;
        }
        
        if (!$status['valid']) {
            echo '<div class="notice notice-error">';
            echo '<p><strong>' . esc_html($this->product_name) . ':</strong> ';
            echo 'Licenza non valida. ';
            echo '<a href="' . admin_url('options-general.php?page=' . $this->product_slug . '-license') . '">Attiva la licenza</a>';
            echo '</p></div>';
        }
    }
    
    /**
     * Aggiungi pagina impostazioni licenza
     * 
     * @param string $parent_slug Slug menu genitore (opzionale)
     */
    public function add_license_page($parent_slug = 'options-general.php') {
        if ($parent_slug === 'options-general.php') {
            // Sottomenu Impostazioni
            add_options_page(
                'Licenza ' . $this->product_name,
                'Licenza ' . $this->product_name,
                'manage_options',
                $this->product_slug . '-license',
                array($this, 'render_license_page')
            );
        } else {
            // Sottomenu custom
            add_submenu_page(
                $parent_slug,
                'Licenza',
                'Licenza',
                'manage_options',
                $this->product_slug . '-license',
                array($this, 'render_license_page')
            );
        }
    }
    
    /**
     * Renderizza pagina licenza
     */
    public function render_license_page() {
        // Gestione form submit
        if (isset($_POST['activate_license']) && check_admin_referer('roomzero_activate_license')) {
            $license_key = sanitize_text_field($_POST['license_key']);
            if ($this->activate_license($license_key)) {
                echo '<div class="notice notice-success"><p>✅ Licenza attivata con successo!</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>❌ ' . esc_html($this->get_last_error()) . '</p></div>';
            }
        }
        
        if (isset($_POST['deactivate_license']) && check_admin_referer('roomzero_deactivate_license')) {
            if ($this->deactivate_license()) {
                echo '<div class="notice notice-success"><p>✅ Licenza disattivata</p></div>';
            }
        }
        
        if (isset($_POST['validate_license']) && check_admin_referer('roomzero_validate_license')) {
            if ($this->validate_license()) {
                echo '<div class="notice notice-success"><p>✅ Licenza valida</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>❌ Licenza non valida</p></div>';
            }
        }
        
        $status = $this->get_license_status();
        $license_key = $this->get_license_key();
        $license_data = $this->get_license_data();
        ?>
        
        <div class="wrap roomzero-license-page">
            <h1><?php echo esc_html($this->product_name); ?> - Gestione Licenza</h1>
            
            <?php if ($status['valid']): ?>
                <!-- Licenza Attiva -->
                <div class="notice notice-success inline">
                    <p><strong>✅ Licenza Attiva</strong></p>
                </div>
                
                <table class="form-table">
                    <tr>
                        <th>Stato</th>
                        <td>
                            <span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span>
                            <strong style="color: #46b450;">Attiva</strong>
                        </td>
                    </tr>
                    <tr>
                        <th>Chiave Licenza</th>
                        <td><code><?php echo esc_html($license_key); ?></code></td>
                    </tr>
                    <tr>
                        <th>Dominio</th>
                        <td><code><?php echo esc_html($this->get_domain()); ?></code></td>
                    </tr>
                    <?php if ($license_data && isset($license_data['expires_at'])): ?>
                    <tr>
                        <th>Scadenza</th>
                        <td>
                            <?php 
                            if ($license_data['expires_at']) {
                                echo esc_html(date('d/m/Y', strtotime($license_data['expires_at'])));
                            } else {
                                echo 'Mai (licenza a vita)';
                            }
                            ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php if ($license_data && isset($license_data['activations'])): ?>
                    <tr>
                        <th>Attivazioni</th>
                        <td>
                            <?php echo esc_html($license_data['activations']['current']); ?> / 
                            <?php echo esc_html($license_data['activations']['max']); ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <th>Ultimo Controllo</th>
                        <td>
                            <?php 
                            if (isset($status['checked_at'])) {
                                echo esc_html(human_time_diff($status['checked_at'], time())) . ' fa';
                            }
                            ?>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <form method="post" style="display:inline;">
                        <?php wp_nonce_field('roomzero_validate_license'); ?>
                        <button type="submit" name="validate_license" class="button button-secondary">
                            🔄 Verifica Licenza
                        </button>
                    </form>
                    
                    <form method="post" style="display:inline;">
                        <?php wp_nonce_field('roomzero_deactivate_license'); ?>
                        <button type="submit" name="deactivate_license" class="button button-secondary"
                                onclick="return confirm('Sei sicuro di voler disattivare la licenza?');">
                            🔓 Disattiva Licenza
                        </button>
                    </form>
                </p>
                
            <?php else: ?>
                <!-- Form Attivazione -->
                <div class="notice notice-warning inline">
                    <p><strong>⚠️ Licenza non attivata</strong></p>
                </div>
                
                <form method="post">
                    <?php wp_nonce_field('roomzero_activate_license'); ?>
                    
                    <table class="form-table">
                        <tr>
                            <th><label for="license_key">Chiave Licenza</label></th>
                            <td>
                                <input type="text" 
                                       id="license_key" 
                                       name="license_key" 
                                       class="regular-text" 
                                       placeholder="ABC-12345678-12345678-12345678-12345678"
                                       required>
                                <p class="description">
                                    Inserisci la chiave licenza ricevuta via email dopo l'acquisto.
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th>Dominio</th>
                            <td>
                                <code><?php echo esc_html($this->get_domain()); ?></code>
                                <p class="description">
                                    La licenza sarà attivata per questo dominio.
                                </p>
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <button type="submit" name="activate_license" class="button button-primary">
                            Attiva Licenza
                        </button>
                    </p>
                </form>
                
                <hr>
                
                <h3>📍 Dove trovo la chiave licenza?</h3>
                <p>La chiave licenza ti è stata inviata via email dopo l'acquisto del plugin.</p>
                <p>Se non la trovi, contatta il supporto: <a href="mailto:support@roomzero.net">support@roomzero.net</a></p>
                
            <?php endif; ?>
        </div>
        
        <?php
    }
    
    /**
     * Ottieni dominio corrente
     * 
     * @return string Dominio pulito
     */
    private function get_domain() {
        $domain = parse_url(home_url(), PHP_URL_HOST);
        $domain = str_replace('www.', '', $domain);
        return $domain;
    }
    
    /**
     * Ottieni informazioni server
     * 
     * @return array Dati server
     */
    private function get_server_info() {
        global $wp_version;
        
        return array(
            'wp_version'  => $wp_version,
            'php_version' => phpversion(),
            'site_url'    => home_url(),
            'timestamp'   => time()
        );
    }
}
