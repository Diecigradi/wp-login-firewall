<?php
/**
 * Plugin Name: WP Login Firewall
 * Description: Aggiunge un layer di sicurezza prima del login
 * Version: 1.0
 * Author: DevRoom by RoomZero Creative Solutions
 * License: GPL2
 * Text Domain: wp-secure-login
 * url: https://www.roomzero.it
 */

// Previene l'accesso diretto
defined('ABSPATH') or die('Accesso negato!');

// Include la classe principale del plugin
require_once plugin_dir_path(__FILE__) . 'includes/class-wp-login-firewall.php';

// Include la classe admin
require_once plugin_dir_path(__FILE__) . 'includes/class-wp-login-firewall-admin.php';

// Inizializza il plugin
new WPLoginFirewall();

// Inizializza il pannello admin
if (is_admin()) {
    new WPLoginFirewallAdmin();
}

// Hook AJAX
add_action('wp_ajax_nopriv_wplf_verify_user', array(new WPLoginFirewall(), 'verify_user'));
?>