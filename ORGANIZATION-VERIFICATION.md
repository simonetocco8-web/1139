# Verifica — modello dati e struttura organizzativa

Data: 14 ottobre 2026. PHP 8.4.26, Laravel 13.36.0, Livewire 4.4.7; lock compatibile PHP 8.3.0. Riferimento progettuale: ORGANIZATION.md. Il precedente VERIFICATION.md conserva gli esiti delle sole fondamenta.

## Controlli completati
- `composer test`: Pint passa, Larastan livello 7 senza errori; **49 test, 153 asserzioni**, tutti superati su SQLite.
- `php vendor/bin/phpunit -c phpunit.mysql.xml`: **51 test, 162 asserzioni**, tutti superati su **MySQL 8.0.46 InnoDB**. Include la suite funzionale completa e controlli del motore, foreign_key_checks e colonna STORED GENERATED.
- Migrazioni realmente eseguite su entrambi i motori; rollback delle sole due nuove migrazioni e successiva reinstallazione provati su MySQL nel database temporaneo dedicato; foreign key composite, numeri loggia per obbedienza, compatibilità riti, una sola assegnazione aperta per livello, RESTRICT sulle strutture storiche verificati anche senza Action.
- Seeder demo eseguito anche nel database SQLite locale di sviluppo; nessun account creato.
- Test di auto-parent/cicli/livelli invertiti, salti di livello e radici autonome; rifiuto di catene territoriali incoerenti e reparenting che rompa collegamenti attuali.
- Cambi territoriali idempotenti, chiusura delle righe precedenti, snapshot stabili dopo rinomina/disattivazione; impossibile riaprire una assegnazione chiusa nel modello.
- Accesso negato a guest, utenti senza secondo fattore o con marker di altro soggetto, utenti ordinari e Super User senza membership locale; revoca del privilegio dopo rendering Livewire, IDOR nel selettore, revoca membership e disattivazione obbedienza.
- CRUD UI, ricerca e filtri/stati, seed demo idempotente senza account e blocco del seeder in produzione.
- `npm run build`: asset Vite compilati e presenti in public/build.
- `artisan config:cache`, `route:cache`, `view:cache` eseguiti con successo, cache locali successivamente eliminate.
- Chromium/Playwright: le cinque schermate a 1440x1000 e 320x740; form strutture/logge aperti con veri aggiornamenti Livewire, selezione loggia effettuata via browser. HTTP 200, nessun errore JavaScript/server o richiesta esterna, nessun overflow orizzontale. Dieci screenshot in docs/screenshots.

## Correzioni emerse dal collaudo
MySQL ha rilevato un identificatore indice oltre il limite 64 caratteri; assegnato nome esplicito breve e ripetuta l'intera suite con successo. L'analisi statica ha portato a query firstOrFail dopo whereKey (evitano ritorni ambigui collection/model) e normalizzazione dei campi nullable. Rifiutato reparenting che invalidi una catena loggia esistente; chiusura delle assegnazioni ammessa una sola volta.

## Limiti e confini
- Nessun flusso di login/invito/OTP o gestione utenti realizzato: accesso pubblico ancora chiuso. Le schermate funzionano con sessioni già autenticate e verificate; le prove browser usano una fixture esterna, temporanea e non distribuita.
- Membership/ruoli presenti esclusivamente per predisporre il selettore; nessun modulo fratelli o dati contabili. L'isolamento provato riguarda queste superfici, non moduli futuri ancora inesistenti.
- Il cambiamento territoriale preserva assegnazioni/destinatari/snapshot; la contabilità futura dovrà referenziarli esplicitamente. Non si dichiara testato uno storico contabile inesistente.
- Il test MySQL usa istanza temporanea con credenziali generate solo per il test, non un servizio di produzione; nessuna dipendenza Docker sul server applicativo. Non collaudato un hosting cPanel specifico né SMTP/SMS.
- La serializzazione è verificata strutturalmente e nei test funzionali; nessun carico/concorrenza massiva testato. La gerarchia è verificata nelle Action, non con trigger SQL; accessi diretti privilegiati al database devono restare limitati.
- WCAG 2.2 AA resta obiettivo; non effettuato audit completo. IBAN controllato sintatticamente, nessun servizio bancario integrato.
- CI aggiornata per includere MySQL8; lo stato remoto effettivo va distinto dalle verifiche locali completate.

Non implementate le fasi successive: iscrizioni, capitazioni, canoni, tesoreria, inviti o invio OTP.
