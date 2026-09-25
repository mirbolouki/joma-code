<?php
// Copy as joma-db-check-config.php OUTSIDE the website's document root.
// Fill locally in cPanel; never send this completed file or credentials in chat.
return [
    'enabled' => false, // Set true only during the short test window; delete afterwards.
    'access_token' => '', // A fresh random password-manager token of at least 32 characters.
    'test_database_confirmed' => false, // true only for the disposable test database below.
    'allow_rollback_tests' => false, // Optional: enable after the read-only metadata check passes.
    'db' => [
        'host' => 'localhost', // Use the database host supplied by your hosting panel.
        'port' => 3306,
        'name' => 'mirbolouki_clinic',
        'user' => '', // Full cPanel database username, not the database name unless actually equal.
        'password' => '',
    ],
];
