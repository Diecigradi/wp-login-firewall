<?php
/**
 * Plugin Name: WP Login Firewall
 * Plugin URI: https://github.com/Diecigradi/wp-login-firewall
 * Description: Sistema di verifica a due step per il login di WordPress con protezione avanzata contro attacchi brute force
 * Version: 3.1.0
 * Author: DevRoom by RoomZero Creative Solutions
 * Author URI: https://roomzerostaging.it
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-login-firewall
 * Requires at least: 5.0
 * Requires PHP: 7.4
 */

// Previene accesso diretto
if (!defined('ABSPATH')) {
    exit;
}

// Definisce costanti del plugin
define('WPLF_VERSION', '3.1.0');

// License Manager constants
define('WPLF_SLUG', 'wp-login-firewall');  // ⚠️ Identico allo slug nel License Manager
define('WPLF_NAME', 'WP Login Firewall');

define('WPLF_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WPLF_PLUGIN_URL', plugin_dir_url(__FILE__));

// Carica la classe per la gestione licenze e aggiornamenti
require_once WPLF_PLUGIN_DIR . 'includes/class-roomzero-license.php';
require_once WPLF_PLUGIN_DIR . 'includes/class-roomzero-updater.php';

// Carica la classe database
require_once WPLF_PLUGIN_DIR . 'includes/class-wplf-database.php';

// Carica la classe debug
require_once WPLF_PLUGIN_DIR . 'includes/class-wplf-debug.php';

// Carica la classe principale
require_once WPLF_PLUGIN_DIR . 'includes/class-wplf-core.php';

// Carica admin solo nel backend
if (is_admin()) {
    require_once WPLF_PLUGIN_DIR . 'includes/class-wplf-admin.php';
}

// Inizializza il plugin
function wplf_init() {
    // Inizializza debug se abilitato
    if (get_option('wplf_debug_mode', 0)) {
        WPLF_Debug::get_instance();
    }
    
    new WPLF_Core();
    
    if (is_admin()) {
        new WPLF_Admin();
    }
}
add_action('plugins_loaded', 'wplf_init');

// Activation hook
register_activation_hook(__FILE__, 'wplf_activate');

function wplf_activate() {
    // Crea le tabelle nel database
    WPLF_Database::create_tables();
    
    // Verifica se ci sono dati da migrare da wp_options
    $old_logs = get_option('wplf_access_logs', array());
    $old_blocks = get_option('wplf_blocked_ips', array());
    
    if (!empty($old_logs) || !empty($old_blocks)) {
        // Migra i dati esistenti alle tabelle
        WPLF_Database::migrate_existing_data();
    }
    
    // Salva la versione del database
    update_option('wplf_db_version', WPLF_Database::DB_VERSION);
}

// Deactivation hook
register_deactivation_hook(__FILE__, function() {
    // Pulisci transient al disattivazione
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wplf_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_wplf_%'");
});

// Inizializza License Manager e Updater

class WP_Login_Firewall {
    private $license_manager;
    private $updater;
    
    public function __construct() {
        // Inizializza License Manager
        $this->license_manager = new RoomZero_License_Manager(
            wp-login-firewall,
            WP Login Firewall
        );
        
        // Aggiungi pagina licenza
        add_action('admin_menu', array($this->license_manager, 'add_license_page'));
        
        // Inizializza Updater
        add_action('admin_init', array($this, 'init_updater'));
        
        // Carica funzionalità solo se licenza valida
        add_action('init', array($this, 'init'));
    }
    
    public function init_updater() {
        $license_key = $this->license_manager->get_license_key();
        
        $this->updater = new RoomZero_Plugin_Updater(
            __FILE__,
            wp-login-firewall,
            WP Login Firewall,
            $license_key
        );
    }
    
    public function init() {
        if (!$this->license_manager->is_license_valid()) {
            return; // Licenza non valida - blocca funzionalità
        }
        
        // ✅ LICENZA VALIDA - Carica funzionalità
        $this->load_features();
    }
    
    private function load_features() {
        // Tutte le funzionalità del plugin
    }
}
// Avvia il plugin - metti questa riga ALLA FINE del file principale
new WP_Login_Firewall();
