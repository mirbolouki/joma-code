<?php
/* WIP. Consistent read snapshot intended for MySQL; not tested there. */
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
            $rows = db_select_all($this->db, 'SELECT id,event_type,wallet_id,destination_wallet_id,amount_rial,occurred_at FROM finance_v2_events WHERE occurred_at < ? ORDER BY occurred_at,id', 's', array($end_utc));
            $events = array();
            foreach ($rows as $row) {
                $events[] = array('id'=>$this->integer($row['id']), 'type'=>$row['event_type'],
                    'wallet'=>'w'.$this->integer($row['wallet_id']), 'amount'=>$this->integer($row['amount_rial']),
                    'occurred_at'=>$row['occurred_at'],
                    'to'=>$row['destination_wallet_id'] === null ? null : 'w'.$this->integer($row['destination_wallet_id']));
            }
            $rows = db_select_all($this->db, 'SELECT id, expense_date, amount_rial FROM finance_v2_expenses WHERE expense_date >= ? AND expense_date < ? ORDER BY id', 'ss', array($start_local, $end_local));
            $expenses = array();
            foreach ($rows as $row) $expenses[] = array('id'=>$this->integer($row['id']), 'date'=>$row['expense_date'], 'amount'=>$this->integer($row['amount_rial']));
            $out = joma_v2_report_build($wallets, $events, $expenses, $start_utc, $end_utc, $start_local, $end_local);
            if (!mysqli_query($this->db, 'COMMIT')) throw new RuntimeException('Could not finish report snapshot');
            return $out;
        } catch (Throwable $ex) {
            mysqli_query($this->db, 'ROLLBACK');
            throw $ex;
        }
    }
}
