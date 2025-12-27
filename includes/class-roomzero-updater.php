<?php
/**
 * RoomZero Plugin Updater
 * 
 * Gestisce aggiornamenti automatici da RoomZero License Manager
 * Hook al sistema di aggiornamenti nativo di WordPress
 * 
 * @package RoomZero
 * @version 1.0.0
 * @author RoomZero
 * @link https://roomzero.net
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class RoomZero_Plugin_Updater {
    
    /**
     * URL base API RoomZero License Manager
     * @var string
     */
    private $api_url = 'https://licensemanager.roomzero.net/api/v1/';
    
    /**
     * File del plugin (es: my-plugin/my-plugin.php)
     * @var string
     */
    private $plugin_file;
    
    /**
     * Slug del plugin (deve corrispondere al License Manager)
     * @var string
     */
    private $plugin_slug;
    
    /**
     * Versione corrente del plugin
     * @var string
     */
    private $version;
    
    /**
     * Chiave licenza
     * @var string
     */
    private $license_key;
    
    /**
     * Channel aggiornamenti (stable, beta, alpha, rc)
     * @var string
     */
    private $channel;
    
    /**
     * Cache transient name
     * @var string
     */
    private $cache_key;
    
    /**
     * Durata cache in secondi (12 ore)
     * @var int
     */
    private $cache_duration = 43200;
    
    /**
     * Costruttore
     * 
     * @param string $plugin_file   Path completo file plugin (usa __FILE__)
     * @param string $plugin_slug   Slug prodotto nel License Manager
     * @param string $version       Versione corrente plugin
     * @param string $license_key   Chiave licenza (opzionale)
     * @param string $channel       Channel aggiornamenti (default: stable)
     */
    public function __construct($plugin_file, $plugin_slug, $version, $license_key = '', $channel = 'stable') {
        $this->plugin_file = plugin_basename($plugin_file);
        $this->plugin_slug = $plugin_slug;
        $this->version = $version;
        $this->license_key = $license_key;
        $this->channel = $channel;
        $this->cache_key = 'roomzero_update_' . md5($this->plugin_slug);
        
        // Hook ai filtri WordPress
        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_update'));
        add_filter('plugins_api', array($this, 'plugins_api_handler'), 10, 3);
        add_filter('upgrader_pre_install', array($this, 'pre_install'), 10, 2);
        add_filter('upgrader_post_install', array($this, 'post_install'), 10, 2);
        
        // Hook per forzare controllo da dashboard
        add_action('load-update-core.php', array($this, 'force_check'));
        add_action('load-plugins.php', array($this, 'force_check'));
    }
    
    /**
     * Controlla aggiornamenti disponibili
     * Hook: pre_set_site_transient_update_plugins
     * 
     * @param object $transient Transient WordPress update_plugins
     * @return object Transient modificato
     */
    public function check_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }
        
        // Verifica se abbiamo cache valida
        $cached = get_transient($this->cache_key);
        if ($cached !== false && !$this->is_force_check()) {
            if (isset($cached['update_available']) && $cached['update_available']) {
                $transient->response[$this->plugin_file] = $this->format_plugin_data($cached);
            }
            return $transient;
        }
        
        // Chiama API check-updates
        $remote = $this->request_update_info();
        
        if ($remote && !is_wp_error($remote)) {
            // Salva in cache
            set_transient($this->cache_key, $remote, $this->cache_duration);
            
            // Se aggiornamento disponibile, aggiungi al transient
            if (isset($remote['update_available']) && $remote['update_available']) {
                $transient->response[$this->plugin_file] = $this->format_plugin_data($remote);
            } else {
                // Nessun aggiornamento, rimuovi eventuali notifiche precedenti
                unset($transient->response[$this->plugin_file]);
            }
        }
        
        return $transient;
    }
    
    /**
     * Gestisce richiesta info plugin da WordPress
     * Hook: plugins_api
     * 
     * @param mixed  $result  False di default
     * @param string $action  Azione richiesta
     * @param object $args    Argomenti richiesta
     * @return mixed Plugin info o false
     */
    public function plugins_api_handler($result, $action, $args) {
        // Solo per richieste plugin_information del nostro plugin
        if ($action !== 'plugin_information' || !isset($args->slug) || $args->slug !== $this->plugin_slug) {
            return $result;
        }
        
        // Ottieni info aggiornamento
        $remote = $this->request_update_info();
        
        if (!$remote || is_wp_error($remote)) {
            return $result;
        }
        
        // Crea oggetto info plugin per WordPress
        $plugin_info = new stdClass();
        $plugin_info->name = $remote['product']['name'] ?? $this->plugin_slug;
        $plugin_info->slug = $this->plugin_slug;
        $plugin_info->version = $remote['latest_version'] ?? $this->version;
        $plugin_info->author = '<a href="https://roomzero.net">RoomZero</a>';
        $plugin_info->homepage = 'https://roomzero.net';
        $plugin_info->requires = '5.8';
        $plugin_info->tested = '6.4';
        $plugin_info->requires_php = '7.4';
        $plugin_info->last_updated = $remote['version_info']['released_at'] ?? date('Y-m-d H:i:s');
        $plugin_info->download_link = $this->get_download_url($remote);
        
        // Sezioni informative
        $sections = array(
            'description' => '<p>Plugin premium by RoomZero</p>',
        );
        
        if (!empty($remote['version_info']['changelog'])) {
            $sections['changelog'] = $this->format_changelog($remote['version_info']['changelog']);
        }
        
        $plugin_info->sections = $sections;
        
        // Info download
        if (!empty($remote['version_info']['file_size'])) {
            $plugin_info->download_size = $remote['version_info']['file_size'];
        }
        
        return $plugin_info;
    }
    
    /**
     * Hook eseguito prima dell'installazione
     */
    public function pre_install($true, $args) {
        // Puoi aggiungere backup o altre operazioni pre-install
        return $true;
    }
    
    /**
     * Hook eseguito dopo l'installazione
     */
    public function post_install($true, $hook_extra) {
        // Pulisci cache dopo aggiornamento
        delete_transient($this->cache_key);
        return $true;
    }
    
    /**
     * Forza controllo aggiornamenti (salta cache)
     */
    public function force_check() {
        if (isset($_GET['force-check']) && $_GET['force-check'] == '1') {
            delete_transient($this->cache_key);
        }
    }
    
    /**
     * Verifica se è un controllo forzato
     * 
     * @return bool
     */
    private function is_force_check() {
        return isset($_GET['force-check']) && $_GET['force-check'] == '1';
    }
    
    /**
     * Richiesta info aggiornamento all'API
     * 
     * @return array|WP_Error Dati aggiornamento o errore
     */
    private function request_update_info() {
        $url = add_query_arg(array(
            'product_slug'    => $this->plugin_slug,
            'current_version' => $this->version,
            'channel'         => $this->channel,
            'license_key'     => $this->license_key,
        ), $this->api_url . 'check-updates.php');
        
        $response = wp_remote_get($url, array(
            'timeout' => 15,
            'headers' => array(
                'Accept' => 'application/json'
            )
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return new WP_Error('api_error', 'API returned code: ' . $code);
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (!$data) {
            return new WP_Error('json_error', 'Invalid JSON response');
        }
        
        return $data;
    }
    
    /**
     * Formatta dati per WordPress update system
     * 
     * @param array $remote Dati da API
     * @return stdClass Oggetto formattato per WordPress
     */
    private function format_plugin_data($remote) {
        $plugin_data = new stdClass();
        $plugin_data->slug = $this->plugin_slug;
        $plugin_data->plugin = $this->plugin_file;
        $plugin_data->new_version = $remote['latest_version'] ?? $this->version;
        $plugin_data->url = 'https://roomzero.net';
        $plugin_data->package = $this->get_download_url($remote);
        $plugin_data->tested = '6.4';
        $plugin_data->requires_php = '7.4';
        
        return $plugin_data;
    }
    
    /**
     * Genera URL download con licenza
     * 
     * @param array $remote Dati da API
     * @return string URL download
     */
    private function get_download_url($remote) {
        if (empty($remote['version_info']['id'])) {
            return '';
        }
        
        return add_query_arg(array(
            'version_id'  => $remote['version_info']['id'],
            'license_key' => $this->license_key
        ), $this->api_url . 'download.php');
    }
    
    /**
     * Formatta changelog per visualizzazione
     * 
     * @param string $changelog Testo changelog
     * @return string HTML formattato
     */
    private function format_changelog($changelog) {
        $output = '<div class="roomzero-changelog">';
        $output .= '<h4>🎉 Novità in questa versione</h4>';
        $output .= '<pre>' . esc_html($changelog) . '</pre>';
        $output .= '</div>';
        
        return $output;
    }
    
    /**
     * Imposta channel aggiornamenti
     * 
     * @param string $channel stable|beta|alpha|rc
     */
    public function set_channel($channel) {
        if (in_array($channel, array('stable', 'beta', 'alpha', 'rc'))) {
            $this->channel = $channel;
            delete_transient($this->cache_key); // Forza ricontrollo
        }
    }
    
    /**
     * Imposta chiave licenza
     * 
     * @param string $license_key Chiave licenza
     */
    public function set_license_key($license_key) {
        $this->license_key = $license_key;
        delete_transient($this->cache_key); // Forza ricontrollo
    }
}
