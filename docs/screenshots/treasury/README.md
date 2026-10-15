# Tesoreria — schermate fittizie

20 acquisizioni Chromium, desktop 1440×1000 e mobile 320×740, su database temporaneo. Accesso tramite password e OTP consegnato al server SMTP locale di prova. Nessun account o credenziale nel repository.

| Schermata | Desktop | Mobile |
|---|---|---|
| Registro e saldi | [Apri](registro-saldi-desktop.png) | [Apri](registro-saldi-mobile.png) |
| Conti bancari | [Apri](conti-bancari-desktop.png) | [Apri](conti-bancari-mobile.png) |
| Casse contanti | [Apri](casse-contanti-desktop.png) | [Apri](casse-contanti-mobile.png) |
| Nuova operazione | [Apri](nuova-operazione-desktop.png) | [Apri](nuova-operazione-mobile.png) |
| Dettaglio operazione | [Apri](dettaglio-operazione-desktop.png) | [Apri](dettaglio-operazione-mobile.png) |
| Storno | [Apri](storno-desktop.png) | [Apri](storno-mobile.png) |
| Importazione e riconciliazione | [Apri](riconciliazione-desktop.png) | [Apri](riconciliazione-mobile.png) |
| Anteprima CSV | [Apri](anteprima-csv-desktop.png) | [Apri](anteprima-csv-mobile.png) |
| Estratto importato | [Apri](estratto-importato-desktop.png) | [Apri](estratto-importato-mobile.png) |
| Movimento abbinato | [Apri](movimento-riconciliato-desktop.png) | [Apri](movimento-riconciliato-mobile.png) |

QA: nessun errore JavaScript/5xx, richiesta esterna o overflow orizzontale. Operazione registrata e stornata su entrambi i viewport; CSV con duplicato mostrato in anteprima, confermato e abbinato alla registrazione già esistente senza nuova contabilizzazione. CSV e PDF scaricati su desktop; PDF controllato con pdfinfo/pdftotext, inclusi Unicode, identificativi e giroconti.
