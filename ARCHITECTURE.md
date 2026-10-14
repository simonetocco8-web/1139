# Architettura — prima fase

## Ambiente verificato (14 ottobre 2026)
Repository /workspace/1139 vuoto, ramo work senza commit; nessun AGENTS.md applicativo rilevato. PHP CLI 8.4.26 con PDO MySQL, SQLite, mbstring, XML, curl, zip, sodium; Composer 2.8.12; Node 24.19.0 e npm 11.9.0 per build. Laravel 13 richiede PHP ^8.3. Starter ufficiale laravel/livewire-starter-kit: Laravel ^13.17, Livewire ^4.1, Fortify ^1.37.2. Compatibilità effettiva da confermare mediante Composer e test. MySQL 8 e SMTP non disponibili/configurati nella sessione; hosting cPanel ancora da verificare.

## Decisioni
Monolite modulare PHP/MySQL, dominio unico, Blade/Livewire/Tailwind, Vite solo in build. Sessioni, cache e coda su database; file privati su disco local. Nessuna dipendenza operativa da Redis, Docker, WebSocket o worker residenti. Cron esegue scheduler e queue:work --stop-when-empty con lock. Europe/Rome, italiano, EUR. Timestamp tecnici UTC, periodi contabili come DATE locale; formattazione italiana alla presentazione.

## Domini futuri
app/Domains/{Organization,Identity,Membership,Capitations,Levies,Treasury,Notifications,Reports,Audit}. Service/Action gestiscono invarianti e transazioni, Policy autorizzano, componenti Livewire presentano e validano. Nessun dominio contabile viene implementato in fase 1.

## Confine di sicurezza
Account globale distinto da persona/iscrizione. Il tenant è una loggia. Nella fase 2 TenantContext risolverà una loggia solo dalle associazioni autorizzate del soggetto, mai dal lodge_id del browser. Scope obbligatorio fail-closed anche in job, export e file; policy aggiuntive e vincoli compositi impediscono relazioni tra tenant. Nessuna policy before che conceda accesso generale al Super User. Gestione organizzativa globale separata dal contenuto locale. Accesso assistito con richiesta, consenso amministratore, motivazione, permessi, scadenza, revoca e audit; non impersonificazione indiscriminata.

## Autenticazione
Starter Fortify mantenuto come base, registrazione pubblica disabilitata. Prima di implementare inviti e OTP email/SMS, l'autenticazione applicativa è bloccata: login e endpoint Fortify di autenticazione/modifica credenziali non esposti; nessuna dashboard privata accessibile, neppure tramite Livewire. Si conserva solo una pagina pubblica informativa priva di dati personali. Il TOTP dello starter non sostituisce l'OTP richiesto.
Fase 2: credenziali producono sessione pending con solo identificatore challenge; OTP CSPRNG hashato/HMAC con segreto server, 5 minuti, un uso, massimo 5 tentativi, consumo atomico e throttling account/IP. Consegna via canale verificato. Sessione rigenerata e autenticata solo al consumo; revoca e recupero senza bypass. Interfaccia secondo fattore predisposta per TOTP/passkey. Nessun OTP nei log; SMS adapter non configurato finché manca provider.

## Privacy e operatività
File in storage/app/private e download mediato da controller/policy; mai symlink pubblico per giustificativi. Notifiche tecniche neutre. Export autorizzati e temporanei, audit senza valori sensibili, backup cifrati e ripristino verificato. Consenso assistenza, tempi di conservazione, anonimizzazione, basi giuridiche e responsabili sono decisioni da definire prima dei dati reali. Nessun analytics/font/CDN esterno obbligatorio. HTTPS, cookie secure in produzione, APP_DEBUG=false, document root public. Deploy senza Node: includere public/build; vendor installabile in build con --no-dev. Health restituisce esclusivamente stato di vita, senza versioni, tenant o credenziali.
