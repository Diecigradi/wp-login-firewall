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
        
        $token = sanitize_text_field($_COOKIE['wplf_token']);
        
        // Recupera i dati del token dal transient
        $token_data = get_transient('wplf_token_' . $token);
        
        if (!$token_data || !isset($token_data['timestamp'])) {
            return;
        }
        
        // Calcola il tempo rimanente effettivo
        $token_lifetime = get_option('wplf_token_lifetime', 5) * MINUTE_IN_SECONDS;
        $elapsed = current_time('timestamp') - $token_data['timestamp'];
        $remaining_seconds = $token_lifetime - $elapsed;
        
        // Se è già scaduto, non mostrare nulla
        if ($remaining_seconds <= 0) {
            return;
        }
        
        $remaining_minutes = ceil($remaining_seconds / 60);
        
        // Leggi tentativi falliti e massimo consentito
        $failed_attempts = isset($token_data['failed_attempts']) ? intval($token_data['failed_attempts']) : 0;
        $max_attempts = isset($token_data['max_attempts']) ? intval($token_data['max_attempts']) : 5;
        $remaining_attempts = $max_attempts - $failed_attempts;
        
        // Verifica se c'è un errore di token bruciato
        $token_burned = isset($_GET['wplf_error']) && $_GET['wplf_error'] === 'token_burned';
        
        ?>
        <div class="wplf-token-notice">
            <p id="wplf-token-message" data-remaining="<?php echo esc_attr($remaining_seconds); ?>">
                Verifica completata. Hai <strong><?php echo esc_html($remaining_minutes); ?> minut<?php echo $remaining_minutes == 1 ? 'o' : 'i'; ?></strong> rimanent<?php echo $remaining_minutes == 1 ? 'e' : 'i'; ?>.
            </p>
        </div>
        
        <?php if ($token_burned): ?>
            <div class="wplf-attempts-notice wplf-attempts-error">
                <p>
                    <strong>Troppi tentativi errati.</strong><br>
                    <a href="<?php echo esc_url(wp_login_url()); ?>">Clicca qui per procedere con una nuova verifica</a>
                </p>
            </div>
        <?php elseif ($failed_attempts > 0): ?>
            <div class="wplf-attempts-notice <?php echo $remaining_attempts <= 2 ? 'wplf-attempts-critical' : 'wplf-attempts-warning'; ?>">
                <p>
                    ⚠️ <strong>Password errata.</strong> Tentativi rimanenti: <strong><?php echo esc_html($remaining_attempts); ?>/<?php echo esc_html($max_attempts); ?></strong>
                </p>
                <?php if ($remaining_attempts <= 2): ?>
                    <p class="wplf-attempts-hint">
                        Attenzione: se sbagli di nuovo dovrai procedere con una nuova verifica.<br>
                        <!-- <a href="<?php echo esc_url(wp_lostpassword_url()); ?>">Password dimenticata?</a> -->
                    </p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
        <script>
        (function() {
            var msg = document.getElementById('wplf-token-message');
            if (!msg) return;
            
            // Legge i secondi rimanenti dal data attribute
            var remaining = parseInt(msg.getAttribute('data-remaining'));
            
            if (isNaN(remaining) || remaining <= 0) {
                msg.innerHTML = 'Tempo scaduto. <a href="<?php echo esc_url(wp_login_url()); ?>">Ripeti verifica</a>';
                msg.parentElement.className = 'wplf-token-notice wplf-expired';
                return;
            }
            
            function updateCountdown() {
                if (remaining <= 0) {
                    msg.innerHTML = 'Tempo scaduto. <a href="<?php echo esc_url(wp_login_url()); ?>">Ripeti verifica</a>';
                    msg.parentElement.className = 'wplf-token-notice wplf-expired';
                    return;
                }
                
                var minutes = Math.floor(remaining / 60);
                var seconds = remaining % 60;
                
                // Formatta con zero padding
                var formattedTime = minutes + ':' + (seconds < 10 ? '0' : '') + seconds;
                
                msg.innerHTML = 'Verifica completata. Hai <strong>' + formattedTime + '</strong> rimanent' + (remaining == 1 ? 'e' : 'i') + '.';
                
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
            background: #f0f0f0ff;
            border: 1px solid #c8c8c8ff;
            border-radius: 3px;
            padding: 15px !important;
            margin-bottom: 20px !important;
            text-align: center;
            transition: all 0.3s ease;
        }
        
        .wplf-token-notice p {
            margin: 0;
            color: #525252ff;
            font-size: 14px;
            line-height: 1.5;
        }
        
        .wplf-token-notice strong {
            color: #525252ff;
            font-size: 16px;
            font-weight: 600;
            display: inline-block;
            min-width: 45px;
            text-align: center;
        }
        
        /* Stato warning (< 60 secondi) */
        .wplf-token-notice.wplf-warning {
            background: #fff3cd;
            border-color: #ff8707ff;
            animation: pulse-warning 1s ease-in-out infinite;
        }
        
        .wplf-token-notice.wplf-warning p {
            color: #525252ff;
        }
        
        .wplf-token-notice.wplf-warning strong {
            color: #525252ff;
        }
        
        /* Stato scaduto */
        .wplf-token-notice.wplf-expired {
            background: #ff0000ff;
            border-color: #ff0000ff;
        }
        
        .wplf-token-notice.wplf-expired p {
            color: #ffffffff;
        }
        
        .wplf-token-notice.wplf-expired a {
            color: #ffffffff;
            text-decoration: underline;
            font-weight: 600;
        }
        
        .wplf-token-notice.wplf-expired a:hover {
            color: #ffffffff;
        }
        
        /* Box tentativi password */
        .wplf-attempts-notice {
            background: #fff3cd;
            border: 2px solid #ffc107;
            border-radius: 3px;
            padding: 12px 15px !important;
            margin-bottom: 20px !important;
            text-align: left;
        }
        
        .wplf-attempts-notice p {
            margin: 0 0 8px 0;
            color: #856404;
            font-size: 14px;
            line-height: 1.5;
        }
        
        .wplf-attempts-notice p:last-child {
            margin-bottom: 0;
        }
        
        .wplf-attempts-notice strong {
            font-weight: 600;
        }
        
        .wplf-attempts-notice a {
            color: #856404;
            text-decoration: underline;
            font-weight: 600;
        }
        
        .wplf-attempts-notice a:hover {
            color: #533f03;
        }
        
        /* Stato critico (ultimi 2 tentativi) */
        .wplf-attempts-notice.wplf-attempts-critical {
            background: #f8d7da;
            border-color: #dc3545;
            animation: pulse-critical 1.5s ease-in-out infinite;
        }
        
        .wplf-attempts-notice.wplf-attempts-critical p {
            color: #721c24;
        }
        
        .wplf-attempts-notice.wplf-attempts-critical a {
            color: #721c24;
        }
        
        .wplf-attempts-notice.wplf-attempts-critical a:hover {
            color: #491217;
        }
        
        .wplf-attempts-hint {
            font-size: 13px;
            margin-top: 8px;
            padding-top: 8px;
            border-top: 1px solid rgba(0, 0, 0, 0.1);
        }
        
        /* Stato errore (token bruciato) */
        .wplf-attempts-notice.wplf-attempts-error {
            background: #f8d7da;
            border-color: #dc3545;
        }
        
        .wplf-attempts-notice.wplf-attempts-error p {
            color: #721c24;
            font-size: 15px;
        }
        
        .wplf-attempts-notice.wplf-attempts-error a {
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
        
        @keyframes pulse-critical {
            0%, 100% {
                transform: scale(1);
                box-shadow: 0 0 0 0 rgba(220, 53, 69, 0);
            }
            50% {
                transform: scale(1.01);
                box-shadow: 0 0 0 3px rgba(220, 53, 69, 0.3);
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
            
            .wplf-attempts-notice {
                padding: 10px 12px;
                font-size: 13px;
            }
        }
        </style>
        <?php
    }
}
