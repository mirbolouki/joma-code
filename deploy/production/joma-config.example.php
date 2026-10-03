<?php
declare(strict_types=1);

/**
 * JOMA Production Configuration — my.mirbolouki.com
 * Put your actual database credentials and seed token here.
 */

$joma_config = [
    'host'     => 'localhost',
    'port'     => 3306,
    'database' => 'mirbolouki_clinic',
    'username' => 'mirbolouki_clinicusr',
    'password' => '', // کلمه عبور دیتابیس را اینجا وارد کنید
    'charset'  => 'utf8mb4',
    'token'    => 'change_me_to_a_secure_seed_token_123456789', // توکن امن جهت راه‌اندازی اولیه (حداقل ۱۶ کاراکتر)
];
