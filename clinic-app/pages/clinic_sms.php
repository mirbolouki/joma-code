<?php
clinic_require_staff();
$c=cs_config();$msg='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    csrf_check();
    try {
        if (isset($_POST['save_config'])) {
            clinic_require_admin();
            foreach (array('otp_template','invite_template') as $k) $c[$k]=max(0,(int)$_POST[$k]);
            foreach (array('otp_parameter','invite_parameter') as $k) {
                if (!preg_match('/^[A-Za-z0-9_]{1,40}$/',$_POST[$k])) throw new RuntimeException('نام پارامتر باید مطابق قالب باشد.');
                $c[$k]=$_POST[$k];
            }
            foreach (array('username','line') as $k) $c[$k]=trim($_POST[$k]);
            $key=trim(isset($_POST['api_key'])?$_POST['api_key']:'');
            if ($key!=='') {if (preg_match('/[\r\n]/',$key)) throw new RuntimeException('کلید نامعتبر است.');$c['api_key']=$key;}
            $c['enabled']=isset($_POST['enabled']);$c['free_text']=isset($_POST['free_text']);
            cs_save_config($c);$msg='تنظیمات ذخیره شد.';
        } else {
            $cid=(int)(isset($_POST['client_id'])?$_POST['client_id']:0);$cl=clinic_get_client($cid);
            if (!$cl || !clinic_can_access_client($cl)) throw new RuntimeException('پرونده در محدوده دسترسی شما نیست.');
            $nonce=isset($_POST['send_nonce'])?(string)$_POST['send_nonce']:'';
            if (!$nonce || empty($_SESSION['cs_send_nonce']) || !hash_equals($_SESSION['cs_send_nonce'],$nonce)) throw new RuntimeException('فرم ارسال قدیمی است؛ صفحه را دوباره باز کنید.');
            unset($_SESSION['cs_send_nonce']); // no accidental resends on refresh
            if (!cs_limit(array(array('staff:'.clinic_my_id(),20,3600),array('staff:dest:'.$cl['mobile'],5,3600),array('sms:global',100,86400)))) throw new RuntimeException('محدودیت تعداد ارسال؛ بعداً امتحان کنید.');
            if (isset($_POST['send_invite'])) {
                if ((int)$cl['doctor_id']<=0) throw new RuntimeException('ابتدا پزشک پرونده را تعیین کنید.');
                $tok=clinic_create_invite($cl['doctor_id'],$cl['id'],$cl['mobile'],clinic_my_id());
                $r=cs_verify_send($cl['mobile'],$c['invite_template'],$c['invite_parameter'],$tok,'invite');
                $msg=$r['msg'];
            } else {
                if (!in_array(clinic_role(),array('admin','head_secretary','secretary'),true)) throw new RuntimeException('ارسال متن دلخواه فقط برای مدیر و منشی است.');
                if (!$c['enabled'] || !$c['free_text'] || !$c['username'] || !$c['api_key'] || !$c['line']) throw new RuntimeException('ارسال متن دلخواه یا مشخصات خط فعال نیست.');
                if (empty($_POST['confirm'])) throw new RuntimeException('متن و مقصد را تأیید کنید.');
                $text=trim(isset($_POST['text'])?$_POST['text']:'');
                if ($text==='' || mb_strlen($text)>1000) throw new RuntimeException('متن باید بین ۱ تا ۱۰۰۰ نویسه باشد.');
                // Documented POST alternative to URL GET: credentials never in URL.
                $r=cs_http('https://api.sms.ir/v1/send',array('username'=>$c['username'],'password'=>$c['api_key'],'line'=>$c['line'],'mobile'=>$cl['mobile'],'text'=>$text),'',false);
                cs_log('custom',$cl['mobile'],$r);$msg=$r['msg'];
            }
        }
    } catch (mysqli_sql_exception $e) {$msg='خطای دیتابیس؛ مدیر باید بررسی کند.';}
      catch (RuntimeException $e) {$msg=$e->getMessage();}
}
joma_header('پیامک و دعوت‌نامه');echo '<div class="card"><h1>پیامک و دعوت‌نامه</h1><p>'.e($msg).'</p><p>پذیرش ارسال با تحویل به گوشی متفاوت است. متن پیامک نباید حاوی اطلاعات بالینی باشد.</p></div>';
if (clinic_is_admin()) {
    echo '<div class="card"><h2>تنظیم اتصال sms.ir</h2><form method="post">'.csrf_field().'<input type="hidden" name="save_config" value="1">';
    echo '<label><input type="checkbox" name="enabled" '.($c['enabled']?'checked':'').'> فعال‌بودن ارسال پیامک و کد تأیید</label>';
    echo '<label>کلید خصوصی API (خالی = بدون تغییر؛ مقدار فعلی نمایش داده نمی‌شود)</label><input type="password" name="api_key" autocomplete="new-password">';
    foreach (array('otp_template'=>'شناسه قالب کد تأیید','otp_parameter'=>'نام پارامتر کد؛ مثلاً CODE','invite_template'=>'شناسه قالب دعوت','invite_parameter'=>'نام پارامتر دعوت؛ مثلاً TOKEN','username'=>'نام کاربری sms.ir برای متن دلخواه','line'=>'شماره خط ارسال متن دلخواه') as $k=>$label) echo '<label>'.e($label).'</label><input name="'.$k.'" value="'.e($c[$k]).'" dir="ltr">';
    echo '<label><input type="checkbox" name="free_text" '.($c['free_text']?'checked':'').'> فعال‌کردن ارسال متن دلخواه از خط پنل</label><button class="btn">ذخیره تنظیمات</button></form>';
    echo '<h3>متن پیشنهادی قالب دعوت برای این زیردامنه</h3><pre dir="ltr">https://my.mirbolouki.com/index.php?i=#TOKEN#</pre><p>دامنه و مسیر بالا بخش ثابت قالب هستند؛ TOKEN شناسه ۲۴ نویسه‌ای دعوت است. کل لینک را پارامتر نگذارید. برای کد از #CODE# استفاده کنید. هر دو قالب باید توسط sms.ir تأیید شوند.</p></div>';
}
$_SESSION['cs_send_nonce']=bin2hex(random_bytes(16));
echo '<div class="card"><h2>ارسال برای مراجع</h2><form method="post">'.csrf_field().'<input type="hidden" name="send_nonce" value="'.e($_SESSION['cs_send_nonce']).'"><label>پرونده و شماره مقصد</label><select name="client_id" required><option value="">انتخاب کنید</option>';
foreach (clinic_list_clients(array('status'=>'active')) as $cl) echo '<option value="'.(int)$cl['id'].'">'.e(clinic_client_display_name($cl).' — '.$cl['mobile'].' — '.$cl['file_no']).'</option>';
echo '</select><button class="btn" name="send_invite" value="1">ارسال لینک فرم پذیرش</button>';
if (in_array(clinic_role(),array('admin','head_secretary','secretary'),true)) echo '<hr><label>متن دلخواه</label><textarea name="text" maxlength="1000" rows="5"></textarea><label><input type="checkbox" name="confirm" value="1">متن و شماره مقصد را بررسی و هزینه ارسال را تأیید می‌کنم.</label><button class="btn" name="send_custom" value="1">ارسال متن دلخواه</button>';
echo '</form></div>';
if (clinic_is_admin()) {
    $logs=cs_state(function (&$s) {return isset($s['log']['items'])?array_reverse($s['log']['items']):array();});
    echo '<div class="card"><h2>۲۰۰ درخواست اخیر (بدون متن و کد محرمانه)</h2><table><tr><th>زمان</th><th>نوع</th><th>مقصد</th><th>پذیرش API</th><th>شناسه پیام / وضعیت</th></tr>';
    foreach ($logs as $r) echo '<tr><td>'.e($r['time']).'</td><td>'.e($r['kind']).'</td><td>'.e($r['mobile']).'</td><td>'.($r['ok']?'پذیرفته شد؛ تحویل نامشخص':'تأیید نشد').'</td><td>'.e($r['id'].' / '.$r['code']).'</td></tr>';
    echo '</table></div>';
}
joma_footer();
