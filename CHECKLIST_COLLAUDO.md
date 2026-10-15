# Checklist di collaudo del gestionale

Audit baseline `d2bba13`, 15 ottobre 2026. Questa checklist è una **procedura da eseguire**, non un'attestazione di prove già svolte. Gli esiti dell'audit sono in AUDIT_COMPLETO.md. Registrare per ogni voce ambiente, commit, operatore, data, atteso/ottenuto ed evidenza privata; non allegare credenziali o nominativi reali al repository.

## Regole di esecuzione

- [ ] Predisporre staging isolato con dati fittizi, DB e credenziali dedicati non autorizzati sul database di produzione, storage separato e mail sink.
- [ ] Verificare connessione **risolta**, non solo DB_DATABASE: nessun DB_URL ereditato o cache di produzione. Il guard attuale è insufficiente (P03).
- [ ] Non avviare PHPUnit, seed demo, migrate:fresh, rollback di prova, pruning applicativo o restore sul server operativo. Eventuali migrazioni di rilascio sono un'attività separata approvata, preceduta da backup verificato.
- [ ] Le verifiche HTTP di produzione devono essere autorizzate e non invasive. Le prove finanziarie, OTP brute force, XSS, injection e concorrenza vanno in staging.
- [ ] Creare due logge A/B e account: Super User senza ruoli locali, amministratore solo A, tesoriere solo A, Fratello A/B con persone locali distinte. Aggiungere due amministratori per prove di revoca senza bloccare l'ambiente.

## Installazione e primo accesso

- [ ] Confrontare commit installato e asset con baseline approvata; registrare eventuale differenza.
- [ ] Seguire CONFIGURAZIONE_HOSTING.md per PHP web/CLI, MySQL, storage e cron.
- [ ] Nuova installazione **di test**: migrate due volte. Atteso: seconda esecuzione senza nuove modifiche; nessun seed obbligatorio.
- [ ] Primo Super User tramite identity:invite-superuser su casella di test: invito neutro, nessuna password/token in console, accettazione entro 24 ore, nessuna sessione prima di password+OTP. Secondo bootstrap rifiutato.
- [ ] Invito iniziale amministratore di A verso persona distinta dal Super User; nessun accesso locale acquisito dalla piattaforma.
- [ ] Route di registrazione pubblica, endpoint standard TOTP/password-confirm non previsti e bypass dello starter assenti o negati. /up restituisce solo liveness.

## Login, OTP e recupero

- [ ] Email inesistente e password errata: stessa risposta generica e nessuna sessione autenticata completa.
- [ ] Password corretta: solo challenge pending; dashboard, export, file e update Livewire negati prima dell'OTP.
- [ ] OTP corretto: sessione rigenerata, vecchio session ID non riutilizzabile, accesso consentito ai soli ruoli assegnati.
- [ ] OTP scaduto al quinto minuto, già consumato, di altra challenge/sessione o codice precedente dopo reinvio: tutti negati.
- [ ] Cinque errori chiudono la challenge; reinvio prima di 60 secondi negato. Verificare rate limit account su IP diversi e IP su account diversi solo in staging.
- [ ] Due verifiche simultanee dello stesso OTP: una sola sessione conclusa/consumo valido.
- [ ] Account disattivato o versione credenziali cambiata mentre challenge pending: nessun login.
- [ ] SMTP non configurato o provider in errore: nessun successo inventato, challenge non utilizzabile. Nessun OTP nei log o nel DB in chiaro.
- [ ] Recupero password: risposta uniforme, token breve e monouso; reset non autentica né sostituisce il secondo fattore e revoca le sessioni precedenti.
- [ ] Cambio recapito/canale: password + secondo fattore recente, verifica nuovo recapito, revoca versionata, vecchia challenge non più valida.
- [ ] Recupero fattore: password + codice offline monouso + OTP nuovo recapito; il solo accesso alla casella non cambia il canale. Verificare anche procedura per perdita di tutti i codici senza bypass.
- [ ] Revoca sessioni: vecchio browser e altre sessioni negati alla richiesta successiva, inclusi file/export/Livewire.

## Tenant, permessi e assistenza

- [ ] Amministratore A non vede elenchi/persone/operazioni di B; sostituire manualmente lodge, person, enrollment, installment, receipt, obligation, operation, attachment, batch e privacy case negli URL/POST di test.
- [ ] Tesoriere opera finanziariamente ma non modifica ruoli/anagrafiche/impostazioni amministrative se non assegnati; Fratello legge solo la propria posizione locale.
- [ ] Stesso account Fratello A/B: cambio loggia cambia persona, rate e ricevute; nessun totale trasversale indebito.
- [ ] Form aperto su A, selezione B in altra scheda, submit sul vecchio form: rifiuto 409 o equivalente, nessuna modifica alla loggia sbagliata.
- [ ] Revoca ruolo mentre pagina aperta: richiesta successiva negata; testare sia POST tradizionali sia update Livewire.
- [ ] Super User senza membership non consulta finanza, elenchi, allegati o export locali. Panoramica tecnica mostra solo dati globali minimizzati.
- [ ] Assistenza: richiesta motivata, approvatore locale distinto, permessi espliciti, scadenza/revoca e audit. Nessuna autoapprovazione o amministrazione identità tramite assistenza.
- [ ] Terminare iscrizione senza eliminare storia; verificare che i ruoli restano separati e revocarli esplicitamente quando previsto dalla policy.

