<?php
require __DIR__ . '/../src/ledger.php';
function same($a, $b, $why) { if ($a !== $b) throw new Exception($why . ': ' . var_export($a, true)); }
function fails($f, $why) { try { $f(); } catch (InvalidArgumentException $e) { return; } catch (OverflowException $e) { return; } throw new Exception('Expected failure: ' . $why); }
$wallets = array('request-1' => array('kind'=>'REQUEST'), 'appointment-1' => array('kind'=>'APPOINTMENT'), 'appointment-2' => array('kind'=>'APPOINTMENT'));
$s = joma_ledger_new($wallets);
$events = array(
 array('id'=>'1','type'=>'RECEIPT','wallet'=>'request-1','amount'=>1000000),
 array('id'=>'2','type'=>'TRANSFER','wallet'=>'request-1','to'=>'appointment-1','amount'=>1000000,'reason'=>'بیعانه نوبت'),
 array('id'=>'3','type'=>'CHARGE','wallet'=>'appointment-1','amount'=>4200000),
 array('id'=>'4','type'=>'RECEIPT','wallet'=>'appointment-1','amount'=>2000000),
 array('id'=>'5','type'=>'REFUND','wallet'=>'appointment-1','amount'=>500000,'reason'=>'اصلاح پرداخت'),
);
foreach ($events as $event) $s = joma_ledger_apply($s, $event);
same(joma_ledger_balance($s, 'request-1')['credit'], 0, 'Deposit transferred');
same(joma_ledger_balance($s, 'appointment-1')['received'], 2500000, 'Net receipts');
same(joma_ledger_balance($s, 'appointment-1')['due'], 1700000, 'Due');
fails(function () use ($s) { joma_ledger_apply($s, array('id'=>'6','type'=>'TRANSFER','wallet'=>'appointment-1','to'=>'appointment-2','amount'=>1,'reason'=>'x')); }, 'Cannot transfer billed money');
fails(function () use ($s, $events) { joma_ledger_apply($s, $events[0]); }, 'Duplicate event');
fails(function () use ($s) { joma_ledger_apply($s, array('id'=>'6','type'=>'REFUND','wallet'=>'appointment-1','amount'=>2500001,'reason'=>'x')); }, 'Excess refund');
fails(function () use ($s) { joma_ledger_apply($s, array('id'=>'6','type'=>'CHARGE','wallet'=>'appointment-1','amount'=>10)); }, 'Revision needs reason');
fails(function () use ($s) { joma_ledger_apply($s, array('id'=>'6','type'=>'CHARGE','wallet'=>'request-1','amount'=>10)); }, 'No request charge');
fails(function () use ($s) { joma_ledger_apply($s, array('id'=>'6','type'=>'TRANSFER','wallet'=>'request-1','to'=>'request-1','amount'=>1,'reason'=>'x')); }, 'Self transfer');
same(joma_ledger_balance($s, 'appointment-1')['due'], 1700000, 'Failures do not change state');
$s = joma_ledger_apply($s, array('id'=>'6','type'=>'CHARGE','wallet'=>'appointment-1','amount'=>2000000,'reason'=>'تخفیف'));
same(joma_ledger_balance($s, 'appointment-1')['credit'], 500000, 'Revised charge creates credit');
$s = joma_ledger_apply($s, array('id'=>'7','type'=>'TRANSFER','wallet'=>'appointment-1','to'=>'appointment-2','amount'=>500000,'reason'=>'انتقال اعتبار'));
same(joma_ledger_balance($s, 'appointment-2')['credit'], 500000, 'Transfer credit');
same(joma_ledger_balance($s, 'appointment-1')['due'], 0, 'Invoice remains paid');
$replayed = joma_ledger_new($wallets);
foreach ($s['events'] as $event) $replayed = joma_ledger_apply($replayed, $event);
same($replayed, $s, 'Replay reconstructs state');
$overflow = joma_ledger_new(array('r'=>array('kind'=>'REQUEST')));
$overflow = joma_ledger_apply($overflow, array('id'=>'a','type'=>'RECEIPT','wallet'=>'r','amount'=>PHP_INT_MAX));
fails(function () use ($overflow) { joma_ledger_apply($overflow, array('id'=>'b','type'=>'RECEIPT','wallet'=>'r','amount'=>1)); }, 'Overflow');
echo "All ledger tests passed.\n";
