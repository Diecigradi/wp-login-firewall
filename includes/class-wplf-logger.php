<?php
/**
 * WP Login Firewall - Logger
 * 
 * Gestisce il logging completo dei tentativi di accesso
 */

// Previene accesso diretto
if (!defined('ABSPATH')) {
    exit;
}

class WPLF_Logger {
    
    /**
     * Ottiene l'IP del client
     */
    private function get_client_ip() {
        $ip = '';
        
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }
    
    /**
     * Registra un tentativo di accesso
     * 
     * @param string $username Username tentato
     * @param string $status Stato: 'success', 'failed', 'blocked', 'rate_limited'
     * @param string $message Messaggio descrittivo
     */
    public function log_attempt($username, $status, $message = '') {
        global $wpdb;
        
        $table = WPLF_Database::get_logs_table();
        
        $wpdb->insert(
            $table,
            array(
                'timestamp' => current_time('mysql'),
                'ip' => $this->get_client_ip(),
                'username' => sanitize_text_field($username),
                'status' => $status,
                'message' => sanitize_text_field($message),
                'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : 'Unknown'
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s')
        );
        
        return $wpdb->insert_id;
    }
    
    /**
     * Ottiene tutti i log
     * 
     * @param int $limit Numero massimo di log da recuperare
     * @return array Array di log
     */
    public function get_logs($limit = 100) {
        global $wpdb;
        
        $table = WPLF_Database::get_logs_table();
        
        $sql = "SELECT * FROM {$table} ORDER BY timestamp DESC";
        
        if ($limit > 0) {
            $sql .= $wpdb->prepare(" LIMIT %d", $limit);
        }
        
        return $wpdb->get_results($sql, ARRAY_A);
    }
    
    /**
     * Ottiene log filtrati per IP
     * 
     * @param string $ip Indirizzo IP
     * @param int $limit Limite risultati
     * @return array Log filtrati
     */
    public function get_logs_by_ip($ip, $limit = 50) {
        global $wpdb;
        
        $table = WPLF_Database::get_logs_table();
        
        $sql = $wpdb->prepare(
            "SELECT * FROM {$table} WHERE ip = %s ORDER BY timestamp DESC",
            $ip
        );
        
        if ($limit > 0) {
            $sql .= $wpdb->prepare(" LIMIT %d", $limit);
        }
        
        return $wpdb->get_results($sql, ARRAY_A);
    }
    
    /**
     * Ottiene log filtrati per username
     * 
     * @param string $username Username
     * @param int $limit Limite risultati
     * @return array Log filtrati
     */
    public function get_logs_by_username($username, $limit = 50) {
        global $wpdb;
        
        $table = WPLF_Database::get_logs_table();
        
        $sql = $wpdb->prepare(
            "SELECT * FROM {$table} WHERE username = %s ORDER BY timestamp DESC",
            $username
        );
        
        if ($limit > 0) {
            $sql .= $wpdb->prepare(" LIMIT %d", $limit);
        }
        
        return $wpdb->get_results($sql, ARRAY_A);
    }
    
    /**
     * Ottiene statistiche dai log
     * 
     * @return array Statistiche aggregate
     */
    public function get_statistics() {
        global $wpdb;
        
        $table = WPLF_Database::get_logs_table();
        
        // Conteggi per status
        $status_counts = $wpdb->get_results(
            "SELECT status, COUNT(*) as count FROM {$table} GROUP BY status",
            ARRAY_A
        );
        
        $stats = array(
            'total_attempts' => 0,
            'successful' => 0,
            'failed' => 0,
            'blocked' => 0,
            'rate_limited' => 0,
            'unique_ips' => 0,
            'unique_usernames' => 0,
            'last_24h' => 0,
            'last_7d' => 0
        );
        
        // Popola conteggi status
        foreach ($status_counts as $row) {
            $status = $row['status'];
            $count = (int)$row['count'];
            $stats['total_attempts'] += $count;
            if (isset($stats[$status])) {
                $stats[$status] = $count;
            }
        }
        
        // IP unici
        $unique_ips = $wpdb->get_var("SELECT COUNT(DISTINCT ip) FROM {$table}");
        $stats['unique_ips'] = (int)$unique_ips;
        
        // Username unici
        $unique_usernames = $wpdb->get_var("SELECT COUNT(DISTINCT username) FROM {$table}");
        $stats['unique_usernames'] = (int)$unique_usernames;
        
        // Ultimi 24h
        $last_24h = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE timestamp > %s",
            date('Y-m-d H:i:s', strtotime('-24 hours'))
        ));
        $stats['last_24h'] = (int)$last_24h;
        
        // Ultimi 7 giorni
        $last_7d = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE timestamp > %s",
            date('Y-m-d H:i:s', strtotime('-7 days'))
        ));
        $stats['last_7d'] = (int)$last_7d;
        
        return $stats;
    }
    
    /**
     * Cancella tutti i log
     */
    public function clear_logs() {
        global $wpdb;
        
        $table = WPLF_Database::get_logs_table();
        
        return $wpdb->query("TRUNCATE TABLE {$table}");
    }
    
    /**
     * Cancella log più vecchi di X giorni
     * 
     * @param int $days Numero di giorni
     */
    public function clear_old_logs($days = 30) {
        global $wpdb;
        
        $table = WPLF_Database::get_logs_table();
        
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        return $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE timestamp < %s",
            $cutoff
        ));
    }
    
    /**
     * Esporta log in formato CSV
     * 
     * @return string Contenuto CSV
     */
    public function export_to_csv() {
        $logs = $this->get_logs(0);
        
        $csv = "Timestamp,IP,Username,Status,Message,User Agent\n";
        
        foreach ($logs as $log) {
            $csv .= sprintf(
                '"%s","%s","%s","%s","%s","%s"' . "\n",
                $log['timestamp'] ?? '',
                $log['ip'] ?? '',
                $log['username'] ?? '',
                $log['status'] ?? '',
                $log['message'] ?? '',
                $log['user_agent'] ?? ''
            );
        }
        
        return $csv;
    }
}
