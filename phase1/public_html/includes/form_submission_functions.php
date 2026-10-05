<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — فاز ۴: ثبت پاسخ، ویرایش، ابطال و پیش‌نویس
 *
 *  قواعد ثابت:
 *    • هر تخصیص حداکثر یک ثبت دارد (UNIQUE(assignment_id)).
 *    • ویرایش پاسخ را بازنویسی نمی‌کند؛ نسخهٔ تازه می‌سازد
 *      (revision + 1) و نسخهٔ پیشین با is_current = 0 می‌ماند.
 *    • پنجرهٔ ویرایش ۲۴ ساعت است؛ پس از آن فقط ابطال.
 *    • ابطال، داده را پاک نمی‌کند؛ فقط وضعیت را عوض می‌کند.
 *    • هیچ متنِ پاسخی وارد audit_log نمی‌شود.
 *    • پیش‌نویس فقط برای مراجع (P8).
 * ═══════════════════════════════════════════════════════════════════ */

/** ثبت یک تخصیص (یا null) */
function form_submission_find_by_assignment($db, $assignment_id)
{
    return db_select_one($db,
        "SELECT * FROM form_submissions WHERE assignment_id = ? LIMIT 1",
        'i', array((int)$assignment_id));
}

/**
 * پاسخ‌های جاری یک ثبت، به ترتیب نمایش فیلدها.
 * فیلدهای غیرفعال‌شده هم می‌آیند، چون پاسخ تاریخی باید کامل خوانده شود.
 */
function form_submission_values_current($db, $submission_id)
{
    return db_select_all($db,
        "SELECT v.*, f.field_code, f.label, f.help_text, f.field_type, f.options_text,
                f.display_order, f.is_active
           FROM form_submission_values v
           INNER JOIN form_fields f ON f.id = v.field_id
          WHERE v.submission_id = ? AND v.is_current = 1
          ORDER BY f.display_order ASC, f.id ASC",
        'i', array((int)$submission_id));
}

/** پاسخ‌های یک بازنگری مشخص (تاریخچه) */
function form_submission_values_revision($db, $submission_id, $revision)
{
    return db_select_all($db,
        "SELECT v.*, f.field_code, f.label, f.field_type, f.options_text, f.display_order
           FROM form_submission_values v
           INNER JOIN form_fields f ON f.id = v.field_id
          WHERE v.submission_id = ? AND v.revision = ?
          ORDER BY f.display_order ASC, f.id ASC",
        'ii', array((int)$submission_id, (int)$revision));
}

/** فهرست شماره‌های بازنگری موجود، از تازه به قدیم */
function form_submission_revisions($db, $submission_id)
{
    $rows = db_select_all($db,
        "SELECT revision, MIN(created_at) AS created_at
           FROM form_submission_values WHERE submission_id = ?
          GROUP BY revision ORDER BY revision DESC",
        'i', array((int)$submission_id));
    return $rows ? $rows : array();
}

/** مقدار جاری هر فیلد به‌صورت نگاشت field_id ⇐ value_text (برای فرم ویرایش) */
function form_submission_values_map($db, $submission_id)
{
    $map = array();
    foreach (form_submission_values_current($db, $submission_id) as $row) {
        $map[(int)$row['field_id']] = $row['value_text'];
    }
    return $map;
}

/* ─────────────── اعتبارسنجی یکجای همهٔ فیلدها ─────────────── */

/**
 * همهٔ فیلدهای فعال قالب را در برابر ورودی POST می‌سنجد.
 *
 * @return array('ok'=>bool, 'values'=>array(field_id=>value), 'errors'=>array(field_id=>msg))
 */
