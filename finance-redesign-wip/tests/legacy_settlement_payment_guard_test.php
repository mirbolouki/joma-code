<?php
define('FINANCE_V2_ACTIVE',true);
require __DIR__.'/../app/includes/finance_functions.php';
// If the guard ever touches a database, this stub makes the test fail.
function db_select_one() { throw new RuntimeException('Unexpected legacy DB read'); }
$result=finance_settlement_update_payment(null,1,array('amount_paid_rial'=>100),2,'admin');
if ($result['ok']!==false || strpos($result['error'],'مسدود')===false) throw new Exception('Legacy settlement payment was not blocked');
echo "Legacy settlement overwrite blocked when V2 active.\n";
