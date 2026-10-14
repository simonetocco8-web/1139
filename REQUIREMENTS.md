# Requisiti e criteri di accettazione

Fonte: richiesta allegata «CODEX — Progetto Gestionale Multi-Loggia». Fondamenta completate; le richieste successive autorizzano organizzazione e FASE 2 utenti, permessi e OTP.

## Fase 1
- Verifica repository, PHP >=8.3, Composer, estensioni e compatibilità Laravel 13/Livewire 4.
- Prima del codice: ARCHITECTURE.md, DATABASE.md, REQUIREMENTS.md, ROADMAP.md.
- Starter ufficiale Livewire/Fortify con Blade/Tailwind e build Vite distribuibile su cPanel.
- Configurazione MySQL 8 senza credenziali inventate; session/cache/queue database e storage privato.
- Locale italiano, Europe/Rome, EUR; layout responsive pubblico senza dati riservati.
- Nessuna registrazione pubblica e nessun login utilizzabile prima del secondo fattore richiesto. Componenti privati dello starter rimossi/bloccati.
- Route health neutra e test per stato, rendering, assenza endpoint registrazione e chiusura area privata/Livewire.
- README con sviluppo, build, deploy cPanel, cron e limiti; elenco verifiche e problemi reali.

## Ambito futuro
Organizzazione gerarchica facoltativa e non ciclica; iscritti distinti dagli account; inviti e ruoli multipli per loggia; Super User solo amministrazione globale, assistenza autorizzata auditabile; tenant scoping fail-closed per query/file/export/job. OTP email/SMS sicuro, recupero e revoca. Capitazioni con periodicità, esenzioni, riduzioni, anticipi, parziali e arretrati. Canoni per sovrastrutture anche pro capite. Tesoreria indipendente con conti/casse, saldi iniziali, incassi/spese, giroconti, storni immutabili, allegati privati. Report e privacy.

## Assunzioni e decisioni aperte
Monolite e tenant loggia; account globale, persona locale; user può avere più ruoli. Nessun invito/account seed nella prima fase. Autenticazione fail-closed finché OTP email/SMS non realizzato: scelta necessaria per evitare bypass del requisito. SMS futuro via adapter, nessun provider predefinito. Contabilità per EUR, arrotondamento half-up; pro-rata, scadenze nel giorno inesistente (proposta ultimo giorno mese), priorità esenzioni/riduzioni e chiusura esercizio da confermare prima dell'implementazione. Contatti verificati e accesso assistito con scadenza. Hosting, SMTP, backup, conservazione, procedure recupero e responsabili privacy non ancora definiti.

## Qualità trasversale
WCAG 2.2 AA obiettivo (audit completo futuro); mobile-first, navigazione desktop laterale, controlli chiari, form e filtri/paginazione nei moduli futuri. Nessun dato personale pubblico, indicizzazione vietata, HTTPS obbligatorio in produzione. Test di isolamento e ruoli, OTP, scadenze/idempotenza, parziali/arretrati, saldi/giroconti/storni nelle rispettive fasi. Non dichiarare il prodotto operativo per dati reali nella fase 1.

## Fase organizzativa (richiesta successiva)
Implementati CRUD con disattivazione per obbedienze, riti, strutture, logge e associazioni rito/obbedienza. Numero univoco nella propria obbedienza; gerarchie coerenti, non cicliche e con livelli intermedi opzionali; vincoli MySQL 8 e relazioni Eloquent. Collegamenti territoriali versionati, cancellazioni distruttive escluse e protette da FK quando collegate. Selettore loggia per sole membership/ruoli autorizzati; nessun accesso tenant implicito al Super User. Dati demo fittizi con seeder esplicito, senza account. Test SQLite e MySQL, schermate desktop/mobile in docs/screenshots.

La chiusura iniziale dell’autenticazione è superata dalla fase 2 descritta sotto. Non procedere a capitazioni, canoni, tesoreria o altre fasi.

## Fase 2 completata
Inviti senza registrazione pubblica, Fortify password + OTP verificato email/SMS, digest server, 6 cifre CSPRNG, 5 minuti, un uso, 5 tentativi, 60 secondi fra reinvii, limiti account/IP e invalidazione precedente. Driver fake solo test; configurazione mancante mai dichiarata come invio riuscito. Recupero password senza login, cambio recapito con password e OTP recente più verifica del nuovo contatto, revoca versionata e recupero fattore con codice offline monouso e nuovo OTP.
Ruoli locali multipli e permessi lettura/creazione/modifica/storno/export/amministrazione/report. Protezioni server-side, Livewire, tenant switch ed export. Super User separato dai contenuti; assistenza esplicita con approvazione, scadenza, revoca e audit. Test SQLite/MySQL, incluso consumo concorrente OTP; schermate desktop/mobile fittizie. Dettagli e limiti in IDENTITY.md e IDENTITY-VERIFICATION.md. Nessun modulo contabile o anagrafico aggiunto.

## Fase 3 completata
Anagrafiche locali separate da iscrizioni/account/ruoli; date inizio/fine, stato ed esenzioni motivate, storico cifrato immutabile. Elenco, ricerca nome/cognome e filtro ultima iscrizione, inserimento/modifica, chiusura senza cancellazione, inviti vincolati alla persona e account associabile a più logge. Import CSV con anteprima cifrata, validazione e conferma atomica; esportazione autorizzata e minimizzata. Impostazioni locali includono struttura organizzativa, preferenze, visibilità aggregata futura tesoreria, amministratori/tesorieri. Nessun elenco in ricerca globale.
Conferme e controlli server-side, test ID manomessi di persone/iscrizioni/inviti, isolamento logge e proprietà personale, nove persone demo fittizie senza account. Posizione economica predisposta con resolver di proprietà; nessun importo o saldo finché non saranno implementati i moduli contabili. Esclusi capitazioni, canoni, tesoreria e ulteriori fasi.
