<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — فاز ۴: تخصیص فرم به پرونده
 *
 *  لنگر تخصیص، «پذیرش» است نه «پرونده» — چون طبق ADR-008 فرم پذیرش
 *  اولیه در لحظهٔ تثبیت نخستین نوبت ساخته می‌شود و ممکن است در آن لحظه
 *  هنوز پرونده‌ای باز نشده باشد. با باز شدن پرونده، ستون clinical_case_id
 *  پر می‌شود (form_assignments_attach_case).
 *
 *  دسترسی (P7): فرم سند پرونده است. درمانگرِ مسئولِ فعلی همهٔ فرم‌های آن
 *  پرونده را می‌بیند، صرف‌نظر از اینکه چه کسی آن را پر کرده.
 * ═══════════════════════════════════════════════════════════════════ */

/** SELECT مشترک با همهٔ اتصال‌های لازم */
function form_assignment_select_sql()
{
    return "SELECT fa.*,
                   t.title AS template_title, t.description AS template_description,
                   t.template_code, t.version AS template_version,
                   t.admin_can_view, t.patient_can_have_draft, t.status AS template_status,
                   p.first_name AS patient_first_name, p.last_name AS patient_last_name,
                   p.public_id AS patient_public_id,
                   ad.public_id AS admission_public_id,
                   ad.referred_therapist_person_id,
                   cc.public_id AS case_public_id,
                   cc.responsible_therapist_person_id,
                   fs.id AS submission_id, fs.public_id AS submission_public_id,
                   fs.submitted_at, fs.submitted_by_person_id, fs.submitted_by_role_code,
                   fs.status AS submission_status, fs.edit_count, fs.current_revision,
                   fs.retracted_at
              FROM form_assignments fa
              INNER JOIN form_templates t ON t.id = fa.template_id
              INNER JOIN persons p ON p.id = fa.patient_person_id
              INNER JOIN admissions ad ON ad.id = fa.admission_id
              LEFT JOIN clinical_cases cc ON cc.id = fa.clinical_case_id
              LEFT JOIN form_submissions fs ON fs.assignment_id = fa.id";
}

function form_assignment_find_by_public_id($db, $public_id)
{
    return db_select_one($db,
        form_assignment_select_sql() . " WHERE fa.public_id = ? LIMIT 1",
        's', array((string)$public_id));
}

/**
 * درمانگرِ مسئولِ این تخصیص کیست؟
 * اگر پرونده باز شده باشد، درمانگر مسئول پرونده؛ وگرنه درمانگر ارجاع‌شدهٔ
 * پذیرش (تصمیم D4-5: فرم پذیرش پیش از پذیرفتن مسئولیت هم دیده می‌شود،
 * چون دقیقاً برای همان تصمیم ساخته شده است).
 */
function form_assignment_owner_therapist($assignment)
{
    if (!empty($assignment['responsible_therapist_person_id'])) {
        return (int)$assignment['responsible_therapist_person_id'];
    }
    return (int)$assignment['referred_therapist_person_id'];
}

/** آیا این درمانگر اجازهٔ دیدن این تخصیص را دارد؟ */
function form_assignment_therapist_can_view($assignment, $therapist_person_id)
{
    return form_assignment_owner_therapist($assignment) === (int)$therapist_person_id;
}

/**
 * یافتن تخصیص برای یک درمانگر. در صورت نبود یا نداشتن دسترسی، همان پیام
 * یکسان پرتاب و رویداد ثبت می‌شود (درس D4 فاز ۳).
 */
function form_assignment_find_for_therapist($db, $public_id, $therapist_person_id)
{
    $a = form_assignment_find_by_public_id($db, $public_id);
    if (!$a) {
        form_access_denied_log($db, $therapist_person_id, 'assignment_not_found', $public_id);
        throw new Exception(FORM_DENY_MESSAGE);
    }
    if (!form_assignment_therapist_can_view($a, $therapist_person_id)) {
        form_access_denied_log($db, $therapist_person_id, 'not_owner_therapist', $public_id,
            (int)$a['id']);
        throw new Exception(FORM_DENY_MESSAGE);
    }
    return $a;
}