function form_values_validate_all($fields, $input)
{
    $values = array();
    $errors = array();
    foreach ($fields as $field) {
        $key = 'f_' . $field['public_id'];
        $raw = isset($input[$key]) ? $input[$key] : '';
        $check = form_value_validate($field, $raw);
        if ($check['ok']) {
            $values[(int)$field['id']] = $check['value'];
        } else {
            $errors[(int)$field['id']] = $check['error'];
        }
    }
    return array('ok' => (count($errors) === 0), 'values' => $values, 'errors' => $errors);
}

/** ورودی خام را برای بازنمایی دوبارهٔ فرم پس از خطا نگه می‌دارد */
function form_input_keep($fields, $input)
{
    $keep = array();
    foreach ($fields as $field) {
        $key = 'f_' . $field['public_id'];
        if (!isset($input[$key])) {
            continue;
        }
        $keep[(int)$field['id']] = is_array($input[$key])
            ? array_map('strval', $input[$key])
            : (string)$input[$key];
    }
    return $keep;
}

/* ───────────────────────── ثبت ───────────────────────── */

/**
 * ثبت نخستین پاسخ یک تخصیص. تراکنشی.
 * قالب با نخستین ثبت، قفل می‌شود (locked_at).
 */
function form_submission_create($db, $assignment, $fields, $values,
                                $actor_person_id, $actor_role_code)
{
    if ($assignment['status'] !== 'PENDING') {
        throw new Exception('این فرم دیگر در وضعیت «در انتظار تکمیل» نیست.');
    }
    if ((int)$assignment['assignee_person_id'] !== (int)$actor_person_id) {
        throw new Exception(FORM_DENY_MESSAGE);
    }
    if (count($fields) === 0) {
        throw new Exception('این فرم هیچ پرسش فعالی ندارد.');
    }

    mysqli_begin_transaction($db);
    try {
        /* قفل سطر تخصیص تا دو ارسال همزمان دو ثبت نسازند */
        $locked = db_select_one($db,
            "SELECT id, status FROM form_assignments WHERE id = ? FOR UPDATE",
            'i', array((int)$assignment['id']));
        if (!$locked || $locked['status'] !== 'PENDING') {
            throw new Exception('این فرم پیش از این ثبت یا لغو شده است.');
        }

        $now = now_dt();
        $public_id = generate_public_id('fs');
        $res = db_execute($db,
            "INSERT INTO form_submissions
               (public_id, assignment_id, submitted_by_person_id, submitted_by_role_code,
                submitted_at, current_revision, edit_count, status)
             VALUES (?,?,?,?,?,1,0,'ACTIVE')",
            'siiss',
            array($public_id, (int)$assignment['id'], (int)$actor_person_id,
                  $actor_role_code, $now));
        $submission_id = (int)$res['insert_id'];

        form_submission_values_insert($db, $submission_id, $fields, $values, 1, $now);

        db_execute($db,
            "UPDATE form_assignments SET status = 'SUBMITTED' WHERE id = ?",
            'i', array((int)$assignment['id']));

        /* قفل‌شدن قالب در نخستین ثبت */
        db_execute($db,
            "UPDATE form_templates SET locked_at = ? WHERE id = ? AND locked_at IS NULL",
            'si', array($now, (int)$assignment['template_id']));

        /* پیش‌نویس دیگر معنا ندارد */
        db_execute($db, "DELETE FROM form_draft_saves WHERE assignment_id = ?",
            'i', array((int)$assignment['id']));

        audit_log_write($db, $actor_person_id, $actor_role_code, 'FORM_SUBMITTED',
            'form_submission', $submission_id,
            array('assignment_id' => (int)$assignment['id'],
                  'template_code' => $assignment['template_code'],
                  'version' => (int)$assignment['template_version'],
                  'field_count' => count($fields)));

        mysqli_commit($db);
        return $submission_id;
    } catch (Exception $ex) {
        mysqli_rollback($db);
        throw $ex;
    }
}

