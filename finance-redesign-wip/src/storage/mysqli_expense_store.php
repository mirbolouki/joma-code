<?php
/* Prototype only. Not yet tested against MySQL or integrated in the app. */
class JomaV2MysqliExpenseStore
{
    private $db;
    function __construct($db) { $this->db = $db; }
    function begin() { if (!mysqli_begin_transaction($this->db)) throw new RuntimeException('Begin failed'); }
    function commit() { if (!mysqli_commit($this->db)) throw new RuntimeException('Commit failed'); }
    function rollback() { mysqli_rollback($this->db); }
    function expenseOperationExists($key) {
        return (bool) db_select_one($this->db, 'SELECT id FROM finance_v2_expenses WHERE operation_key = ? LIMIT 1', 's', array($key));
    }
    function expenseAccountActive($id) {
        return (bool) db_select_one($this->db, "SELECT id FROM finance_bank_accounts WHERE id = ? AND status = 'ACTIVE' FOR UPDATE", 'i', array($id));
    }
    function insertExpense($r) {
        $result = db_execute($this->db, 'INSERT INTO finance_v2_expenses (operation_key,expense_date,category,amount_rial,account_id,description,recorded_by_person_id,recorded_at) VALUES (?,?,?,?,?,?,?,?)',
            'ssssisis', array($r['operation_key'], $r['expense_date'], $r['category'], (string)$r['amount_rial'],
                $r['account_id'], $r['description'], $r['recorded_by_person_id'], now_dt()));
        return (int)$result['insert_id'];
    }
}
