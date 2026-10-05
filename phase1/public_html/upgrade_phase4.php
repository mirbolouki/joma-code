<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — ارتقا به فاز ۴ (فرم‌های بالینی و پورتال مراجع)
 *
 *  قواعد ایمنی این فایل — همان قواعد ارتقای فاز ۲ و ۳:
 *    • فقط «مدیرِ واردشده به سامانه» می‌تواند آن را اجرا کند.
 *    • اجرا فقط با POST و توکن CSRF انجام می‌شود.
 *    • هیچ جدول یا ستونی از فازهای پیشین حذف یا بازسازی نمی‌شود.
 *      تنها تغییر در جدول‌های قدیمی، افزودن یک مقدار به ENUM ستون
 *      otp_codes.purpose است که افزایشی و بی‌خطر است.
 *    • اجرای دوباره چیزی را تکرار نمی‌کند (Idempotent).
 *    • نسخهٔ Migration فقط پس از راستی‌آزمایی همهٔ گام‌ها ثبت می‌شود.
 *
 *  در MySQL دستورهای DDL تراکنش‌پذیر نیستند؛ روش بازگشت کامل =
 *  بازگرداندن پشتیبان. پیش از اجرا از phpMyAdmin ← Export پشتیبان بگیرید.
 *
 *  پس از دیدن پیام موفقیت و بررسی پاورقی، این فایل را از هاست حذف کنید.
 * ═══════════════════════════════════════════════════════════════════ */

require_once __DIR__ . '/includes/bootstrap.php';

define('PHASE4_MIGRATION_VERSION', '4.0.0');

$db = $GLOBALS['db'];

auth_require_active_session($db);
require_role(array(ROLE_ADMIN));

$actor_person_id = isset($_SESSION['person_id']) ? (int)$_SESSION['person_id'] : null;
$actor_role_code = isset($_SESSION['active_role_code']) ? $_SESSION['active_role_code'] : ROLE_ADMIN;

$schema_path = __DIR__ . '/database/phase4_schema.sql';
$new_tables = array('form_templates', 'form_fields', 'form_assignments',
                    'form_submissions', 'form_submission_values', 'form_draft_saves');
$required_tables = array('migrations', 'persons', 'accounts', 'role_assignments', 'otp_codes',
                         'lookup_lists', 'lookup_items', 'service_types', 'admissions',
                         'clinical_cases', 'audit_log', 'rooms', 'appointments',
                         'appointment_holds', 'confidential_notes');

/* ── ابزارها ─────────────────────────────────────────────────────── */

function up4_table_exists($db, $name)
{
    $res = @mysqli_query($db, "SHOW TABLES LIKE '" . mysqli_real_escape_string($db, $name) . "'");
    if (!$res) {
        return false;
    }
    $exists = (mysqli_num_rows($res) > 0);
    mysqli_free_result($res);
    return $exists;
}

/** آیا ستون otp_codes.purpose مقدار PATIENT_LOGIN را می‌پذیرد؟ */
function up4_otp_purpose_ready($db)
{
    $res = @mysqli_query($db, "SHOW COLUMNS FROM otp_codes LIKE 'purpose'");
    if (!$res) {
        return false;
    }
    $row = mysqli_fetch_assoc($res);
    mysqli_free_result($res);
    if (!$row || !isset($row['Type'])) {
        return false;
    }
    return (strpos($row['Type'], 'PATIENT_LOGIN') !== false);
}

function up4_parse_schema($path)
{
    $sql = @file_get_contents($path);
    if ($sql === false) {
        throw new Exception('فایل database/phase4_schema.sql پیدا نشد.');
    }
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    $parts = explode(';', $sql);
    $out = array();
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }
        if (preg_match('/^CREATE\s+TABLE\s+`?([A-Za-z0-9_]+)`?/i', $part, $m)) {
            $out[] = array('table' => $m[1], 'sql' => $part);
        }
    }
    return $out;
}

function up4_ensure_list($db, $list_code, $list_title)
{
    $row = db_select_one($db, "SELECT id FROM lookup_lists WHERE list_code = ?", 's', array($list_code));
    if ($row) {
        return (int)$row['id'];
    }
    $res = db_execute($db, "INSERT INTO lookup_lists (list_code, list_title) VALUES (?,?)",
        'ss', array($list_code, $list_title));
    return (int)$res['insert_id'];
}