/** درج مقادیر یک بازنگری */
function form_submission_values_insert($db, $submission_id, $fields, $values, $revision, $now)
{
    foreach ($fields as $field) {
        $fid = (int)$field['id'];
        $val = array_key_exists($fid, $values) ? $values[$fid] : null;
        db_execute($db,
            "INSERT INTO form_submission_values
               (submission_id, field_id, revision, value_text, is_current, created_at)
             VALUES (?,?,?,?,1,?)",
            'iiiss', array((int)$submission_id, $fid, (int)$revision, $val, $now));
    }
}

/* ───────────────────────── ویرایش ───────────────────────── */

/**
 * ویرایش داخل پنجرهٔ ۲۴ ساعته. نسخهٔ تازه می‌سازد و نسخهٔ پیشین را
 * بایگانی می‌کند (is_current = 0).
 */
function form_submission_edit($db, $assignment, $submission, $fields, $values,
                              $actor_person_id, $actor_role_code)
{
    if ((int)$submission['submitted_by_person_id'] !== (int)$actor_person_id) {
        throw new Exception('فقط پرکنندهٔ فرم می‌تواند پاسخ را ویرایش کند.');
    }
    if ($submission['status'] !== 'ACTIVE') {
        throw new Exception('این پاسخ باطل شده و دیگر ویرایش نمی‌شود.');
    }
    if (!form_submission_in_edit_window($submission)) {
        throw new Exception('مهلت ۲۴ ساعتهٔ ویرایش این پاسخ به پایان رسیده است. '
            . 'در صورت نیاز می‌توانید آن را باطل کنید.');
    }

    mysqli_begin_transaction($db);
    try {
        $fresh = db_select_one($db,
            "SELECT * FROM form_submissions WHERE id = ? FOR UPDATE",
            'i', array((int)$submission['id']));
        if (!$fresh || $fresh['status'] !== 'ACTIVE') {
            throw new Exception('وضعیت این پاسخ در این فاصله تغییر کرده است؛ صفحه را تازه کنید.');
        }
        if (!form_submission_in_edit_window($fresh)) {
            throw new Exception('مهلت ۲۴ ساعتهٔ ویرایش این پاسخ به پایان رسیده است.');
        }

        $now = now_dt();
        $new_revision = (int)$fresh['current_revision'] + 1;

        db_execute($db,
            "UPDATE form_submission_values SET is_current = 0
              WHERE submission_id = ? AND is_current = 1",
            'i', array((int)$submission['id']));

        form_submission_values_insert($db, (int)$submission['id'], $fields, $values,
            $new_revision, $now);

        db_execute($db,
            "UPDATE form_submissions
                SET current_revision = ?, edit_count = edit_count + 1, last_edited_at = ?
              WHERE id = ?",
            'isi', array($new_revision, $now, (int)$submission['id']));

        audit_log_write($db, $actor_person_id, $actor_role_code, 'FORM_EDITED',
            'form_submission', (int)$submission['id'],
            array('assignment_id' => (int)$assignment['id'],
                  'revision' => $new_revision,
                  'template_code' => $assignment['template_code']));

        mysqli_commit($db);
        return $new_revision;
    } catch (Exception $ex) {
        mysqli_rollback($db);
        throw $ex;
    }
}

/* ───────────────────────── ابطال ───────────────────────── */

/** دلایل ابطال از فهرست‌های پایه */
function form_retraction_reasons($db)
{
    return lookup_items_fetch_active($db, FORM_RETRACTION_LIST);
}

/**
 * ابطال پاسخ. حذف فیزیکی نداریم؛ پاسخ می‌ماند و با نشان «باطل‌شده»
 * دیده می‌شود. پرکننده یا درمانگر مسئول می‌تواند ابطال کند.
 */
