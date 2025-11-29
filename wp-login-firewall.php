<?php
/**
 * Plugin Name: WP Login Firewall
 * Plugin URI: https://github.com/Diecigradi/wp-login-firewall
 * Description: Sistema di verifica a due step per il login di WordPress con protezione avanzata contro attacchi brute force
 * Version: 3.0.0
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
define('WPLF_VERSION', '3.0.0');
define('WPLF_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WPLF_PLUGIN_URL', plugin_dir_url(__FILE__));

// Carica la classe principale
require_once WPLF_PLUGIN_DIR . 'includes/class-wplf-core.php';

// Inizializza il plugin
function wplf_init() {
    new WPLF_Core();
}
add_action('plugins_loaded', 'wplf_init');

// Activation hook
register_activation_hook(__FILE__, function() {
    // Nulla da fare per ora
});

// Deactivation hook
register_deactivation_hook(__FILE__, function() {
    // Pulisci transient al disattivazione
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wplf_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_wplf_%'");
});
