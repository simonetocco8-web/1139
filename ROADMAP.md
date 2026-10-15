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

## Stato dopo richiesta FASE 2
Identità e isolamento della voce 2 completati per i moduli attuali: Fortify, inviti, ruoli locali, OTP, revoche, recupero, context tenant, export accessi, report accessi e assistenza auditabile. Verifiche in IDENTITY-VERIFICATION.md. La precedente indicazione di login chiuso descrive lo stato storico prima della fase 2. Organizzazione completata; iscrizioni, capitazioni, canoni, tesoreria e report contabili restano esclusi. Fermarsi qui fino a nuova istruzione.

## Stato dopo richiesta FASE 3
Anagrafiche locali/iscrizioni della voce 3 completate: schede, stati, esenzioni, storico, inviti associati alla persona, CSV e impostazioni loggia. Contabilità non implementata: pagina personale e resolver di proprietà predisposti senza saldi inventati. Le preferenze notifiche/tesoreria non attivano moduli futuri. Fermarsi al modulo Membership; le voci successive richiedono nuova istruzione.

## Stato dopo richiesta FASE 4
Capitazioni completate: calendario, piani/versioni, condizioni personali, snapshot, generazione automatica/idempotente, rettifiche e report/personale. Servizi di pagamenti/credito/imputazioni collegati al contratto transazionale Treasury, con adapter reale indisponibile fino alla fase Tesoreria. Nessun sistema finanziario parallelo; nessun incasso reale o simulato nel seed. Le indicazioni precedenti di contabilità esclusa descrivono le rispettive fasi storiche. Fermarsi qui; canoni e Tesoreria richiedono una nuova istruzione.

## Stato dopo la fase 5 autorizzata
Canoni completati con programmi/versioni, deroghe locali, conteggio e destinatari storicizzati, generazione idempotente e pagamenti parziali sul contratto transazionale delle uscite. Dashboard, scadenzario e storico; test di gerarchia, periodi, isolamento e concorrenza. Rimane indisponibile il servizio finanziario reale: nessun saldo o movimento simulato. Fermarsi qui; la Tesoreria operativa richiede una nuova istruzione.

## Stato dopo la fase 6 autorizzata
Tesoreria completata con operazioni/righe bilanciate, adapter reali, saldi e storni integrati, protezione cassa, registri, allegati, riconciliazione ed export CSV/PDF. Test MySQL di spese simultanee, idempotenza, trasferimenti e incassi reali sul ledger. Le limitazioni Treasury indisponibile descritte nelle fasi 4/5 sono superate. Nessuna fase successiva avviata.
