<?php
/**
 * Plugin Name: WP Secure Login System
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
require_once plugin_dir_path(__FILE__) . 'includes/class-custom-login-security.php';

// Inizializza il plugin
new CustomLoginSecurity();

// Hook AJAX
add_action('wp_ajax_nopriv_cls_verify_user', array(new CustomLoginSecurity(), 'verify_user'));
?>