## Organizzazione e iscritti

- [ ] Inserire riti/obbedienze, compatibilità, strutture con livelli intermedi mancanti e logge; ricercare/modificare/disattivare.
- [ ] Numero loggia duplicato nella stessa obbedienza rifiutato, stesso numero in altra obbedienza consentito.
- [ ] Gerarchie cicliche, stesso livello come genitore, altra obbedienza o catene incoerenti rifiutate lato server.
- [ ] Cambiare territorio di A: precedente assegnazione chiusa, nuovo collegamento aperto; vecchi canoni/movimenti rimangono sul destinatario storico.
- [ ] Inserire persona/iscrizione; intervalli sovrapposti e fine precedente all'inizio rifiutati. Rettifiche anagrafiche mantengono storico.
- [ ] CSV: UTF-8, formato modello, anteprima, errori riga per riga, limite 200 righe, conferma unica; token/sessione/tenant manomessi e anteprima scaduta negati.
- [ ] Export iscritti solo autorizzato; verificare minimizzazione e celle formula senza esecuzione in foglio elettronico.

## Capitazioni

- [ ] Quattro periodicità e ancoraggio 31 gennaio / febbraio bisestile; scadenze inizio/fine periodo e giorni aggiuntivi conformi alle regole.
- [ ] Prorata none e daily espliciti, ingresso/cessazione, più intervalli non sovrapposti e arrotondamento half-up. Nessun addebito fuori iscrizione.
- [ ] Esenzione, sconto e importo personale; nuove versioni solo sui cicli consentiti. Rate già generate conservate, con rettifiche esplicite per conguagli.
- [ ] Generazione ripetuta e due job concorrenti: una rata per piano/persona/periodo, nessuna entrata finanziaria alla maturazione.
- [ ] Incasso 150,00 € e due rate da 100,00 €: imputare 100,00 € + 30,00 €, credito 20,00 €, residui 0,00 € / 70,00 €. Successiva imputazione credito 20,00 €: residuo seconda rata 50,00 €, saldo finanziario invariato.
- [ ] Stessa chiave incasso: una sola operazione. Chiave riutilizzata con importi diversi: rifiuto; errore su una rata annulla l'intera transazione.
- [ ] Storno incasso: credito annullato, imputazioni annullate con nuove righe, rate riaperte; storico e audit intatti.

## Canoni — includere la regressione P01

- [ ] Regole fisse/pro capite su obbedienza, struttura e deroga loggia; priorità e periodi, destinatario storico, IBAN snapshot.
- [ ] Criterio active_at_start / active_non_exempt_at_start, data riferimento e unità salvate; nessuna informazione su altre logge nello snapshot locale.
- [ ] **Regressione P01:** persona attiva a gennaio, sospesa a febbraio prima del recupero di gennaio. Atteso 10,00 € con quota 10,00 € a gennaio, oppure blocco esplicito se storia insufficiente; mai zero silenzioso. Attualmente fallisce.
- [ ] Ripetere il caso con cambio esenzione, riattivazione e job mancanti; approvare evidenza storica e gestione delle obbligazioni errate senza overwrite.
- [ ] Pagamenti parziali e successivi: residuo corretto, una sola uscita per versamento, nessuna uscita prima del pagamento; doppio job non duplica obblighi.

## Tesoreria e riconciliazione — includere la regressione P02

