<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — فاز ۴: قالب‌های فرم و فرم‌ساز (حوزهٔ مدیر)
 *
 *  چرخهٔ عمر قالب:
 *     DRAFT ──فعال‌سازی──► ACTIVE ──نخستین پاسخ──► ACTIVE(قفل‌شده)
 *                                               ──آرشیو──► ARCHIVED
 *
 *  پس از قفل‌شدن، فیلدها تغییر نمی‌کنند؛ تنها راه، ساخت نسخهٔ تازه است.
 *  این قاعده تضمین می‌کند پاسخ‌های ثبت‌شده همیشه با همان فیلدهایی که
 *  کاربر دیده بود قابل تفسیر بمانند.
 * ═══════════════════════════════════════════════════════════════════ */

/* ───────────────────────── خواندن ───────────────────────── */

function form_template_find($db, $template_id)
{
    return db_select_one($db,
        "SELECT * FROM form_templates WHERE id = ? LIMIT 1",
        'i', array((int)$template_id));
}

function form_template_find_by_public_id($db, $public_id)
{
    return db_select_one($db,
        "SELECT * FROM form_templates WHERE public_id = ? LIMIT 1",
        's', array((string)$public_id));
}

/** نسخهٔ فعال یک کد قالب (حداکثر یکی در هر لحظه) */
function form_template_active_by_code($db, $template_code)
{
    return db_select_one($db,
        "SELECT * FROM form_templates
          WHERE template_code = ? AND status = 'ACTIVE'
          ORDER BY version DESC LIMIT 1",
        's', array((string)$template_code));
}

/** همهٔ قالب‌ها برای صفحهٔ مدیریت، با شمار تخصیص */
function form_templates_fetch_all($db)
{
    return db_select_all($db,
        "SELECT t.*,
                (SELECT COUNT(*) FROM form_assignments a WHERE a.template_id = t.id) AS assignment_count,
                (SELECT COUNT(*) FROM form_fields f WHERE f.template_id = t.id AND f.is_active = 1) AS field_count
           FROM form_templates t
          ORDER BY t.template_code ASC, t.version DESC",
        '', array());
}

/** قالب‌های قابل تخصیص */
function form_templates_fetch_active($db)
{
    return db_select_all($db,
        "SELECT t.*,
                (SELECT COUNT(*) FROM form_fields f WHERE f.template_id = t.id AND f.is_active = 1) AS field_count
           FROM form_templates t
          WHERE t.status = 'ACTIVE'
          ORDER BY t.title ASC",
        '', array());
}

/** فیلدهای یک قالب، به ترتیب نمایش */
function form_fields_fetch($db, $template_id, $include_inactive = false)
{
    $sql = "SELECT * FROM form_fields WHERE template_id = ?";
    if (!$include_inactive) {
        $sql .= " AND is_active = 1";
    }
    $sql .= " ORDER BY display_order ASC, id ASC";
    return db_select_all($db, $sql, 'i', array((int)$template_id));
}

function form_field_find($db, $template_id, $field_public_id)
{
    return db_select_one($db,
        "SELECT * FROM form_fields WHERE template_id = ? AND public_id = ? LIMIT 1",
        'is', array((int)$template_id, (string)$field_public_id));
}

/** قالب قفل است؟ (یعنی پاسخی برایش ثبت شده) */
function form_template_is_locked($template)
{
    return ($template['locked_at'] !== null && $template['locked_at'] !== '');
}

/* ───────────────────────── نوشتن ───────────────────────── */

