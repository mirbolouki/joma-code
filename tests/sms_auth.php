<?php
// Isolated contract tests: network/MySQL calls replaced with test doubles.
$root=sys_get_temp_dir().'/clinic-tests-'.bin2hex(random_bytes(4));
mkdir($root.'/functions',0700,true);mkdir($root.'/config',0700,true);mkdir($root.'/data',0700,true);
copy(__DIR__.'/../clinic-app/functions/clinic_sms.php',$root.'/functions/clinic_sms.php');
require $root.'/functions/clinic_sms.php';
function check($ok,$label) {if (!$ok) throw new RuntimeException('FAIL: '.$label); $GLOBALS['checks']++;echo "PASS $label\n";}
$GLOBALS['checks']=0;
function clinic_my_id(){return 7;}
function clinic_norm_mobile($m){return preg_match('/^09\d{9}$/',$m)?$m:'';}
function session_user_array($u){return $u;}
function curl_init($url){$GLOBALS['url']=$url;return new stdClass();}
function curl_setopt_array($ch,$opts){$GLOBALS['opts']=$opts;return true;}
function curl_exec($ch){return $GLOBALS['response'];}
function curl_getinfo($ch,$key){return $GLOBALS['http'];}
function curl_close($ch){}
function clinic_db(){return null;}
function mysqli_begin_transaction($db){$GLOBALS['snapshot']=array($GLOBALS['users'],$GLOBALS['clients']);}
function mysqli_commit($db){}
function mysqli_rollback($db){list($GLOBALS['users'],$GLOBALS['clients'])=$GLOBALS['snapshot'];}
function clinic_db_one($sql,$types,$values){return array('acquired'=>1,'released'=>1);}
function clinic_db_q($sql,$types,$v){return array_values(array_filter($GLOBALS['users'],function($u)use($v){return $u['phone']===$v[0]||$u['username']===$v[1];}));}
function clinic_get_client_by_mobile($m){foreach($GLOBALS['clients'] as $c)if($c['mobile']===$m)return $c;return null;}
function clinic_get_client_by_user($id){foreach($GLOBALS['clients'] as $c)if($c['user_id']===$id)return $c;return null;}
function clinic_get_client($id){return isset($GLOBALS['clients'][$id])?$GLOBALS['clients'][$id]:null;}
function clinic_get_user($id){return isset($GLOBALS['users'][$id])?$GLOBALS['users'][$id]:null;}
function clinic_create_client($v,$actor){$id=count($GLOBALS['clients'])+1;$GLOBALS['clients'][$id]=array_merge($v,array('id'=>$id,'user_id'=>0));return array('id'=>$id);}
function clinic_update_client($id,$patch,$actor){$GLOBALS['clients'][$id]=array_merge($GLOBALS['clients'][$id],$patch);return array('ok'=>true);}
function clinic_create_user($v){$id=count($GLOBALS['users'])+1;$GLOBALS['users'][$id]=array_merge($v,array('id'=>$id,'active'=>1));return $id;}
function clinic_update_user($id,$p){$GLOBALS['users'][$id]=array_merge($GLOBALS['users'][$id],$p);return true;}
function clinic_get_invite_by_token($t){return isset($GLOBALS['invites'][$t])?$GLOBALS['invites'][$t]:null;}
function fails($fn,$label){try{$fn();}catch(RuntimeException $e){check(true,$label);return;}check(false,$label);}
try {
    $_SESSION=array();$_SERVER['REMOTE_ADDR']='192.0.2.3';
    $config=cs_config();check(!$config['enabled'],'SMS disabled until configured');
    $config=array_merge($config,array('enabled'=>true,'api_key'=>'FAKE_TEST_SECRET','otp_template'=>123,'invite_template'=>456));cs_save_config($config);
    check(cs_config()['api_key']==='FAKE_TEST_SECRET','private config roundtrip');
    $GLOBALS['http']=200;$GLOBALS['response']='{"status":1,"data":{"messageId":987}}';
    $r=cs_verify_send('09120000000',123,'CODE','123456','otp');
    check($r['ok'] && $r['id']==='987','provider success requires message ID');
    check($GLOBALS['url']==='https://api.sms.ir/v1/send/verify','verify endpoint fixed HTTPS');
    check($GLOBALS['opts'][CURLOPT_SSL_VERIFYPEER] && $GLOBALS['opts'][CURLOPT_SSL_VERIFYHOST]===2,'TLS validation enabled');
    check(!$GLOBALS['opts'][CURLOPT_FOLLOWLOCATION],'no redirect credential leakage');
    $body=json_decode($GLOBALS['opts'][CURLOPT_POSTFIELDS],true);
    check($body['parameters'][0]['value']==='123456','documented JSON contract');
    cs_http('https://api.sms.ir/v1/send',array('username'=>'test','password'=>'fake','line'=>'3000','mobile'=>'09120000000','text'=>'سلام'),'',false);
    check(strpos($GLOBALS['url'],'?')===false && strpos($GLOBALS['opts'][CURLOPT_POSTFIELDS],'password=fake')!==false,'custom SMS uses POST body, not credential-bearing URL');
    check(!empty($body['templateId']),'template identifier supplied');
    check(!cs_verify_send('09120000000',456,'TOKEN',str_repeat('a',26),'invite')['ok'],'25 character parameter cap');
    check(cs_verify_send('09120000000',456,'TOKEN',bin2hex(random_bytes(12)),'invite')['ok'],'24 character invitation accepted');
    foreach(array(array(401,1),array(429,1),array(500,1),array(200,0)) as $v){$GLOBALS['http']=$v[0];$GLOBALS['response']=json_encode(array('status'=>$v[1],'data'=>array('messageId'=>12)));check(!cs_http('https://api.sms.ir/v1/send/verify',array(),'x')['ok'],'provider failure '.$v[0].'/'.$v[1]);}
    $GLOBALS['http']=200;$GLOBALS['response']='not-json';check(!cs_http('https://api.sms.ir/v1/send/verify',array(),'x')['ok'],'malformed response rejected');
    $GLOBALS['response']='{"status":1,"data":{"messageId":987}}';
    $r=cs_otp_request('09121111111','register','');check($r['ok'],'OTP request');
    $code=json_decode($GLOBALS['opts'][CURLOPT_POSTFIELDS],true)['parameters'][0]['value'];
    check(strlen($code)===6,'six digit OTP');
    $raw=file_get_contents($root.'/data/clinic_sms_state.json');check(strpos($raw,'"value":"'.$code.'"')===false && strpos($raw,'FAKE_TEST_SECRET')===false,'no raw OTP or API key in state/log');
    check(!cs_otp_check($code,'reset',''),'OTP purpose binding');
    check(!cs_otp_check($code,'register','different'),'OTP invitation binding');
    check(!cs_otp_request('09121111111','register','')['ok'],'resend cooldown across session');
    check(cs_otp_check($code,'register','')==='09121111111','correct OTP succeeds');
    check(!cs_otp_check($code,'register',''),'OTP single use');
    cs_otp_request('09122222222','register','');$id=$_SESSION['cs_challenge'];
    $code=json_decode($GLOBALS['opts'][CURLOPT_POSTFIELDS],true)['parameters'][0]['value'];
    for($i=0;$i<5;$i++) cs_otp_check('wrong','register','');
    check(!cs_otp_check($code,'register',''),'five failed attempts lock challenge');
    cs_otp_request('09123333333','register','');$id=$_SESSION['cs_challenge'];
    cs_state(function(&$s)use($id){$s['otp:'.$id]['until']=time()-1;});
    check(!cs_otp_check('123456','register',''),'expired challenge rejected');
    check(cs_limit(array(array('sample-limit',1,60))),'first limit reservation');
    $_SESSION=array();check(!cs_limit(array(array('sample-limit',1,60))),'clearing session cannot bypass limit');
    $GLOBALS['users']=array();$GLOBALS['clients']=array();$GLOBALS['invites']=array();
    $u=cs_finish_account('09124444444','long-password','نام','خانوادگی','register','');
    check($u['role_key']==='client' && count($GLOBALS['clients'])===1,'registration creates client role and one record');
    check($GLOBALS['clients'][1]['doctor_id']===0,'direct registration waits for doctor');
    check(password_verify('long-password',$u['password_hash']),'password hashed');
    cs_finish_account('09124444444','new-password','نام','خانوادگی','reset','');
    check(count($GLOBALS['clients'])===1 && count($GLOBALS['users'])===1,'reset reuses account and record');
    check(password_verify('new-password',$GLOBALS['users'][1]['password_hash']),'reset changes password');
    $GLOBALS['users'][1]['role_key']='admin';
    fails(function(){cs_finish_account('09124444444','hacker-password','','','reset','');},'staff account cannot be reset through client flow');
    check($GLOBALS['users'][1]['role_key']==='admin','staff role preserved');$GLOBALS['users'][1]['role_key']='client';
    $GLOBALS['users'][1]['active']=0;
    fails(function(){cs_finish_account('09124444444','hacker-password','','','reset','');},'inactive account rejected');$GLOBALS['users'][1]['active']=1;
    fails(function(){cs_finish_account('09125555555','long-password','','','reset','');},'reset cannot create unknown account');
    $GLOBALS['invites']['invite']=array('mobile'=>'09125555555','doctor_id'=>42,'client_id'=>0);
    $u=cs_finish_account('09125555555','long-password','نام','دعوت','register','invite');
    check($GLOBALS['clients'][2]['doctor_id']===42,'invitation assigns issuing doctor');
    fails(function(){cs_finish_account('09124444444','long-password','','','register','invite');},'invitation cannot target another mobile');
    $GLOBALS['invites']['other']=array('mobile'=>'09125555555','doctor_id'=>43,'client_id'=>0);
    fails(function(){cs_finish_account('09125555555','long-password','','','register','other');},'existing doctor not silently replaced');
    check($GLOBALS['clients'][2]['doctor_id']===42,'doctor preserved on rejection');
    $GLOBALS['clients'][2]['user_id']=999;
    fails(function(){cs_finish_account('09125555555','long-password','','','register','invite');},'ambiguous account binding rejected');
    echo 'ALL '.$GLOBALS['checks']." CHECKS PASSED (mock transport and database)\n";
} finally {
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $f){$f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname());}rmdir($root);
}
