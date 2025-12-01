<?php
/**
 * WP Login Firewall - Login Form Enhancer
 * 
 * Migliora il form di login standard WordPress con countdown token
 */

// Previene accesso diretto
if (!defined('ABSPATH')) {
    exit;
}

class WPLF_Login_Enhancer {
    
    /**
     * Costruttore
     */
    public function __construct() {
        // Hook per aggiungere contenuto al form di login
        add_action('login_form', array($this, 'show_token_countdown'));
        
        // Hook per aggiungere stili al form di login
        add_action('login_enqueue_scripts', array($this, 'enqueue_login_styles'));
    }
    
    /**
     * Mostra il countdown del token nel form di login
     */
    public function show_token_countdown() {
        // Verifica se c'è un token valido nel cookie
        if (!isset($_COOKIE['wplf_token']) || empty($_COOKIE['wplf_token'])) {
            return;
        }
        
        // Ottiene il lifetime configurato
        $token_lifetime = get_option('wplf_token_lifetime', 5);
        
        ?>
        <div class="wplf-token-notice">
            <p id="wplf-token-message">
                🔐 Verifica completata. Token valido per <strong><?php echo esc_html($token_lifetime); ?> minuti</strong>
            </p>
        </div>
        
        <script>
        (function() {
            var msg = document.getElementById('wplf-token-message');
            if (!msg) return;
            
            // Estrae i minuti dal messaggio
            var match = msg.textContent.match(/(\d+) minut/);
            if (!match) return;
            
            var totalMinutes = parseInt(match[1]);
            var remaining = totalMinutes * 60; // Converti in secondi
            
            function updateCountdown() {
                if (remaining <= 0) {
                    msg.innerHTML = '⚠️ Token scaduto. <a href="<?php echo esc_url(wp_login_url()); ?>">Ripeti verifica</a>';
                    msg.parentElement.className = 'wplf-token-notice wplf-expired';
                    return;
                }
                
                var minutes = Math.floor(remaining / 60);
                var seconds = remaining % 60;
                
                // Formatta con zero padding
                var formattedTime = minutes + ':' + (seconds < 10 ? '0' : '') + seconds;
                
                msg.innerHTML = '🔐 Verifica completata. Token valido per: <strong>' + formattedTime + '</strong>';
                
                // Cambia colore quando mancano meno di 60 secondi
                if (remaining <= 60) {
                    msg.parentElement.className = 'wplf-token-notice wplf-warning';
                }
                
                remaining--;
                setTimeout(updateCountdown, 1000);
            }
            
            // Avvia il countdown
            updateCountdown();
        })();
        </script>
        <?php
    }
    
    /**
     * Carica gli stili per il countdown nel login
     */
    public function enqueue_login_styles() {
        // Verifica se c'è un token valido
        if (!isset($_COOKIE['wplf_token']) || empty($_COOKIE['wplf_token'])) {
            return;
        }
        
        ?>
        <style>
        .wplf-token-notice {
            background: #d4edda;
            border: 2px solid #c3e6cb;
            border-radius: 6px;
            padding: 14px 16px;
            margin-bottom: 20px;
            text-align: center;
            transition: all 0.3s ease;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .wplf-token-notice p {
            margin: 0;
            color: #155724;
            font-size: 14px;
            line-height: 1.5;
        }
        
        .wplf-token-notice strong {
            color: #0a3622;
            font-size: 16px;
            font-family: 'Courier New', Courier, monospace;
            font-weight: 600;
            display: inline-block;
            min-width: 45px;
            text-align: center;
        }
        
        /* Stato warning (< 60 secondi) */
        .wplf-token-notice.wplf-warning {
            background: #fff3cd;
            border-color: #ffc107;
            animation: pulse-warning 1s ease-in-out infinite;
        }
        
        .wplf-token-notice.wplf-warning p {
            color: #856404;
        }
        
        .wplf-token-notice.wplf-warning strong {
            color: #664d03;
        }
        
        /* Stato scaduto */
        .wplf-token-notice.wplf-expired {
            background: #f8d7da;
            border-color: #f5c6cb;
        }
        
        .wplf-token-notice.wplf-expired p {
            color: #721c24;
        }
        
        .wplf-token-notice.wplf-expired a {
            color: #491217;
            text-decoration: underline;
            font-weight: 600;
        }
        
        .wplf-token-notice.wplf-expired a:hover {
            color: #721c24;
        }
        
        @keyframes pulse-warning {
            0%, 100% {
                transform: scale(1);
            }
            50% {
                transform: scale(1.02);
            }
        }
        
        /* Responsive */
        @media screen and (max-width: 480px) {
            .wplf-token-notice {
                padding: 12px;
                font-size: 13px;
            }
            
            .wplf-token-notice strong {
                font-size: 15px;
            }
        }
        </style>
        <?php
    }
}
