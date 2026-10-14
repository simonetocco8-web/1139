# Verifiche fase 2 — 14 ottobre 2026

## Risultati effettivi
- SQLite: 95 test, 584 asserzioni, inclusi tutti i test organizzativi preesistenti.
- MySQL 8.0.46 InnoDB: 98 test, 603 asserzioni. Database temporaneo dedicato, schema sacrificabile. Due processi indipendenti tentano contemporaneamente lo stesso OTP: una sola sessione login, un consumo e un evento audit.
- Pint e Larastan livello 7 senza errori; Composer validate --strict, build Vite e cache config/route/view riusciti. Rollback delle sole due migrazioni identità e successiva riapplicazione verificati sul database MySQL temporaneo.
- Browser Chromium desktop 1440×1000 e mobile 320×740: login reale password + OTP ricevuto da SMTP locale di test, selezione loggia, accessi/ruoli, report accessi, assistenza, inviti piattaforma, sicurezza, logout e pagine recupero. Nessun errore JavaScript, richiesta esterna o overflow orizzontale rilevato. 18 schermate fittizie salvate nella galleria.

## Casi di sicurezza coperti
Password corretta produce solo pending; password errata/account ignoto hanno risposta uniforme. OTP digest, cifratura payload, nonce/sessione, scadenza esatta a 5 minuti, 5 tentativi, cooldown 60 secondi, reinvio invalidante, replay e consumo concorrente. Limiti account/IP, reset durante pending, revoca versionata, canale non verificato, fake vietato fuori testing, gateway SMS con conferma stretta e invio non configurato.
Inviti monouso/scaduti/revocati, issuer non più autorizzato, ruoli forgiati, primo amministratore senza auto-invito, account esistente senza cambio password. Reset neutro e senza autenticazione; contatti modificati solo dopo nuova prova, password e OTP recente; recupero offline monouso/rotazione senza login implicito. Password forti e input pre-hash non accettato come password già validata. Link tecnici indipendenti da Host non fidato e HTTP vietato in produzione.
Matrice permessi locale, più ruoli/logge, nessun fallback Super User, lettura solo propri record, export filtrato e protezione formula CSV, Livewire dopo switch tenant/revoca, ultimo amministratore protetto. Assistenza con consenso diverso richiedente, scope limitato, scadenza, revoca e audit. Revoca inviti/grant di altri tenant rifiutata prima di caricare contenuti riservati. Vecchi endpoint Fortify/TOTP/registrazione assenti, marker inviati dal browser ininfluenti, account disabilitati esclusi.

## Limiti della verifica
SMTP provato realmente contro server loopback temporaneo, non provider operativo. SMS provato tramite fake e mock HTTP, non spedizione reale. Nessuna credenziale SMTP/SMS di produzione configurata; nessun messaggio reale ad altri soggetti. Test non equivalgono a penetration test indipendente, audit WCAG completo o collaudo cPanel. Moduli iscritti/contabilità/report contabili non implementati e non dichiarati verificati. Documenti delle precedenti fasi descrivono i rispettivi risultati storici.

## Riproduzione
`composer test`; poi `php vendor/bin/phpunit -c phpunit.mysql.xml` con ambiente MySQL di test separato, nome database `_test`, credenziali effimere. `npm run build`. I test MySQL includono migrazioni, vincoli e processo worker CLI in tests/Support: quest'ultimo è confinato all'ambiente testing/database `_test`, non è una route applicativa né un bypass distribuito.
