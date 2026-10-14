<?php

require __DIR__.'/../vendor/autoload.php';

// RefreshDatabase is destructive: never target an operational database.
$database = getenv('DB_DATABASE') ?: 'organization_test';
if (! str_ends_with($database, '_test')) {
    throw new LogicException('I test MySQL richiedono un database dedicato con nome terminante in _test.');
}
