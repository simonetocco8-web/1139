<?php

return [
    'roles' => ['administrator' => 'Amministratore di loggia', 'treasurer' => 'Tesoriere', 'brother' => 'Fratello'],
    'permissions' => ['read' => 'Lettura', 'create' => 'Creazione', 'update' => 'Modifica', 'reverse' => 'Storno', 'export' => 'Esportazione', 'administration' => 'Amministrazione', 'reports' => 'Report'],
    'events' => [
        'treasury_account_created' => 'Conto o cassa aperto', 'treasury_category_created' => 'Categoria finanziaria inserita', 'treasury_posted' => 'Operazione finanziaria contabilizzata', 'treasury_reversed' => 'Operazione finanziaria stornata', 'treasury_statement_created' => 'Riga bancaria registrata', 'treasury_reconciled' => 'Movimento bancario riconciliato', 'treasury_reconciliation_released' => 'Abbinamento bancario rilasciato', 'treasury_import_confirmed' => 'Estratto conto importato', 'treasury_attachment_added' => 'Allegato finanziario salvato', 'treasury_attachment_downloaded' => 'Allegato finanziario scaricato', 'treasury_exported' => 'Registro finanziario esportato',
        'levy_program_created' => 'Programma canone inserito', 'levy_condition_created' => 'Condizione generale canone inserita', 'levy_override_created' => 'Deroga locale canone inserita', 'levy_generated' => 'Obblighi canoni generati', 'levy_payment_posted' => 'Pagamento canone confermato dalla Tesoreria',
        'capitation_plan_created' => 'Piano di capitazione inserito', 'capitation_plan_revised' => 'Condizioni future del piano modificate', 'capitation_rule_created' => 'Condizione economica individuale inserita', 'capitation_generated' => 'Rate capitazioni generate', 'capitation_received' => 'Incasso capitazioni confermato dalla Tesoreria', 'capitation_allocated' => 'Credito imputato alle rate', 'capitation_corrected' => 'Rettifica capitazione registrata', 'capitation_allocation_reversed' => 'Imputazione capitazione annullata',
        'tenant_selected' => 'Ambiente selezionato', 'roles_updated' => 'Autorizzazioni locali aggiornate',
        'invitation_created' => 'Invito predisposto', 'invitation_accepted' => 'Invito accettato', 'invitation_revoked' => 'Invito revocato', 'invitation_delivery_failed' => 'Invio invito non confermato',
        'assistance_requested' => 'Assistenza richiesta', 'assistance_approved' => 'Assistenza approvata', 'assistance_revoked' => 'Assistenza revocata', 'assistance_used' => 'Accesso di assistenza utilizzato',
        'membership_created' => 'Persona e iscrizione inserite', 'membership_person_updated' => 'Anagrafica modificata', 'membership_enrollment_created' => 'Iscrizione inserita', 'membership_enrollment_updated' => 'Iscrizione modificata', 'membership_account_linked' => 'Account associato', 'membership_settings_updated' => 'Impostazioni loggia modificate', 'membership_import_confirmed' => 'Importazione iscritti confermata', 'membership_exported' => 'Iscrizioni esportate',
        'access_exported' => 'Elenco accessi esportato',
    ],
];
