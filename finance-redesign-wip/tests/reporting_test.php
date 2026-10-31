<?php
require __DIR__ . '/../src/reporting.php';
function same($a,$b,$message) { if ($a !== $b) throw new Exception($message.': '.var_export($a,true)); }
function rejects($f,$message) { try { $f(); } catch (InvalidArgumentException $e) { return; } catch (OverflowException $e) { return; } throw new Exception('Expected rejection: '.$message); }
$wallets=array('request-1'=>array('kind'=>'REQUEST'),'request-2'=>array('kind'=>'REQUEST'),'appointment-1'=>array('kind'=>'APPOINTMENT','scheduled_at'=>'2026-02-05 08:00:00'));
$events=array(
 array('id'=>1,'type'=>'RECEIPT','wallet'=>'request-1','amount'=>1000,'occurred_at'=>'2026-01-15 10:00:00'),
 array('id'=>2,'type'=>'RECEIPT','wallet'=>'request-2','amount'=>700,'occurred_at'=>'2026-02-01 00:00:00'),
 array('id'=>3,'type'=>'TRANSFER','wallet'=>'request-1','to'=>'appointment-1','amount'=>1000,'occurred_at'=>'2026-02-01 08:00:00'),
 array('id'=>4,'type'=>'CHARGE','wallet'=>'appointment-1','amount'=>3500,'occurred_at'=>'2026-02-05 08:00:00'),
 array('id'=>5,'type'=>'RECEIPT','wallet'=>'appointment-1','amount'=>2000,'occurred_at'=>'2026-02-05 08:00:00'),
 array('id'=>6,'type'=>'REFUND','wallet'=>'appointment-1','amount'=>100,'occurred_at'=>'2026-02-10 08:00:00'),
 array('id'=>7,'type'=>'CHARGE','wallet'=>'appointment-1','amount'=>3000,'occurred_at'=>'2026-02-15 08:00:00'),
 array('id'=>8,'type'=>'RECEIPT','wallet'=>'appointment-1','amount'=>100,'occurred_at'=>'2026-03-01 00:00:00')
);
$expenses=array(array('id'=>1,'date'=>'2026-02-28','amount'=>500),array('id'=>2,'date'=>'2026-03-01','amount'=>999));
$r=joma_v2_report_build($wallets,$events,$expenses,'2026-02-01 00:00:00','2026-03-01 00:00:00','2026-02-01','2026-03-01');
same($r['cash_received_rial'],2700,'receipts only, transfer not cash');
same($r['cash_refunded_rial'],100,'refund'); same($r['net_cash_rial'],2600,'net cash');
same($r['session_charge_changes_rial'],3000,'initial charge and adjustment');
same($r['scheduled_session_charges_rial'],3000,'final fee by scheduled date as of cutoff');
same($r['expenses_rial'],500,'expense date bounds'); same($r['request_credit_rial'],700,'request credit');
same($r['appointment_due_rial'],100,'remaining debt'); same($r['appointment_credit_rial'],0,'no appointment credit');
$r=joma_v2_report_build($wallets,$events,$expenses,'2026-02-12 00:00:00','2026-03-01 00:00:00','2026-02-12','2026-03-01');
same($r['session_charge_changes_rial'],-500,'later downward correction is not a new invoice');
same($r['scheduled_session_charges_rial'],0,'scheduled date before range');
same($r['cash_received_rial'],0,'old receipts not counted in narrow period'); same($r['appointment_due_rial'],100,'closing includes older history');
$r=joma_v2_report_build($wallets,$events,$expenses,'2026-01-01 00:00:00','2026-02-01 00:00:00','2026-01-01','2026-02-01');
same($r['request_credit_rial'],1000,'deposit before session remains credit'); same($r['session_charge_changes_rial'],0,'deposit is not session charge');
$bad=$events; $bad[4]['id']=4; rejects(function()use($wallets,$bad){joma_v2_report_build($wallets,$bad,array(),'2026-01-01 00:00:00','2026-03-01 00:00:00','2026-01-01','2026-03-01');},'duplicate');
$bad=$events; $bad[2]['amount']=1001; rejects(function()use($wallets,$bad){joma_v2_report_build($wallets,$bad,array(),'2026-01-01 00:00:00','2026-03-01 00:00:00','2026-01-01','2026-03-01');},'overtransfer');
rejects(function()use($wallets){joma_v2_report_build($wallets,array(),array(),'2026-02-30 00:00:00','2026-03-01 00:00:00','2026-02-01','2026-03-01');},'invalid date');
rejects(function()use($wallets,$events){joma_v2_report_build($wallets,array_reverse($events),array(),'2026-01-01 00:00:00','2026-03-01 00:00:00','2026-01-01','2026-03-01');},'out of order');
$r=joma_v2_report_build($wallets,$events,array(),'2026-02-10 00:00:00','2026-02-11 00:00:00','2026-02-10','2026-02-11');
same($r['net_cash_rial'],-100,'period can have more refunds than receipts');
$w=array('unknown-date'=>array('kind'=>'APPOINTMENT'));
$e=array(array('id'=>1,'type'=>'RECEIPT','wallet'=>'unknown-date','amount'=>600,'occurred_at'=>'2026-02-01 01:00:00'),array('id'=>2,'type'=>'CHARGE','wallet'=>'unknown-date','amount'=>0,'occurred_at'=>'2026-02-01 02:00:00'));
$r=joma_v2_report_build($w,$e,array(),'2026-02-01 00:00:00','2026-03-01 00:00:00','2026-02-01','2026-03-01');
same($r['appointment_credit_rial'],600,'overpayment is credit');
same($r['undated_invoiced_count'],1,'missing scheduled date is flagged');
rejects(function(){joma_v2_report_add(PHP_INT_MAX,1);},'positive overflow');
rejects(function(){joma_v2_report_add(PHP_INT_MIN,-1);},'negative overflow');
echo "Financial report calculation tests passed (new-ledger only).\n";
