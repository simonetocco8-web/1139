# Verifiche fase 5 — canoni

Verifica del 15 ottobre 2026, PHP 8.4.26, Laravel 13, SQLite per la suite rapida e MySQL 8.0.46 effimero per vincoli e processi concorrenti. Database MySQL dedicato con suffisso `_test`, credenziali casuali temporanee fuori repository. Nessun database operativo modificato.

## Test automatici

Suite SQLite: **205 test superati, 989 asserzioni**. Suite MySQL: **214 test superati, 1.044 asserzioni**. I nuovi casi sono 40 feature test e 2 test MySQL concorrenti; restano inclusi tutti i controlli delle fasi precedenti. Comandi riproducibili:

```sh
composer test
# Solo database MySQL dedicato, mai dati reali:
vendor/bin/phpunit -c phpunit.mysql.xml
npm run build
```

`tests/Feature/LevyTest.php` copre periodicità mensile/trimestrale/semestrale/annuale; cicli e importi; gerarchia nazionale/provinciale senza regionale; programmi multipli; conteggio con data inclusiva, cessazioni, sospensioni, esenzioni configurate e appartenenza ad altra loggia; importi nulli e overflow; snapshot e IBAN immutabili; trasferimenti e generazione arretrata sul vecchio destinatario; priorità delle deroghe e fallback dopo scadenza; sospensione/ripresa delle condizioni; variazioni future senza retroattività; scadenza da fine periodo e mese privo del giorno originale; pagamenti parziali e successivi, chiavi ripetute o conflittuali, sovrapagamento, conto altrui, rollback del post e riferimento invalido; adapter predefinito indisponibile; URL manomessi, cambio tenant, ruolo Fratello, Super User privo di accesso locale, permessi report, pagina globale priva di deroghe locali; conferme e OTP; FK composite, cancellazioni distruttive e chiave unica del ciclo; demo fittizia idempotente; data CLI vuota.

`tests/MySQL/LevyConcurrencyTest.php` avvia due processi PHP sincronizzati da una barriera, senza transazione esterna di RefreshDatabase:

- Due generatori simultanei: risultati `[0,1]`, un solo obbligo e nessun pagamento.
- Due richieste simultanee con la stessa chiave: stesso pagamento restituito, un solo evento di uscita dello spy e residuo corretto.

Durante la prima verifica concorrente è emersa una lettura non bloccante prima del lock che apriva lo snapshot REPEATABLE READ troppo presto. È stata eliminata: risoluzione dell’obbedienza fuori dalla transazione, poi lock obbedienza/loggia prima delle letture operative. Entrambi i casi concorrenti passano dopo la correzione. Il vincolo UNIQUE è mantenuto come difesa aggiuntiva.

Nessun provider finanziario finto è utilizzabile dall’applicazione. Lo spy confinato in `tests/Support` verifica che il post avvenga nella stessa connessione e transazione; un errore dopo la prima scrittura rimuove sia quell’evento di prova sia il pagamento locale.

## Migrazioni, controlli e browser

Migrazione `2026_10_15_000001_create_levies.php` applicata su MySQL, rollback dell’ultimo step e riapplicazione riusciti. Le migrazioni precedenti rimangono inalterate. Pint verificato con cache nuova, PHPStan/Larastan livello 7, build Vite, controllo diff e suite delle fasi precedenti.

14 [schermate desktop/mobile](docs/screenshots/levies/README.md), inclusi dettaglio storico, regole generali e deroga. Browser Chromium a 1440 e 320 px: login password + OTP SMTP locale, selezione loggia, pagine HTTP 200, salvataggio di una deroga futura, nessun errore JS, richiesta esterna o overflow orizzontale. Controllo visivo del dettaglio e del form mobile.

## Limite operativo

Tesoreria reale ancora assente: nessuna uscita bancaria/cassa eseguita in questa fase. Il servizio e il form sono predisposti, il binding indisponibile blocca pagamenti e segnala l’assenza del servizio. Nessun saldo finanziario parallelo e nessun pagamento nei dati demo. Conteggi di arretrati non ancora generati usano le iscrizioni registrate alla generazione, con snapshot dell’evidenza; non ricostruiscono automaticamente sospensioni pregresse da dati attuali. Regole e decisioni in [LEVIES.md](LEVIES.md). Nessuna fase successiva implementata.
