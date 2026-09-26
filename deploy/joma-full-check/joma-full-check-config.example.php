<?php
// Copy to joma-full-check-config.php and fill only pass + token. Do not commit real file.
$joma_full_config = [
    'host' => 'localhost',
    'user' => 'mirbolouki_clinicusr',
    'pass' => 'PUT_REAL_PASSWORD_HERE',
    'name' => 'mirbolouki_clinic',
    'port' => 3306,
    // light writes: PK/rollback/lock 2s — safe, auto-deleted
    'allow_write_tests' => true,
    // heavy: creates person/resource then deletes — also auto-deleted
    'allow_heavy_tests' => true,
    // 32+ random chars for HTTP ?token=
    'token' => 'PUT_RANDOM_32_PLUS_CHAR_TOKEN_HERE',
];
