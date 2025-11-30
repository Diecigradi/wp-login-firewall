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
     * Chiave option per IP bloccati
     */
    const BLOCKED_IPS_KEY = 'wplf_blocked_ips';
    
    /**
     * Durata blocco in secondi (24 ore)
     */
    const BLOCK_DURATION = 86400; // 24 * 60 * 60
    
    /**
     * Tentativi falliti prima del blocco
     */
    const FAILED_ATTEMPTS_THRESHOLD = 10;
    
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
        if ($ip === null) {
            $ip = $this->get_client_ip();
        }
        
        $blocked_ips = get_option(self::BLOCKED_IPS_KEY, array());
        
        if (!isset($blocked_ips[$ip])) {
            return false;
        }
        
        $block_data = $blocked_ips[$ip];
        
        // Verifica se il blocco è scaduto
        if (time() > $block_data['expires']) {
            $this->unblock_ip($ip);
            return false;
        }
        
        return true;
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
        if ($duration === null) {
            $duration = self::BLOCK_DURATION;
        }
        
        $blocked_ips = get_option(self::BLOCKED_IPS_KEY, array());
        
        $blocked_ips[$ip] = array(
            'blocked_at' => current_time('mysql'),
            'expires' => time() + $duration,
            'reason' => sanitize_text_field($reason),
            'attempts' => isset($blocked_ips[$ip]) ? $blocked_ips[$ip]['attempts'] + 1 : 1
        );
        
        return update_option(self::BLOCKED_IPS_KEY, $blocked_ips, false);
    }
    
    /**
     * Sblocca un IP
     * 
     * @param string $ip IP da sbloccare
     * @return bool True se sbloccato con successo
     */
    public function unblock_ip($ip) {
        $blocked_ips = get_option(self::BLOCKED_IPS_KEY, array());
        
        if (isset($blocked_ips[$ip])) {
            unset($blocked_ips[$ip]);
            return update_option(self::BLOCKED_IPS_KEY, $blocked_ips, false);
        }
        
        return false;
    }
    
    /**
     * Ottiene informazioni su un IP bloccato
     * 
     * @param string $ip IP da verificare
     * @return array|false Dati del blocco o false
     */
    public function get_block_info($ip = null) {
        if ($ip === null) {
            $ip = $this->get_client_ip();
        }
        
        $blocked_ips = get_option(self::BLOCKED_IPS_KEY, array());
        
        if (!isset($blocked_ips[$ip])) {
            return false;
        }
        
        return $blocked_ips[$ip];
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
        
        $remaining = $block_info['expires'] - time();
        
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
        $blocked_ips = get_option(self::BLOCKED_IPS_KEY, array());
        
        // Rimuovi blocchi scaduti
        $current_time = time();
        foreach ($blocked_ips as $ip => $data) {
            if ($current_time > $data['expires']) {
                unset($blocked_ips[$ip]);
            }
        }
        
        // Aggiorna se ci sono stati cambiamenti
        update_option(self::BLOCKED_IPS_KEY, $blocked_ips, false);
        
        return $blocked_ips;
    }
    
    /**
     * Verifica tentativi falliti e blocca se necessario
     * 
     * @param string $ip IP da verificare
     * @param WPLF_Logger $logger Istanza del logger
     * @return bool True se l'IP è stato bloccato
     */
    public function check_and_block($ip, $logger) {
        // Conta tentativi falliti recenti (ultima ora)
        $logs = $logger->get_logs_by_ip($ip, 0);
        
        $failed_count = 0;
        $one_hour_ago = strtotime('-1 hour');
        
        foreach ($logs as $log) {
            if (isset($log['status']) && in_array($log['status'], array('failed', 'rate_limited'))) {
                $timestamp = strtotime($log['timestamp']);
                if ($timestamp > $one_hour_ago) {
                    $failed_count++;
                }
            }
        }
        
        // Blocca se supera la soglia
        if ($failed_count >= self::FAILED_ATTEMPTS_THRESHOLD) {
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
        return delete_option(self::BLOCKED_IPS_KEY);
    }
    
    /**
     * Cancella blocchi scaduti
     * 
     * @return int Numero di blocchi rimossi
     */
    public function cleanup_expired_blocks() {
        $blocked_ips = get_option(self::BLOCKED_IPS_KEY, array());
        $initial_count = count($blocked_ips);
        
        $current_time = time();
        foreach ($blocked_ips as $ip => $data) {
            if ($current_time > $data['expires']) {
                unset($blocked_ips[$ip]);
            }
        }
        
        update_option(self::BLOCKED_IPS_KEY, $blocked_ips, false);
        
        return $initial_count - count($blocked_ips);
    }
}