function form_template_create($db, $data, $actor_person_id, $actor_role_code)
{
    $title = trim((string)$data['title']);
    $code  = trim((string)$data['template_code']);

    if ($title === '' || mb_strlen($title) > 150) {
        throw new Exception('عنوان قالب الزامی است و حداکثر ۱۵۰ نویسه می‌تواند باشد.');
    }
    if (!preg_match('/^[a-z][a-z0-9_]{2,49}$/', $code)) {
        throw new Exception('شناسهٔ قالب باید با حرف کوچک انگلیسی شروع شود و فقط حرف کوچک، عدد و زیرخط داشته باشد (۳ تا ۵۰ نویسه).');
    }

    $exists = db_select_one($db,
        "SELECT id FROM form_templates WHERE template_code = ? LIMIT 1",
        's', array($code));
    if ($exists) {
        throw new Exception('قالبی با این شناسه از قبل وجود دارد. برای تغییر آن، از «ساخت نسخهٔ جدید» استفاده کنید.');
    }

    $public_id = generate_public_id('ft');
    $res = db_execute($db,
        "INSERT INTO form_templates
           (public_id, template_code, version, title, description,
            default_assignee_role, admin_can_view, patient_can_have_draft,
            status, created_by_person_id, created_at)
         VALUES (?,?,1,?,?,?,?,?,'DRAFT',?,?)",
        'sssssiiis',
        array($public_id, $code, $title,
              ($data['description'] !== '' ? $data['description'] : null),
              $data['default_assignee_role'],
              (int)$data['admin_can_view'],
              (int)$data['patient_can_have_draft'],
              (int)$actor_person_id, now_dt()));

    audit_log_write($db, $actor_person_id, $actor_role_code, 'FORM_TEMPLATE_CREATED',
        'form_template', (int)$res['insert_id'],
        array('template_code' => $code, 'title' => $title));

    return (int)$res['insert_id'];
}

function form_template_update($db, $template, $data, $actor_person_id, $actor_role_code)
{
    $title = trim((string)$data['title']);
    if ($title === '' || mb_strlen($title) > 150) {
        throw new Exception('عنوان قالب الزامی است و حداکثر ۱۵۰ نویسه می‌تواند باشد.');
    }

    db_execute($db,
        "UPDATE form_templates
            SET title = ?, description = ?, default_assignee_role = ?,
                admin_can_view = ?, patient_can_have_draft = ?, updated_at = ?
          WHERE id = ?",
        'sssiisi',
        array($title,
              ($data['description'] !== '' ? $data['description'] : null),
              $data['default_assignee_role'],
              (int)$data['admin_can_view'],
              (int)$data['patient_can_have_draft'],
              now_dt(), (int)$template['id']));

    audit_log_write($db, $actor_person_id, $actor_role_code, 'FORM_TEMPLATE_UPDATED',
        'form_template', (int)$template['id'], array('title' => $title));
}

/**
 * فعال‌سازی قالب. در هر لحظه فقط یک نسخهٔ ACTIVE از هر template_code
 * مجاز است؛ بررسی داخل تراکنش و با قفل سطر انجام می‌شود.
 */
function form_template_activate($db, $template_id, $actor_person_id, $actor_role_code)
{
    mysqli_begin_transaction($db);
    try {
        $t = db_select_one($db,
            "SELECT * FROM form_templates WHERE id = ? FOR UPDATE",
            'i', array((int)$template_id));
        if (!$t) {
            throw new Exception('قالب یافت نشد.');
        }
        if ($t['status'] === 'ACTIVE') {
            mysqli_commit($db);
            return;
        }

        $fields = form_fields_fetch($db, (int)$t['id']);
        if (count($fields) === 0) {
            throw new Exception('قالب بدون فیلد قابل فعال‌سازی نیست. دست‌کم یک پرسش اضافه کنید.');
        }

        $other = db_select_one($db,
            "SELECT id, version FROM form_templates
              WHERE template_code = ? AND status = 'ACTIVE' AND id <> ?
              LIMIT 1",
            'si', array($t['template_code'], (int)$t['id']));
        if ($other) {
            throw new Exception('نسخهٔ ' . to_persian_digits((int)$other['version'])
                . ' این قالب هم‌اکنون فعال است. نخست آن را بایگانی کنید، سپس این نسخه را فعال کنید.');
        }

        db_execute($db,
            "UPDATE form_templates SET status = 'ACTIVE', updated_at = ? WHERE id = ?",
            'si', array(now_dt(), (int)$t['id']));

        audit_log_write($db, $actor_person_id, $actor_role_code, 'FORM_TEMPLATE_ACTIVATED',
            'form_template', (int)$t['id'],
            array('template_code' => $t['template_code'], 'version' => (int)$t['version']));

        mysqli_commit($db);
    } catch (Exception $e) {
        mysqli_rollback($db);
        throw $e;
    }
}

function form_template_archive($db, $template_id, $actor_person_id, $actor_role_code)
{
    $t = form_template_find($db, $template_id);
    if (!$t) {
        throw new Exception('قالب یافت نشد.');
    }
    db_execute($db,
        "UPDATE form_templates SET status = 'ARCHIVED', updated_at = ? WHERE id = ?",
        'si', array(now_dt(), (int)$t['id']));

    audit_log_write($db, $actor_person_id, $actor_role_code, 'FORM_TEMPLATE_ARCHIVED',
        'form_template', (int)$t['id'],
        array('template_code' => $t['template_code'], 'version' => (int)$t['version']));
}

