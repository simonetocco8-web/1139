# Verifiche fase 6 — Tesoreria

Verifica del 15 ottobre 2026, Laravel 13, PHP 8.4.26 locale, MySQL 8.0.46 effimero dedicato con suffisso `_test`; SQLite per la suite rapida. Credenziali casuali fuori repository, nessun database operativo modificato.

## Risultati

- **248 test SQLite superati, 1.137 asserzioni**.
- **261 test MySQL superati, 1.235 asserzioni**.
- 43 nuovi feature test e 4 test concorrenti MySQL; rimangono inclusi tutti i test delle fasi precedenti.
- Pint verificato con cache nuova, PHPStan/Larastan livello 7 senza errori, build Vite riuscita.
- Dompdf 3.1.6 installato con lockfile; nessun advisory segnalato da Composer durante l’installazione.

```sh
composer test
# Soltanto database MySQL dedicato di test:
vendor/bin/phpunit -c phpunit.mysql.xml
npm run build
```

`TreasuryTest` copre apertura/immutabilità, banca scoperta e cassa iniziale negativa, idempotenza/conflicti, entrate/spese, banca/cassa e conti multipli, conservazione del consolidato e bilanciatura delle operazioni, cassa negativa attuale e storica, data prima dell’apertura, rettifiche, incassi capitazioni parziali/multi-imputazione/credito, canoni parziali, storni con riapertura delle obbligazioni e annullamento del credito, blocco dello storno che scopre la cassa, storno dell’apertura senza alterarne l’importo originario, rollback delle righe reali se il chiamante fallisce dopo il post.

Riconciliazione: abbinamento senza nuovo incasso, rifiuto della nuova contabilizzazione in presenza di candidati anche con data diversa, approvazione atomica della registrazione mancante, rilascio motivato prima dello storno, incompatibilità di conto/importo, riferimenti bancari conflittuali. CSV: anteprima cifrata, duplicati esatti e contenuti simili con riferimenti distinti, validazione, conferma idempotente, binding alla sessione e scadenza. File: cifratura, download autorizzato/nosniff, file eseguibile rifiutato. Export CSV/PDF effettivi e neutralizzazione formule. URL manomessi, conti/categorie di altri tenant, OTP/conferme, Super User privo di accesso locale, Fratello escluso dai movimenti e ammesso solo agli aggregati esplicitamente condivisi. FK composite, demo bilanciata/idempotente senza account di login.

## Concorrenza MySQL

`tests/MySQL/TreasuryConcurrencyTest.php` usa DatabaseMigrations, processi PHP separati e barriera di partenza, senza transazione esterna di RefreshDatabase:

1. Due spese da 80 EUR sulla stessa cassa da 100 EUR: una sola accettata, l’altra rifiutata; saldo 20 EUR, nessuna registrazione parziale.
2. Due richieste con la stessa chiave di spesa: stesso identificativo, un solo movimento.
3. Giroconti opposti simultanei: entrambi i saldi finali conservati e tutte le righe bilanciate.
4. Due incassi capitazione con la stessa chiave e adapter reale: una ricevuta, un post finanziario e un collegamento di sorgente.

I test delle fasi precedenti verificano anche generazione concorrente di rate/canoni, pagamento canone ripetuto e consumo singolo OTP. Gli adapter fake precedenti restano confinati ai loro test; i nuovi test di Tesoreria utilizzano il ledger reale. L’errore simulato dopo il post dimostra il rollback delle righe finanziarie, oltre che della ricevuta.

## Migrazione e distribuzione

Migrazione `2026_10_15_010000_create_treasury.php` applicata su MySQL, rollback dell’ultimo step e riapplicazione verificati. Le tabelle precedenti non vengono riscritte e il saldo di apertura non viene inventato a partire dalle obbligazioni. Route e viste Blade compilabili; cache rimosse dopo la verifica. Build aggiornata inclusa per hosting cPanel senza Node runtime.

## Browser e PDF

20 [schermate desktop/mobile](docs/screenshots/treasury/README.md), viewport 1440×1000 e 320×740, database SQLite temporaneo e dati interamente fittizi. Login con password e OTP realmente consegnato al server SMTP locale, selezione della loggia, registro e saldi, conti/casse, operazione contabilizzata e stornata, CSV duplicato in anteprima e conferma, abbinamento a movimento già esistente senza seconda entrata. Nessun errore JavaScript, risposta 5xx, richiesta esterna o overflow orizzontale. Acquisizioni dopo il completamento del caricamento e stabilizzazione dei font.

Download CSV e PDF su desktop. PDF validato con pdfinfo/pdftotext: documento generato da Dompdf, testo Unicode leggibile, identificativi, date e righe dei giroconti, nessun JavaScript. PDF e CSV scaricati restano soltanto nell’area temporanea di verifica, senza credenziali o file personali nel repository.

## Confine della consegna

La Tesoreria è ora attiva e sostituisce i binding indisponibili delle fasi 4/5. Registra eventi finanziari avvenuti, senza ordinare bonifici o collegarsi a servizi bancari esterni. EUR, nessun cambio valuta, nessun pagamento automatico da scadenza. Non sono introdotti moduli delle fasi successive. Regole operative, autorizzazioni, limiti di import/export e configurazione in [TREASURY.md](TREASURY.md).
