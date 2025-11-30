<?php
/**
 * WP Login Firewall - Database Manager
 * 
 * Gestisce le tabelle personalizzate del plugin
 */

// Previene accesso diretto
if (!defined('ABSPATH')) {
    exit;
}

class WPLF_Database {
    
    /**
     * Versione dello schema database
     */
    const DB_VERSION = '1.0';
    
    /**
     * Nome tabella log
     */
    public static function get_logs_table() {
        global $wpdb;
        return $wpdb->prefix . 'wplf_logs';
    }
    
    /**
     * Nome tabella IP bloccati
     */
    public static function get_blocked_ips_table() {
        global $wpdb;
        return $wpdb->prefix . 'wplf_blocked_ips';
    }
    
    /**
     * Crea le tabelle del plugin
     */
    public static function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $logs_table = self::get_logs_table();
        $blocked_table = self::get_blocked_ips_table();
        
        $sql = array();
        
        // Tabella log
        $sql[] = "CREATE TABLE IF NOT EXISTS {$logs_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            timestamp DATETIME NOT NULL,
            ip VARCHAR(45) NOT NULL,
            username VARCHAR(255) NOT NULL,
            status VARCHAR(20) NOT NULL,
            message TEXT,
            user_agent TEXT,
            PRIMARY KEY (id),
            KEY idx_timestamp (timestamp),
            KEY idx_ip (ip),
            KEY idx_username (username),
            KEY idx_status (status)
        ) {$charset_collate};";
        
        // Tabella IP bloccati
        $sql[] = "CREATE TABLE IF NOT EXISTS {$blocked_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            ip VARCHAR(45) NOT NULL,
            blocked_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            reason VARCHAR(255),
            attempts INT(11) DEFAULT 1,
            PRIMARY KEY (id),
            UNIQUE KEY idx_ip (ip),
            KEY idx_expires (expires_at)
        ) {$charset_collate};";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        foreach ($sql as $query) {
            dbDelta($query);
        }
        
        // Salva versione database
        update_option('wplf_db_version', self::DB_VERSION);
    }
    
    /**
     * Migra i dati esistenti da wp_options alle tabelle
     */
    public static function migrate_existing_data() {
        global $wpdb;
        
        // Migra log
        $old_logs = get_option('wplf_access_logs', array());
        if (!empty($old_logs)) {
            $logs_table = self::get_logs_table();
            
            foreach ($old_logs as $log) {
                $wpdb->insert(
                    $logs_table,
                    array(
                        'timestamp' => $log['timestamp'],
                        'ip' => $log['ip'],
                        'username' => $log['username'],
                        'status' => $log['status'],
                        'message' => isset($log['message']) ? $log['message'] : '',
                        'user_agent' => isset($log['user_agent']) ? $log['user_agent'] : ''
                    ),
                    array('%s', '%s', '%s', '%s', '%s', '%s')
                );
            }
            
            // Rimuovi vecchi dati
            delete_option('wplf_access_logs');
        }
        
        // Migra IP bloccati
        $old_blocked = get_option('wplf_blocked_ips', array());
        if (!empty($old_blocked)) {
            $blocked_table = self::get_blocked_ips_table();
            
            foreach ($old_blocked as $ip => $data) {
                $wpdb->insert(
                    $blocked_table,
                    array(
                        'ip' => $ip,
                        'blocked_at' => $data['blocked_at'],
                        'expires_at' => date('Y-m-d H:i:s', $data['expires']),
                        'reason' => isset($data['reason']) ? $data['reason'] : '',
                        'attempts' => isset($data['attempts']) ? $data['attempts'] : 1
                    ),
                    array('%s', '%s', '%s', '%s', '%d')
                );
            }
            
            // Rimuovi vecchi dati
            delete_option('wplf_blocked_ips');
        }
    }
    
    /**
     * Elimina le tabelle del plugin
     */
    public static function drop_tables() {
        global $wpdb;
        
        $logs_table = self::get_logs_table();
        $blocked_table = self::get_blocked_ips_table();
        
        $wpdb->query("DROP TABLE IF EXISTS {$logs_table}");
        $wpdb->query("DROP TABLE IF EXISTS {$blocked_table}");
        
        delete_option('wplf_db_version');
    }
    
    /**
     * Verifica se le tabelle esistono
     */
    public static function tables_exist() {
        global $wpdb;
        
        $logs_table = self::get_logs_table();
        $blocked_table = self::get_blocked_ips_table();
        
        $logs_exists = $wpdb->get_var("SHOW TABLES LIKE '{$logs_table}'") === $logs_table;
        $blocked_exists = $wpdb->get_var("SHOW TABLES LIKE '{$blocked_table}'") === $blocked_table;
        
        return $logs_exists && $blocked_exists;
    }
    
    /**
     * Pulisce i log più vecchi di X giorni
     * 
     * @param int $days Numero di giorni
     * @return int Numero di record eliminati
     */
    public static function cleanup_old_logs($days = 30) {
        global $wpdb;
        
        $logs_table = self::get_logs_table();
        $date = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        return $wpdb->query(
            $wpdb->prepare("DELETE FROM {$logs_table} WHERE timestamp < %s", $date)
        );
    }
    
    /**
     * Pulisce gli IP bloccati scaduti
     * 
     * @return int Numero di IP rimossi
     */
    public static function cleanup_expired_blocks() {
        global $wpdb;
        
        $blocked_table = self::get_blocked_ips_table();
        $now = current_time('mysql');
        
        return $wpdb->query(
            $wpdb->prepare("DELETE FROM {$blocked_table} WHERE expires_at < %s", $now)
        );
    }
}
