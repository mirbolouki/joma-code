<?php
require __DIR__ . '/../src/center_hourly.php';
function same($a,$b,$message) { if ($a !== $b) throw new Exception($message . ': ' . var_export($a,true)); }
function rejects($fn,$message) { try { $fn(); } catch (InvalidArgumentException $e) { return; } catch (OverflowException $e) { return; } throw new Exception('Expected rejection: '.$message); }
$rows = array(array('reserved_minutes'=>60,'actual_minutes'=>35,'hourly_rate_rial'=>1000000), array('reserved_minutes'=>45,'actual_minutes'=>62,'hourly_rate_rial'=>2000000));
$r = joma_v2_center_hourly_exact('ACTUAL', $rows);
same($r['minutes'],97,'actual minutes');
same($r['whole_rial'],2650000,'sum exact');
same($r['remainder'],0,'no fraction after aggregate');
same(joma_v2_center_final_rial($r)['amount_rial'],2650000,'whole amount');
$r = joma_v2_center_hourly_exact('ACTUAL', array($rows[0]));
same($r['whole_rial'],583333,'fraction whole'); same($r['remainder'],20,'fraction remainder');
rejects(function() use ($r) { joma_v2_center_final_rial($r); },'fraction needs explicit choice');
rejects(function() use ($r) { joma_v2_center_final_rial($r,583333,' '); },'reason required');
same(joma_v2_center_final_rial($r,583334,'manual agreement')['amount_rial'],583334,'explicit decision');
$r = joma_v2_center_hourly_exact('RESERVED', array($rows[0]));
same($r['whole_rial'],1000000,'reserved minutes');
rejects(function(){ joma_v2_center_hourly_exact('ACTUAL',array(array('reserved_minutes'=>60,'actual_minutes'=>null,'hourly_rate_rial'=>100))); },'missing actual');
rejects(function(){ joma_v2_center_hourly_exact('UNKNOWN',array()); },'unknown basis');
rejects(function(){ joma_v2_center_hourly_exact('ACTUAL',array(array('reserved_minutes'=>1,'actual_minutes'=>2,'hourly_rate_rial'=>PHP_INT_MAX))); },'overflow');
$toman = array(array('reserved_minutes'=>60,'actual_minutes'=>35,'hourly_rate_toman'=>'100000.00'));
$r = joma_v2_center_hourly_toman_exact('ACTUAL', $toman);
same($r['whole_rial'],583333,'exact decimal room rate'); same($r['remainder'],200,'decimal remainder /600');
rejects(function() use ($r) { joma_v2_center_final_rial($r); },'fraction requires manual decision');
same(joma_v2_center_final_rial($r,583333,'manual contract')['amount_rial'],583333,'manual fraction decision');
$toman[0]['hourly_rate_toman']='1.23'; $toman[0]['actual_minutes']=60;
$r=joma_v2_center_hourly_toman_exact('ACTUAL',$toman);
same($r['whole_rial'],12,'fractional rial rate'); same($r['remainder'],180,'fractional room rate exact');
$toman[0]['hourly_rate_toman']=null;
rejects(function() use ($toman) { joma_v2_center_hourly_toman_exact('ACTUAL',$toman); },'missing rate');
echo "Hourly center calculations passed (no settlement integration).\n";