function up4_ensure_item($db, $list_id, $item_code, $label, $order)
{
    $row = db_select_one($db,
        "SELECT id FROM lookup_items WHERE lookup_list_id = ? AND item_code = ?",
        'is', array((int)$list_id, $item_code));
    if ($row) {
        return false;
    }
    db_execute($db,
        "INSERT INTO lookup_items (lookup_list_id, item_code, label, display_order, is_active)
         VALUES (?,?,?,?,1)",
        'issi', array((int)$list_id, $item_code, $label, (int)$order));
    return true;
}

/** گزینه‌های یک فیلد از برچسب‌های فارسی و کدهای پایدار */
function up4_options($pairs)
{
    $out = array();
    foreach ($pairs as $p) {
        $out[] = array('code' => $p[0], 'label' => $p[1]);
    }
    return json_encode($out, JSON_UNESCAPED_UNICODE);
}

/* ── دادهٔ مرجع ──────────────────────────────────────────────────── */
$seed_lists = array(
    array(
        'code'  => 'form_retraction_reason',
        'title' => 'دلیل ابطال پاسخ فرم',
        'items' => array(
            array('fr_patient_request', 'درخواست مراجع', 10),
            array('fr_wrong_entry',     'ثبت اشتباه یا فرم نادرست', 20),
            array('fr_duplicate',       'پاسخ تکراری', 30),
            array('fr_clinical',        'تصمیم بالینی درمانگر', 40),
            array('fr_legal',           'علت قانونی', 50),
        ),
    ),
);

/* ── قالب پذیرش اولیه (ADR-008 / تصمیم D4-1) ─────────────────────── */
$intake_fields = array(
    array('marital_status', 'وضعیت تأهل', 'SINGLE_CHOICE', 1, up4_options(array(
        array('single', 'مجرد'), array('married', 'متأهل'),
        array('separated', 'جداشده'), array('widowed', 'همسرفوت‌شده'))), null, null, null),
    array('education_level', 'بالاترین مدرک تحصیلی', 'SINGLE_CHOICE', 1, up4_options(array(
        array('below_diploma', 'زیر دیپلم'), array('diploma', 'دیپلم'),
        array('associate', 'کاردانی'), array('bachelor', 'کارشناسی'),
        array('master_plus', 'کارشناسی ارشد و بالاتر'))), null, null, null),
    array('employment_status', 'وضعیت اشتغال', 'SINGLE_CHOICE', 1, up4_options(array(
        array('employed', 'شاغل'), array('unemployed', 'بیکار'),
        array('student', 'دانشجو'), array('homemaker', 'خانه‌دار'),
        array('retired', 'بازنشسته'))), null, null, null),
    array('living_with', 'با چه کسانی زندگی می‌کنید؟', 'MULTI_CHOICE', 1, up4_options(array(
        array('alone', 'تنها'), array('spouse', 'همسر'),
        array('children', 'فرزندان'), array('parents', 'والدین'),
        array('relatives', 'سایر بستگان'))), null, null, null),
    array('prior_therapy', 'سابقهٔ دریافت خدمات روان‌شناختی', 'YES_NO', 1, null, null, null, null),
    array('current_medication', 'در حال حاضر دارویی مصرف می‌کنید؟', 'YES_NO', 1, null, null, null, null),
    array('psychiatric_admission', 'سابقهٔ بستری به دلایل روان‌پزشکی', 'YES_NO', 1, null, null, null, null),
    array('distress_level', 'شدت ناراحتی فعلی', 'SCALE', 1, null, 0, 10, null),
    array('problem_duration', 'مدت زمان درگیری با مشکل فعلی', 'SINGLE_CHOICE', 1, up4_options(array(
        array('lt_1m', 'کمتر از یک ماه'), array('1_6m', '۱ تا ۶ ماه'),
        array('6_12m', '۶ تا ۱۲ ماه'), array('gt_1y', 'بیش از یک سال'))), null, null, null),
    array('referral_source', 'چگونه با کلینیک آشنا شدید؟', 'SINGLE_CHOICE', 0, up4_options(array(
        array('physician', 'پزشک'), array('acquaintance', 'آشنایان'),
        array('internet', 'اینترنت'), array('social', 'شبکه‌های اجتماعی'),
        array('other', 'سایر'))), null, null, null),
);

/* ── وضعیت فعلی ──────────────────────────────────────────────────── */
$already_applied = false;
try {
    $already_applied = migration_applied($db, PHASE4_MIGRATION_VERSION);
} catch (Exception $ex) {
    $already_applied = false;
}

$status_tables = array();
foreach ($new_tables as $t) {
    $status_tables[$t] = up4_table_exists($db, $t);
}
$missing_required = array();
foreach ($required_tables as $t) {
    if (!up4_table_exists($db, $t)) {
        $missing_required[] = $t;
    }
}

