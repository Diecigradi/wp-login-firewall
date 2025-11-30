<?php
/**
 * WP Login Firewall - Rate Limiter
 * 
 * Gestisce il rate limiting per IP
 */

// Previene accesso diretto
if (!defined('ABSPATH')) {
    exit;
}

class WPLF_Rate_Limiter {
    
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
     * Verifica se l'IP ha superato il rate limit
     * 
     * @return bool True se ha superato il limite
     */
    public function is_rate_limited() {
        $ip = $this->get_client_ip();
        $transient_key = 'wplf_rate_' . md5($ip);
        
        $attempts = get_transient($transient_key);
        
        if ($attempts === false) {
            return false;
        }
        
        $max_attempts = get_option('wplf_rate_limit_attempts', 5);
        
        return $attempts >= $max_attempts;
    }
    
    /**
     * Incrementa il contatore di tentativi per l'IP
     */
    public function increment_attempts() {
        $ip = $this->get_client_ip();
        $transient_key = 'wplf_rate_' . md5($ip);
        
        $attempts = get_transient($transient_key);
        $time_window = get_option('wplf_rate_limit_minutes', 15) * 60;
        
        if ($attempts === false) {
            set_transient($transient_key, 1, $time_window);
        } else {
            set_transient($transient_key, $attempts + 1, $time_window);
        }
    }
    
    /**
     * Ottiene il numero di tentativi rimanenti
     * 
     * @return int Tentativi rimanenti
     */
    public function get_remaining_attempts() {
        $ip = $this->get_client_ip();
        $transient_key = 'wplf_rate_' . md5($ip);
        
        $attempts = get_transient($transient_key);
        $max_attempts = get_option('wplf_rate_limit_attempts', 5);
        
        if ($attempts === false) {
            return $max_attempts;
        }
        
        return max(0, $max_attempts - $attempts);
    }
    
    /**
     * Ottiene il tempo rimanente del blocco in secondi
     * 
     * @return int Secondi rimanenti (0 se non bloccato)
     */
    public function get_time_remaining() {
        $ip = $this->get_client_ip();
        $transient_key = 'wplf_rate_' . md5($ip);
        
        $timeout = get_option('_transient_timeout_' . $transient_key);
        
        if ($timeout === false) {
            return 0;
        }
        
        $remaining = $timeout - time();
        
        return max(0, $remaining);
    }
    
    /**
     * Resetta il rate limit per un IP
     * 
     * @param string $ip IP da resettare (opzionale, default: IP corrente)
     */
    public function reset_rate_limit($ip = null) {
        if ($ip === null) {
            $ip = $this->get_client_ip();
        }
        
        $transient_key = 'wplf_rate_' . md5($ip);
        delete_transient($transient_key);
    }
    
    /**
     * Formatta il tempo rimanente in formato leggibile
     * 
     * @return string Tempo formattato (es: "5 minuti")
     */
    public function get_formatted_time_remaining() {
        $seconds = $this->get_time_remaining();
        
        if ($seconds < 60) {
            return $seconds . ' secondi';
        }
        
        $minutes = ceil($seconds / 60);
        return $minutes . ' minuti';
    }
}
