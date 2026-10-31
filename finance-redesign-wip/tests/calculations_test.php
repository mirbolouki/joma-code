<?php
require __DIR__ . '/../src/calculations.php';
function same($a, $b, $message) { if ($a !== $b) throw new Exception($message . ': ' . var_export($a, true)); }
function rejects($callback, $message) {
    try { $callback(); } catch (InvalidArgumentException $e) { return; } catch (OverflowException $e) { return; }
    throw new Exception('Expected rejection: ' . $message);
}
$rates = array(
    array('service_id'=>1, 'therapist_id'=>2, 'active'=>true, 'rate_rial_per_minute'=>120000),
    array('service_id'=>1, 'therapist_id'=>3, 'active'=>true, 'rate_rial_per_minute'=>150000),
    array('service_id'=>2, 'therapist_id'=>2, 'active'=>true, 'rate_rial_per_minute'=>180000)
);
$snapshot = joma_finance_rate_snapshot($rates, 1, 2);
$rates[0]['rate_rial_per_minute'] = 240000;
same($snapshot['rate_rial_per_minute'], 120000, 'Snapshot remains unchanged');
same(joma_finance_rate_snapshot($rates, 1, 3)['rate_rial_per_minute'], 150000, 'Therapist match');
same(joma_finance_rate_snapshot($rates, 2, 2)['rate_rial_per_minute'], 180000, 'Service match');
rejects(function () use ($rates) { joma_finance_rate_snapshot($rates, 2, 3); }, 'Missing rate');
$dup = array($rates[0], $rates[0]);
rejects(function () use ($dup) { joma_finance_rate_snapshot($dup, 1, 2); }, 'Duplicate rate');
same(joma_finance_session_charge(35, $snapshot['rate_rial_per_minute'])['base_rial'], 4200000, '35 minutes');
same(joma_finance_session_charge(62, $snapshot['rate_rial_per_minute'])['base_rial'], 7440000, '62 minutes');
same(joma_finance_session_charge(62, 120000, 7000000, 'تخفیف')['final_rial'], 7000000, 'Override');
rejects(function () { joma_finance_session_charge(35, 100, 3000, ' '); }, 'Reason required');
rejects(function () { joma_finance_session_charge(null, 100); }, 'Missing duration');
rejects(function () { joma_finance_session_charge(35.5, 100); }, 'No fractional minutes');
rejects(function () { joma_finance_session_charge(PHP_INT_MAX, 2); }, 'Overflow');
$entries = array(array('kind'=>'DEPOSIT', 'amount_rial'=>1000000), array('kind'=>'PAYMENT', 'amount_rial'=>2000000), array('kind'=>'REFUND', 'amount_rial'=>500000));
$result = joma_finance_item_balance(4200000, $entries);
same($result['due_rial'], 1700000, 'Balance after deposit, payment and refund');
same(joma_finance_item_balance(null, $entries)['credit_rial'], 2500000, 'Unbilled credit');
same(joma_finance_item_balance(1000000, $entries)['credit_rial'], 1500000, 'Overpayment credit');
rejects(function () { joma_finance_item_balance(null, array(array('kind'=>'REFUND', 'amount_rial'=>1))); }, 'Excess refund');
same(joma_finance_center_minutes('RESERVED', 60, 35), 60, 'Reserved basis');
same(joma_finance_center_minutes('ACTUAL', 60, 35), 35, 'Actual basis');
rejects(function () { joma_finance_center_minutes('ACTUAL', 60, null); }, 'Missing actual time');
rejects(function () { joma_finance_center_minutes('UNKNOWN', 60, 35); }, 'Missing center setting');
echo "All finance calculation tests passed.\n";
