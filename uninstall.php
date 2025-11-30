<?php
/**
 * WP Login Firewall - Uninstall
 * 
 * Questo file viene eseguito quando il plugin viene disinstallato
 * Rimuove completamente tutte le tracce dal database
 */

// Se uninstall non è chiamato da WordPress, esci
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Carica la classe database per utilizzare i metodi di cleanup
require_once plugin_dir_path(__FILE__) . 'includes/class-wplf-database.php';

// 1. Elimina le tabelle personalizzate
WPLF_Database::drop_tables();

// 2. Elimina tutte le opzioni del plugin da wp_options
delete_option('wplf_rate_limit_attempts');
delete_option('wplf_rate_limit_minutes');
delete_option('wplf_ip_block_threshold');
delete_option('wplf_ip_block_duration');
delete_option('wplf_log_retention_days');
delete_option('wplf_admin_whitelist');
delete_option('wplf_db_version');

// 3. Elimina eventuali vecchie opzioni legacy (se esistono ancora)
delete_option('wplf_access_logs');
delete_option('wplf_blocked_ips');

// 4. Elimina tutti i transient del plugin
global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wplf_%'");
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_wplf_%'");

// 5. Per installazioni multisite, elimina anche dalle altre installazioni
if (is_multisite()) {
    $sites = get_sites(array('number' => 0));
    
    foreach ($sites as $site) {
        switch_to_blog($site->blog_id);
        
        // Elimina tabelle
        WPLF_Database::drop_tables();
        
        // Elimina opzioni
        delete_option('wplf_rate_limit_attempts');
        delete_option('wplf_rate_limit_minutes');
        delete_option('wplf_ip_block_threshold');
        delete_option('wplf_ip_block_duration');
        delete_option('wplf_log_retention_days');
        delete_option('wplf_admin_whitelist');
        delete_option('wplf_db_version');
        delete_option('wplf_access_logs');
        delete_option('wplf_blocked_ips');
        
        // Elimina transient
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wplf_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_wplf_%'");
        
        restore_current_blog();
    }
}

// Cleanup completato - Il database è ora completamente pulito
