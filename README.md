# WP Login Firewall

🛡️ Sistema di sicurezza avanzato per WordPress con verifica in due fasi.

## Versione

**3.0.0** - Ricostruzione completa

## Caratteristiche

- ✅ Verifica username prima dell'accesso al form di login
- ✅ Pagina di verifica completamente personalizzata
- ✅ Sistema token sicuro con scadenza (5 minuti)
- ✅ Protezione contro tentativi di bypass
- ✅ Logging dettagliato dei tentativi di accesso non autorizzati
- ✅ Pannello amministratore con statistiche
- ✅ Esportazione log in formato CSV
- ✅ Design moderno e responsive
- ✅ Completamente personalizzabile tramite CSS variables

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
- Visualizzare statistiche dei tentativi di bypass
- Consultare log dettagliati
- Esportare dati in CSV
- Cancellare log

## Requisiti

- WordPress 5.0+
- PHP 7.4+

## Licenza

GPL v2 or later

## Supporto

Per segnalazioni bug o richieste di funzionalità, apri una issue su GitHub.