/** یافتن تخصیص برای مراجع: باید مال خودش و با پرکنندهٔ «مراجع» باشد */
function form_assignment_find_for_patient($db, $public_id, $patient_person_id)
{
    $a = form_assignment_find_by_public_id($db, $public_id);
    if (!$a
        || (int)$a['patient_person_id'] !== (int)$patient_person_id
        || (int)$a['assignee_person_id'] !== (int)$patient_person_id
        || $a['assignee_role'] !== 'PATIENT') {
        form_access_denied_log($db, $patient_person_id, 'patient_scope', $public_id);
        throw new Exception(FORM_DENY_MESSAGE);
    }
    return $a;
}

function form_access_denied_log($db, $actor_person_id, $reason, $public_id, $entity_id = null)
{
    audit_log_write($db, $actor_person_id,
        isset($_SESSION['active_role_code']) ? $_SESSION['active_role_code'] : null,
        'FORM_ACCESS_DENIED', 'form_assignment', $entity_id,
        array('reason' => $reason, 'requested_public_id' => (string)$public_id));
}

/* ───────────────────────── فهرست‌ها ───────────────────────── */

/** همهٔ فرم‌های یک پذیرش (پایهٔ نمایش در صفحهٔ پرونده) */
function form_assignments_for_admission($db, $admission_id)
{
    return db_select_all($db,
        form_assignment_select_sql() . " WHERE fa.admission_id = ?
         ORDER BY FIELD(fa.status,'PENDING','SUBMITTED','RETRACTED','CANCELLED'),
                  fa.assigned_at DESC",
        'i', array((int)$admission_id));
}

/** فرم‌های در انتظارِ خودِ یک شخص (مراجع یا درمانگر) */
function form_assignments_pending_for_person($db, $person_id)
{
    return db_select_all($db,
        form_assignment_select_sql() . " WHERE fa.assignee_person_id = ? AND fa.status = 'PENDING'
         ORDER BY fa.assigned_at ASC",
        'i', array((int)$person_id));
}

/** فرم‌های ثبت‌شده یا باطل‌شدهٔ خودِ یک شخص */
function form_assignments_submitted_by_person($db, $person_id, $limit = 50)
{
    $limit = (int)$limit;
    if ($limit < 1) { $limit = 1; }
    if ($limit > 200) { $limit = 200; }
    return db_select_all($db,
        form_assignment_select_sql() . " WHERE fa.assignee_person_id = ?
           AND fa.status IN ('SUBMITTED','RETRACTED')
         ORDER BY fs.submitted_at DESC LIMIT " . $limit,
        'i', array((int)$person_id));
}

function form_assignments_pending_count($db, $person_id)
{
    $row = db_select_one($db,
        "SELECT COUNT(*) AS c FROM form_assignments WHERE assignee_person_id = ? AND status = 'PENDING'",
        'i', array((int)$person_id));
    return $row ? (int)$row['c'] : 0;
}

/** وضعیت فرم پذیرش اولیهٔ یک پذیرش — برای منشی (فقط وضعیت، بدون محتوا) */
function form_intake_status_for_admission($db, $admission_id)
{
    if (!phase4_ready($db)) {
        return null;
    }
    $row = db_select_one($db,
        "SELECT fa.status FROM form_assignments fa
          INNER JOIN form_templates t ON t.id = fa.template_id
          WHERE fa.admission_id = ? AND t.template_code = ?
          ORDER BY fa.id DESC LIMIT 1",
        'is', array((int)$admission_id, FORM_INTAKE_CODE));
    return $row ? $row['status'] : null;
}

/* ───────────────────────── ساخت و لغو ───────────────────────── */

/**
 * تخصیص دستی توسط درمانگر.
 *
 * @param string $assignee_role 'THERAPIST' یا 'PATIENT'
 * @param bool   $confirm_duplicate تأیید کاربر برای تخصیص تکراریِ در انتظار
 */
