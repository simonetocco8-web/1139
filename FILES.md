# File della prima fase

Tutti i file dello starter sono nuovi nel repository inizialmente vuoto. I file principali per la revisione:

| File / directory | Scopo |
| --- | --- |
| ARCHITECTURE.md, DATABASE.md, REQUIREMENTS.md, ROADMAP.md | Analisi iniziale, assunzioni, relazioni/vincoli futuri, fasi e gate |
| README.md, VERIFICATION.md, FILES.md, LICENSE | Avvio, deploy cPanel, esiti e limiti, inventario e licenza |
| composer.json / composer.lock | Laravel 13, Fortify, Livewire 4 e dipendenze compatibili PHP 8.3 |
| package.json / package-lock.json / vite.config.js | Build Vite/Tailwind senza Node in produzione |
| .env.example | MySQL/SMTP senza credenziali, locale e storage/sessioni/coda/cache |
| config/app.php, platform.php, fortify.php, filesystems.php | Impostazioni di base, autenticazione chiusa e file privati non serviti automaticamente |
| bootstrap/app.php, app/Http/Middleware/PrivacyHeaders.php | Routing, health neutra e header privacy |
| app/Providers/FortifyServiceProvider.php | Disabilitazione totale delle route Fortify nella fase 1 |
| resources/views/components/layouts/foundation.blade.php | Layout responsive con navigazione e skip link |
| resources/views/welcome.blade.php | Pagina pubblica senza dati personali |
| app/Livewire/FoundationStatus.php, resources/views/livewire/foundation-status.blade.php | Componente Livewire pubblico senza azioni di modifica |
| resources/css/app.css, resources/js/app.js, public/build | Frontend e asset compilati per hosting tradizionale |
| public/robots.txt | Divieto indicizzazione |
| database/migrations, database/seeders/DatabaseSeeder.php | Tabelle infrastrutturali dello starter e seed vuoto |
| tests/Feature/FoundationTest.php, phpunit.xml, phpstan.neon, pint.json | Test e controlli iniziali |
| .github/workflows/tests.yml | CI PHP 8.3/Node 24 predisposta |

`.env`, database SQLite locale, vendor, node_modules, log/cache e intermedi non fanno parte dei sorgenti versionabili. In produzione generare APP_KEY e configurare i servizi reali. Gli asset public/build sono inclusi per rendere il frontend consegnabile senza Node sul server.
