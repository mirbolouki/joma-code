<?php
declare(strict_types=1);

/**
 * JOMA Master Gateway & Role-Based Router
 */
require_once __DIR__ . '/includes/app_bootstrap.php';
joma_require_auth();

$roleCode = $_SESSION['joma_demo_user']['role_code'] ?? 'secretary';

// Redirect based on role
switch ($roleCode) {
    case 'therapist':
        header('Location: /therapist/index.php');
        exit;
    case 'admin':
        header('Location: /settings/index.php');
        exit;
    case 'patient':
        header('Location: /portal/index.php');
        exit;
    case 'secretary':
    default:
        header('Location: /reception/index.php');
        exit;
}
