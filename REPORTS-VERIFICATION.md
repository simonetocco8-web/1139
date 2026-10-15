# Verifiche fase 7 — 15 ottobre 2026

Ambiente locale: PHP 8.4.26, Laravel 13, PHPUnit 11.5.57, Node 24.19.0, MySQL 8.0.46 InnoDB effimero dedicato. Nessun dato reale. La CI usa PHP 8.3, Node 24 e MySQL 8. Nessuna verifica su hosting cPanel o provider reali.

## Controlli automatici

- `composer validate --strict` e `composer check-platform-reqs`: superati.
- Pint: controllo completo con cache nuova, superato; Larastan livello 7: nessun errore.
- `composer test`: **281 test, 1.333 asserzioni**, tutti superati su SQLite.
- `php vendor/bin/phpunit -c phpunit.mysql.xml`: **295 test, 1.442 asserzioni**, tutti superati su MySQL, inclusi vincoli e concorrenza dei moduli precedenti.
- Nuova copertura: **33 test funzionali** (196 asserzioni) e **un test MySQL concorrente** (11 asserzioni).
- `npm run build`: superato; asset aggiornati nel repository, nessuna nuova dipendenza.
- `git diff --check`: nessun errore di whitespace.

`ManagementReportsTest` verifica la riconciliazione di apertura/entrate/uscite/rettifiche/chiusura con i saldi effettivi, categorie, banche e casse; giroconti esclusi dai flussi, obbligazioni non pagate senza effetti sul saldo, parziali, credito e imputazioni senza doppi incassi. Gli storni riaprono i residui e compensano il ledger, senza cancellazioni. Verificati anno corrente, mesi e apertura patrimoniale, periodi precedenti ai conti e report vuoti.

Si verifica esplicitamente la distinzione fra flusso per data contabile e stato attuale delle obbligazioni selezionate per scadenza. Nella dashboard personale incasso e imputato differiscono in presenza di anticipo; lo storno nel mese successivo modifica solo il flusso di quel mese, mantenendo lo stato attuale documentato della ricevuta. Arretrati al netto dei parziali, esclusione delle rate interamente pagate e scadenze personali senza dati altrui.

Sicurezza: URL e parametri manomessi, cambio della loggia attiva, un altro tenant comunque autorizzato, account multi-loggia, persona non associata, export personale esplicitamente concesso senza accesso ai report generali. Nessun accesso ai contenuti privati dalle query della dashboard Super User; attività locali escluse dal riepilogo globale. Saldi condivisi senza nomi dei conti o movimenti. Revoca ruoli e secondo fattore invalido bloccano dashboard/export. Il solo permesso export non sostituisce reports. CSV/PDF rispettano proprietario e tenant, header privati, importi del Document e protezione formula injection. Date italiane/ISO equivalenti, calendario non valido e anni/periodi futuri rifiutati, tipi/formati sconosciuti non instradati. Heartbeat non rilevato/recente/in ritardo testati senza inventare salute del cron.

`ReportSnapshotTest` usa due processi MySQL: un incasso viene contabilizzato dopo la prima query finanziaria del report. Il documento conserva apertura, flussi e righe della stessa vista REPEATABLE READ; un secondo report vede il nuovo saldo e l'entrata. È un test reale di commit concorrente, non un mock delle somme.

## Browser e documenti

Chromium/Playwright: desktop **1440×1000**, mobile **320×740**, tre ruoli distinti, login password più OTP inviato a un sink SMTP locale. Nessun marker di autenticazione costruito nel browser. Menu apribile/richiudibile, tutti i sette report gestionali, prospetto personale, filtro italiano e selettore mobile verificati. Nessun overflow orizzontale, errore JavaScript, risposta 5xx o richiesta a risorse esterne. Rifiuto 403 del report finanziario per Fratello verificato anche nel browser; nessun link export personale senza permesso.

CSV/PDF scaricati realmente su entrambi i viewport. PDF validato con pdfinfo/pdftotext: A4 orizzontale, testo italiano/euro leggibile, una pagina sul dataset di prova, nessun JavaScript. Importi confrontati con la schermata e il CSV: apertura 1.100,00 €, banca finale 980,00 €, cassa 140,00 €, complessivo 1.120,00 €, entrate 30,00 €, uscite 10,00 €, variazione 20,00 €. Il giroconto di 50,00 € è escluso da entrate/uscite.

Verificati anche gli stati «Preparazione del documento…», download riuscito e messaggio d'errore dopo logout/revoca della sessione, senza scaricare una pagina di login come documento. Schermate reali: [indice desktop/mobile](docs/screenshots/reports/README.md), 24 immagini con soli dati fittizi. Account/fixture locali di browser non distribuiti.

## Migrazione, scheduler e limiti

Migrazione heartbeat applicata, rollback di un solo passo e riapplicazione sul MySQL sacrificabile; nessuna tabella contabile eliminata. Cache route e compilazione Blade verificate, poi ripulite. `schedule:run` locale ha eseguito `system-heartbeat` correttamente; non è una prova del cron dell'hosting o della coda in produzione.

Nessun nuovo modulo, invio operativo o servizio bancario. Export sincroni limitati (CSV 5.000, PDF 250 righe), pagine a card; la vista dei debiti è corrente e dichiarata come tale, non un estratto storico dei residui. La privacy dei download è garantita nell'applicazione, non sui dispositivi dei destinatari. Non eseguiti audit WCAG completo, pentest indipendente o collaudo su dati/hosting reali. Dettagli e configurazione in [REPORTS.md](REPORTS.md). Fase 7 conclusa, nessuna fase successiva avviata.
