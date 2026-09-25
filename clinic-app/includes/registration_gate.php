<?php
if (!empty($_SERVER['SCRIPT_FILENAME'])) {
    $self = str_replace('\\', '/', __FILE__);
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME']);
    if ($self === $script) {
        http_response_code(403);
        exit;
    }
}

/**
 * JOMA Registration Security Gate — hotfix موقت برای محدود کردن ثبت‌نام عمومی.
 *
 * این فایل یک Registration Gate مستقل است.
 * منطق اصلی ساخت کاربر (validate_registration / create_user) را بازنویسی نمی‌کند.
 *
 * ------------------------------------------------------------------
 * فلگ و کد — تنها منبع (Source of Truth)
 * ------------------------------------------------------------------
 * غیرفعال‌سازی: مقدار $JOMA_REGISTRATION_SECURITY_CODE_ENABLED را false کنید.
 * نیازی به Database یا تغییر config.php نیست.
 * حذف کامل: این فایل را پاک کنید؛ ثبت‌نام مثل قبل کار می‌کند.
 */

if (!isset($JOMA_REGISTRATION_SECURITY_CODE_ENABLED)) {
    $JOMA_REGISTRATION_SECURITY_CODE_ENABLED = true;
}
if (!isset($JOMA_REGISTRATION_SECURITY_CODE)) {
    $JOMA_REGISTRATION_SECURITY_CODE = 'J0m@14O5';
}

$GLOBALS['JOMA_REGISTRATION_SECURITY_CODE_ENABLED'] = $JOMA_REGISTRATION_SECURITY_CODE_ENABLED;
$GLOBALS['JOMA_REGISTRATION_SECURITY_CODE'] = $JOMA_REGISTRATION_SECURITY_CODE;

function joma_registration_security_code_enabled() {
    if (isset($GLOBALS['JOMA_CONFIG']) && is_array($GLOBALS['JOMA_CONFIG'])
        && array_key_exists('registration_security_code_enabled', $GLOBALS['JOMA_CONFIG'])) {
        return (bool) $GLOBALS['JOMA_CONFIG']['registration_security_code_enabled'];
    }
    if (isset($GLOBALS['JOMA_REGISTRATION_SECURITY_CODE_ENABLED'])) {
        return (bool) $GLOBALS['JOMA_REGISTRATION_SECURITY_CODE_ENABLED'];
    }
    return true;
}

function joma_registration_security_code() {
    if (isset($GLOBALS['JOMA_CONFIG']) && is_array($GLOBALS['JOMA_CONFIG'])
        && !empty($GLOBALS['JOMA_CONFIG']['registration_security_code'])) {
        return (string) $GLOBALS['JOMA_CONFIG']['registration_security_code'];
    }
    if (isset($GLOBALS['JOMA_REGISTRATION_SECURITY_CODE']) && $GLOBALS['JOMA_REGISTRATION_SECURITY_CODE'] !== '') {
        return (string) $GLOBALS['JOMA_REGISTRATION_SECURITY_CODE'];
    }
    return 'J0m@14O5';
}

function joma_registration_gate_posted_code() {
    if (!isset($_POST['security_code'])) return null;
    if (!is_string($_POST['security_code'])) return '';
    return $_POST['security_code'];
}

function joma_registration_gate_equals($expected, $got) {
    $expected = (string) $expected;
    $got = (string) $got;
    if (function_exists('hash_equals')) {
        return hash_equals($expected, $got);
    }
    if (strlen($expected) !== strlen($got)) return false;
    $r = 0;
    $n = strlen($expected);
    for ($i = 0; $i < $n; $i++) {
        $r |= ord($expected[$i]) ^ ord($got[$i]);
    }
    return $r === 0;
}

/**
 * اگر Feature خاموش باشد: رشته خالی (عبور).
 * اگر کد خالی/غایب باشد: پیام الزام.
 * اگر کد نادرست باشد: پیام خطا — بدون افشای کد صحیح.
 */