$log = array();
$failed = false;
$done = false;

/* ── اجرا ────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    try {
        if (count($missing_required) > 0) {
            throw new Exception('پیش‌نیازهای فازهای ۱ تا ۳ کامل نیستند. جدول‌های غایب: '
                . implode('، ', $missing_required)
                . '. ابتدا ارتقای فاز ۳ را کامل کنید.');
        }

        /* گام ۱ — ساخت شش جدول */
        $statements = up4_parse_schema($schema_path);
        if (count($statements) !== count($new_tables)) {
            throw new Exception('فایل اسکیما ناقص است؛ ' . count($new_tables)
                . ' جدول انتظار می‌رفت و ' . count($statements) . ' مورد پیدا شد.');
        }
        foreach ($statements as $st) {
            if (up4_table_exists($db, $st['table'])) {
                $log[] = array('ok', 'جدول «' . $st['table'] . '» از قبل وجود داشت — بدون تغییر.');
                continue;
            }
            if (!@mysqli_query($db, $st['sql'])) {
                throw new Exception('ساخت جدول «' . $st['table'] . '» ناموفق بود: '
                    . mysqli_error($db));
            }
            $log[] = array('new', 'جدول «' . $st['table'] . '» ساخته شد.');
        }

        /* گام ۲ — افزودن PATIENT_LOGIN به ENUM کاربردهای کد یک‌بارمصرف */
        if (up4_otp_purpose_ready($db)) {
            $log[] = array('ok', 'ستون otp_codes.purpose از قبل مقدار PATIENT_LOGIN را داشت.');
        } else {
            $alter = "ALTER TABLE otp_codes MODIFY purpose "
                . "ENUM('REGISTER','RESET_PASSWORD','CONFIRM_MOBILE_CHANGE','PATIENT_LOGIN') NOT NULL";
            if (!@mysqli_query($db, $alter)) {
                throw new Exception('افزودن مقدار PATIENT_LOGIN به ستون otp_codes.purpose ناموفق بود: '
                    . mysqli_error($db));
            }
            $log[] = array('new', 'مقدار PATIENT_LOGIN به ستون otp_codes.purpose افزوده شد.');
        }

        /* گام ۳ — دادهٔ مرجع */
        foreach ($seed_lists as $list) {
            $list_id = up4_ensure_list($db, $list['code'], $list['title']);
            $added = 0;
            foreach ($list['items'] as $item) {
                if (up4_ensure_item($db, $list_id, $item[0], $item[1], $item[2])) {
                    $added++;
                }
            }
            $log[] = array($added > 0 ? 'new' : 'ok',
                'فهرست «' . $list['title'] . '»: ' . to_persian_digits($added)
                . ' گزینهٔ تازه افزوده شد.');
        }

        /* گام ۴ — قالب پذیرش اولیه */
        $existing_tpl = db_select_one($db,
            "SELECT id, status FROM form_templates WHERE template_code = ? ORDER BY version DESC LIMIT 1",
            's', array(FORM_INTAKE_CODE));
        if ($existing_tpl) {
            $log[] = array('ok', 'قالب «فرم پذیرش اولیه» از قبل وجود داشت — دست‌نخورده ماند.');
        } else {
            if ($actor_person_id === null) {
                throw new Exception('شناسهٔ مدیر در نشست یافت نشد.');
            }
            $now = now_dt();
            $res = db_execute($db,
                "INSERT INTO form_templates
                   (public_id, template_code, version, title, description,
                    default_assignee_role, admin_can_view, patient_can_have_draft,
                    status, created_by_person_id, created_at)
                 VALUES (?,?,1,?,?,'PATIENT',1,1,'ACTIVE',?,?)",
                'ssssis',
                array(generate_public_id('ft'), FORM_INTAKE_CODE,
                      'فرم پذیرش اولیه',
                      'پرسش‌های پایه که پیش از نخستین جلسه از مراجع پرسیده می‌شود.',
                      (int)$actor_person_id, $now));
            $tpl_id = (int)$res['insert_id'];

            $order = 10;
            foreach ($intake_fields as $f) {
                db_execute($db,
                    "INSERT INTO form_fields
                       (public_id, template_id, field_code, label, help_text, field_type,
                        is_required, display_order, options_text, min_value, max_value,
                        max_length, is_active, created_at)
                     VALUES (?,?,?,?,NULL,?,?,?,?,?,?,?,1,?)",
                    'sisssiisddis',
                    array(generate_public_id('ff'), $tpl_id, $f[0], $f[1], $f[2],
                          (int)$f[3], $order, $f[4], $f[5], $f[6], $f[7], $now));
                $order += 10;
            }

            audit_log_write($db, $actor_person_id, $actor_role_code, 'FORM_TEMPLATE_CREATED',
                'form_template', $tpl_id,
                array('template_code' => FORM_INTAKE_CODE, 'seed' => true,
                      'field_count' => count($intake_fields)));
            audit_log_write($db, $actor_person_id, $actor_role_code, 'FORM_TEMPLATE_ACTIVATED',
                'form_template', $tpl_id,
                array('template_code' => FORM_INTAKE_CODE, 'version' => 1));

            $log[] = array('new', 'قالب «فرم پذیرش اولیه» با '
                . to_persian_digits(count($intake_fields)) . ' پرسش ساخته و فعال شد.');
        }

        /* گام ۵ — راستی‌آزمایی */
        foreach ($new_tables as $t) {
            if (!up4_table_exists($db, $t)) {
                throw new Exception('راستی‌آزمایی ناموفق: جدول «' . $t . '» ساخته نشده است.');
            }
        }
        if (!up4_otp_purpose_ready($db)) {
            throw new Exception('راستی‌آزمایی ناموفق: ستون otp_codes.purpose مقدار PATIENT_LOGIN را نمی‌پذیرد.');
        }
        foreach ($seed_lists as $list) {
            foreach ($list['items'] as $item) {
                $row = db_select_one($db,
                    "SELECT li.id FROM lookup_items li
                     INNER JOIN lookup_lists ll ON ll.id = li.lookup_list_id
                     WHERE ll.list_code = ? AND li.item_code = ?",
                    'ss', array($list['code'], $item[0]));
                if (!$row) {
                    throw new Exception('راستی‌آزمایی ناموفق: گزینهٔ مرجع «' . $item[1] . '» ثبت نشد.');
                }
            }
        }
        $check_tpl = db_select_one($db,
            "SELECT t.id,
                    (SELECT COUNT(*) FROM form_fields f WHERE f.template_id = t.id AND f.is_active = 1) AS c
               FROM form_templates t
              WHERE t.template_code = ? AND t.status = 'ACTIVE' LIMIT 1",
            's', array(FORM_INTAKE_CODE));
        if (!$check_tpl || (int)$check_tpl['c'] < count($intake_fields)) {
            throw new Exception('راستی‌آزمایی ناموفق: قالب پذیرش اولیه کامل ساخته نشد.');
        }
        $log[] = array('ok', 'راستی‌آزمایی جدول‌ها، داده‌های مرجع و قالب پذیرش اولیه موفق بود.');

        /* گام ۶ — ثبت نسخه */
        if (!migration_applied($db, PHASE4_MIGRATION_VERSION)) {
            db_execute($db, "INSERT INTO migrations (version, applied_at) VALUES (?,?)",
                'ss', array(PHASE4_MIGRATION_VERSION, now_dt()));
            $log[] = array('new', 'نسخهٔ ' . PHASE4_MIGRATION_VERSION . ' در جدول migrations ثبت شد.');
        } else {
            $log[] = array('ok', 'نسخهٔ ' . PHASE4_MIGRATION_VERSION . ' از قبل ثبت شده بود.');
        }

        audit_log_write($db, $actor_person_id, $actor_role_code, 'MIGRATION_APPLIED',
            'migration', null, array('version' => PHASE4_MIGRATION_VERSION));

        $done = true;
        $already_applied = true;
    } catch (Exception $ex) {
        $failed = true;
        $log[] = array('fail', $ex->getMessage());
        log_system_error('PHASE4_UPGRADE', $ex);
    }

    foreach ($new_tables as $t) {
        $status_tables[$t] = up4_table_exists($db, $t);
    }
}

