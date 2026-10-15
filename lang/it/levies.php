<?php

return [
    'level' => ['national' => 'Nazionale', 'regional' => 'Regionale', 'provincial' => 'Provinciale'],
    'periodicity' => ['monthly' => 'Mensile', 'quarterly' => 'Trimestrale', 'semiannual' => 'Semestrale', 'annual' => 'Annuale'],
    'basis' => ['fixed' => 'Importo fisso', 'per_member' => 'Importo per fratello'],
    'criterion' => ['active_at_start' => 'Iscritti nel periodo, incluse esenzioni amministrative', 'active_non_exempt_at_start' => 'Iscritti nel periodo, escluse esenzioni amministrative'],
    'status' => ['zero' => 'Importo nullo', 'paid' => 'Saldato', 'due' => 'Da pagare', 'partial' => 'Parziale', 'overdue' => 'Scaduto', 'overdue_partial' => 'Scaduto · parziale'],
];
