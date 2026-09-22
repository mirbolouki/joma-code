<?php
// Clinic SMS/auth extension. Secrets are never returned to the browser or logs.
function cs_config() {
    $path = __DIR__ . '/../config/clinic_sms.php';
    $v = is_file($path) ? include $path : array();
    return array_merge(array('enabled'=>false,'api_key'=>'','otp_template'=>0,'invite_template'=>0,
        'otp_parameter'=>'CODE','invite_parameter'=>'TOKEN','username'=>'','line'=>'','free_text'=>false), is_array($v)?$v:array());
}
function cs_save_config($v) {
    $path = __DIR__ . '/../config/clinic_sms.php';
    $tmp = tempnam(dirname($path), '.sms-');
    if (!$tmp) throw new RuntimeException('ذخیره تنظیمات ممکن نیست.');
    chmod($tmp, 0600);
    if (file_put_contents($tmp, "<?php\nreturn " . var_export($v,true) . ";\n") === false || !rename($tmp,$path)) {
        @unlink($tmp); throw new RuntimeException('ذخیره تنظیمات ممکن نیست.');
    }
}
// All mutations serialized; rate limits cannot be bypassed by clearing cookies.
function cs_state($callback) {
    $dir = __DIR__ . '/../data';
    if (!is_dir($dir)) mkdir($dir,0750,true);
    $f = fopen($dir.'/clinic_sms_state.json','c+');
    if (!$f || !flock($f, LOCK_EX)) throw new RuntimeException('ذخیره امن موقتاً در دسترس نیست.');
    @chmod($dir.'/clinic_sms_state.json',0600);
    try {
        $raw=stream_get_contents($f); $s=$raw===''?array():json_decode($raw,true);
        if (!is_array($s)) throw new RuntimeException('ذخیره امن نیاز به بررسی مدیر دارد.');
        foreach ($s as $k=>$v) if (isset($v['until']) && $v['until']<time()) unset($s[$k]);
        $result=$callback($s);
        $out=json_encode($s,JSON_UNESCAPED_UNICODE);
        rewind($f);
        if (!ftruncate($f,0) || fwrite($f,$out)!==strlen($out) || !fflush($f)) throw new RuntimeException('ذخیره امن ناموفق بود.');
        return $result;
    } finally {flock($f,LOCK_UN);fclose($f);}
}
function cs_limit($items) {
    return cs_state(function (&$s) use ($items) {
        foreach ($items as $i) {
            $key='limit:'.hash('sha256',$i[0]);
            if (isset($s[$key]) && $s[$key]['n'] >= $i[1]) return false;
        }
        foreach ($items as $i) {
            $key='limit:'.hash('sha256',$i[0]);
            if (!isset($s[$key])) $s[$key]=array('n'=>0,'until'=>time()+$i[2]);
            $s[$key]['n']++;
        }
        return true;
    });
}
function cs_ip() {return isset($_SERVER['REMOTE_ADDR'])?$_SERVER['REMOTE_ADDR']:'unknown';}
function cs_log($kind,$mobile,$result) {
    cs_state(function (&$s) use ($kind,$mobile,$result) {
        if (!isset($s['log'])) $s['log']=array('items'=>array());
        $s['log']['items'][]=array('time'=>date('Y-m-d H:i:s'),'kind'=>$kind,
            'mobile'=>substr($mobile,0,4).'***'.substr($mobile,-4),'actor'=>clinic_my_id(),
            'ok'=>!empty($result['ok']),'id'=>isset($result['id'])?$result['id']:'',
            'code'=>isset($result['code'])?$result['code']:'');
        $s['log']['items']=array_slice($s['log']['items'],-200);
    });
}
function cs_http($url,$body,$key,$json=true) {
    if (!function_exists('curl_init')) return array('ok'=>false,'code'=>'curl_missing','msg'=>'افزونه cURL فعال نیست.');
    $ch=curl_init($url);
    curl_setopt_array($ch,array(CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_HTTPHEADER=>$json?array('Accept: application/json','Content-Type: application/json','X-API-KEY: '.$key):array('Accept: application/json','Content-Type: application/x-www-form-urlencoded'),
        CURLOPT_POSTFIELDS=>$json?json_encode($body):http_build_query($body)));
    $raw=curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    $r=is_string($raw)?json_decode($raw,true):null;
    $ok=$http===200 && is_array($r) && isset($r['status']) && (int)$r['status']===1 && !empty($r['data']['messageId']);
    return array('ok'=>$ok,'id'=>$ok?(string)$r['data']['messageId']:'','code'=>$http.':'.(isset($r['status'])?(int)$r['status']:'network'),
        'msg'=>$ok?'درخواست ارسال توسط sms.ir پذیرفته شد؛ تحویل به گوشی هنوز تأیید نشده است.':'ارسال تأیید نشد. اعتبار پنل، کلید، قالب و ارتباط هاست را بررسی کنید. در خطای ارتباط، پیش از تکرار گزارش پنل پیامکی را ببینید.');
}
function cs_verify_send($mobile,$template,$name,$value,$kind) {
    $c=cs_config();
    if (!$c['enabled'] || !$c['api_key'] || (int)$template<=0) return array('ok'=>false,'msg'=>'ارسال پیامکی هنوز توسط مدیر تنظیم نشده است.');
    if (!preg_match('/^[A-Za-z0-9_]+$/',$name) || mb_strlen($value)>25) return array('ok'=>false,'msg'=>'پارامتر قالب نامعتبر است؛ حداکثر ۲۵ نویسه.');
    $r=cs_http('https://api.sms.ir/v1/send/verify',array('mobile'=>$mobile,'templateId'=>(int)$template,
        'parameters'=>array(array('name'=>$name,'value'=>$value))),$c['api_key']);
    cs_log($kind,$mobile,$r);return $r;
}
function cs_otp_request($mobile,$purpose,$token) {
    if ($mobile==='') return array('ok'=>false,'msg'=>'شماره همراه معتبر وارد کنید.');
    if (!cs_limit(array(array('sms:m:'.$mobile,1,90),array('sms:mh:'.$mobile,5,3600),array('sms:md:'.$mobile,10,86400),
        array('sms:ip:'.cs_ip(),15,3600),array('sms:global',100,86400)))) return array('ok'=>false,'msg'=>'تعداد درخواست‌ها زیاد است. کمی بعد دوباره امتحان کنید.');
    $code=(string)random_int(100000,999999);$id=bin2hex(random_bytes(16));
    cs_state(function (&$s) use ($id,$mobile,$purpose,$token,$code) {
        $s['latest:'.$mobile]=array('id'=>$id,'until'=>time()+300);
        $s['otp:'.$id]=array('hash'=>password_hash($code,PASSWORD_DEFAULT),'mobile'=>$mobile,'purpose'=>$purpose,'token'=>$token,'tries'=>0,'until'=>time()+300);
    });
    $c=cs_config();$r=cs_verify_send($mobile,$c['otp_template'],$c['otp_parameter'],$code,'otp');
    if ($r['ok']) $_SESSION['cs_challenge']=$id;
    else cs_state(function (&$s) use ($id) {unset($s['otp:'.$id]);});
    return $r;
}
function cs_otp_check($code,$purpose,$token) {
    $id=isset($_SESSION['cs_challenge'])?$_SESSION['cs_challenge']:'';
    return cs_state(function (&$s) use ($id,$code,$purpose,$token) {
        $key='otp:'.$id;
        if (!isset($s[$key])) return false;
        $r=&$s[$key];
        if ($r['purpose']!==$purpose || $r['token']!==$token || $r['tries']>=5 || !isset($s['latest:'.$r['mobile']]) || $s['latest:'.$r['mobile']]['id']!==$id) return false;
        $r['tries']++;
        if (!password_verify($code,$r['hash'])) return false;
        $mobile=$r['mobile'];unset($s[$key]);return $mobile;
    });
}
function cs_login($u) {
    session_regenerate_id(true);unset($_SESSION['csrf'],$_SESSION['cs_challenge'],$_SESSION['cs_grant']);
    $_SESSION['user']=session_user_array($u);
    $_SESSION['clinic_auth_version']=hash('sha256',$u['password_hash']);
}
function cs_invite($token) {
    $inv=clinic_get_invite_by_token($token);
    if (!$inv) return null;
    if ($inv['client_id']) {
        $cl=clinic_get_client($inv['client_id']);
        if (!$cl || (int)$cl['doctor_id']!==(int)$inv['doctor_id']) return null;
        $inv['mobile']=$cl['mobile'];
    }
    return clinic_norm_mobile($inv['mobile'])!==''?$inv:null;
}
// Verified ownership is necessary, not sufficient: staff/ambiguous bindings are rejected.
function cs_finish_account($mobile,$password,$first,$last,$purpose,$token) {
    $db=clinic_db();
    $lock=clinic_db_one("SELECT GET_LOCK('clinic_account_write_v21',10) AS acquired",'',array());
    if (!$lock || (int)$lock['acquired']!==1) throw new RuntimeException('لطفاً دوباره تلاش کنید.');
    mysqli_begin_transaction($db);
    try {
        $inv=$token!==''?cs_invite($token):null;
        if ($token!=='' && (!$inv || clinic_norm_mobile($inv['mobile'])!==$mobile)) throw new RuntimeException('دعوت‌نامه معتبر نیست. از مطب لینک جدید بگیرید.');
        $users=clinic_db_q('SELECT * FROM clinic_users WHERE phone=? OR username=? FOR UPDATE','ss',array($mobile,$mobile));
        if (count($users)>1) throw new RuntimeException('برای بررسی اتصال پرونده با مطب تماس بگیرید.');
        $u=$users?$users[0]:null;
        if ($u && ($u['role_key']!=='client' || !(int)$u['active'])) throw new RuntimeException('این مسیر فقط برای حساب فعال مراجعان است.');
        if ($purpose==='reset' && !$u) throw new RuntimeException('حساب مراجع پیدا نشد؛ از ثبت‌نام استفاده کنید.');
        $cl=clinic_get_client_by_mobile($mobile);
        if ($inv && $inv['client_id'] && (!$cl || (int)$cl['id']!==(int)$inv['client_id'])) throw new RuntimeException('پرونده با دعوت‌نامه مطابقت ندارد.');
        if ($cl && (int)$cl['user_id'] && (!$u || (int)$cl['user_id']!==(int)$u['id'])) throw new RuntimeException('اتصال حساب به پرونده نیاز به بررسی مطب دارد.');
        if ($u) {
            $other=clinic_get_client_by_user($u['id']);
            if ($other && (!$cl || (int)$other['id']!==(int)$cl['id'])) throw new RuntimeException('شماره پرونده با حساب مطابقت ندارد. با مطب تماس بگیرید.');
        }
        if ($inv && $cl && (int)$cl['doctor_id']>0 && (int)$cl['doctor_id']!==(int)$inv['doctor_id']) throw new RuntimeException('تغییر پزشک باید توسط مطب بررسی شود.');
        $doctor=$cl?(int)$cl['doctor_id']:($inv?(int)$inv['doctor_id']:0);
        if (!$cl) {
            if ($first==='' || $last==='') throw new RuntimeException('نام و نام خانوادگی لازم است.');
            // Public registration can wait for assignment; staff creation still requires a doctor.
            $res=clinic_create_client(array('first_name'=>$first,'last_name'=>$last,'mobile'=>$mobile,'doctor_id'=>$doctor,'verified_registration'=>true),0);
            if (empty($res['id'])) throw new RuntimeException('ساخت پرونده ممکن نشد. با مطب تماس بگیرید.');
            $cl=clinic_get_client($res['id']);
        } elseif ($inv && $doctor===0) {
            $doctor=(int)$inv['doctor_id'];clinic_update_client($cl['id'],array('doctor_id'=>$doctor),0);
        }
        $hash=password_hash($password,PASSWORD_DEFAULT);
        if ($u) {clinic_update_user($u['id'],array('password_hash'=>$hash));$uid=(int)$u['id'];}
        else $uid=clinic_create_user(array('first_name'=>$cl['first_name'],'last_name'=>$cl['last_name'],'username'=>$mobile,'phone'=>$mobile,'password_hash'=>$hash,'role_key'=>'client','doctor_id'=>$doctor));
        $updated=$uid?clinic_update_client($cl['id'],array('user_id'=>$uid),0):array();
        if (!$uid || empty($updated['ok'])) throw new RuntimeException('ثبت حساب ناموفق بود.');
        mysqli_commit($db);return clinic_get_user($uid);
    } catch (Throwable $e) {mysqli_rollback($db);throw $e;}
    finally {clinic_db_one("SELECT RELEASE_LOCK('clinic_account_write_v21') AS released",'',array());}
}