$page_title = 'ارتقا به فاز ۴';
$layout = 'auth';
$wide_card = true;
$brand_subtitle = 'ارتقای سامانه به فاز ۴ — فرم‌ها و پورتال مراجع';
require __DIR__ . '/templates/header.php';
?>
<h2>🧾 ارتقا به فاز ۴ — فرم‌های بالینی و پورتال مراجع</h2>

<?php if ($done) { ?>
  <div class="alert alert-success">
    <strong>✅ ارتقا با موفقیت انجام شد.</strong><br>
    اکنون در منوی مدیر «قالب‌های فرم» و در صفحهٔ ورود، تب «مراجع» فعال است.<br>
    نخست پاورقی صفحه را ببینید؛ باید «نسخهٔ ۴.۰.۰ (فاز ۴ — فرم‌ها و پورتال مراجع)»
    نوشته شده باشد. پس از آن فایل <code>upgrade_phase4.php</code> را از هاست حذف کنید.
  </div>
<?php } elseif ($failed) { ?>
  <div class="alert alert-danger">
    <strong>⚠️ ارتقا کامل نشد.</strong><br>
    پیام خطا را در گزارش زیر ببینید. گام‌هایی که پیش‌تر انجام شده‌اند دوباره اجرا
    نمی‌شوند؛ پس از رفع ایراد، کافی است دوباره دکمهٔ ارتقا را بزنید.
  </div>
<?php } elseif ($already_applied) { ?>
  <div class="alert alert-info">
    ℹ️ نسخهٔ <?php echo e(PHASE4_MIGRATION_VERSION); ?> پیش‌تر روی این پایگاه داده اجرا شده است.
    اجرای دوباره بی‌خطر است.
  </div>
<?php } ?>