function form_submission_retract($db, $assignment, $submission, $reason_id,
                                 $actor_person_id, $actor_role_code)
{
    if ($submission['status'] !== 'ACTIVE') {
        throw new Exception('این پاسخ پیش از این باطل شده است.');
    }
    $is_author = ((int)$submission['submitted_by_person_id'] === (int)$actor_person_id);
    $is_owner_therapist = (form_assignment_owner_therapist($assignment) === (int)$actor_person_id);
    if (!$is_author && !$is_owner_therapist) {
        throw new Exception(FORM_DENY_MESSAGE);
    }

    $reason_id = (int)$reason_id;
    if ($reason_id <= 0) {
        throw new Exception('دلیل ابطال را انتخاب کنید.');
    }
    $reason_ok = false;
    foreach (form_retraction_reasons($db) as $r) {
        if ((int)$r['id'] === $reason_id) { $reason_ok = true; break; }
    }
    if (!$reason_ok) {
        throw new Exception('دلیل ابطال معتبر نیست.');
    }

    mysqli_begin_transaction($db);
    try {
        $now = now_dt();
        $res = db_execute($db,
            "UPDATE form_submissions
                SET status = 'RETRACTED', retracted_at = ?, retracted_by_person_id = ?,
                    retraction_reason_id = ?
              WHERE id = ? AND status = 'ACTIVE'",
            'siii', array($now, (int)$actor_person_id, $reason_id, (int)$submission['id']));
        if ((int)$res['affected'] !== 1) {
            throw new Exception('وضعیت این پاسخ در این فاصله تغییر کرده است؛ صفحه را تازه کنید.');
        }

        db_execute($db,
            "UPDATE form_assignments SET status = 'RETRACTED' WHERE id = ?",
            'i', array((int)$assignment['id']));

        audit_log_write($db, $actor_person_id, $actor_role_code, 'FORM_RETRACTED',
            'form_submission', (int)$submission['id'],
            array('assignment_id' => (int)$assignment['id'],
                  'reason_id' => $reason_id,
                  'by' => ($is_author ? 'author' : 'responsible_therapist')));

        mysqli_commit($db);
    } catch (Exception $ex) {
        mysqli_rollback($db);
        throw $ex;
    }
}

/* ─────────────── دسترسی مدیر (Q2 = ب، ریسک P4) ─────────────── */

/**
 * مدیر فقط اگر قالب admin_can_view داشته باشد پاسخ را می‌بیند و هر بار
 * دیده‌شدن، حسابرسی می‌شود. این آگاهانه از ADR-004 فاصله می‌گیرد.
 */
function form_admin_can_view($assignment)
{
    return ((int)$assignment['admin_can_view'] === 1);
}

function form_admin_view_log($db, $assignment, $submission_id, $actor_person_id)
{
    audit_log_write($db, $actor_person_id, 'admin', 'FORM_SUBMISSION_VIEWED_BY_ADMIN',
        'form_submission', (int)$submission_id,
        array('assignment_id' => (int)$assignment['id'],
              'template_code' => $assignment['template_code'],
              'patient_person_id' => (int)$assignment['patient_person_id']));
}

/* ───────────────────────── پیش‌نویس (P8) ───────────────────────── */

/** فقط مراجع پیش‌نویس دارد، و فقط اگر قالب اجازه داده باشد */
function form_draft_allowed($assignment)
{
    return ($assignment['assignee_role'] === 'PATIENT'
        && (int)$assignment['patient_can_have_draft'] === 1
        && $assignment['status'] === 'PENDING');
}

function form_draft_find($db, $assignment_id)
{
    return db_select_one($db,
        "SELECT * FROM form_draft_saves WHERE assignment_id = ? LIMIT 1",
        'i', array((int)$assignment_id));
}

/**
 * ذخیرهٔ پیش‌نویس: ورودی خام، بدون اعتبارسنجی (هدفش همین است که ناقص
 * باشد). یک ردیف به‌ازای هر تخصیص؛ بازنویسی می‌شود.
 */
