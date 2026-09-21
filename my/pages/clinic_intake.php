<?php
// فرم پذیرش / جلسه اول — لینک عمومی (توکن) + تکمیل توسط مراجع لاگین‌کرده
$u = current_user();
$opt = clinic_intake_options();

// ---- صفحه موفقیت ----
if (isset($_GET['done'])) {
    $show_crisis = isset($_GET['risk']) && $_GET['risk'] === '1';
    joma_header('ثبت فرم', array(), $u ? array() : array('public' => 1));
    echo '<div class="card" style="max-width:640px;margin:20px auto;"><h1>✅ فرم شما با موفقیت ثبت شد</h1>';
    echo '<p>اطلاعات شما برای درمانگر ارسال شد و در جلسه اول بررسی می‌شود.</p>';
    if ($show_crisis) {
        $set = clinic_get_settings();
        echo '<div class="clinic-alert red">💚 ' . nl2br(clinic_h($set['crisis_text'])) . '</div>';
    }
    if ($u) {
        echo '<p><a class="btn" href="' . e(joma_url('index.php?p=clinic_dashboard')) . '">ورود به پیشخوان من</a></p>';
    } else {
        echo '<p><a class="btn" href="' . e(joma_url('index.php?p=login')) . '">ورود با موبایل و رمز</a></p>';
    }
    echo '</div>';
    joma_footer();
    return;
}

$token = isset($_GET['t']) ? trim($_GET['t']) : '';
$invite = $token !== '' ? clinic_get_invite_by_token($token) : null;
$mode = ''; // new | complete | mine
$client = null;
$doctor_id = 0;

if ($invite) {
    $doctor_id = (int) $invite['doctor_id'];
    if ((int) $invite['client_id'] > 0) {
        $client = clinic_get_client((int) $invite['client_id']);
        if (!$client) $invite = null;
        else $mode = 'complete';
    } else {
        $mode = 'new';
    }
} elseif ($u && clinic_role() === 'client') {
    $client = clinic_get_client_by_user($u['id']);
    if ($client) {
        $mode = 'mine';
        $doctor_id = (int) $client['doctor_id'];
    }
}

if ($mode === '') {
    joma_header('فرم پذیرش', array(), array('public' => 1));
    echo '<div class="card" style="max-width:640px;margin:20px auto;"><h1>فرم پذیرش</h1>';
    echo '<p class="bad">لینک پذیرش نامعتبر است یا منقضی/استفاده شده است. لطفاً از مطب لینک جدید بگیرید.</p>';
    echo '<p><a class="btn" href="' . e(joma_url('index.php?p=login')) . '">ورود</a></p></div>';
    joma_footer();
    return;
}

// قفل فرم بررسی‌شده برای حالت mine
if ($mode === 'mine' && $client['intake_status'] === 'reviewed') {
    joma_header('فرم جلسه اول');
    echo '<div class="card"><div class="clinic-alert green">فرم شما قبلاً ثبت و توسط درمانگر بررسی شده است. برای اصلاح با درمانگر خود صحبت کنید.</div>';
    echo '<p><a class="btn" href="' . e(joma_url('index.php?p=clinic_client&tab=intake')) . '">مشاهده فرم من</a></p></div>';
    joma_footer();
    return;
}

$doc = get_user($doctor_id);
$set = clinic_get_settings();

// پیش‌پر کردن
if ($client && isset($client['intake']) && is_array($client['intake']) && $client['intake']) {
    $in = $client['intake'];
} else {
    $in = clinic_intake_empty();
}
if ($client) {
    if ($in['s1']['first_name'] === '') $in['s1']['first_name'] = $client['first_name'];
    if ($in['s1']['last_name'] === '') $in['s1']['last_name'] = $client['last_name'];
    if ($in['s1']['mobile'] === '') $in['s1']['mobile'] = $client['mobile'];
    if ($in['s1']['birth_date'] === '') $in['s1']['birth_date'] = $client['birth_date'];
    if ($in['s1']['job'] === '') $in['s1']['job'] = $client['job'];
    if ($in['s1']['education'] === '') $in['s1']['education'] = $client['education'];
    if ($in['s1']['marital'] === '') $in['s1']['marital'] = $client['marital'];
    if ($in['s1']['emergency_contact'] === '') $in['s1']['emergency_contact'] = $client['emergency_contact'];
    if ($in['s1']['referrer'] === '') $in['s1']['referrer'] = $client['referrer'];
} elseif ($invite && $invite['mobile'] !== '') {
    $in['s1']['mobile'] = $invite['mobile'];
}

