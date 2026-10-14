# Fase organizzativa — decisioni prima dell'implementazione

La nuova richiesta autorizza modello dati e CRUD organizzativi nel repository esistente e sostituisce il precedente stop per questa sola fase. Nessuna contabilità, iscrizione, invito o login/OTP implementato.

- Tabelle globali organizzative visibili/modificabili solo al Super User, tramite policy senza autorizzazione implicita per dati locali.
- Flag users.is_platform_admin predefinito falso e non mass-assignable; memberships lodge_user con ruolo e stato per predisporre selezione loggia (nessun CRUD di account/ruoli in questa fase).
- Schermate sotto /admin/organizzazione con controlli ripetuti in mount, ogni richiesta Livewire e Action. Richiesti account autenticato e attestazione server-side del secondo fattore legata allo stesso account. Il login resta chiuso; nessuna route demo/bypass distribuita.
- Obbedienze/riti identificati da codice univoco, associazione UNIQUE(obedience_id,rite_id). Associazione obbligatoria per rito usato da una loggia; FK composita impedisce dissociazione se ancora usata.
- Struttura con parent facoltativo, livello nazionale/regionale/provinciale, stessa obbedienza, livello genitore strettamente superiore. Ammessi salti di livello e radici provinciali/regionali; vietati cicli. Obbedienza e livello immutabili dopo creazione; modifica parent non riscrive assegnazioni storiche delle logge.
- Loggia UNIQUE(obedience_id,number), identità e obbedienza stabili; rito modificabile se compatibile. Dati di contatto restano minimali. Disattivazione reversibile, nessuna eliminazione fisica esposta.
- lodge_territorial_assignments appendono intervalli starts_at/ended_at e snapshot livello/denominazione; una sola assegnazione aperta per livello/loggia via colonna generata e UNIQUE. FK composite garantiscono medesima obbedienza; timestamp UTC. Contabilità futura farà riferimento all'id di assegnazione/destinatario dell'epoca, mai soltanto al collegamento corrente.
- Le Action serializzano cambi organizzativi bloccando la riga obbedienza in transazione. Selezioni territoriali multiple devono formare una singola catena di antenati; nessun livello intermedio obbligatorio. Disattivazione genitore non cascata: ogni elemento mantiene il proprio stato, ma nuovi collegamenti a elementi inattivi sono vietati.
- Selettore limitato alle membership attive e logge/obbedienze attive; rivalida ogni richiesta e non concede accesso al Super User per il solo flag piattaforma. Nessun record contabile/anagrafico locale è presente.
- Audit organizzativo minimale append-only in tabella separata; attore/id entità/tipo operazione, senza contenuti riservati. Eventi di cambio territoriale mantenuti tramite assegnazioni e audit.
- Dati demo solo con seeder esplicito non eseguibile in produzione: nomi di entità inventati, nessun account/credenziale.

Test: CRUD autorizzato/negato, secondo fattore assente/soggetto errato, replay Livewire dopo revoca permesso, FK/UNIQUE MySQL, assenza cicli e livelli saltati, link incompatibili, storico dopo spostamento/disattivazione, selector IDOR e revoca membership. MySQL8 verificato separatamente da SQLite.

## Dettagli finali
Il reparenting controlla tutte le catene correnti delle logge della stessa obbedienza e viene annullato se ne spezza una. Complessità attuale proporzionale alle logge dell'obbedienza; ottimizzazione dei sottoalberi da valutare con volumi reali, senza cambiare gli invarianti. Timestamp dello storico e audit in UTC, presentazione Europe/Rome. Per preservare lo storico non si espone delete fisico: il "D" del CRUD è disattivazione reversibile. Email/telefono/indirizzo/IBAN restano opzionali; l'IBAN ha controllo sintattico, non integrazione bancaria.
