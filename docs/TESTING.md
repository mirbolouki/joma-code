# Clinic SMS extension test record

Source baseline: clinic-v2-upload.zip in this repository. Working source: clinic-app/.

Run `npm ci --prefix tools`, then `npm test --prefix tools` (development only; do not upload tools/tests).
PHP WASM provides a reproducible PHP 8.3 runner where native PHP is unavailable.

Executed with PHP 8.3.33 and PHP 8.5.10:
- All 58 PHP files parsed with TOKEN_PARSE.
- 42 contract/logic checks: configured state, provider JSON contract, TLS options, provider and malformed-response failures, 25-char template limit, 24-char invite parameter, OTP hashing, single-use, expiry, purpose/invite binding, five-attempt lockout, session-independent throttling, account reuse, password hashing/reset, no staff takeover, inactive account rejection, no reset-created accounts, invitation doctor assignment, mobile binding and conflicting account/doctor rejection.
- 10 actual clinic phone normalization checks.

Transport and database functions in sms_auth.php are TEST DOUBLES. These are not real SMS.ir sends, MySQL integration tests, delivery confirmations, or browser E2E tests. Native MySQL was unavailable in this sandbox. There is no live-provider key in this repository. Do not claim production readiness from these tests.

Deployment acceptance required: Apache/LiteSpeed directory protection, PHP HTTPS detection and secure cookie, actual MySQL transactions and atomic file sequence, mail/SMS service connectivity, approved templates, actual provider POST form support, scoped roles, real mobile OTP flows, session invalidation, existing invite migration behavior, backup/restore.

Deferred: test-site integration, payment/release entitlements, exercise downloads, timed reminders, automatic invitation on appointment confirmation, form builder/drafts, provider delivery polling. Outbound navigation links only are included for test/joma.
