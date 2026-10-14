# Fase 2 — identità, permessi e secondo fattore

Autorizzazione corrente: realizzare questa sola fase. Nessuna capitazione, canone, tesoreria o iscrizione implementata.

## Flussi e assunzioni
- Fortify rimane la base: controller login e reset, broker password e action reset; route registrate esplicitamente, nessuna registrazione, TOTP/passkey o endpoint standard non verificato. Pipeline login personalizzata: niente Auth::login fino al consumo OTP.
- Invito con token casuale 256 bit, digest, validità 24h e consumo atomico. Per un nuovo account la prova di possesso della casella è il token ricevuto; accettazione attiva l'account ma NON autentica. Per account già esistente occorre sessione verificata della stessa persona, nessuna modifica password tramite invito tenant. I ruoli sono una proposta dell'invito autorizzata dal locale; non dal modulo del destinatario.
- Bootstrap del primo Super User solo da CLI tramite invito email, se non ne esiste uno; nessuna password predefinita. Il Super User può predisporre il primo amministratore di una loggia priva di amministratore storico, ma non invitare sé stesso e non rimpiazzare amministratori già presenti per accedere ai contenuti. Le altre concessioni locali richiedono amministratore della loggia.
- OTP 6 cifre CSPRNG, 5 minuti, HMAC-SHA256 con segreto server e separazione dei domini. Segreto OTP dedicato opzionale, altrimenti derivato da APP_KEY con HKDF. Identificatore UUID, legame al nonce della sessione pending, account/versione credenziali/canale. Hash del codice persistito, mai codice, mai job o log contenente OTP. 5 tentativi/challenge, cooldown 60 secondi e rate limit separati account/IP. Reinvio crea una nuova challenge e invalida quella precedente in transazione.
- Consegna sincrona con timeout: SMTP esplicito e gateway SMS HTTPS con token/contratto accepted=true; nessun fallback log/array o finto successo. Fake solo ambiente testing, contenuti in memoria. Errori auditati senza eccezioni o recapiti. Accettazione SMTP/provider non equivale a consegna finale al destinatario.
- Solo OTP valido crea sessione completa: rigenerazione id, nessun remember-me, attestazioni server-side legate a userid e session_version. Autenticazione pending separata. Ogni richiesta/Action rivalida account attivo/versione/secondo fattore; revoca può eliminare sessioni DB e cambia versione anche per altri driver.
- Reset password con token broker breve, risposta uniforme, testi neutri, password forte, revoca sessioni/challenge. Nessun login al reset e nessuna sostituzione del secondo canale.
- Cambio email/telefono: sessione completa con OTP recente (5 minuti), password corrente, OTP al nuovo recapito e applicazione atomica; revoca delle sessioni. Telefono cifrato a riposo, payload cambio contatto cifrato. Nessun cambio prima della verifica del nuovo recapito.
- Recupero fattore: password + codice offline casuale monouso ad alta entropia, poi OTP al nuovo recapito; nessuna sessione autenticata durante recupero, nessuna procedura email-only o override Super User. Se mancano anche i codici offline non è disponibile un recupero automatico; definire una procedura indipendente di verifica identità prima di aggiungere un eventuale recupero manuale.

## Permessi per loggia
| Ruolo | Lettura | Creazione | Modifica | Storno | Export | Amministrazione | Report |
|---|---|---|---|---|---|---|---|
| Amministratore di loggia | sì | sì | sì | sì | sì | sì | sì |
| Tesoriere | sì | sì | sì | sì | sì | no | sì |
| Fratello | propri record | no | no | no | no | no | no |

Ruoli multipli per tenant, nessun ruolo locale globale. Flag Super User distinto e nessun bypass tenant. Selettore include membership attive o assistenza approvata valida. Context autorizza tenant scelto, query/record/operazione; lettura Fratello limitata a proprietario. Esportazione elenco accessi richiede anche amministrazione (permesso export finanziario futuro non espone identità). Moduli futuri devono usare Context, policy e scope, non query nude. Le azioni Livewire amministrative sono legate al tenant montato: cambio tenant in altra scheda rifiuta componenti obsoleti.

## Assistenza
Richiesta del Super User con motivo, loggia, scope e durata fino a 60 minuti. Approvazione esplicita da diverso amministratore locale ordinario; mai auto-approvazione e mai scope amministrazione identità/ruoli. Scadenza e revoca controllate ogni richiesta. Audit di richiesta, approvazione, revoca e uso. Il semplice flag Super User non concede accesso. Ruoli locali revocati non possono approvare. La concessione non impersona altri account.

## Validazione
Test bypass Fortify/Livewire/reset/inviti, nonce e challenge altrui, OTP expiry/replay/5 tentativi/reinvio, delivery fake e mancata configurazione, versioni sessioni/cambio recapito/recupero, ruoli per loggia, export isolati, assistenza scaduta/revocata e cambio tenant. Suite SQLite e MySQL 8; CI PHP8.3. Nessuna credenziale SMTP/SMS reale inventata.
