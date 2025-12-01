# WP Login Firewall

🛡️ Sistema di sicurezza avanzato per WordPress con verifica in due fasi e protezione enterprise-grade.

## Versione

**3.1.0** - Security Hardening Release

### 🔒 Security Updates (v3.1.0)

- ✅ **IP Spoofing Prevention**: Usa solo `REMOTE_ADDR` affidabile, header manipolabili rimossi
- ✅ **Token Security**: Cookie HttpOnly/Secure invece di URL, token crittograficamente sicuri con `random_bytes()`
- ✅ **Username Enumeration Protection**: Delay randomizzato e messaggi unificati
- ✅ **Race Condition Fix**: Incremento atomico rate limiter con SQL lock
- ✅ **Admin Whitelist**: Opzionale e configurabile
- ✅ **Proxy Support**: Validazione opzionale per Cloudflare/CDN con whitelist CIDR

## Caratteristiche

### Sicurezza
- ✅ Verifica username prima dell'accesso al form di login
- ✅ **Rate Limiting configurabile** (tentativi/minuti)
- ✅ **IP Blocking automatico** con soglia configurabile
- ✅ Sistema token sicuro con scadenza (5 minuti)
- ✅ **Cookie HttpOnly/Secure** per token (non visibili in URL)
- ✅ **Token crittograficamente sicuri** (random_bytes)
- ✅ Protezione contro IP spoofing
- ✅ Protezione contro username enumeration
- ✅ Protezione contro race condition
- ✅ **Whitelist admin opzionale**
- ✅ Logging SQL illimitato con retention configurabile

### Amministrazione
- ✅ Pannello completo con statistiche real-time
- ✅ **Impostazioni configurabili**: rate limit, IP block, retention
- ✅ Log dettagliati con IP, timestamp, user-agent, status
- ✅ **Blocco manuale IP** dalla pagina log
- ✅ Esportazione log in formato CSV
- ✅ Gestione IP bloccati con scadenza automatica

### UI/UX
- ✅ Pagina di verifica completamente personalizzata
- ✅ Design moderno e responsive
- ✅ Completamente personalizzabile tramite CSS variables
- ✅ Pagina "Come Funziona" con documentazione integrata

## Installazione

1. Carica la cartella del plugin in `/wp-content/plugins/`
2. Attiva il plugin dal menu "Plugin" di WordPress
3. Il sistema sarà immediatamente attivo

## Utilizzo

Quando un utente tenta di accedere a `/wp-login.php`, verrà mostrata una pagina di verifica dove dovrà inserire il proprio username o email. Solo dopo la verifica potrà accedere al form di login standard di WordPress.

## Personalizzazione

Modifica le CSS variables in `assets/css/style.css` per personalizzare colori, spaziature e tipografia:

```css
:root {
    --wplf-primary: #667eea;
    --wplf-secondary: #764ba2;
    /* ... altre variabili ... */
}
```

## Pannello Amministratore

Accedi a **Dashboard → Login Firewall** per:

### Dashboard
- Visualizzare statistiche real-time (totali, riusciti, falliti, rate limited, bloccati)
- Monitorare IP attualmente bloccati

### Log Accessi
- Consultare log dettagliati con filtri
- **Bloccare manualmente IP** con un click
- Esportare dati in CSV
- Cancellare log

### IP Bloccati
- Gestire lista IP bloccati
- Vedere scadenza blocco
- Sbloccare manualmente

### Impostazioni
- **Rate Limiting**: configurare tentativi e tempo (default: 5 tentativi / 15 minuti)
- **IP Blocking**: configurare soglia e durata (default: 10 tentativi / 24 ore)
- **Log Retention**: configurare giorni mantenimento log (default: 30 giorni)
- **Admin Whitelist**: abilitare/disabilitare bypass admin (default: abilitato)

### Come Funziona
- Documentazione integrata del flusso di sicurezza

## Requisiti

- WordPress 5.0+
- PHP 7.4+
- MySQL 5.6+ (InnoDB per atomic operations)

## Architettura Sicurezza

### Flusso Protezione Multi-Layer

1. **IP Check**: Verifica se IP è bloccato (MySQL)
2. **Username Verification**: Controlla esistenza utente (delay randomizzato)
3. **Admin Whitelist**: Bypass opzionale per amministratori
4. **Rate Limiting**: Max tentativi per IP in finestra temporale (transient atomico)
5. **IP Blocking**: Blocco automatico dopo soglia superata (MySQL persistent)
6. **Token Generation**: Cookie sicuro HttpOnly con random_bytes()
7. **One-Time Token**: Auto-eliminato dopo uso

### Database Schema

#### wp_wplf_logs
- Logging illimitato con 4 indici (timestamp, IP, username, status)
- Retention automatica configurabile

#### wp_wplf_blocked_ips
- IP blocking persistente con scadenza
- Cleanup automatico blocchi scaduti

### Security Features

- **IP Detection**: Solo `REMOTE_ADDR` (no spoofing), supporto proxy con CIDR whitelist
- **Token Storage**: Cookie HttpOnly/Secure/SameSite=Lax (no URL leakage)
- **Token Generation**: `random_bytes(32)` (256-bit entropy)
- **Rate Limiter**: SQL atomic increment (no race condition)
- **Username Enum Protection**: Messaggi unificati + delay 200-500ms
- **CSRF Protection**: WordPress nonce + SameSite cookie
- **SQL Injection**: Prepared statements ovunque
- **XSS Protection**: Sanitization + escaping output

## Licenza

GPL v2 or later

## Autore

DevRoom by RoomZero Creative Solutions  
GitHub: [Diecigradi/wp-login-firewall](https://github.com/Diecigradi/wp-login-firewall)

## Supporto

Per segnalazioni bug o richieste di funzionalità, apri una issue su GitHub.

## Changelog

### v3.1.0 (2025-12-01) - Security Hardening Release

**CRITICAL FIXES:**
- 🔒 Fix IP Spoofing: rimossi header manipolabili (HTTP_CLIENT_IP, HTTP_X_FORWARDED_FOR)
- 🔒 Token Security: migrati da URL a Cookie HttpOnly/Secure
- 🔒 Token Generation: `random_bytes()` invece di `wp_generate_password()`
- 🔒 Username Enumeration: messaggi unificati + delay randomizzato
- 🔒 Race Condition: incremento atomico rate limiter con SQL lock

**NEW FEATURES:**
- ⚙️ Impostazioni configurabili: rate limit, IP block, retention, admin whitelist
- 📊 Blocco manuale IP dalla pagina log
- 🌐 Supporto proxy/CDN affidabili con whitelist CIDR
- 📖 Pagina "Come Funziona" integrata
- 🗑️ File `uninstall.php` per pulizia completa database

**IMPROVEMENTS:**
- Database migration da wp_options a tabelle MySQL dedicate
- Logging illimitato con retention automatica
- Statistiche real-time dashboard
- Backward compatibility per token in URL (deprecato)

**COMMITS:**
- `13fd66b` Security Fix: Prevenzione IP Spoofing
- `4fdfc05` Security Fix: Token via Cookie HttpOnly + random_bytes()
- `5541663` Security Fix: Prevenzione Username Enumeration
- `edb66a3` Security Fix: Atomic Increment Rate Limiter
- `ca389df` Feature: Uninstall.php cleanup
- `8cdb89b` Feature: Admin whitelist opzionale
- Altri 10+ commit per settings page, manual IP block, bug fixes

### v3.0.0 (2025-11-29) - Ricostruzione Completa

- Architettura completamente riscritta
- UI moderna e responsive
- Sistema token sicuro
- Pannello admin con statistiche
