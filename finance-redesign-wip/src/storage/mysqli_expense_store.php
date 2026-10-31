<?php
/* Prototype only. Tested against synthetic MySQL, not integrated in the live app. */
class JomaV2MysqliExpenseStore
{
    private $db;
    function __construct($db) { $this->db = $db; }
    function begin() { if (!mysqli_query($this->db, 'START TRANSACTION')) throw new RuntimeException('Begin failed'); }
    function commit() { if (!mysqli_query($this->db, 'COMMIT')) throw new RuntimeException('Commit failed'); }
    function rollback() { mysqli_query($this->db, 'ROLLBACK'); }
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
    function lockExpense($id) {
        return db_select_one($this->db,'SELECT id,account_id FROM finance_v2_expenses WHERE id=? FOR UPDATE','i',array($id));
    }
    function latestExpenseRevision($id) {
        return db_select_one($this->db,'SELECT id,action,account_id FROM finance_v2_expense_revisions WHERE expense_id=? ORDER BY id DESC LIMIT 1 FOR UPDATE','i',array($id));
    }
    function revisionOperationExists($key) {
        return (bool) db_select_one($this->db,'SELECT id FROM finance_v2_expense_revisions WHERE operation_key=? LIMIT 1','s',array($key));
    }
    function insertExpenseRevision($r) {
        $result=db_execute($this->db,'INSERT INTO finance_v2_expense_revisions (expense_id,operation_key,action,expense_date,category,amount_rial,account_id,description,reason,recorded_by_person_id,recorded_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
            'isssssissis',array($r['expense_id'],$r['operation_key'],$r['action'],$r['expense_date'],$r['category'],
                $r['amount_rial'] === null ? null : (string)$r['amount_rial'],$r['account_id'],$r['description'],$r['reason'],$r['recorded_by_person_id'],now_dt()));
        return (int)$result['insert_id'];
    }
    private function selectExpenseSql() {
        return "SELECT ex.id, COALESCE(rv.id,0) AS revision_id, rv.action,
          CASE WHEN cr.id IS NOT NULL THEN cr.expense_date ELSE ex.expense_date END AS expense_date,
          CASE WHEN cr.id IS NOT NULL THEN cr.category ELSE ex.category END AS category,
          CASE WHEN cr.id IS NOT NULL THEN cr.amount_rial ELSE ex.amount_rial END AS amount_rial,
          CASE WHEN cr.id IS NOT NULL THEN cr.account_id ELSE ex.account_id END AS account_id,
          CASE WHEN cr.id IS NOT NULL THEN cr.description ELSE ex.description END AS description,
          acc.label AS account_label,acc.status AS account_status
          FROM finance_v2_expenses ex LEFT JOIN finance_v2_expense_revisions rv
            ON rv.id=(SELECT MAX(r2.id) FROM finance_v2_expense_revisions r2 WHERE r2.expense_id=ex.id)
          LEFT JOIN finance_v2_expense_revisions cr
            ON cr.id=(SELECT MAX(r3.id) FROM finance_v2_expense_revisions r3 WHERE r3.expense_id=ex.id AND r3.action='CORRECT')
          JOIN finance_bank_accounts acc ON acc.id=CASE WHEN cr.id IS NOT NULL THEN cr.account_id ELSE ex.account_id END";
    }
    function listExpenses() { return db_select_all($this->db,$this->selectExpenseSql().' ORDER BY ex.id DESC LIMIT 100'); }
    function findExpense($id) { return db_select_one($this->db,$this->selectExpenseSql().' WHERE ex.id=?','i',array($id)); }
    function originalExpense($id) { return db_select_one($this->db,'SELECT ex.*, acc.label AS account_label FROM finance_v2_expenses ex JOIN finance_bank_accounts acc ON acc.id=ex.account_id WHERE ex.id=?','i',array($id)); }
    function expenseHistory($id) { return db_select_all($this->db,'SELECT rv.*, acc.label AS account_label FROM finance_v2_expense_revisions rv LEFT JOIN finance_bank_accounts acc ON acc.id=rv.account_id WHERE rv.expense_id=? ORDER BY rv.id','i',array($id)); }
}
