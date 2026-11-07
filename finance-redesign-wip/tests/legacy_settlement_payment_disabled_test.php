<?php
// Compatibility test with V2 explicitly disabled, using fakes only.
define('FINANCE_V2_ACTIVE',false);
require __DIR__.'/../app/includes/finance_functions.php';
$writes=array();
function db_select_one($db,$sql,$types='',$args=array()) { return array('amount_due_rial'=>'1000','amount_paid_rial'=>null,'paid_at'=>null); }
function db_execute($db,$sql,$types,$args) { global $writes; $writes[]=array($sql,$args); }
function audit_log_write($db,$actor,$role,$action,$kind,$id,$data) {}
function now_dt() { return '2026-01-01 00:00:00'; }
$r=finance_settlement_update_payment(null,1,array('amount_paid_rial'=>500,'paid_bank_account_id'=>1,'note'=>null),2,'admin');
if ($r['ok']!==true || count($writes)!==1 || $writes[0][1][0]!==500) throw new Exception('Legacy behavior changed with V2 disabled');
echo "Legacy settlement behavior retained when V2 disabled (mock only).\n";
