<?php
/* WIP. Consistent read snapshot tested only with synthetic MySQL. */
require_once __DIR__ . '/../reporting.php';
class JomaV2MysqliReportStore
{
    private $db;
    function __construct($db) { $this->db = $db; }
    private function integer($value) {
        $text = (string)$value;
        if (!preg_match('/^(0|[1-9][0-9]{0,17})$/D', $text)) throw new UnexpectedValueException('Invalid stored integer');
        return (int)$text;
    }
    private function expenseRows($cutoff=null) {
        // MAX(id) is the serialized revision sequence for an expense. A cutoff
        // selects only versions recorded before the UTC boundary. This is not
        // a database commit-time audit, and relies on the application's UTC clock.
        $revisionCutoff=$cutoff===null ? '' : ' AND r2.recorded_at < ?';
        $expenseCutoff=$cutoff===null ? '' : ' AND ex.recorded_at < ?';
        $sql="SELECT ex.id, rv.id AS revision_id, rv.action AS revision_action,
                CASE WHEN rv.id IS NULL THEN ex.expense_date ELSE rv.expense_date END AS expense_date,
                CASE WHEN rv.id IS NULL THEN ex.amount_rial ELSE rv.amount_rial END AS amount_rial
                FROM finance_v2_expenses ex LEFT JOIN finance_v2_expense_revisions rv
                  ON rv.id=(SELECT MAX(r2.id) FROM finance_v2_expense_revisions r2 WHERE r2.expense_id=ex.id".$revisionCutoff.")
                WHERE 1=1".$expenseCutoff;
        // Do not filter on nullable revision fields in SQL: invalid stored rows
        // must fail closed in the calculation instead of disappearing silently.
        $rows=$cutoff===null ? db_select_all($this->db,$sql.' ORDER BY ex.id') : db_select_all($this->db,$sql.' ORDER BY ex.id','ss',array($cutoff,$cutoff));
        $out=array();
        foreach ($rows as $row) {
            if ($row['revision_id']!==null) {
                if ($row['revision_action']==='VOID') continue;
                if ($row['revision_action']!=='CORRECT') throw new UnexpectedValueException('Invalid expense revision action');
            }
            if ($row['amount_rial']===null || $row['expense_date']===null) throw new UnexpectedValueException('Incomplete expense revision');
            $out[]=array('id'=>$this->integer($row['id']),'date'=>$row['expense_date'],'amount'=>$this->integer($row['amount_rial']));
        }
        return $out;
    }
    /** The report deliberately excludes legacy, test/package sales and center
     * payments. It fails closed if legacy and new payments share an appointment. */
    function build($start_utc, $end_utc, $start_local, $end_local) {
        if (!mysqli_query($this->db, 'START TRANSACTION WITH CONSISTENT SNAPSHOT')) {
            throw new RuntimeException('Could not start report snapshot');
        }
        try {
            $rows = db_select_all($this->db, "SELECT w.id, w.request_id, w.appointment_id, ap.appointment_start_utc,
                (SELECT COUNT(*) FROM finance_session_payments fsp WHERE fsp.appointment_id = w.appointment_id) AS legacy_count
                FROM finance_v2_wallets w LEFT JOIN appointments ap ON ap.id = w.appointment_id ORDER BY w.id");
            $wallets = array();
            foreach ($rows as $row) {
                if (($row['request_id'] === null) === ($row['appointment_id'] === null)) throw new UnexpectedValueException('Invalid wallet');
                if ((int)$row['legacy_count'] > 0) throw new UnexpectedValueException('Overlapping old and new payments require reconciliation');
                $id = $this->integer($row['id']);
                $wallets['w'.$id] = array('kind'=>$row['request_id'] === null ? 'APPOINTMENT' : 'REQUEST',
                    'scheduled_at'=>$row['appointment_start_utc']);
            }
            $rows = db_select_all($this->db, 'SELECT id,event_type,wallet_id,destination_wallet_id,amount_rial,actual_session_date,occurred_at FROM finance_v2_events WHERE occurred_at < ? ORDER BY occurred_at,id', 's', array($end_utc));
            $events = array();
            foreach ($rows as $row) {
                $events[] = array('id'=>$this->integer($row['id']), 'type'=>$row['event_type'],
                    'wallet'=>'w'.$this->integer($row['wallet_id']), 'amount'=>$this->integer($row['amount_rial']),
                    'occurred_at'=>$row['occurred_at'], 'actual_session_date'=>$row['actual_session_date'],
                    'to'=>$row['destination_wallet_id'] === null ? null : 'w'.$this->integer($row['destination_wallet_id']));
            }
            // Two explicitly named views: latest correction now and the version
            // recorded by the exclusive UTC end of the report period.
            $expenses=$this->expenseRows();
            $asOfExpenses=$this->expenseRows($end_utc);
            $out = joma_v2_report_build($wallets, $events, $expenses, $start_utc, $end_utc, $start_local, $end_local);
            $out['expenses_as_of_end_rial']=joma_v2_report_expenses($asOfExpenses,$start_local,$end_local);
            if (!mysqli_query($this->db, 'COMMIT')) throw new RuntimeException('Could not finish report snapshot');
            return $out;
        } catch (Throwable $ex) {
            mysqli_query($this->db, 'ROLLBACK');
            throw $ex;
        }
    }
}
