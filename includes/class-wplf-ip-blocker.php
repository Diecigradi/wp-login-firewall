<?php
/**
 * WP Login Firewall - IP Blocker
 * 
 * Gestisce il blocco temporaneo degli IP
 */

// Previene accesso diretto
if (!defined('ABSPATH')) {
    exit;
}

class WPLF_IP_Blocker {
    
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
     * Verifica se un IP è bloccato
     * 
     * @param string $ip IP da verificare (opzionale, default: IP corrente)
     * @return bool True se bloccato
     */
    public function is_blocked($ip = null) {
        global $wpdb;
        
        if ($ip === null) {
            $ip = $this->get_client_ip();
        }
        
        $table = WPLF_Database::get_blocked_ips_table();
        
        $block = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE ip = %s AND expires_at > NOW()",
            $ip
        ), ARRAY_A);
        
        return !empty($block);
    }
    
    /**
     * Blocca un IP
     * 
     * @param string $ip IP da bloccare
     * @param string $reason Motivo del blocco
     * @param int $duration Durata in secondi (opzionale)
     * @return bool True se bloccato con successo
     */
    public function block_ip($ip, $reason = 'Troppi tentativi falliti', $duration = null) {
        global $wpdb;
        
        if ($duration === null) {
            $duration = get_option('wplf_ip_block_duration', 24) * 3600; // ore -> secondi
        }
        
        $table = WPLF_Database::get_blocked_ips_table();
        
        // Verifica se già bloccato
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE ip = %s",
            $ip
        ), ARRAY_A);
        
        $blocked_at = current_time('mysql');
        $expires_at = date('Y-m-d H:i:s', time() + $duration);
        $attempts = $existing ? (int)$existing['attempts'] + 1 : 1;
        
        if ($existing) {
            // Aggiorna blocco esistente
            return $wpdb->update(
                $table,
                array(
                    'blocked_at' => $blocked_at,
                    'expires_at' => $expires_at,
                    'reason' => sanitize_text_field($reason),
                    'attempts' => $attempts
                ),
                array('ip' => $ip),
                array('%s', '%s', '%s', '%d'),
                array('%s')
            );
        } else {
            // Nuovo blocco
            return $wpdb->insert(
                $table,
                array(
                    'ip' => $ip,
                    'blocked_at' => $blocked_at,
                    'expires_at' => $expires_at,
                    'reason' => sanitize_text_field($reason),
                    'attempts' => $attempts
                ),
                array('%s', '%s', '%s', '%s', '%d')
            );
        }
    }
    
    /**
     * Sblocca un IP
     * 
     * @param string $ip IP da sbloccare
     * @return bool True se sbloccato con successo
     */
    public function unblock_ip($ip) {
        global $wpdb;
        
        $table = WPLF_Database::get_blocked_ips_table();
        
        return $wpdb->delete(
            $table,
            array('ip' => $ip),
            array('%s')
        );
    }
    
    /**
     * Ottiene informazioni su un IP bloccato
     * 
     * @param string $ip IP da verificare
     * @return array|false Dati del blocco o false
     */
    public function get_block_info($ip = null) {
        global $wpdb;
        
        if ($ip === null) {
            $ip = $this->get_client_ip();
        }
        
        $table = WPLF_Database::get_blocked_ips_table();
        
        $block = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE ip = %s",
            $ip
        ), ARRAY_A);
        
        return $block ? $block : false;
    }
    
    /**
     * Ottiene il tempo rimanente del blocco in secondi
     * 
     * @param string $ip IP da verificare
     * @return int Secondi rimanenti (0 se non bloccato)
     */
    public function get_time_remaining($ip = null) {
        $block_info = $this->get_block_info($ip);
        
        if (!$block_info) {
            return 0;
        }
        
        $expires = strtotime($block_info['expires_at']);
        $remaining = $expires - time();
        
        return max(0, $remaining);
    }
    
    /**
     * Formatta il tempo rimanente
     * 
     * @param string $ip IP da verificare
     * @return string Tempo formattato
     */
    public function get_formatted_time_remaining($ip = null) {
        $seconds = $this->get_time_remaining($ip);
        
        if ($seconds < 60) {
            return $seconds . ' secondi';
        }
        
        if ($seconds < 3600) {
            $minutes = ceil($seconds / 60);
            return $minutes . ' minuti';
        }
        
        $hours = ceil($seconds / 3600);
        return $hours . ' ore';
    }
    
    /**
     * Ottiene tutti gli IP bloccati
     * 
     * @return array Array di IP bloccati
     */
    public function get_all_blocked_ips() {
        global $wpdb;
        
        $table = WPLF_Database::get_blocked_ips_table();
        
        // Ottieni solo blocchi attivi
        $blocks = $wpdb->get_results(
            "SELECT * FROM {$table} WHERE expires_at > NOW() ORDER BY blocked_at DESC",
            ARRAY_A
        );
        
        return $blocks;
    }
    
    /**
     * Verifica tentativi falliti e blocca se necessario
     * 
     * @param string $ip IP da verificare
     * @param WPLF_Logger $logger Istanza del logger
     * @return bool True se l'IP è stato bloccato
     */
    public function check_and_block($ip, $logger) {
        global $wpdb;
        
        // Conta tentativi falliti recenti (ultima ora)
        $table = WPLF_Database::get_logs_table();
        
        $one_hour_ago = date('Y-m-d H:i:s', strtotime('-1 hour'));
        
        $failed_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} 
            WHERE ip = %s 
            AND status = 'failed' 
            AND timestamp > %s",
            $ip,
            $one_hour_ago
        ));
        
        $threshold = get_option('wplf_ip_block_threshold', 10);
        
        // Blocca se supera la soglia
        if ($failed_count >= $threshold) {
            $this->block_ip($ip, "Superati {$failed_count} tentativi falliti");
            return true;
        }
        
        return false;
    }
    
    /**
     * Cancella tutti i blocchi
     * 
     * @return bool True se cancellati con successo
     */
    public function clear_all_blocks() {
        global $wpdb;
        
        $table = WPLF_Database::get_blocked_ips_table();
        
        return $wpdb->query("TRUNCATE TABLE {$table}");
    }
    
    /**
     * Cancella blocchi scaduti
     * 
     * @return int Numero di blocchi rimossi
     */
    public function cleanup_expired_blocks() {
        global $wpdb;
        
        $table = WPLF_Database::get_blocked_ips_table();
        
        return $wpdb->query(
            "DELETE FROM {$table} WHERE expires_at < NOW()"
        );
    }
}
