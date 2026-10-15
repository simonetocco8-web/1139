# Canoni — schermate fittizie

14 acquisizioni con Chromium, viewport desktop 1440×1000 e mobile 320×740. Accesso reale nell’ambiente temporaneo tramite password e OTP consegnato al server SMTP locale di prova. Nessun account o credenziale dimostrativa inseriti nel repository.

| Schermata | Desktop | Mobile |
|---|---|---|
| Dashboard con importi e stato | [Apri](dashboard-desktop.png) | [Apri](dashboard-mobile.png) |
| Scadenzario | [Apri](scadenzario-desktop.png) | [Apri](scadenzario-mobile.png) |
| Dettaglio e calcolo originale | [Apri](dettaglio-conteggio-desktop.png) | [Apri](dettaglio-conteggio-mobile.png) |
| Condizioni applicabili | [Apri](condizioni-desktop.png) | [Apri](condizioni-mobile.png) |
| Form deroga locale | [Apri](deroga-desktop.png) | [Apri](deroga-mobile.png) |
| Regole generali piattaforma | [Apri](regole-generali-desktop.png) | [Apri](regole-generali-mobile.png) |
| Storico pagamenti | [Apri](storico-pagamenti-desktop.png) | [Apri](storico-pagamenti-mobile.png) |

QA browser: nessun errore JavaScript o risposta 5xx, nessuna richiesta esterna, nessun overflow orizzontale. Una deroga futura è stata registrata con conferma server-side. Il dettaglio mostra esplicitamente Tesoreria indisponibile: storico vuoto e nessun pagamento simulato. I flussi di pagamento parziale, concorrenza e rollback vengono verificati esclusivamente dai test automatici con spy di Tesoreria.
