<?php

return [
    'enabled' => (bool) env('COMMUNICATIONS_ENABLED', false),
    'overdue_interval_days' => (int) env('COMMUNICATIONS_OVERDUE_INTERVAL_DAYS', 7),
    'types' => [
        'cap_upcoming' => 'Capitazione in prossima scadenza',
        'cap_overdue' => 'Capitazione scaduta',
        'levy_upcoming' => 'Canone in prossima scadenza',
        'levy_overdue' => 'Canone non pagato',
        'administrative' => 'Comunicazioni amministrative',
        'technical' => 'Alert tecnici piattaforma',
    ],
    'roles' => [
        'administrator' => ['cap_upcoming', 'cap_overdue', 'levy_upcoming', 'levy_overdue', 'administrative'],
        'treasurer' => ['cap_upcoming', 'cap_overdue', 'levy_upcoming', 'levy_overdue', 'administrative'],
        'brother' => ['cap_upcoming', 'cap_overdue', 'administrative'],
        'secretary' => ['cap_upcoming', 'cap_overdue', 'administrative'],
        'worshipful_master' => ['cap_upcoming', 'cap_overdue', 'levy_upcoming', 'levy_overdue', 'administrative'],
    ],
];
