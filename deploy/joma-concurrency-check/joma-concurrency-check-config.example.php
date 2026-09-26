<?php
// Copy to joma-concurrency-check-config.php and fill real values. Never commit the real file.
// Keep this file outside document root if possible, or protect with .htaccess (already in deploy folder).
$joma_concurrency_config = [
    'host' => '127.0.0.1',
    'user' => 'mirbolouki_clinic',
    'pass' => 'PUT_REAL_PASSWORD_HERE',
    'name' => 'mirbolouki_clinic',
    'port' => 3306,
    // Set to true to allow the few write tests (PK duplicate, rollback, row lock). They create and delete test rows.
    'allow_write_tests' => false,
    // Required for HTTP access: set a random 32+ char token and use ?token=YOUR_TOKEN
    'token' => 'PUT_RANDOM_32_PLUS_CHAR_TOKEN_HERE',
];
