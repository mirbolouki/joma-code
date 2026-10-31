<?php
/* Experimental expense recording. No update/delete/reversal capability yet. */
require_once __DIR__ . '/record_event.php';
function joma_v2_record_expense($store, $expense, $actor)
{
    if (!isset($actor['person_id'], $actor['role']) || $actor['role'] !== 'admin') {
        throw new InvalidArgumentException('Only administrators can record expenses');
    }
    joma_v2_positive_id($actor['person_id'], 'Actor');
    if (!isset($expense['key'], $expense['date'], $expense['category'], $expense['amount_rial'], $expense['account_id']) ||
        !is_string($expense['key']) || !preg_match('/^[A-Za-z0-9_-]{16,100}$/D', $expense['key'])) {
        throw new InvalidArgumentException('Missing or invalid expense fields');
    }
    $date = $expense['date'];
    if (!is_string($date) || !preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $date, $parts) ||
        !checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1])) {
        throw new InvalidArgumentException('Invalid expense date');
    }
    if (!is_string($expense['category']) || trim($expense['category']) === '' || mb_strlen(trim($expense['category']), 'UTF-8') > 100) {
        throw new InvalidArgumentException('Invalid expense category');
    }
    $description = isset($expense['description']) ? $expense['description'] : '';
    if (!is_string($description) || mb_strlen(trim($description), 'UTF-8') > 255) {
        throw new InvalidArgumentException('Invalid expense description');
    }
    joma_v2_rial($expense['amount_rial']);
    joma_v2_positive_id($expense['account_id'], 'Account');
    $store->begin();
    try {
        if ($store->expenseOperationExists($expense['key'])) throw new InvalidArgumentException('Duplicate expense operation');
        if (!$store->expenseAccountActive($expense['account_id'])) throw new InvalidArgumentException('Account is not active');
        $record = array('operation_key'=>$expense['key'], 'expense_date'=>$date,
            'category'=>trim($expense['category']), 'amount_rial'=>$expense['amount_rial'],
            'account_id'=>$expense['account_id'], 'description'=>trim($description) === '' ? null : trim($description),
            'recorded_by_person_id'=>$actor['person_id']);
        $id = $store->insertExpense($record);
        $store->commit();
        return $id;
    } catch (Throwable $e) {
        $store->rollback();
        throw $e;
    }
}