function form_draft_save($db, $assignment, $fields, $input, $actor_person_id, $actor_role_code)
{
    if (!form_draft_allowed($assignment)) {
        throw new Exception('برای این فرم امکان ذخیرهٔ پیش‌نویس وجود ندارد.');
    }

    $data = array();
    foreach ($fields as $field) {
        $key = 'f_' . $field['public_id'];
        if (!isset($input[$key])) {
            continue;
        }
        $data[$field['public_id']] = is_array($input[$key])
            ? array_map('strval', $input[$key])
            : (string)$input[$key];
    }
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    $now = now_dt();

    $existing = form_draft_find($db, (int)$assignment['id']);
    if ($existing) {
        db_execute($db,
            "UPDATE form_draft_saves SET draft_data_json = ?, saved_at = ?,
                    saved_by_person_id = ?, saved_by_role_code = ?
              WHERE id = ?",
            'ssisi', array($json, $now, (int)$actor_person_id, $actor_role_code,
                           (int)$existing['id']));
    } else {
        db_execute($db,
            "INSERT INTO form_draft_saves
               (public_id, assignment_id, saved_by_person_id, saved_by_role_code,
                draft_data_json, saved_at)
             VALUES (?,?,?,?,?,?)",
            'siisss', array(generate_public_id('fd'), (int)$assignment['id'],
                            (int)$actor_person_id, $actor_role_code, $json, $now));
    }

    audit_log_write($db, $actor_person_id, $actor_role_code, 'FORM_DRAFT_SAVED',
        'form_assignment', (int)$assignment['id'],
        array('field_count' => count($data)));

    form_drafts_cleanup_lazy($db);
}

/** بارگذاری پیش‌نویس به شکل نگاشت field_id ⇐ مقدار خام */
function form_draft_load($db, $assignment, $fields, $actor_person_id, $actor_role_code)
{
    $draft = form_draft_find($db, (int)$assignment['id']);
    if (!$draft) {
        return array();
    }
    $data = json_decode($draft['draft_data_json'], true);
    if (!is_array($data)) {
        return array();
    }
    $map = array();
    foreach ($fields as $field) {
        $pid = $field['public_id'];
        if (array_key_exists($pid, $data)) {
            $map[(int)$field['id']] = $data[$pid];
        }
    }
    audit_log_write($db, $actor_person_id, $actor_role_code, 'FORM_DRAFT_LOADED',
        'form_assignment', (int)$assignment['id'], array());
    return $map;
}

function form_draft_discard($db, $assignment, $actor_person_id, $actor_role_code)
{
    $res = db_execute($db, "DELETE FROM form_draft_saves WHERE assignment_id = ?",
        'i', array((int)$assignment['id']));
    if ((int)$res['affected'] > 0) {
        audit_log_write($db, $actor_person_id, $actor_role_code, 'FORM_DRAFT_DISCARDED',
            'form_assignment', (int)$assignment['id'], array());
    }
}

/**
 * پاکسازی تنبل پیش‌نویس‌های کهنه (۳۰ روز)، به سبک holds_expire_lazy فاز ۲.
 * حداکثر ۵۰ ردیف در هر فراخوانی تا صفحه کند نشود. از دو نقطه صدا زده
 * می‌شود: ذخیرهٔ پیش‌نویس، و بازکردن فهرست فرم‌های مراجع.
 */
function form_drafts_cleanup_lazy($db)
{
    if (!phase4_ready($db)) {
        return 0;
    }
    $cutoff = gmdate('Y-m-d H:i:s', time() - (FORM_DRAFT_RETENTION_DAYS * 86400));
    $rows = db_select_all($db,
        "SELECT id FROM form_draft_saves WHERE saved_at < ? ORDER BY saved_at ASC LIMIT "
            . (int)FORM_DRAFT_SWEEP_LIMIT,
        's', array($cutoff));
    if (!$rows) {
        return 0;
    }
    $n = 0;
    foreach ($rows as $row) {
        db_execute($db, "DELETE FROM form_draft_saves WHERE id = ?",
            'i', array((int)$row['id']));
        $n++;
    }
    return $n;
}