function form_assignment_create($db, $admission, $template, $assignee_role,
                                $actor_person_id, $actor_role_code, $confirm_duplicate = false)
{
    if ($template['status'] !== 'ACTIVE') {
        throw new Exception('فقط قالب‌های فعال قابل تخصیص‌اند.');
    }
    if ($assignee_role !== 'THERAPIST' && $assignee_role !== 'PATIENT') {
        throw new Exception('پرکنندهٔ فرم مشخص نیست.');
    }
    if (count(form_fields_fetch($db, (int)$template['id'])) === 0) {
        throw new Exception('این قالب هیچ پرسش فعالی ندارد.');
    }

    $pending = db_select_one($db,
        "SELECT fa.id FROM form_assignments fa
          INNER JOIN form_templates t ON t.id = fa.template_id
          WHERE fa.admission_id = ? AND t.template_code = ? AND fa.status = 'PENDING'
          LIMIT 1",
        'is', array((int)$admission['id'], $template['template_code']));
    if ($pending && !$confirm_duplicate) {
        throw new Exception('DUPLICATE_PENDING');
    }

    $assignee_person_id = ($assignee_role === 'PATIENT')
        ? (int)$admission['patient_person_id']
        : (int)$actor_person_id;

    $case = db_select_one($db,
        "SELECT id FROM clinical_cases WHERE admission_id = ? LIMIT 1",
        'i', array((int)$admission['id']));

    $public_id = generate_public_id('fa');
    $res = db_execute($db,
        "INSERT INTO form_assignments
           (public_id, admission_id, clinical_case_id, patient_person_id, template_id,
            assignee_role, assignee_person_id, assignment_source,
            assigned_by_person_id, assigned_by_role_code, status, assigned_at)
         VALUES (?,?,?,?,?,?,?,'MANUAL',?,?,'PENDING',?)",
        'siiiisiiss',
        array($public_id, (int)$admission['id'],
              ($case ? (int)$case['id'] : null),
              (int)$admission['patient_person_id'], (int)$template['id'],
              $assignee_role, $assignee_person_id,
              (int)$actor_person_id, $actor_role_code, now_dt()));

    audit_log_write($db, $actor_person_id, $actor_role_code, 'FORM_ASSIGNED',
        'form_assignment', (int)$res['insert_id'],
        array('template_code' => $template['template_code'],
              'version' => (int)$template['version'],
              'assignee_role' => $assignee_role,
              'admission_id' => (int)$admission['id']));

    return (int)$res['insert_id'];
}

/** لغو تخصیصِ هنوز پرنشده */
function form_assignment_cancel($db, $assignment, $actor_person_id, $actor_role_code)
{
    if ($assignment['status'] !== 'PENDING') {
        throw new Exception('فقط فرم‌های «در انتظار تکمیل» لغو می‌شوند. '
            . 'فرمی که پاسخ دارد لغو نمی‌شود؛ پاسخ آن باید باطل شود.');
    }
    $res = db_execute($db,
        "UPDATE form_assignments
            SET status = 'CANCELLED', cancelled_at = ?, cancelled_by_person_id = ?
          WHERE id = ? AND status = 'PENDING'",
        'sii', array(now_dt(), (int)$actor_person_id, (int)$assignment['id']));

    if ((int)$res['affected'] !== 1) {
        throw new Exception('وضعیت این فرم در این فاصله تغییر کرده است؛ صفحه را تازه کنید.');
    }

    /* پیش‌نویس احتمالی مراجع هم کنار گذاشته می‌شود (حذف صریح، بدون CASCADE) */
    db_execute($db, "DELETE FROM form_draft_saves WHERE assignment_id = ?",
        'i', array((int)$assignment['id']));

    audit_log_write($db, $actor_person_id, $actor_role_code, 'FORM_ASSIGNMENT_CANCELLED',
        'form_assignment', (int)$assignment['id'],
        array('template_code' => $assignment['template_code']));
}

/**
 * وصل‌کردن تخصیص‌های یک پذیرش به پروندهٔ تازه‌باز‌شده (تصمیم D4-4).
 * از داخل clinical_case_open_from_admission() فراخوانی می‌شود و اگر
 * جدول‌های فاز ۴ نصب نباشند، بی‌اثر برمی‌گردد.
 */
