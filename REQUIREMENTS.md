# Requisiti e criteri di accettazione

Fonte: richiesta allegata «CODEX — Progetto Gestionale Multi-Loggia». Esecuzione limitata alla prima fase.

## Fase 1
- Verifica repository, PHP >=8.3, Composer, estensioni e compatibilità Laravel 13/Livewire 4.
- Prima del codice: ARCHITECTURE.md, DATABASE.md, REQUIREMENTS.md, ROADMAP.md.
- Starter ufficiale Livewire/Fortify con Blade/Tailwind e build Vite distribuibile su cPanel.
- Configurazione MySQL 8 senza credenziali inventate; session/cache/queue database e storage privato.
- Locale italiano, Europe/Rome, EUR; layout responsive pubblico senza dati riservati.
- Nessuna registrazione pubblica e nessun login utilizzabile prima del secondo fattore richiesto. Componenti privati dello starter rimossi/bloccati.
- Route health neutra e test per stato, rendering, assenza endpoint registrazione e chiusura area privata/Livewire.
- README con sviluppo, build, deploy cPanel, cron e limiti; elenco verifiche e problemi reali.

## Fasi successive, non implementate
Organizzazione gerarchica facoltativa e non ciclica; iscritti distinti dagli account; inviti e ruoli multipli per loggia; Super User solo amministrazione globale, assistenza autorizzata auditabile; tenant scoping fail-closed per query/file/export/job. OTP email/SMS sicuro, recupero e revoca. Capitazioni con periodicità, esenzioni, riduzioni, anticipi, parziali e arretrati. Canoni per sovrastrutture anche pro capite. Tesoreria indipendente con conti/casse, saldi iniziali, incassi/spese, giroconti, storni immutabili, allegati privati. Report e privacy.

## Assunzioni e decisioni aperte
Monolite e tenant loggia; account globale, persona locale; user può avere più ruoli. Nessun invito/account seed nella prima fase. Autenticazione fail-closed finché OTP email/SMS non realizzato: scelta necessaria per evitare bypass del requisito. SMS futuro via adapter, nessun provider predefinito. Contabilità per EUR, arrotondamento half-up; pro-rata, scadenze nel giorno inesistente (proposta ultimo giorno mese), priorità esenzioni/riduzioni e chiusura esercizio da confermare prima dell'implementazione. Contatti verificati e accesso assistito con scadenza. Hosting, SMTP, backup, conservazione, procedure recupero e responsabili privacy non ancora definiti.

## Qualità trasversale
WCAG 2.2 AA obiettivo (audit completo futuro); mobile-first, navigazione desktop laterale, controlli chiari, form e filtri/paginazione nei moduli futuri. Nessun dato personale pubblico, indicizzazione vietata, HTTPS obbligatorio in produzione. Test di isolamento e ruoli, OTP, scadenze/idempotenza, parziali/arretrati, saldi/giroconti/storni nelle rispettive fasi. Non dichiarare il prodotto operativo per dati reali nella fase 1.
