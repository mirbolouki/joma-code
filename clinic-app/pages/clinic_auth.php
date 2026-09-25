<?php
header('Cache-Control: no-store');header('Referrer-Policy: no-referrer');
$purpose=isset($_GET['mode']) && $_GET['mode']==='reset'?'reset':'register';
$token=isset($_GET['t'])?(string)$_GET['t']:'';
$inv=$token!==''?cs_invite($token):null;
if ($token!=='' && !$inv) {joma_header('دعوت‌نامه');echo '<div class="card">دعوت‌نامه معتبر نیست یا شماره همراه ندارد. از مطب لینک جدید بگیرید.</div>';joma_footer();return;}
if (current_user() && clinic_role()!=='client') {clinic_deny('برای استفاده از مسیر مراجعان ابتدا از حساب کارکنان خارج شوید.');}
$err='';$message='';
$grant=isset($_SESSION['cs_grant'])?$_SESSION['cs_grant']:null;
if ($grant && ($grant['until']<time() || $grant['purpose']!==$purpose || $grant['token']!==$token)) {unset($_SESSION['cs_grant']);$grant=null;}
if ($_SERVER['REQUEST_METHOD']==='POST') {
    csrf_check();
    try {
        $action=isset($_POST['action'])?$_POST['action']:'';
        if ($action==='send') {
            unset($_SESSION['cs_grant']);$grant=null;
            $mobile=$inv?clinic_norm_mobile($inv['mobile']):clinic_norm_mobile(isset($_POST['mobile'])?$_POST['mobile']:'');
            $r=cs_otp_request($mobile,$purpose,$token);
            if (!$r['ok']) $err=$r['msg']; else $message='درخواست ارسال کد ثبت شد. کد ۵ دقیقه اعتبار دارد؛ ارسال مجدد پس از ۹۰ ثانیه ممکن است.';
        } elseif ($action==='verify') {
            $mobile=cs_otp_check(clinic_norm_digits(trim(isset($_POST['code'])?$_POST['code']:'')),$purpose,$token);
            if (!$mobile) $err='کد نامعتبر، منقضی یا بیش از حد امتحان شده است.';
            else {session_regenerate_id(true);$_SESSION['cs_grant']=array('mobile'=>$mobile,'purpose'=>$purpose,'token'=>$token,'until'=>time()+900);$grant=$_SESSION['cs_grant'];}
        } elseif ($action==='finish' && $grant) {
            $pw=isset($_POST['password'])?(string)$_POST['password']:'';
            if (strlen($pw)<10 || strlen($pw)>72 || $pw!==(isset($_POST['password2'])?$_POST['password2']:'')) throw new RuntimeException('رمز و تکرار آن یکسان و بین ۱۰ تا ۷۲ بایت باشند.');
            $u=cs_finish_account($grant['mobile'],$pw,mb_substr(trim(isset($_POST['first'])?$_POST['first']:''),0,100),mb_substr(trim(isset($_POST['last'])?$_POST['last']:''),0,100),$purpose,$token);
            cs_login($u);
            if ($token!=='') $_SESSION['cs_invite_verified']=array('token'=>$token,'mobile'=>$grant['mobile'],'until'=>time()+3600);
            joma_redirect($token!==''?'index.php?p=clinic_intake&t='.$token:'index.php?p=clinic_dashboard');
        }
    } catch (mysqli_sql_exception $e) {$err='ثبت اطلاعات انجام نشد. مدیر باید اتصال و وضعیت دیتابیس را بررسی کند.';}
      catch (RuntimeException $e) {$err=$e->getMessage();}
}
joma_header($purpose==='reset'?'بازیابی رمز مراجع':'ثبت‌نام و تأیید شماره',array(),array('public'=>1));
echo '<div class="card auth-card"><h1>'.($purpose==='reset'?'بازیابی رمز مراجع':'تأیید شماره و حساب مراجع').'</h1>';
if ($inv) echo '<p>دعوت به تکمیل فرم پذیرش؛ کد فقط به شماره ثبت‌شده توسط مطب ارسال می‌شود.</p>';
if ($err) echo '<p class="bad">'.e($err).'</p>';
if ($message) echo '<p>'.e($message).'</p>';
if (!$grant) {
    echo '<form method="post">'.csrf_field().'<input type="hidden" name="action" value="send">';
    if (!$inv) echo '<label>شماره همراه</label><input name="mobile" dir="ltr" inputmode="tel" autocomplete="tel" required maxlength="16">';
    echo '<button class="btn">ارسال کد تأیید</button></form>';
    echo '<form method="post">'.csrf_field().'<input type="hidden" name="action" value="verify"><label>کد شش‌رقمی پیامک</label><input name="code" dir="ltr" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required><button class="btn">تأیید کد</button></form>';
} else {
    echo '<p>شماره تأیید شد. رمز جدید را تعیین کنید؛ از این پس با شماره همراه و این رمز وارد می‌شوید.</p><form method="post">'.csrf_field().'<input type="hidden" name="action" value="finish">';
    echo '<label>نام (برای پرونده جدید)</label><input name="first" maxlength="100"><label>نام خانوادگی (برای پرونده جدید)</label><input name="last" maxlength="100">';
    echo '<label>رمز جدید (حداقل ۱۰ نویسه انگلیسی)</label><input name="password" type="password" autocomplete="new-password" required minlength="10" maxlength="72"><label>تکرار رمز</label><input name="password2" type="password" autocomplete="new-password" required><button class="btn">ثبت رمز و ورود</button></form>';
}
echo '<p><a href="'.e(joma_url('index.php?p=clinic_login')).'">بازگشت به ورود با رمز</a></p></div>';joma_footer();