function form_assignments_attach_case($db, $admission_id, $clinical_case_id)
{
    if (!phase4_ready($db)) {
        return 0;
    }
    $res = db_execute($db,
        "UPDATE form_assignments SET clinical_case_id = ?
          WHERE admission_id = ? AND clinical_case_id IS NULL",
        'ii', array((int)$clinical_case_id, (int)$admission_id));
    return (int)$res['affected'];
}

/* ───────────── هوک ADR-008: تخصیص خودکار فرم پذیرش ───────────── */

/**
 * پس از تثبیت موفق نخستین نوبتِ یک مراجعِ تازه، فرم پذیرش اولیه به خودِ
 * مراجع تخصیص می‌یابد.
 *
 * قواعد سخت‌گیرانه:
 *   • فقط اگر این شخص پیش از این هیچ نوبت SCHEDULED/COMPLETED/NO_SHOW
 *     نداشته باشد (نوبت تازه از شمارش کنار گذاشته می‌شود).
 *   • فقط اگر نسخهٔ ACTIVE از قالب patient_intake موجود باشد.
 *   • فقط اگر برای این شخص هیچ تخصیصی از این قالب (هر نسخه، هر وضعیت جز
 *     CANCELLED) وجود نداشته باشد.
 *
 * این تابع **هرگز** استثنا به بیرون نمی‌دهد: شکست آن نباید نوبتِ
 * تثبیت‌شده را خراب کند. خطا در لاگ و حسابرسی ثبت می‌شود.
 *
 * @return string یکی از: 'assigned' | 'skipped' | 'failed'
 */
function form_auto_assign_intake($db, $appointment_id, $admission_id, $patient_person_id)
{
    if (!phase4_ready($db)) {
        return 'skipped';
    }
    try {
        $prior = db_select_one($db,
            "SELECT ap.id
               FROM appointments ap
               INNER JOIN admissions ad ON ad.id = ap.admission_id
              WHERE ad.patient_person_id = ?
                AND ap.id <> ?
                AND ap.status IN ('SCHEDULED','COMPLETED','NO_SHOW')
              LIMIT 1",
            'ii', array((int)$patient_person_id, (int)$appointment_id));
        if ($prior) {
            return 'skipped';
        }

        $template = form_template_active_by_code($db, FORM_INTAKE_CODE);
        if (!$template) {
            audit_log_write($db, null, null, 'FORM_AUTO_ASSIGN_FAILED',
                'admission', (int)$admission_id,
                array('reason' => 'no_active_intake_template'));
            return 'failed';
        }

        $existing = db_select_one($db,
            "SELECT fa.id FROM form_assignments fa
              INNER JOIN form_templates t ON t.id = fa.template_id
              WHERE fa.patient_person_id = ? AND t.template_code = ?
                AND fa.status <> 'CANCELLED'
              LIMIT 1",
            'is', array((int)$patient_person_id, FORM_INTAKE_CODE));
        if ($existing) {
            return 'skipped';
        }

        $case = db_select_one($db,
            "SELECT id FROM clinical_cases WHERE admission_id = ? LIMIT 1",
            'i', array((int)$admission_id));

        mysqli_begin_transaction($db);
        try {
            $public_id = generate_public_id('fa');
            $res = db_execute($db,
                "INSERT INTO form_assignments
                   (public_id, admission_id, clinical_case_id, patient_person_id, template_id,
                    assignee_role, assignee_person_id, assignment_source,
                    trigger_appointment_id, assigned_by_person_id, assigned_by_role_code,
                    status, assigned_at)
                 VALUES (?,?,?,?,?,'PATIENT',?,'AUTO_FIRST_APPOINTMENT',?,NULL,NULL,'PENDING',?)",
                'siiiiiis',
                array($public_id, (int)$admission_id,
                      ($case ? (int)$case['id'] : null),
                      (int)$patient_person_id, (int)$template['id'],
                      (int)$patient_person_id, (int)$appointment_id, now_dt()));

            audit_log_write($db, null, null, 'FORM_AUTO_ASSIGNED',
                'form_assignment', (int)$res['insert_id'],
                array('template_code' => FORM_INTAKE_CODE,
                      'version' => (int)$template['version'],
                      'appointment_id' => (int)$appointment_id));

            mysqli_commit($db);
            return 'assigned';
        } catch (Exception $inner) {
            mysqli_rollback($db);
            throw $inner;
        }
    } catch (Exception $ex) {
        log_system_error('form_auto_assign_intake', $ex);
        try {
            audit_log_write($db, null, null, 'FORM_AUTO_ASSIGN_FAILED',
                'admission', (int)$admission_id,
                array('reason' => 'exception'));
        } catch (Exception $ignored) {
            /* حسابرسی هم شکست خورد؛ نوبت نباید آسیب ببیند */
        }
        return 'failed';
    }
}

