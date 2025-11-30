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
     * Chiave option per i log
     */
    const LOG_OPTION_KEY = 'wplf_access_logs';
    
    /**
     * Max numero di log da mantenere
     */
    const MAX_LOGS = 1000;
    
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
        $logs = get_option(self::LOG_OPTION_KEY, array());
        
        $log_entry = array(
            'timestamp' => current_time('mysql'),
            'ip' => $this->get_client_ip(),
            'username' => sanitize_text_field($username),
            'status' => $status,
            'message' => sanitize_text_field($message),
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : 'Unknown'
        );
        
        // Aggiungi in cima
        array_unshift($logs, $log_entry);
        
        // Mantieni solo gli ultimi MAX_LOGS
        if (count($logs) > self::MAX_LOGS) {
            $logs = array_slice($logs, 0, self::MAX_LOGS);
        }
        
        update_option(self::LOG_OPTION_KEY, $logs, false);
        
        return $log_entry;
    }
    
    /**
     * Ottiene tutti i log
     * 
     * @param int $limit Numero massimo di log da recuperare
     * @return array Array di log
     */
    public function get_logs($limit = 100) {
        $logs = get_option(self::LOG_OPTION_KEY, array());
        
        if ($limit > 0 && count($logs) > $limit) {
            return array_slice($logs, 0, $limit);
        }
        
        return $logs;
    }
    
    /**
     * Ottiene log filtrati per IP
     * 
     * @param string $ip Indirizzo IP
     * @param int $limit Limite risultati
     * @return array Log filtrati
     */
    public function get_logs_by_ip($ip, $limit = 50) {
        $logs = $this->get_logs(0);
        
        $filtered = array_filter($logs, function($log) use ($ip) {
            return $log['ip'] === $ip;
        });
        
        if ($limit > 0 && count($filtered) > $limit) {
            return array_slice($filtered, 0, $limit);
        }
        
        return array_values($filtered);
    }
    
    /**
     * Ottiene log filtrati per username
     * 
     * @param string $username Username
     * @param int $limit Limite risultati
     * @return array Log filtrati
     */
    public function get_logs_by_username($username, $limit = 50) {
        $logs = $this->get_logs(0);
        
        $filtered = array_filter($logs, function($log) use ($username) {
            return $log['username'] === $username;
        });
        
        if ($limit > 0 && count($filtered) > $limit) {
            return array_slice($filtered, 0, $limit);
        }
        
        return array_values($filtered);
    }
    
    /**
     * Ottiene statistiche dai log
     * 
     * @return array Statistiche aggregate
     */
    public function get_statistics() {
        $logs = $this->get_logs(0);
        
        $stats = array(
            'total_attempts' => count($logs),
            'successful' => 0,
            'failed' => 0,
            'blocked' => 0,
            'rate_limited' => 0,
            'unique_ips' => array(),
            'unique_usernames' => array(),
            'last_24h' => 0,
            'last_7d' => 0
        );
        
        $yesterday = strtotime('-24 hours');
        $week_ago = strtotime('-7 days');
        
        foreach ($logs as $log) {
            // Conta per status
            if (isset($log['status'])) {
                $key = $log['status'];
                if (isset($stats[$key])) {
                    $stats[$key]++;
                }
            }
            
            // IP unici
            if (isset($log['ip'])) {
                $stats['unique_ips'][$log['ip']] = true;
            }
            
            // Username unici
            if (isset($log['username'])) {
                $stats['unique_usernames'][$log['username']] = true;
            }
            
            // Conta per periodo
            if (isset($log['timestamp'])) {
                $timestamp = strtotime($log['timestamp']);
                
                if ($timestamp > $yesterday) {
                    $stats['last_24h']++;
                }
                
                if ($timestamp > $week_ago) {
                    $stats['last_7d']++;
                }
            }
        }
        
        $stats['unique_ips'] = count($stats['unique_ips']);
        $stats['unique_usernames'] = count($stats['unique_usernames']);
        
        return $stats;
    }
    
    /**
     * Cancella tutti i log
     */
    public function clear_logs() {
        return delete_option(self::LOG_OPTION_KEY);
    }
    
    /**
     * Cancella log più vecchi di X giorni
     * 
     * @param int $days Numero di giorni
     */
    public function clear_old_logs($days = 30) {
        $logs = $this->get_logs(0);
        $cutoff = strtotime("-{$days} days");
        
        $filtered = array_filter($logs, function($log) use ($cutoff) {
            if (!isset($log['timestamp'])) {
                return true;
            }
            
            $timestamp = strtotime($log['timestamp']);
            return $timestamp > $cutoff;
        });
        
        update_option(self::LOG_OPTION_KEY, array_values($filtered), false);
        
        return count($logs) - count($filtered);
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
