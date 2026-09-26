<?php
declare(strict_types=1);

/**
 * JOMA HTTP helpers — JSON only, no HTML, no stack trace.
 * Maps internal codes to HTTP status via audit.php, sets security headers.
 */
require_once __DIR__ . '/audit.php';

function joma_http_headers(): array {
    return [
        'Content-Type' => 'application/json; charset=utf-8',
        'Cache-Control' => 'no-store',
        'Pragma' => 'no-cache',
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'Referrer-Policy' => 'no-referrer',
    ];
}

function joma_http_json(int $status, array $body): void {
    if (headers_sent()) {
        // CLI/tests: just output
        echo json_encode($body, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
        return;
    }
    http_response_code($status);
    foreach (joma_http_headers() as $k=>$v) { header("$k: $v"); }
    echo json_encode($body, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}

function joma_http_error(string $internalCode, ?string $messageFa = null): void {
    [$status,$reason] = joma_http_map($internalCode);
    $msg = $messageFa ?? joma_http_fa_message($internalCode);
    // Never expose internal stack, DB details, or existence of other cases.
    $body = ['ok'=>false,'code'=>$reason,'message'=>$msg];
    joma_http_json($status,$body);
}

function joma_http_fa_message(string $code): string {
    $map=[
        'AUTHENTICATION_REQUIRED'=>'نشست شما معتبر نیست؛ دوباره وارد شوید.',
        'CONTEXT_DENIED'=>'دسترسی شما برای این عملیات در این مرکز معتبر نیست.',
        'PERMISSION_DENIED'=>'مجوز لازم برای این عمل را ندارید.',
        'ASSIGNED_THERAPIST_REQUIRED'=>'فقط درمانگر تعیین‌شده می‌تواند این پذیرش را انجام دهد.',
        'STATE_CONFLICT'=>'وضعیت فعلی اجازهٔ این عملیات را نمی‌دهد.',
        'CAPACITY_CONFLICT'=>'ظرفیت در این بازه پر شده است؛ زمان دیگری انتخاب کنید.',
        'HOLD_CONFLICT'=>'نگه‌داشت معتبر نیست یا زمان آن تغییر کرده است.',
        'HOLD_EXPIRED_OR_NOT_STARTED'=>'مهلت نگه‌داشت تمام شده یا هنوز نرسیده است.',
        'IDEMPOTENCY_CONFLICT'=>'درخواست تکراری با محتوای متفاوت است.',
        'COMMAND_IN_PROGRESS'=>'درخواست در حال پردازش است؛ لطفاً دوباره تلاش کنید.',
        'INVALID_ID'=>'شناسهٔ نامعتبر است.',
        'INVALID_TIME'=>'زمان نامعتبر است.',
        'INVALID_TIME_WINDOW'=>'بازهٔ زمانی یا مهلت نگه‌داشت نامعتبر است.',
        'INVALID_PURPOSE'=>'شرح هدف باید ۱ تا ۵۰۰ حرف باشد.',
        'NOT_FOUND'=>'موردی یافت نشد.',
        'RESOURCE_NOT_AVAILABLE'=>'منبع در دسترس نیست.',
        'DB_ERROR'=>'خطای موقت؛ دوباره تلاش کنید.',
    ];
    return $map[$code] ?? 'خطایی رخ داد؛ دوباره تلاش کنید.';
}

function joma_http_success(array $data, int $status=200): void {
    $body=array_merge(['ok'=>true],$data);
    joma_http_json($status,$body);
}