/** شمار هشدارهای تخصیص خودکار ناموفق — برای داشبورد مدیر */
function form_auto_assign_failures_recent($db, $days = 7)
{
    if (!phase4_ready($db)) {
        return 0;
    }
    $since = gmdate('Y-m-d H:i:s', time() - ((int)$days * 86400));
    $row = db_select_one($db,
        "SELECT COUNT(*) AS c FROM audit_log
          WHERE action_code = 'FORM_AUTO_ASSIGN_FAILED' AND created_at >= ?",
        's', array($since));
    return $row ? (int)$row['c'] : 0;
}

/** آیا قالب پذیرش اولیه فعال است؟ (هشدار داشبورد مدیر) */
function form_intake_template_missing($db)
{
    if (!phase4_ready($db)) {
        return false;
    }
    return form_template_active_by_code($db, FORM_INTAKE_CODE) ? false : true;
}

/* ───────────── فهرست‌های سطح‌بالا برای صفحه‌ها ───────────── */

/**
 * همهٔ فرم‌های مرتبط با یک درمانگر (P7): هر فرمی روی پرونده‌هایی که او
 * مسئولشان است، به‌علاوهٔ فرم‌های پذیرش‌هایی که به او ارجاع شده و هنوز
 * پرونده‌ای ندارند — صرف‌نظر از اینکه چه کسی آن را پر کرده است.
 */
function form_assignments_for_therapist($db, $therapist_person_id, $status = null, $limit = 100)
{
    $limit = (int)$limit;
    if ($limit < 1) { $limit = 1; }
    if ($limit > 300) { $limit = 300; }

    $sql = form_assignment_select_sql()
        . " WHERE (cc.responsible_therapist_person_id = ?
                   OR (fa.clinical_case_id IS NULL AND ad.referred_therapist_person_id = ?))";
    $types = 'ii';
    $params = array((int)$therapist_person_id, (int)$therapist_person_id);

    if ($status !== null) {
        $sql .= " AND fa.status = ?";
        $types .= 's';
        $params[] = (string)$status;
    }
    $sql .= " ORDER BY FIELD(fa.status,'PENDING','SUBMITTED','RETRACTED','CANCELLED'),
                       fa.assigned_at DESC LIMIT " . $limit;

    return db_select_all($db, $sql, $types, $params);
}

/**
 * فهرست پاسخ‌ها برای مدیر — فقط قالب‌هایی که admin_can_view دارند.
 * این فهرست محتوای پاسخ را نشان نمی‌دهد؛ فقط فراداده.
 */
function form_assignments_for_admin($db, $template_id = null, $limit = 100)
{
    $limit = (int)$limit;
    if ($limit < 1) { $limit = 1; }
    if ($limit > 300) { $limit = 300; }

    $sql = form_assignment_select_sql()
        . " WHERE t.admin_can_view = 1 AND fa.status IN ('SUBMITTED','RETRACTED')";
    $types = '';
    $params = array();
    if ($template_id !== null && (int)$template_id > 0) {
        $sql .= " AND t.id = ?";
        $types .= 'i';
        $params[] = (int)$template_id;
    }
    $sql .= " ORDER BY fs.submitted_at DESC LIMIT " . $limit;

    return db_select_all($db, $sql, $types, $params);
}