function joma_registration_gate_check($posted_code) {
    if (!joma_registration_security_code_enabled()) {
        return '';
    }
    if ($posted_code === null || !is_string($posted_code) || trim($posted_code) === '') {
        return 'لطفاً کد امنیتی را وارد کنید.';
    }
    if (!joma_registration_gate_equals(joma_registration_security_code(), trim($posted_code))) {
        return 'کد امنیتی صحیح نیست.';
    }
    return '';
}

function joma_registration_gate_ui($old_code = '') {
    if (!joma_registration_security_code_enabled()) {
        return '';
    }
    $val = is_string($old_code) ? $old_code : '';
    ob_start();
    ?>
<style>
.joma-reg-gate{margin:16px 0 8px}
.joma-reg-gate-row{display:flex;gap:10px;align-items:stretch;flex-wrap:wrap}
.joma-reg-gate-row input{flex:1;min-width:160px}
.joma-reg-gate-row .btn{flex:0 0 auto;white-space:nowrap;box-shadow:none}
.joma-reg-gate-overlay{position:fixed;inset:0;z-index:80;background:rgba(40,30,55,.45);display:flex;align-items:center;justify-content:center;padding:20px}
.joma-reg-gate-overlay[hidden]{display:none}
.joma-reg-gate-dialog{width:min(440px,100%);background:#fff;border-radius:1.6rem;padding:22px 20px;box-shadow:0 16px 48px rgba(92,70,130,.18)}
.joma-reg-gate-dialog h2{margin:0 0 10px;font-size:20px}
.joma-reg-gate-dialog p{margin:0 0 10px;color:var(--fg)}
.joma-reg-gate-contacts{background:#f6f1ea;border-radius:16px;padding:12px 14px;margin:12px 0;font-weight:700}
.joma-reg-gate-contacts div{margin:4px 0}
.joma-reg-gate-contacts span{font-weight:500;color:var(--muted);margin-left:8px}
</style>
<div class="joma-reg-gate">
  <label for="joma-security-code">کد امنیتی</label>
  <div class="joma-reg-gate-row">
    <input id="joma-security-code" name="security_code" type="password" dir="ltr" autocomplete="off" value="<?php echo e($val); ?>">
    <button type="button" class="btn sec" data-joma-reg-gate-open>درخواست کد امنیتی</button>
  </div>
</div>
<div class="joma-reg-gate-overlay" id="joma-reg-gate-modal" hidden>
  <div class="joma-reg-gate-dialog" role="dialog" aria-modal="true" aria-labelledby="joma-reg-gate-title">
    <h2 id="joma-reg-gate-title">کد امنیتی</h2>
    <p>برای دریافت کد امنیتی، لطفاً با پشتیبانی تماس بگیرید.</p>
    <div class="joma-reg-gate-contacts">
      <div><span>پیامک:</span> <b dir="ltr">09967979471</b></div>
      <div><span>پیام‌رسان بله:</span> <b dir="ltr">09967979471</b></div>
    </div>
    <p>کد امنیتی دریافت‌شده را در فرم ثبت‌نام وارد کنید.</p>
    <p><button type="button" class="btn btn-block" data-joma-reg-gate-close>بستن</button></p>
  </div>
</div>
<script>
(function () {
  var openBtn = document.querySelector('[data-joma-reg-gate-open]');
  var modal = document.getElementById('joma-reg-gate-modal');
  if (!openBtn || !modal) return;
  function show() { modal.hidden = false; }
  function hide() { modal.hidden = true; }
  openBtn.addEventListener('click', function (ev) { ev.preventDefault(); show(); });
  modal.addEventListener('click', function (ev) { if (ev.target === modal) hide(); });
  var closeBtn = modal.querySelector('[data-joma-reg-gate-close]');
  if (closeBtn) closeBtn.addEventListener('click', function (ev) { ev.preventDefault(); hide(); });
  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape' && !modal.hidden) hide();
  });
})();
</script>
    <?php
    return ob_get_clean();
}
