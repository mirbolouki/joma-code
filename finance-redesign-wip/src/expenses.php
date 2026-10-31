<?php
/* Draft: original expenses and their subsequent changes are append-only. */
require_once __DIR__ . '/record_event.php';
function joma_v2_expense_actor($actor) {
    if (!isset($actor['person_id'], $actor['role']) || $actor['role'] !== 'admin') throw new InvalidArgumentException('Only administrators can record expenses');
    joma_v2_positive_id($actor['person_id'], 'Actor');
}
function joma_v2_expense_key($key) {
    if (!is_string($key) || !preg_match('/^[A-Za-z0-9_-]{16,100}$/D', $key)) throw new InvalidArgumentException('Invalid operation key');
}
function joma_v2_expense_fields($expense) {
    if (!isset($expense['date'], $expense['category'], $expense['amount_rial'], $expense['account_id'])) throw new InvalidArgumentException('Missing expense fields');
    $date=$expense['date'];
    if (!is_string($date) || !preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D',$date,$parts) || !checkdate((int)$parts[2],(int)$parts[3],(int)$parts[1])) throw new InvalidArgumentException('Invalid expense date');
    if (!is_string($expense['category']) || trim($expense['category']) === '' || mb_strlen(trim($expense['category']),'UTF-8') > 100) throw new InvalidArgumentException('Invalid expense category');
    $description=isset($expense['description']) ? $expense['description'] : '';
    if (!is_string($description) || mb_strlen(trim($description),'UTF-8') > 255) throw new InvalidArgumentException('Invalid expense description');
    joma_v2_rial($expense['amount_rial']); joma_v2_positive_id($expense['account_id'],'Account');
    return array('expense_date'=>$date, 'category'=>trim($expense['category']), 'amount_rial'=>$expense['amount_rial'],
        'account_id'=>$expense['account_id'], 'description'=>trim($description) === '' ? null : trim($description));
}
function joma_v2_record_expense($store, $expense, $actor)
{
    joma_v2_expense_actor($actor); joma_v2_expense_key(isset($expense['key']) ? $expense['key'] : null);
    $record=joma_v2_expense_fields($expense);
    $record['operation_key']=$expense['key']; $record['recorded_by_person_id']=$actor['person_id'];
    $store->begin();
    try {
        if ($store->expenseOperationExists($expense['key'])) throw new InvalidArgumentException('Duplicate expense operation');
        if (!$store->expenseAccountActive($expense['account_id'])) throw new InvalidArgumentException('Account is not active');
        $id=$store->insertExpense($record); $store->commit(); return $id;
    } catch (Throwable $e) { $store->rollback(); throw $e; }
}
/** expected_revision_id prevents a stale form from overwriting another change.
 * VOID means an erroneous record, not a cash refund. Refunds require a separate
 * future workflow; this action must not be used as a cash-flow event. */
function joma_v2_revise_expense($store,$input,$actor)
{
    joma_v2_expense_actor($actor); joma_v2_expense_key(isset($input['key']) ? $input['key'] : null);
    $id=joma_v2_positive_id(isset($input['expense_id']) ? $input['expense_id'] : null,'Expense');
    if (!isset($input['expected_revision_id']) || !is_int($input['expected_revision_id']) || $input['expected_revision_id'] < 0) throw new InvalidArgumentException('Invalid version');
    $action=isset($input['action']) ? $input['action'] : null;
    if (!in_array($action,array('CORRECT','VOID'),true)) throw new InvalidArgumentException('Invalid action');
    $reason=isset($input['reason']) && is_string($input['reason']) ? trim($input['reason']) : '';
    if ($reason === '' || mb_strlen($reason,'UTF-8') > 255) throw new InvalidArgumentException('Reason required');
    $fields=$action === 'CORRECT' ? joma_v2_expense_fields($input) : array('expense_date'=>null,'category'=>null,'amount_rial'=>null,'account_id'=>null,'description'=>null);
    $store->begin();
    try {
        $expense=$store->lockExpense($id);
        if (!$expense) throw new InvalidArgumentException('Expense not found');
        $latest=$store->latestExpenseRevision($id);
        $version=$latest ? (int)$latest['id'] : 0;
        if ($version !== $input['expected_revision_id']) throw new InvalidArgumentException('Expense changed; reload before editing');
        if ($latest && $latest['action'] === 'VOID') throw new InvalidArgumentException('Expense already voided');
        if ($store->revisionOperationExists($input['key'])) throw new InvalidArgumentException('Duplicate operation');
        $currentAccount=$latest ? (int)$latest['account_id'] : (int)$expense['account_id'];
        if ($action === 'CORRECT' && $fields['account_id'] !== $currentAccount && !$store->expenseAccountActive($fields['account_id'])) throw new InvalidArgumentException('Account is not active');
        $fields['expense_id']=$id; $fields['operation_key']=$input['key']; $fields['action']=$action;
        $fields['reason']=$reason; $fields['recorded_by_person_id']=$actor['person_id'];
        $revision=$store->insertExpenseRevision($fields); $store->commit(); return $revision;
    } catch (Throwable $e) { $store->rollback(); throw $e; }
}