- [ ] Apertura banca 1.000,00 €, cassa 100,00 €: consolidato 1.100,00 €. Incasso banca 200,00 €, spesa cassa 30,00 €: 1.270,00 €. Giroconto banca→cassa 50,00 €: banca 1.150,00 €, cassa 120,00 €, consolidato sempre 1.270,00 €.
- [ ] Storno spesa 30,00 €: consolidato 1.300,00 € e risultato economico netto coerente. Trasferimenti non compaiono come ricavi/costi economici.
- [ ] Cassa negativa, anche con registrazione retrodatata, bloccata; due spese simultanee non superano disponibilità. Saldo conto non editabile liberamente.
- [ ] Obblighi/rate non pagati non cambiano i saldi; pagamenti/incassi collegati aggiornano tutto nella stessa transazione.
- [ ] CSV estratto conto: anteprima, riferimento duplicato, riferimento con contenuto diverso, righe simili con riferimenti distinti, conferma ripetuta e nessun posting automatico.
- [ ] Abbinare riga a registrazione esistente senza variazione del saldo; approvare nuova riga solo dopo verifica assenza di posting, una sola volta.
- [ ] **Regressione P02:** riga -100,00 € di rimborso deve potersi abbinare allo storno reale già registrato, senza seconda spesa. Attualmente fallisce; non usare Approva come workaround.
- [ ] Rilascio abbinamento con motivazione, storno, nuovo abbinamento coerente e audit completo; nessuna cancellazione dei movimenti contabilizzati.
- [ ] Allegato PDF/immagine fittizio: privato, download solo autorizzato, URL di altro tenant negato, contenuto originale verificato; tipo/taglia non consentiti rifiutati.

## Dashboard, report ed esportazioni

- [ ] Confrontare registro e saldi banca/cassa con ciascuna dashboard; debiti/crediti non confusi con disponibilità.
- [ ] Report finanziario: iniziale + entrate − uscite + aperture/rettifiche = finale; somma conti = consolidato, categorie = flussi economici netti.
- [ ] CSV e PDF corrispondono alla schermata e ai filtri. Date italiane, EUR, storni e negativi leggibili; superamento limiti produce errore chiaro.
- [ ] Report obbligazioni: residuo attuale, selezione per scadenza; non interpretarlo come saldo storico alla fine del periodo.
- [ ] URL di export non aggira permessi, sessione o tenant. Download privato/no-store e PDF senza richieste esterne.
- [ ] Fratello senza condivisione non vede tesoreria; con preferenza esplicita vede soli aggregati. Singoli movimenti condivisi non sono una funzione disponibile.

## Notifiche, scheduler e code

- [ ] Attivare globalmente e per tipo/loggia soltanto nello staging; preferenze utente e ruoli limitano la consegna.
- [ ] Prossima scadenza e scaduto per capitazioni/canoni: promemoria una volta per finestra, solo destinatari autorizzati, testo esterno neutro senza posizioni altrui.
- [ ] Pagamento, revoca o cambio recapito fra produzione e consumo: invio annullato o indirizzato esclusivamente al recapito verificato attuale.
- [ ] Due worker sullo stesso messaggio: un solo effetto esterno; reinvio da UI richiede conferma e audit. Errore ambiguo non ritentato automaticamente.
- [ ] Canale mancante: blocked, nessuna consegna finta. Esito accepted distinto da ricevuto. Verificare comunicazione amministrativa cifrata e alert tecnico solo piattaforma.
- [ ] Cron reali con PHP corretto: generazioni, heartbeat, prepare/dispatch, worker limitato, integrità e manutenzione. Queue termina e riprende senza daemon, con lock comune a backup.
- [ ] Simulare errore in staging: failed_jobs/stato outbox visibili e allarme; nessun segreto nell'output.

## Privacy, backup, sicurezza e mobile

- [ ] Policy approvata: retention default zero, anteprima prima di applicare; nessuna cancellazione contabile. Richieste, rettifiche, export personale e conservazione motivata verificati.
- [ ] Cancellazione persona non finanziaria solo dopo termini; riferimenti contabili/snapshot bloccano. Account globale e backup gestiti nella procedura separata.
- [ ] Backup cifrato reale in staging, copia offsite, digest da catalogo separato e chiave privata offline. Restore in nuovo DB/storage, confronto saldi/residui/allegati e integrità.
- [ ] Quarantena post-restore: tutte le sessioni/token/assistenze invalide, account disattivati; rivalidazione controllata prima della riapertura, registro cancellazioni successivo allo snapshot considerato.
- [ ] Produrre prova di ripristino dal deposito esterno e tempi RPO/RTO. Un file backup presente o un /up verde non bastano.
- [ ] CSRF POST senza token negato; payload HTML/injection di test non eseguiti; cookie/header e pagina errore controllati anche dietro proxy HTTPS reale.
- [ ] Mobile 320/375/390 px e desktop: menu utilizzabile, card leggibili, niente overflow inutile, tastiera e focus, etichette, errori e caricamenti chiari; provare form lunghi e PDF.
- [ ] Tastiera, zoom 200%, screen reader, contrasto, Safari/iOS e Android reali; non dichiarare WCAG AA senza collaudo completo.

## Verbale finale

Commit installato: ______. Ambiente: ______. Operatore e data: ______.
Problemi P01/P02/P03 chiusi con prove: ______. Hosting/offsite/privacy approvati: ______.
Rischi residui accettati dal responsabile: ______. Decisione di utilizzo e ambito: ______.
**Non firmare il via libera con problemi critici/alti irrisolti di isolamento, OTP, correttezza finanziaria o ripristino.**
