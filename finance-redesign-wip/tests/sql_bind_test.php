<?php
/* Contract check only, NOT an execution or compatibility test against MySQL. */
$GLOBALS['writes'] = array();
function db_execute($db,$sql,$types,$params) {
    if (substr_count($sql,'?') !== strlen($types) || strlen($types) !== count($params)) throw new Exception('SQL parameter count mismatch');
    $GLOBALS['writes'][]=array('sql'=>$sql,'types'=>$types,'params'=>$params);
    return array('insert_id'=>17,'affected'=>1);
}
function now_dt() { return '2026-10-31 12:00:00'; }
require __DIR__ . '/../src/storage/mysqli_store.php';
require __DIR__ . '/../src/storage/mysqli_rate_store.php';
require __DIR__ . '/../src/storage/mysqli_expense_store.php';
require __DIR__ . '/../src/storage/mysqli_center_basis_store.php';
require __DIR__ . '/../src/storage/mysqli_request_wallet_store.php';
$eventStore = new JomaV2MysqliStore(null);
$e = array('operation_key'=>'1234567890123456','event_type'=>'RECEIPT','receipt_kind'=>'DEPOSIT','wallet_id'=>1,'destination_wallet_id'=>null,'amount_rial'=>123,'account_id'=>2,'actual_minutes'=>null,'actual_session_date'=>null,'calculated_rial'=>null,'reason'=>null,'recorded_by_person_id'=>3,'recorded_by_role_code'=>'admin');
if ($eventStore->insertEvent($e)!==17) throw new Exception('Event result');
$rateStore = new JomaV2MysqliRateStore(null);
if ($rateStore->saveRate(1,2,123,true,null)!==17) throw new Exception('Insert rate');
$rateStore->saveRate(1,2,234,false,array('id'=>17,'amount'=>123,'active'=>true));
$rateStore->appendRateChange(17,null,123,true,3);
$rateStore->appendRateChange(17,array('id'=>17,'amount'=>123,'active'=>true),234,false,3);
$rateStore->insertAppointmentWallet(1,2,3,null);
$rateStore->insertAppointmentWallet(2,2,3,0);
$expenseStore = new JomaV2MysqliExpenseStore(null);
$expenseStore->insertExpense(array('operation_key'=>'1234567890123456','expense_date'=>'2026-10-31','category'=>'اجاره','amount_rial'=>123,'account_id'=>1,'description'=>null,'recorded_by_person_id'=>3));
$eventStore->assignMissingRate(1,120000,'reason',3);
$centerStore = new JomaV2MysqliCenterBasisStore(null);
$centerStore->saveCenterBasis(1,'ACTUAL',3,null);
$centerStore->saveCenterBasis(1,'RESERVED',3,'ACTUAL');
$centerStore->appendCenterBasisEvent(1,'ACTUAL','RESERVED',3);
$requestStore = new JomaV2MysqliRequestWalletStore(null);
$requestStore->insertRequestWallet(1);
if (count($GLOBALS['writes'])!==14) throw new Exception('Write count');
echo "SQL bind contracts passed (no MySQL execution).\n";
