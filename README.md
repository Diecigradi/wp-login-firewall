# Custom Login Security

![Version](https://img.shields.io/badge/version-1.0-blue.svg)
![WordPress](https://img.shields.io/badge/wordpress-5.0%2B-blue.svg)
![PHP](https://img.shields.io/badge/php-7.4%2B-purple.svg)
![License](https://img.shields.io/badge/license-GPL--2.0-green.svg)

Un plugin WordPress che aggiunge un layer di sicurezza aggiuntivo al processo di login standard, richiedendo la verifica dell'username o email prima di accedere al form di login.

## 🔒 Caratteristiche

- **Verifica Pre-Login**: Gli utenti devono prima verificare il loro username/email
- **Protezione AJAX**: Tutte le richieste sono protette con nonce per sicurezza CSRF
- **Dual Input Support**: Accetta sia username che email
- **UI Responsive**: Design moderno e mobile-friendly
- **Zero Configurazione**: Funziona immediatamente dopo l'attivazione
- **Leggero e Performante**: Codice pulito e ottimizzato

## 📋 Requisiti

- WordPress 5.0 o superiore
- PHP 7.4 o superiore
- jQuery (incluso in WordPress)

## 🚀 Installazione

### Metodo 1: Upload Manuale

1. Scarica il plugin come file ZIP
2. Vai su `WordPress Admin > Plugin > Aggiungi Nuovo`
3. Clicca su "Carica Plugin"
4. Seleziona il file ZIP e clicca "Installa Ora"
5. Attiva il plugin

### Metodo 2: FTP

1. Scarica e decomprimi il plugin
2. Carica la cartella `wp-secure-login` nella directory `/wp-content/plugins/`
3. Attiva il plugin tramite il menu "Plugin" in WordPress

## 📖 Come Funziona

1. L'utente naviga verso `/wp-login.php`
2. Il plugin intercetta la richiesta e mostra un form di pre-verifica
3. L'utente inserisce username o email
4. Il sistema verifica l'esistenza dell'utente tramite AJAX
5. Se valido, l'utente viene reindirizzato al form di login standard di WordPress
6. L'utente completa il login con la password

## 🎨 Struttura File

```
wp-secure-login/
├── css/
│   └── pre-login-form.css       # Stili del form pre-login
├── js/
│   └── ajax-handler.js          # Gestione AJAX
├── includes/                     # Classi e funzioni PHP (futuro)
├── languages/                    # File di traduzione (futuro)
├── assets/                       # Immagini e risorse
├── custom-login-security.php    # File principale del plugin
├── README.md                     # Questo file
└── LICENSE                       # Licenza GPL-2.0
```

## 🛠️ Sviluppo Futuro

### Prossime Funzionalità Pianificate

- [ ] Pannello di amministrazione per configurazione
- [ ] Rate limiting per prevenire brute force
- [ ] Logging tentativi di accesso falliti
- [ ] IP whitelist/blacklist
- [ ] Integrazione CAPTCHA
- [ ] Two-Factor Authentication (2FA)
- [ ] Notifiche email per accessi sospetti
- [ ] Statistiche e report accessi
- [ ] Personalizzazione template e stili
- [ ] Supporto multilingua completo
- [ ] Export/Import configurazioni

## 🔧 Personalizzazione

### CSS Personalizzato

Puoi sovrascrivere gli stili modificando il file `css/pre-login-form.css` o aggiungendo CSS personalizzato nel tuo tema.

### Hook e Filtri

Il plugin supporterà hook e filtri per sviluppatori nelle prossime versioni:

```php
// Esempio futuro
add_filter('cls_pre_login_fields', 'custom_fields');
add_action('cls_after_verification', 'custom_logging');
```

## 🐛 Segnalazione Bug

Se trovi un bug o hai una richiesta di funzionalità, apri una [issue su GitHub](https://github.com/tuousername/wp-secure-login/issues).

## 🤝 Contribuire

I contributi sono benvenuti! Per favore:

1. Fai un Fork del progetto
2. Crea un branch per la tua feature (`git checkout -b feature/AmazingFeature`)
3. Committa le modifiche (`git commit -m 'Add some AmazingFeature'`)
4. Push al branch (`git push origin feature/AmazingFeature`)
5. Apri una Pull Request

## 📝 Changelog

### [1.0] - 2025-11-29

#### Aggiunto
- Verifica pre-login con username/email
- Sistema AJAX per validazione utente
- UI responsive e moderna
- Protezione nonce per sicurezza
- Supporto email e username

## 👨‍💻 Autore

**RoomZero Development**

## 📄 Licenza

Questo progetto è rilasciato sotto licenza GPL-2.0. Vedi il file [LICENSE](LICENSE) per maggiori dettagli.

## ⚠️ Disclaimer

Questo plugin è fornito "così com'è" senza garanzie di alcun tipo. Usa a tuo rischio e pericolo. Si consiglia sempre di testare in un ambiente di staging prima dell'uso in produzione.

## 💡 Supporto

Per supporto e domande:
- 📧 Email: support@tuodominio.com
- 🌐 Website: https://tuodominio.com
- 💬 GitHub Issues: https://github.com/tuousername/wp-secure-login/issues

---

**Fatto con ❤️ per la comunità WordPress**
