# Piano degli interventi dopo l'audit

Baseline `d2bba13`, 15 ottobre 2026. Questo è un piano: **nessuna correzione applicativa è stata effettuata**. I tre difetti prioritari non sono annullati dal successo della suite esistente. Evitare nuove funzionalità finché correttezza e operatività non sono confermate.

## 1. Contenimento e raccolta evidenze

Responsabili: gestore hosting, responsabile applicativo e amministratori delle logge autorizzate.

- Inventariare commit e configurazione realmente installati, senza distribuire segreti; completare P05 e verificare che nessun file privato sia esposto.
- Garantire copia cifrata esterna verificabile e conservazione delle evidenze. Se emergono esposizione di segreti, bypass o saldi incoerenti, sospendere l'ambito interessato e gestire l'incidente.
- Non eseguire test sull'hosting operativo. Isolare credenziali e destinazioni prima di qualsiasi suite: P03.
- Non approvare come nuove uscite i rimborsi già registrati con storno (P02). Congelare recuperi pro capite ambigui (P01), preservando gli obblighi esistenti e senza cancellarli o riscriverli.

Criterio di uscita: destinazione di collaudo indipendente, accessi/backup protetti e istruzioni temporanee note agli operatori. Non effettuare modifiche finanziarie di massa basate soltanto su questo documento.

## 2. Rendere i test sicuri — P03

Responsabile: sviluppo/CI. Priorità immediata, prima di nuove regressioni automatiche.

Definire un guard comune che controlli APP_ENV testing, configurazione effettiva e destinazione consentita, inclusi DB_URL e cache, prima di RefreshDatabase. Le credenziali devono essere incapaci di accedere a DB operativi anche in caso di errore applicativo. Non basta il suffisso _test del nome dichiarato e non basta force=true in un XML per attestare l'isolamento.

Accettazione: test negativi di configurazione con sentinelle fittizie, senza PDO, coprono override URL/cache/ambiente/host; suite isolata rimane funzionante. Nessuna prova negativa su produzione reale.

## 3. Correggere ricostruzione dei canoni — P01

Responsabili: sviluppo + referente amministrativo/contabile.

Scegliere modello con validità storica di stato/esenzioni e data di riferimento esplicita. Se le evidenze non permettono ricostruzione affidabile, bloccare la generazione arretrata e richiedere validazione auditabile: non dedurre retroattivamente il passato dal solo stato corrente. Lo storico snapshot già presente va preservato.

Definire come rettificare obblighi errati con fatti aggiuntivi e motivazione, senza cambiare retroattivamente pagamenti, destinatari o importi originali. Prima della riconciliazione dei dati reali estrarre un elenco autorizzato degli obblighi generati dopo variazioni/ripartenze; confrontare con evidenze e ottenere approvazione. L'audit non ha letto dati reali e non quantifica eventuali errori già presenti.

Accettazione: la sonda gennaio/febbraio passa con 10,00 € o blocco esplicito per storia insufficiente; nuove regressioni per esenzione, riattivazione, cessazione, cambio territorio, quattro periodicità, job mancanti, replay e concorrenza. Nessuna modifica degli obblighi corretti già maturati.

## 4. Correggere riconciliazione degli storni — P02

Responsabili: sviluppo + tesoriere di collaudo.

Distinguere annullamento puramente contabile da rimborso finanziario effettivo. Consentire abbinamenti validi di originali/rimborsi già contabilizzati e rifiutare la creazione di una seconda registrazione se una riga esistente soddisfa l'evento. Non rimuovere semplicemente i filtri senza verificare l'unicità e la semantica delle riconciliazioni.

Accettazione: incasso + rimborso vengono associati alle due righe esistenti senza cambiare il saldo o gonfiare flussi; niente doppio abbinamento. Coprire giroconti e storni, rilascio, approvazione ripetuta, tenant diversi e due operatori concorrenti. La sonda di audit deve passare. Confrontare CSV/PDF/report dopo l'intero ciclo.

## 5. Completare backup e recupero operativo — P04/P05

Responsabili: gestore hosting e custode dei segreti.

Configurare cron, copia offsite automatica, monitor indipendente e catalogo digest; confermare capacità del piano cPanel e modalità public_html alternativa ove necessaria. Concordare RPO/RTO, retention backup e responsabilità della chiave privata. Provare restore completo dal deposito esterno su nuovo ambiente, con controllo saldi/residui/file/audit.

Predisporre riattivazione individuale post-quarantena basata su verifica attuale di identità/recapiti/credenziali/ruoli; il comando attuale disattiva tutti e il bootstrap iniziale non è una procedura di recupero. Valutare anche perdita totale del secondo fattore/codici offline. Non introdurre bypass o riattivazione indiscriminata.

Accettazione: evidenza cronometrata di recupero da copia esterna, account del vecchio snapshot inutilizzabili prima della rivalidazione, nessuna perdita dei fatti contabili, gestione delle cancellazioni successive allo snapshot, allarmi indipendenti funzionanti.

## 6. Approvare privacy e canali — P06/P07/P08/P09

Responsabili: titolare del trattamento, referente sicurezza e fornitori competenti.

Definire politiche di accesso, conservazione, rettifica/export, richiesta di ex iscritti, account multi-loggia, documenti con terzi, archivi e backup. Approvare trattamento dei dati associativi, informative e rapporti con provider; valutare DPIA ove applicabile. Attivare durate soltanto dopo approvazione, senza cancellazioni contabili improprie.

Collaudare SMTP/SMS reali con account di test, tempi e quote, rischi di email/SMS e procedura per canale perso; accepted non equivale a consegnato. Decidere mitigazioni CSP, allegati e audit esterno coerenti col rischio. Eventuali passkey sono evoluzione separata, non condizione da fingere già implementata.

Accettazione: verbale di policy, esiti dei canali, responsabilità/retention e rischi residui esplicitamente accettati. Nessuna dichiarazione automatica di conformità normativa.

## 7. Usabilità, requisiti secondari e scala — P10/P11/P12

Responsabile: sviluppo con utenti di collaudo.

Aggiornare testi obsoleti e uniformare formato monetario; valutare note amministrative solo dopo minimizzazione e autorizzazioni. Paginare lo storico versamenti e misurare query/PDF/generatori sui volumi attesi, senza indebolire atomicità e isolamento. Se serve condivisione di singoli movimenti col Fratello, definirla come requisito specifico: oggi esistono soltanto aggregati opzionali.

Accettazione: prove 320 px e dispositivi reali, tastiera/zoom/screen reader, conferme e feedback; soglie di durata/memoria cron compatibili con il piano. Non dichiarare WCAG AA o capacità di carico senza misure.

## 8. Collaudo finale e decisione

Rieseguire suite completa e nuove regressioni in staging isolato; audit dipendenze aggiornati, confronto migrazioni e build, verifiche di tenant/OTP/export/file, contabilità e restore. Completare CHECKLIST_COLLAUDO.md e CONFIGURAZIONE_HOSTING.md sul commit effettivamente destinato al rilascio.

**Via libera solo con P01–P03 corretti o processo interessato formalmente escluso e tecnicamente bloccato, P04–P06 verificati e assenza di criticità di isolamento, autenticazione o correttezza finanziaria.** Per l'uso completo richiesto non basta escludere i casi problematici con una nota informale. Registrare rischi residui e approvazione del responsabile; se esiti negativi, correggere e ripetere i controlli interessati.