$errs = array();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $posted = clinic_intake_from_post();
    $errs = clinic_intake_validate_final($posted);
    // بررسی تکراری نبودن موبایل برای پرونده جدید
    if ($mode === 'new' && $posted['s1']['mobile'] !== '') {
        $dup = clinic_get_client_by_mobile($posted['s1']['mobile']);
        if ($dup) {
            $errs[] = 'با این شماره موبایل قبلاً پرونده‌ای ثبت شده است. لطفاً وارد شوید یا با مطب تماس بگیرید.';
        }
    }
    // رمز حساب (فقط اگر حساب وجود ندارد)
    $need_pass = false;
    if ($mode !== 'mine') {
        $exu = clinic_user_by_phone($posted['s1']['mobile']);
        $client_has_user = ($client && (int) $client['user_id'] > 0);
        if (!$exu && !$client_has_user) {
            $need_pass = true;
            $p1 = isset($_POST['password']) ? (string) $_POST['password'] : '';
            $p2 = isset($_POST['password2']) ? (string) $_POST['password2'] : '';
            if (strlen($p1) < 6) $errs[] = 'رمز عبور باید حداقل ۶ نویسه باشد.';
            elseif ($p1 !== $p2) $errs[] = 'رمز عبور و تکرار آن یکسان نیستند.';
        }
    }
    if (!$errs) {
        if ($mode === 'new') {
            $s1 = $posted['s1'];
            $res = clinic_create_client(array(
                'first_name' => $s1['first_name'], 'last_name' => $s1['last_name'],
                'mobile' => $s1['mobile'], 'birth_date' => $s1['birth_date'],
                'job' => $s1['job'], 'education' => $s1['education'], 'marital' => $s1['marital'],
                'emergency_contact' => $s1['emergency_contact'], 'referrer' => $s1['referrer'],
                'referrer_other' => $s1['referrer_other'], 'doctor_id' => $doctor_id,
            ), 0);
            if (!isset($res['id'])) {
                $errs[] = $res['error'];
            } else {
                $cid = (int) $res['id'];
                clinic_intake_save($cid, $posted, 'client');
                // حساب ورود
                $exu2 = clinic_user_by_phone($posted['s1']['mobile']);
                if ($exu2) {
                    clinic_update_client($cid, array('user_id' => (int) $exu2['id']), 0);
                } else {
                    clinic_client_user_ensure($cid, isset($_POST['password']) ? $_POST['password'] : '');
                    // ورود خودکار
                    $nu = clinic_user_by_phone($posted['s1']['mobile']);
                    if ($nu) $_SESSION['user'] = session_user_array($nu);
                }
                clinic_audit('ثبت فرم پذیرش (خوداظهاری)', $cid, '');
                clinic_burn_invite((int) $invite['id']);
                $has_risk = (!empty($posted['flags']['risks']) || !empty($posted['flags']['violence'])) ? '1' : '0';
                joma_redirect('index.php?p=clinic_intake&done=1&risk=' . $has_risk);
            }
        } else {
            // complete / mine
            $cid = (int) $client['id'];
            clinic_intake_save($cid, $posted, 'client');
            $exu3 = clinic_user_by_phone($posted['s1']['mobile']);
            if ($exu3 && (int) $client['user_id'] <= 0) {
                clinic_update_client($cid, array('user_id' => (int) $exu3['id']), 0);
            } elseif (!$exu3 && (int) $client['user_id'] <= 0) {
                clinic_client_user_ensure($cid, isset($_POST['password']) ? $_POST['password'] : '');
                $nu = clinic_user_by_phone($posted['s1']['mobile']);
                if ($nu && !$u) $_SESSION['user'] = session_user_array($nu);
            }
            clinic_audit('ثبت/به‌روزرسانی فرم جلسه اول (خوداظهاری)', $cid, '');
            if ($mode === 'complete') clinic_burn_invite((int) $invite['id']);
            if ($mode === 'mine') {
                flash_set('ok', 'فرم شما ثبت شد و برای درمانگر ارسال شد.');
                joma_redirect('index.php?p=clinic_client&tab=intake');
            }
            $has_risk = (!empty($posted['flags']['risks']) || !empty($posted['flags']['violence'])) ? '1' : '0';
            joma_redirect('index.php?p=clinic_intake&done=1&risk=' . $has_risk);
        }
    }
    // بازنمایش فرم با مقادیر پست‌شده
    $in = $posted;
}

$is_public = !$u;
joma_header('فرم پذیرش', array(), $is_public ? array('public' => 1) : array());
echo '<div class="card" style="max-width:720px;margin:20px auto;">';
echo '<h1>📋 فرم ارزیابی جلسه اول' . ($doc ? ' — ' . clinic_h(clinic_user_display($doc)) : '') . '</h1>';
echo '<div class="clinic-alert blue">پاسخ به سوالات به طراحی مسیر درمان دقیق‌تر کمک می‌کند؛ در صورت عدم آمادگی برای پاسخ، می‌توانید هر پرسش را خالی بگذارید.</div>';
if ($errs) {
    echo '<div class="clinic-alert red"><ul>';
    foreach ($errs as $er) echo '<li>' . $er . '</li>';
    echo '</ul></div>';
}
$action = 'index.php?p=clinic_intake' . ($token !== '' ? '&t=' . e($token) : '');
echo '<form method="post" action="' . $action . '">';
echo csrf_field();
echo clinic_intake_form_html($in, $opt, 'full');
echo clinic_consent_form_html($in['consent']);
if ($mode !== 'mine') {
    echo '<fieldset class="clinic-fs"><legend>🔐 حساب ورود شما</legend>';
    echo '<p class="hint">نام کاربری شما همان شماره موبایل است. اگر قبلاً از مطب رمز گرفته‌اید، این قسمت را خالی بگذارید.</p>';
    echo '<div class="field-row"><div><label>رمز عبور (حداقل ۶ نویسه)</label><input type="password" name="password" dir="ltr" autocomplete="new-password"></div>';
    echo '<div><label>تکرار رمز عبور</label><input type="password" name="password2" dir="ltr" autocomplete="new-password"></div></div>';
    echo '</fieldset>';
}
echo '<p><button class="btn btn-block" type="submit">ثبت نهایی فرم</button></p></form></div>';
joma_footer();