/**
 * ساخت نسخهٔ تازه: کپی کامل قالب و فیلدهایش با version+1، وضعیت DRAFT.
 * تنها راه تغییر قالبی که پاسخ دارد.
 */
function form_template_new_version($db, $template_id, $actor_person_id, $actor_role_code)
{
    mysqli_begin_transaction($db);
    try {
        $t = db_select_one($db,
            "SELECT * FROM form_templates WHERE id = ? FOR UPDATE",
            'i', array((int)$template_id));
        if (!$t) {
            throw new Exception('قالب یافت نشد.');
        }

        $draft = db_select_one($db,
            "SELECT id, version FROM form_templates
              WHERE template_code = ? AND status = 'DRAFT' LIMIT 1",
            's', array($t['template_code']));
        if ($draft) {
            throw new Exception('نسخهٔ ' . to_persian_digits((int)$draft['version'])
                . ' این قالب هنوز پیش‌نویس است. نخست آن را کامل و فعال کنید.');
        }

        $max = db_select_one($db,
            "SELECT MAX(version) AS v FROM form_templates WHERE template_code = ?",
            's', array($t['template_code']));
        $next = ((int)$max['v']) + 1;

        $new_public = generate_public_id('ft');
        $res = db_execute($db,
            "INSERT INTO form_templates
               (public_id, template_code, version, parent_template_id, title, description,
                default_assignee_role, admin_can_view, patient_can_have_draft,
                status, created_by_person_id, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,'DRAFT',?,?)",
            'ssiisssiiis',
            array($new_public, $t['template_code'], $next, (int)$t['id'],
                  $t['title'], $t['description'], $t['default_assignee_role'],
                  (int)$t['admin_can_view'], (int)$t['patient_can_have_draft'],
                  (int)$actor_person_id, now_dt()));
        $new_id = (int)$res['insert_id'];

        foreach (form_fields_fetch($db, (int)$t['id'], true) as $f) {
            db_execute($db,
                "INSERT INTO form_fields
                   (public_id, template_id, field_code, label, help_text, field_type,
                    is_required, display_order, options_text, min_value, max_value,
                    max_length, is_active, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                'sissssiisddiis',
                array(generate_public_id('ff'), $new_id, $f['field_code'], $f['label'],
                      $f['help_text'], $f['field_type'], (int)$f['is_required'],
                      (int)$f['display_order'], $f['options_text'],
                      $f['min_value'], $f['max_value'], $f['max_length'],
                      (int)$f['is_active'], now_dt()));
        }

        audit_log_write($db, $actor_person_id, $actor_role_code, 'FORM_TEMPLATE_VERSIONED',
            'form_template', $new_id,
            array('template_code' => $t['template_code'],
                  'from_version' => (int)$t['version'], 'to_version' => $next));

        mysqli_commit($db);
        return $new_id;
    } catch (Exception $e) {
        mysqli_rollback($db);
        throw $e;
    }
}

/* ───────────────────────── فیلدها ───────────────────────── */

/** پیش از هر تغییر فیلد، این بررسی اجرا می‌شود */
function form_template_assert_editable($template)
{
    if (form_template_is_locked($template)) {
        throw new Exception('این قالب پاسخ ثبت‌شده دارد و فیلدهایش قفل است. '
            . 'برای تغییر، «ساخت نسخهٔ جدید» را بزنید تا پاسخ‌های قبلی دست‌نخورده بمانند.');
    }
    if ($template['status'] === 'ARCHIVED') {
        throw new Exception('قالب بایگانی‌شده ویرایش نمی‌شود.');
    }
}

/**
 * افزودن فیلد.
 * @param bool $free_text_ack تیک «آگاهانه فیلد متن آزاد اضافه می‌کنم» (ADR-007)
 */
function form_field_add($db, $template, $data, $free_text_ack, $actor_person_id, $actor_role_code)
{
    form_template_assert_editable($template);

    $type = (string)$data['field_type'];
    if (!form_field_type_valid($type)) {
        throw new Exception('نوع فیلد معتبر نیست.');
    }
    if ($type === 'DESCRIPTIVE_TEXT' && !$free_text_ack) {
        throw new Exception('افزودن فیلد «متن توضیحی» نیازمند تأیید آگاهانه است. '
            . 'طبق ADR-007، متن آزاد به‌صورت پیش‌فرض در فرم‌های بالینی استفاده نمی‌شود؛ '
            . 'اگر واقعاً لازم است، تیک تأیید را بزنید.');
    }

    $label = trim((string)$data['label']);
    if ($label === '' || mb_strlen($label) > 300) {
        throw new Exception('متن پرسش الزامی است و حداکثر ۳۰۰ نویسه می‌تواند باشد.');
    }

    $count = db_select_one($db,
        "SELECT COUNT(*) AS c FROM form_fields WHERE template_id = ? AND is_active = 1",
        'i', array((int)$template['id']));
    if ((int)$count['c'] >= FORM_MAX_FIELDS) {
        throw new Exception('هر قالب حداکثر ' . to_persian_digits(FORM_MAX_FIELDS)
            . ' پرسش فعال می‌تواند داشته باشد.');
    }

    $code = trim((string)$data['field_code']);
    if ($code === '') {
        $code = 'f' . ((int)$count['c'] + 1) . '_' . substr(bin2hex(random_bytes(3)), 0, 4);
    }
    if (!preg_match('/^[a-z][a-z0-9_]{1,49}$/', $code)) {
        throw new Exception('شناسهٔ پرسش باید با حرف کوچک انگلیسی شروع شود و فقط حرف کوچک، عدد و زیرخط داشته باشد.');
    }
    $dup = db_select_one($db,
        "SELECT id FROM form_fields WHERE template_id = ? AND field_code = ? LIMIT 1",
        'is', array((int)$template['id'], $code));
    if ($dup) {
        throw new Exception('پرسشی با این شناسه در همین قالب وجود دارد.');
    }

    $options_text = null;
    if (form_field_needs_options($type)) {
        $parsed = form_options_parse_input($data['options_raw']);
        if (!$parsed['ok']) {
            throw new Exception($parsed['error']);
        }
        $options_text = form_options_encode($parsed['options']);
    }

    $min = null; $max = null; $max_len = null;
    if (form_field_has_range($type)) {
        $min = ($data['min_value'] === '' ? null : (float)to_latin_digits($data['min_value']));
        $max = ($data['max_value'] === '' ? null : (float)to_latin_digits($data['max_value']));
        if ($type === 'SCALE') {
            if ($min === null) { $min = 1; }
            if ($max === null) { $max = 5; }
        }
        if ($min !== null && $max !== null && $min >= $max) {
            throw new Exception('کمینه باید از بیشینه کوچک‌تر باشد.');
        }
    }
    if ($type === 'DESCRIPTIVE_TEXT') {
        $max_len = ($data['max_length'] === '' ? FORM_TEXT_DEFAULT_MAX : (int)to_latin_digits($data['max_length']));
        if ($max_len < 10) { $max_len = 10; }
        if ($max_len > FORM_TEXT_ABSOLUTE_MAX) { $max_len = FORM_TEXT_ABSOLUTE_MAX; }
    }

    $order = db_select_one($db,
        "SELECT COALESCE(MAX(display_order), 0) AS m FROM form_fields WHERE template_id = ?",
        'i', array((int)$template['id']));

    $res = db_execute($db,
        "INSERT INTO form_fields
           (public_id, template_id, field_code, label, help_text, field_type,
            is_required, display_order, options_text, min_value, max_value,
            max_length, is_active, created_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1,?)",
        'sissssiisddis',
        array(generate_public_id('ff'), (int)$template['id'], $code, $label,
              ($data['help_text'] !== '' ? $data['help_text'] : null),
              $type, (int)$data['is_required'], ((int)$order['m']) + 10,
              $options_text, $min, $max, $max_len, now_dt()));

    audit_log_write($db, $actor_person_id, $actor_role_code, 'FORM_FIELD_ADDED',
        'form_template', (int)$template['id'],
        array('field_code' => $code, 'type' => $type));

    if ($type === 'DESCRIPTIVE_TEXT') {
        audit_log_write($db, $actor_person_id, $actor_role_code, 'FORM_FIELD_FREE_TEXT_ADDED',
            'form_template', (int)$template['id'],
            array('field_code' => $code, 'acknowledged' => true));
    }

    return (int)$res['insert_id'];
}

function form_field_update($db, $template, $field, $data, $actor_person_id, $actor_role_code)
{
    form_template_assert_editable($template);

    $label = trim((string)$data['label']);
    if ($label === '' || mb_strlen($label) > 300) {
        throw new Exception('متن پرسش الزامی است و حداکثر ۳۰۰ نویسه می‌تواند باشد.');
    }

    $type = $field['field_type'];   /* نوع فیلد پس از ساخت تغییر نمی‌کند */
    $options_text = $field['options_text'];
    if (form_field_needs_options($type)) {
        $parsed = form_options_parse_input($data['options_raw'], form_options_decode($field['options_text']));
        if (!$parsed['ok']) {
            throw new Exception($parsed['error']);
        }
        $options_text = form_options_encode($parsed['options']);
    }

    $min = $field['min_value']; $max = $field['max_value']; $max_len = $field['max_length'];
    if (form_field_has_range($type)) {
        $min = ($data['min_value'] === '' ? null : (float)to_latin_digits($data['min_value']));
        $max = ($data['max_value'] === '' ? null : (float)to_latin_digits($data['max_value']));
        if ($min !== null && $max !== null && $min >= $max) {
            throw new Exception('کمینه باید از بیشینه کوچک‌تر باشد.');
        }
    }
    if ($type === 'DESCRIPTIVE_TEXT') {
        $max_len = ($data['max_length'] === '' ? FORM_TEXT_DEFAULT_MAX : (int)to_latin_digits($data['max_length']));
        if ($max_len < 10) { $max_len = 10; }
        if ($max_len > FORM_TEXT_ABSOLUTE_MAX) { $max_len = FORM_TEXT_ABSOLUTE_MAX; }
    }

    db_execute($db,
        "UPDATE form_fields
            SET label = ?, help_text = ?, is_required = ?, options_text = ?,
                min_value = ?, max_value = ?, max_length = ?
          WHERE id = ? AND template_id = ?",
        'ssisddiii',
        array($label, ($data['help_text'] !== '' ? $data['help_text'] : null),
              (int)$data['is_required'], $options_text, $min, $max, $max_len,
              (int)$field['id'], (int)$template['id']));

    audit_log_write($db, $actor_person_id, $actor_role_code, 'FORM_FIELD_UPDATED',
        'form_template', (int)$template['id'], array('field_code' => $field['field_code']));
}

/** حذف نرم — ردیف می‌ماند تا نسخه‌های قبلی تفسیرپذیر بمانند */
function form_field_deactivate($db, $template, $field, $actor_person_id, $actor_role_code)
{
    form_template_assert_editable($template);

    db_execute($db,
        "UPDATE form_fields SET is_active = 0 WHERE id = ? AND template_id = ?",
        'ii', array((int)$field['id'], (int)$template['id']));

    audit_log_write($db, $actor_person_id, $actor_role_code, 'FORM_FIELD_DEACTIVATED',
        'form_template', (int)$template['id'], array('field_code' => $field['field_code']));
}

/** جابه‌جایی یک پله بالا یا پایین */
function form_field_move($db, $template, $field, $direction, $actor_person_id, $actor_role_code)
{
    form_template_assert_editable($template);

    $fields = form_fields_fetch($db, (int)$template['id']);
    $index = -1;
    foreach ($fields as $i => $f) {
        if ((int)$f['id'] === (int)$field['id']) { $index = $i; break; }
    }
    if ($index < 0) {
        throw new Exception('پرسش یافت نشد.');
    }
    $swap = ($direction === 'up') ? $index - 1 : $index + 1;
    if ($swap < 0 || $swap >= count($fields)) {
        return;
    }

    mysqli_begin_transaction($db);
    try {
        $a = $fields[$index];
        $b = $fields[$swap];
        db_execute($db, "UPDATE form_fields SET display_order = ? WHERE id = ?",
            'ii', array((int)$b['display_order'], (int)$a['id']));
        db_execute($db, "UPDATE form_fields SET display_order = ? WHERE id = ?",
            'ii', array((int)$a['display_order'], (int)$b['id']));
        mysqli_commit($db);
    } catch (Exception $e) {
        mysqli_rollback($db);
        throw $e;
    }

    audit_log_write($db, $actor_person_id, $actor_role_code, 'FORM_FIELD_REORDERED',
        'form_template', (int)$template['id'],
        array('field_code' => $field['field_code'], 'direction' => $direction));
}
