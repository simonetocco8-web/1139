# Roadmap

1. **Fondamenta — scope autorizzato attuale.** Documenti, installazione Laravel 13 + starter Livewire 4, impostazioni MySQL/locale/storage, layout pubblico, health, autenticazione chiusa, asset build, test iniziali e guida cPanel. Fermarsi al termine. La verifica MySQL su hosting richiede un database disponibile.
2. **Identità e isolamento.** Inviti, account/membership/ruoli, OTP email/SMS, sessioni pending e revoca, recupero sicuro, TenantContext/Policy, file privati, audit e assistenza autorizzata. Gate: test cross-tenant per ogni canale, replay/bruteforce OTP, nessun bypass Fortify/Livewire/reset.
3. **Organizzazione e iscrizioni.** Obbedienze/riti/gerarchie/logge, anagrafiche locali, UI per ruoli e ricerca; gate: vincoli gerarchici/cicli e associazioni cross-tenant.
4. **Capitazioni.** Periodicità, snapshot, esenzioni/riduzioni, addebiti idempotenti e parziali/arretrati; gate: calendario e concorrenza MySQL.
5. **Canoni.** Destinatari territoriali facoltativi, snapshot pro capite, debiti e pagamenti; gate: maturazione separata dai flussi.
6. **Tesoreria.** Conti/casse, posting atomici, allocazioni, storni/giroconti, allegati; gate: invarianti e concorrenza, nessuna cancellazione contabilizzata.
7. **Report, privacy e rilascio.** Export autorizzati, retention/anonimizzazione, restore backup, audit WCAG, revisione sicurezza, test cPanel/MySQL/cron/SMTP. Gate: decisioni privacy completate e verifica indipendente prima di dati reali.

Le fasi 2–7 richiedono una nuova istruzione: non avviarle autonomamente.

## Aggiornamento della sequenza autorizzata
La richiesta "FASE 1: MODELLO DATI E STRUTTURA ORGANIZZATIVA" autorizza anticipatamente la parte organizzativa della voce 3 e la sola predisposizione del selettore (membership/ruoli), senza realizzare la voce 2. Login/inviti/OTP restano chiusi, nessun modulo di iscrizioni o contabilità implementato. Fermarsi alla consegna organizzativa; le altre voci continuano a richiedere una nuova istruzione.
