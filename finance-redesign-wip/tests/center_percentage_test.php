<?php
require __DIR__ . '/../src/center_percentage.php';
function same($actual,$expected,$message) { if ($actual !== $expected) throw new Exception($message.': '.var_export($actual,true)); }
function rejects($f,$message) { try { $f(); } catch (InvalidArgumentException $e) { return; } catch (OverflowException $e) { return; } throw new Exception('Expected rejection: '.$message); }
$items=array(array('kind'=>'DIRECT_RECEIPT','source_id'=>1,'amount_rial'=>400000),
 array('kind'=>'REQUEST_TRANSFER','source_id'=>2,'amount_rial'=>100000),
 array('kind'=>'LEGACY_DEPOSIT','source_id'=>3,'amount_rial'=>50000),array('kind'=>'LEGACY_BALANCE','source_id'=>3,'amount_rial'=>50000));
$r=joma_v2_center_percentage_exact('12.50',$items);
same($r['gross_rial'],600000,'sum independent sources'); same($r['whole_rial'],75000,'exact share');
same(joma_v2_center_percentage_final($r)['amount_rial'],75000,'whole rial');
$r=joma_v2_center_percentage_exact('33.33',array(array('kind'=>'DIRECT_RECEIPT','source_id'=>1,'amount_rial'=>1)));
same($r['whole_rial'],0,'fraction whole'); same($r['remainder'],3333,'fraction retained');
rejects(function()use($r){joma_v2_center_percentage_final($r);},'no rounding');
same(joma_v2_center_percentage_final($r,1,'agreed')['amount_rial'],1,'manual fraction');
$items[]=array('kind'=>'REFUND','source_id'=>4,'amount_rial'=>50000);
$r=joma_v2_center_percentage_exact('12.50',$items);
same($r['gross_rial'],600000,'refund not silently subtracted'); same(count($r['manual_items']),1,'manual review flagged');
rejects(function()use($r){joma_v2_center_percentage_final($r);},'refund needs review');
rejects(function()use($r){joma_v2_center_percentage_final($r,70000,' ');},'manual reason required');
same(joma_v2_center_percentage_final($r,70000,'contract decision')['amount_rial'],70000,'manual decision');
$items[]=array('kind'=>'APPOINTMENT_TRANSFER','source_id'=>5,'amount_rial'=>40000);
$r=joma_v2_center_percentage_exact('12.50',$items); same(count($r['manual_items']),2,'transfer review');
$items[]=$items[0]; rejects(function()use($items){joma_v2_center_percentage_exact('12.50',$items);},'duplicate source');
rejects(function(){joma_v2_percent_basis_points('100.01');},'over 100 percent');
rejects(function(){joma_v2_percent_basis_points('-1');},'negative percent');
$r=joma_v2_center_percentage_exact('100.00',array(array('kind'=>'DIRECT_RECEIPT','source_id'=>1,'amount_rial'=>999999999999999999)));
same($r['whole_rial'],999999999999999999,'large exact, no float'); same($r['remainder'],0,'no overflow');
rejects(function(){joma_v2_center_percentage_exact('1.00',array(array('kind'=>'DIRECT_RECEIPT','source_id'=>1,'amount_rial'=>999999999999999999),array('kind'=>'DIRECT_RECEIPT','source_id'=>2,'amount_rial'=>1)));},'total overflow');
$events=array(
 array('id'=>10,'type'=>'RECEIPT','source_kind'=>'REQUEST','amount_rial'=>500,'occurred_at'=>'2026-10-01 10:00:00'),
 array('id'=>11,'type'=>'TRANSFER','source_kind'=>'REQUEST','destination_kind'=>'APPOINTMENT','amount_rial'=>300,'occurred_at'=>'2026-10-03 10:00:00','center_snapshot_recorded'=>true,'source_center_id'=>null,'destination_center_id'=>2),
 array('id'=>12,'type'=>'RECEIPT','source_kind'=>'APPOINTMENT','amount_rial'=>200,'occurred_at'=>'2026-10-04 10:00:00','center_snapshot_recorded'=>true,'source_center_id'=>2,'destination_center_id'=>null),
 array('id'=>13,'type'=>'REFUND','source_kind'=>'APPOINTMENT','amount_rial'=>50,'occurred_at'=>'2026-10-05 10:00:00','center_snapshot_recorded'=>true,'source_center_id'=>2,'destination_center_id'=>null),
 array('id'=>14,'type'=>'TRANSFER','source_kind'=>'APPOINTMENT','destination_kind'=>'APPOINTMENT','amount_rial'=>100,'occurred_at'=>'2026-10-06 10:00:00','center_snapshot_recorded'=>true,'source_center_id'=>2,'destination_center_id'=>3)
);
$legacy=array(array('id'=>1,'center_verified'=>true,'has_new_history'=>false,'deposit_center_id'=>2,'balance_center_id'=>null,'deposit_status'=>'PAID','deposit_amount_rial'=>400,'deposit_paid_at'=>'2026-10-02 10:00:00','balance_status'=>'PENDING'));
$sources=joma_v2_percentage_sources(2,'2026-10-02 00:00:00','2026-10-07 00:00:00',$events,$legacy);
$r=joma_v2_center_percentage_exact('10.00',$sources); same($r['gross_rial'],900,'allocated deposit and direct receipt and old deposit, not raw request receipt');
same(count($r['manual_items']),2,'refund and appointment transfer need review');
$sources=joma_v2_percentage_sources(2,'2026-10-01 00:00:00','2026-10-03 00:00:00',$events,$legacy);
$r=joma_v2_center_percentage_exact('10.00',$sources); same($r['gross_rial'],400,'request receipt is not attributed early');
$split=$legacy; $split[0]['balance_status']='PAID'; $split[0]['balance_center_id']=3; $split[0]['balance_amount_rial']=600; $split[0]['balance_paid_at']='2026-10-02 11:00:00';
$r=joma_v2_center_percentage_exact('10.00',joma_v2_percentage_sources(2,'2026-10-02 00:00:00','2026-10-03 00:00:00',array(),$split)); same($r['gross_rial'],400,'legacy deposit goes to verified center');
$r=joma_v2_center_percentage_exact('10.00',joma_v2_percentage_sources(3,'2026-10-02 00:00:00','2026-10-03 00:00:00',array(),$split)); same($r['gross_rial'],600,'legacy balance may have a different verified center');
$bad=$events; $bad[1]['center_snapshot_recorded']=false;
rejects(function()use($bad,$legacy){joma_v2_percentage_sources(2,'2026-10-02 00:00:00','2026-10-07 00:00:00',$bad,$legacy);},'unknown event center');
$bad=$legacy; $bad[0]['center_verified']=false;
rejects(function()use($events,$bad){joma_v2_percentage_sources(2,'2026-10-02 00:00:00','2026-10-07 00:00:00',$events,$bad);},'legacy center unverified');
$bad=$legacy; $bad[0]['has_new_history']=true;
rejects(function()use($events,$bad){joma_v2_percentage_sources(2,'2026-10-02 00:00:00','2026-10-07 00:00:00',$events,$bad);},'legacy overlap');
$bad=$legacy; $bad[0]['deposit_paid_at']=null;
rejects(function()use($events,$bad){joma_v2_percentage_sources(2,'2026-10-02 00:00:00','2026-10-07 00:00:00',$events,$bad);},'missing legacy date');
echo "Percentage center arithmetic and attribution tests passed (not wired to site).\n";
