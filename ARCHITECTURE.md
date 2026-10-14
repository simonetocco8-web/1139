# Architettura — fondamenta e fase organizzativa

## Ambiente verificato (14 ottobre 2026)
Repository /workspace/1139 vuoto, ramo work senza commit; nessun AGENTS.md applicativo rilevato. PHP CLI 8.4.26 con PDO MySQL, SQLite, mbstring, XML, curl, zip, sodium; Composer 2.8.12; Node 24.19.0 e npm 11.9.0 per build. Laravel 13 richiede PHP ^8.3. Starter ufficiale laravel/livewire-starter-kit: Laravel ^13.17, Livewire ^4.1, Fortify ^1.37.2. Compatibilità effettiva da confermare mediante Composer e test. MySQL 8 e SMTP non disponibili/configurati nella sessione; hosting cPanel ancora da verificare.

## Decisioni
Monolite modulare PHP/MySQL, dominio unico, Blade/Livewire/Tailwind, Vite solo in build. Sessioni, cache e coda su database; file privati su disco local. Nessuna dipendenza operativa da Redis, Docker, WebSocket o worker residenti. Cron esegue scheduler e queue:work --stop-when-empty con lock. Europe/Rome, italiano, EUR. Timestamp tecnici UTC, periodi contabili come DATE locale; formattazione italiana alla presentazione.

## Domini
app/Domains/{Organization,Identity,Membership,Capitations,Levies,Treasury,Notifications,Reports,Audit}. Service/Action gestiscono invarianti e transazioni, Policy autorizzano, componenti Livewire presentano e validano. Il dominio Organization è implementato nella fase organizzativa autorizzata il 14 ottobre 2026. I domini contabili e il login rimangono esclusi.

## Confine di sicurezza
Account globale distinto da persona/iscrizione. Il tenant è una loggia. Nella fase 2 TenantContext risolverà una loggia solo dalle associazioni autorizzate del soggetto, mai dal lodge_id del browser. Scope obbligatorio fail-closed anche in job, export e file; policy aggiuntive e vincoli compositi impediscono relazioni tra tenant. Nessuna policy before che conceda accesso generale al Super User. Gestione organizzativa globale separata dal contenuto locale. Accesso assistito con richiesta, consenso amministratore, motivazione, permessi, scadenza, revoca e audit; non impersonificazione indiscriminata.

## Autenticazione
Starter Fortify mantenuto come base, registrazione pubblica disabilitata. Prima di implementare inviti e OTP email/SMS, l'autenticazione applicativa è bloccata: login e endpoint Fortify di autenticazione/modifica credenziali non esposti; nessuna dashboard privata accessibile, neppure tramite Livewire. Si conserva solo una pagina pubblica informativa priva di dati personali. Il TOTP dello starter non sostituisce l'OTP richiesto.
Fase 2: credenziali producono sessione pending con solo identificatore challenge; OTP CSPRNG hashato/HMAC con segreto server, 5 minuti, un uso, massimo 5 tentativi, consumo atomico e throttling account/IP. Consegna via canale verificato. Sessione rigenerata e autenticata solo al consumo; revoca e recupero senza bypass. Interfaccia secondo fattore predisposta per TOTP/passkey. Nessun OTP nei log; SMS adapter non configurato finché manca provider.

## Privacy e operatività
File in storage/app/private e download mediato da controller/policy; mai symlink pubblico per giustificativi. Notifiche tecniche neutre. Export autorizzati e temporanei, audit senza valori sensibili, backup cifrati e ripristino verificato. Consenso assistenza, tempi di conservazione, anonimizzazione, basi giuridiche e responsabili sono decisioni da definire prima dei dati reali. Nessun analytics/font/CDN esterno obbligatorio. HTTPS, cookie secure in produzione, APP_DEBUG=false, document root public. Deploy senza Node: includere public/build; vendor installabile in build con --no-dev. Health restituisce esclusivamente stato di vita, senza versioni, tenant o credenziali.

## Aggiornamento: fase organizzativa autorizzata
Dettagli e assunzioni in ORGANIZATION.md. Organization contiene modelli, Action transazionali e Policy. Obbedienze, riti, compatibilità, strutture e logge sono globali per il Super User, senza accesso implicito ai tenant. Il selettore usa membership/ruoli e query autorizzate, rivalidati ad ogni richiesta; queste sole tabelle di accesso non costituiscono un modulo di gestione utenti.

Le schermate sono realizzate ma l'accesso pubblico resta chiuso. RequireVerifiedSession e VerifiedSession richiedono attestazioni sessione server-side `auth.second_factor_user_id` e `auth.second_factor_verified_at` legate all'account e non scadute. Nessuna route emette tali attestazioni in questa fase: solo il futuro flusso OTP potrà farlo dopo una verifica riuscita. Fortify::ignoreRoutes() resta attivo. Boot Livewire, middleware persistente, Policy e Action ripetono l'autorizzazione. Non abilitare componenti/route solo in base al ruolo senza secondo fattore.

Lo storico territoriale è una relazione versionata: non si sovrascrive una pivot corrente. Le vecchie assegnazioni sono chiuse una sola volta, immutabili nel modello e mai eliminate; FK RESTRICT su destinatari/logge. Il servizio serializza per obbedienza e rifiuta reparenting che renda incoerente una catena territoriale attualmente selezionata. Questo predispone la conservazione della futura contabilità, che non esiste ancora.
