<?php
/* Prototype adapter for the application's mysqli db_* helpers. Not installed,
 * not yet integration-tested against MySQL. The caller controls authorization. */
class JomaV2MysqliStore
{
    private $db;
    function __construct($db) { $this->db = $db; }
    function begin() { if (!mysqli_begin_transaction($this->db)) throw new RuntimeException('Could not begin transaction'); }
    function commit() { if (!mysqli_commit($this->db)) throw new RuntimeException('Could not commit transaction'); }
    function rollback() { mysqli_rollback($this->db); }
    function operationExists($key) {
        return (bool) db_select_one($this->db, 'SELECT id FROM finance_v2_events WHERE operation_key = ? LIMIT 1', 's', array($key));
    }
    private function integer($value) {
        $s = (string)$value;
        if (!preg_match('/^(0|[1-9][0-9]{0,17})$/D', $s)) throw new UnexpectedValueException('Invalid stored rial amount');
        return (int)$s;
    }
    function lockWallets($ids) {
        // Lock one row at a time in the sorted order provided by the service.
        $out = array();
        foreach ($ids as $id) {
            $locked = db_select_one($this->db, 'SELECT id FROM finance_v2_wallets WHERE id = ? FOR UPDATE', 'i', array($id));
            if (!$locked) continue;
            $row = db_select_one($this->db,
                "SELECT w.id, w.request_id, w.appointment_id, w.rate_rial_per_minute,
                        br.converted_admission_id, ap.admission_id, ap.status AS appointment_status,
                        ad.patient_person_id,
                        (SELECT COUNT(*) FROM finance_session_payments fsp WHERE fsp.appointment_id = ap.id) AS legacy_count
                   FROM finance_v2_wallets w
              LEFT JOIN booking_requests br ON br.id = w.request_id
              LEFT JOIN appointments ap ON ap.id = w.appointment_id
              LEFT JOIN admissions ad ON ad.id = ap.admission_id
                  WHERE w.id = ? FOR UPDATE", 'i', array($id));
            if (!$row || (($row['request_id'] === null) === ($row['appointment_id'] === null))) {
                throw new UnexpectedValueException('Wallet identity is invalid');
            }
            if ((int)$row['legacy_count'] > 0) {
                throw new InvalidArgumentException('Legacy payments need explicit reconciliation');
            }
            $is_request = $row['request_id'] !== null;
            $out[$id] = array('kind' => $is_request ? 'REQUEST' : 'APPOINTMENT',
                'admission_id' => $is_request ? ($row['converted_admission_id'] === null ? null : (int)$row['converted_admission_id']) : (int)$row['admission_id'],
                'patient_id' => $row['patient_person_id'] === null ? null : (int)$row['patient_person_id'],
                'appointment_status' => $row['appointment_status'],
                'rate_rial_per_minute' => $row['rate_rial_per_minute'] === null ? null : $this->integer($row['rate_rial_per_minute']));
        }
        return $out;
    }
    function balance($id) {
        // Current locking reads are essential under MySQL REPEATABLE READ:
        // an earlier ordinary read could otherwise leave a stale snapshot after
        // waiting for another transaction to release the wallet lock.
        $rows = db_select_all($this->db,
            "SELECT wallet_id, destination_wallet_id, event_type, amount_rial
               FROM finance_v2_events WHERE wallet_id = ? OR destination_wallet_id = ?
              ORDER BY id ASC FOR UPDATE", 'ii', array($id, $id));
        $received = 0;
        $charge = null;
        foreach ($rows as $row) {
            $amount = $this->integer($row['amount_rial']);
            $kind = $row['event_type'];
            $source = (int)$row['wallet_id'];
            $dest = $row['destination_wallet_id'] === null ? null : (int)$row['destination_wallet_id'];
            if ($source === $id && $kind === 'CHARGE') {
                $charge = $amount;
            } elseif (($source === $id && $kind === 'RECEIPT') || ($dest === $id && $kind === 'TRANSFER')) {
                if ($amount > 999999999999999999 - $received) throw new OverflowException('Stored balance overflow');
                $received += $amount;
            } elseif ($source === $id && ($kind === 'REFUND' || $kind === 'TRANSFER')) {
                if ($amount > $received) throw new UnexpectedValueException('Stored balance is negative');
                $received -= $amount;
            }
        }
        return array('received' => $received, 'charge' => $charge);
    }
    function accountActive($id) {
        return (bool) db_select_one($this->db, "SELECT id FROM finance_bank_accounts WHERE id = ? AND status = 'ACTIVE' FOR UPDATE", 'i', array($id));
    }
    function insertEvent($event) {
        $now = now_dt();
        $result = db_execute($this->db,
            'INSERT INTO finance_v2_events (operation_key,event_type,receipt_kind,wallet_id,destination_wallet_id,amount_rial,account_id,actual_minutes,calculated_rial,reason,occurred_at,recorded_at,recorded_by_person_id,recorded_by_role_code) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            'sssiisiissssis',
            array($event['operation_key'], $event['event_type'], $event['receipt_kind'], $event['wallet_id'],
                $event['destination_wallet_id'], (string)$event['amount_rial'], $event['account_id'],
                $event['actual_minutes'], $event['calculated_rial'] === null ? null : (string)$event['calculated_rial'],
                $event['reason'], $now, $now, $event['recorded_by_person_id'], $event['recorded_by_role_code']));
        return (int)$result['insert_id'];
    }
}