<?php if (count($missing_required) > 0) { ?>
  <div class="alert alert-danger">
    پیش‌نیازها کامل نیستند. جدول‌های غایب: <?php echo e(implode('، ', $missing_required)); ?>
  </div>
<?php } ?>

<div class="card">
  <div class="card-header">وضعیت جدول‌های فاز ۴</div>
  <div class="card-body">
    <?php foreach ($status_tables as $t => $exists) { ?>
      <div class="check-row">
        <span><code><?php echo e($t); ?></code></span>
        <span class="badge <?php echo $exists ? 'badge-success' : 'badge-muted'; ?>">
          <?php echo $exists ? 'موجود' : 'ساخته نشده'; ?>
        </span>
      </div>
    <?php } ?>
    <div class="check-row">
      <span><code>otp_codes.purpose = PATIENT_LOGIN</code></span>
      <span class="badge <?php echo up4_otp_purpose_ready($db) ? 'badge-success' : 'badge-muted'; ?>">
        <?php echo up4_otp_purpose_ready($db) ? 'آماده' : 'افزوده نشده'; ?>
      </span>
    </div>
  </div>
</div>

<?php if (count($log) > 0) { ?>
<div class="card">
  <div class="card-header">گزارش اجرا</div>
  <div class="card-body">
    <?php foreach ($log as $line) { ?>
      <div class="check-row">
        <span><?php echo e($line[1]); ?></span>
        <span class="badge <?php
          echo $line[0] === 'fail' ? 'badge-danger' : ($line[0] === 'new' ? 'badge-primary' : 'badge-muted'); ?>">
          <?php echo $line[0] === 'fail' ? 'خطا' : ($line[0] === 'new' ? 'انجام شد' : 'بدون تغییر'); ?>
        </span>
      </div>
    <?php } ?>
  </div>
</div>
<?php } ?>

<?php if (!$done) { ?>
<div class="card">
  <div class="card-header">پیش از زدن دکمه</div>
  <div class="card-body">
    <ul class="hint-list">
      <li>از پایگاه داده پشتیبان بگیرید (cPanel ← phpMyAdmin ← Export).</li>
      <li>شش جدول تازه ساخته می‌شود و هیچ جدول قدیمی حذف یا بازسازی نمی‌شود.</li>
      <li>تنها تغییر در جدول‌های قدیمی، افزودن مقدار <code>PATIENT_LOGIN</code> به
          ستون <code>otp_codes.purpose</code> است.</li>
      <li>پنج گزینهٔ «دلیل ابطال پاسخ فرم» افزوده می‌شود؛ بعداً قابل ویرایش است.</li>
      <li>قالب «فرم پذیرش اولیه» با ۱۰ پرسش ساخته و فعال می‌شود؛ این قالب
          پس از نخستین نوبتِ هر مراجع تازه، خودکار به او تخصیص می‌یابد.</li>
      <li>اجرای چندباره بی‌خطر است.</li>
    </ul>
    <form method="post" action="upgrade_phase4.php">
      <?php echo csrf_field(); ?>
      <button type="submit" class="btn btn-primary btn-block">🚀 اجرای ارتقا</button>
    </form>
  </div>
</div>
<?php } else { ?>
  <a class="btn btn-primary btn-block" href="<?php echo e(APP_BASE_URL); ?>/admin/forms.php">
    ➡️ رفتن به قالب‌های فرم
  </a>
<?php } ?>
<?php require __DIR__ . '/templates/footer.php'; ?>
