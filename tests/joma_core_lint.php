<?php
declare(strict_types=1);
// Parser only. Never includes the diagnostic or executes any DB/HTTP command.
foreach (['joma-core/domain_rules.php','joma-core/db.php','joma-core/session.php','joma-core/auth.php','joma-core/context.php','joma-core/acceptance.php','joma-core/hold.php','joma-core/portal.php','joma-core/receipt.php','joma-core/audit.php','joma-core/files.php','joma-core/http.php','joma-core/rate_limit.php','tests/joma_domain_rules.php','tests/joma_core_adapters.php','tests/joma_core_acceptance.php','tests/joma_core_hold.php','tests/joma_core_portal.php','tests/joma_core_receipt_audit.php','tests/joma_core_files.php','tests/joma_core_e2e.php','tests/joma_core_lint.php'] as $path) {
    token_get_all(file_get_contents(dirname(__DIR__).'/'.$path), TOKEN_PARSE);
    echo "PARSE OK: $path\n";
}
echo 'PHP '.PHP_VERSION.PHP_EOL;
