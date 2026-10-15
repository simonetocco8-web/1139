<?php

return [
    'backup_public_key' => env('BACKUP_PUBLIC_KEY_FILE'),
    'backup_directory' => env('BACKUP_DIRECTORY', storage_path('app/backups')),
    'mysqldump' => env('MYSQLDUMP_BINARY', 'mysqldump'),
    'backup_max_age_hours' => (int) env('BACKUP_MAX_AGE_HOURS', 26),
    'queue_max_age_minutes' => (int) env('QUEUE_MAX_AGE_MINUTES', 15),
    'policy_reference' => env('PRIVACY_POLICY_REFERENCE'),
    // Zero disables deletion. Configure only after the controller approves the policy.
    'technical_retention_days' => (int) env('PRIVACY_TECHNICAL_RETENTION_DAYS', 0),
    'communication_retention_days' => (int) env('PRIVACY_COMMUNICATION_RETENTION_DAYS', 0),
    'person_retention_days' => (int) env('PRIVACY_PERSON_RETENTION_DAYS', 0),
];
