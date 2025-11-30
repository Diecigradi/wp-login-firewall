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
     * Ottiene l'IP del client in modo sicuro
     * 
     * Usa solo REMOTE_ADDR per prevenire IP spoofing via header HTTP manipolabili.
     * HTTP_CLIENT_IP e HTTP_X_FORWARDED_FOR possono essere falsificati dall'attaccante.
     */
    private function get_client_ip() {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
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
     * Incrementa il contatore di tentativi per l'IP in modo atomico
     * 
     * Usa wp_cache_add() per prevenire race condition.
     * Se fallisce (chiave esiste), fa increment atomico del valore esistente.
     */
    public function increment_attempts() {
        $ip = $this->get_client_ip();
        $transient_key = 'wplf_rate_' . md5($ip);
        $time_window = get_option('wplf_rate_limit_minutes', 15) * 60;
        
        // Tentativo atomico: crea se non esiste
        if (wp_cache_add($transient_key, 1, 'transient', $time_window)) {
            // Successo: transient creato con valore 1
            set_transient($transient_key, 1, $time_window);
            return;
        }
        
        // Transient esiste già: incrementa in modo race-safe
        // Usa database diretto per lock atomico
        global $wpdb;
        
        $option_name = '_transient_' . $transient_key;
        $timeout_name = '_transient_timeout_' . $transient_key;
        
        // Lock row e incrementa atomicamente
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} 
            SET option_value = CAST(option_value AS UNSIGNED) + 1 
            WHERE option_name = %s 
            LIMIT 1",
            $option_name
        ));
        
        // Se update fallisce, il transient è scaduto - ricrea
        if ($result === 0) {
            set_transient($transient_key, 1, $time_window);
            
            // Imposta timeout se non esiste
            if (!get_option($timeout_name)) {
                update_option($timeout_name, time() + $time_window, false);
            }
        }
        
        // Aggiorna cache object
        wp_cache_delete($transient_key, 'transient');
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
