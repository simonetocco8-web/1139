<?php

return [
    'roles' => ['administrator' => 'Amministratore di loggia', 'treasurer' => 'Tesoriere', 'brother' => 'Fratello'],
    'permissions' => ['read' => 'Lettura', 'create' => 'Creazione', 'update' => 'Modifica', 'reverse' => 'Storno', 'export' => 'Esportazione', 'administration' => 'Amministrazione', 'reports' => 'Report'],
    'events' => [
        'capitation_plan_created' => 'Piano di capitazione inserito', 'capitation_plan_revised' => 'Condizioni future del piano modificate', 'capitation_rule_created' => 'Condizione economica individuale inserita', 'capitation_generated' => 'Rate capitazioni generate', 'capitation_received' => 'Incasso capitazioni confermato dalla Tesoreria', 'capitation_allocated' => 'Credito imputato alle rate', 'capitation_corrected' => 'Rettifica capitazione registrata', 'capitation_allocation_reversed' => 'Imputazione capitazione annullata',
        'tenant_selected' => 'Ambiente selezionato', 'roles_updated' => 'Autorizzazioni locali aggiornate',
        'invitation_created' => 'Invito predisposto', 'invitation_accepted' => 'Invito accettato', 'invitation_revoked' => 'Invito revocato', 'invitation_delivery_failed' => 'Invio invito non confermato',
        'assistance_requested' => 'Assistenza richiesta', 'assistance_approved' => 'Assistenza approvata', 'assistance_revoked' => 'Assistenza revocata', 'assistance_used' => 'Accesso di assistenza utilizzato',
        'membership_created' => 'Persona e iscrizione inserite', 'membership_person_updated' => 'Anagrafica modificata', 'membership_enrollment_created' => 'Iscrizione inserita', 'membership_enrollment_updated' => 'Iscrizione modificata', 'membership_account_linked' => 'Account associato', 'membership_settings_updated' => 'Impostazioni loggia modificate', 'membership_import_confirmed' => 'Importazione iscritti confermata', 'membership_exported' => 'Iscrizioni esportate',
        'access_exported' => 'Elenco accessi esportato',
    ],
];
