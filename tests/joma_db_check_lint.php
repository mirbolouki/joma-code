<?php
// Development-only parser check. Does not include/run the diagnostic or connect to a database.
$root = dirname(__DIR__);
foreach (['deploy/joma-db-check/joma-db-check.php', 'deploy/joma-db-check/joma-db-check-config.example.php'] as $relative) {
    token_get_all(file_get_contents($root . '/' . $relative), TOKEN_PARSE);
    echo 'PARSE OK: ' . $relative . PHP_EOL;
}
echo 'PHP ' . PHP_VERSION . '; database runtime tests NOT RUN' . PHP_EOL;
