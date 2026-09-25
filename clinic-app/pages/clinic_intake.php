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
$invite = $token !== '' ? cs_invite($token) : null;
$mode = '';
$client = null;
$doctor_id = 0;
if ($token !== '') {
    $proof=isset($_SESSION['cs_invite_verified'])?$_SESSION['cs_invite_verified']:null;
    if ($invite && (!$u || clinic_role()!=='client' || !$proof || $proof['token']!==$token || $proof['until']<time() || $proof['mobile']!==clinic_norm_mobile($invite['mobile']))) {
        joma_redirect('index.php?p=clinic_auth&t='.rawurlencode($token));
    }
    if ($invite && $u && clinic_role()==='client') {
        $client=clinic_get_client_by_user($u['id']);
        if (!$client || $client['mobile']!==clinic_norm_mobile($invite['mobile']) || (int)$client['doctor_id']!==(int)$invite['doctor_id']) $client=null;
    }
} elseif ($u && clinic_role()==='client') {
    $client=clinic_get_client_by_user($u['id']);
}
if ($client) {$mode='mine';$doctor_id=(int)$client['doctor_id'];}

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

$doc = clinic_get_user($doctor_id);
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
    $posted['s1']['mobile'] = $client['mobile'];
    $errs = clinic_intake_validate_final($posted);
    if (!$errs) {
        $db=clinic_db();mysqli_begin_transaction($db);
        try {
            if ($invite) {
                $locked=clinic_db_one('SELECT * FROM clinic_invites WHERE id=? FOR UPDATE','i',array($invite['id']));
                if (!$locked || $locked['used_at'] || $locked['expires_at']<joma_now()) throw new RuntimeException('دعوت‌نامه قبلاً استفاده شده یا منقضی است.');
            }
            if (!clinic_intake_save($client['id'],$posted,'client')) throw new RuntimeException('ذخیره فرم انجام نشد.');
            if ($invite) clinic_burn_invite($invite['id']);
            clinic_audit('ثبت فرم پذیرش با شماره تأییدشده',$client['id'],'');
            mysqli_commit($db);
            unset($_SESSION['cs_invite_verified']);
            flash_set('ok','فرم شما ثبت شد و برای درمانگر قابل بررسی است.');
            joma_redirect('index.php?p=clinic_client&tab=intake');
        } catch (Throwable $e) {
            mysqli_rollback($db);
            $errs[]='ذخیره فرم انجام نشد؛ اعتبار دعوت‌نامه و ارتباط دیتابیس را بررسی کنید.';
        }
    }
    $in=$posted;
}

$is_public = !$u;
joma_header('فرم پذیرش', array(), $is_public ? array('public' => 1) : array());
echo '<div class="card" style="max-width:720px;margin:20px auto;">';
echo '<h1>📋 فرم ارزیابی جلسه اول' . ($doc ? ' — ' . clinic_h(clinic_user_display($doc)) : '') . '</h1>';
echo '<div class="clinic-alert blue">پاسخ به سوالات به طراحی مسیر درمان دقیق‌تر کمک می‌کند؛ در صورت عدم آمادگی برای پاسخ، می‌توانید هر پرسش را خالی بگذارید.</div>';
if ($errs) {
    echo '<div class="clinic-alert red"><ul>';
    foreach ($errs as $er) echo '<li>' . e($er) . '</li>';
    echo '</ul></div>';
}
$action = 'index.php?p=clinic_intake' . ($token !== '' ? '&t=' . e($token) : '');
echo '<form method="post" action="' . $action . '">';
echo csrf_field();
echo clinic_intake_form_html($in, $opt, 'full');
echo clinic_consent_form_html($in['consent']);
echo '<p><button class="btn btn-block" type="submit">ثبت نهایی فرم</button></p></form></div>';
joma_footer();
