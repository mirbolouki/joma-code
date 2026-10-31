<?php
/* Prototype adapter for the application's mysqli db_* helpers. Not installed,
 * not yet integration-tested against MySQL. The caller controls authorization. */
class JomaV2MysqliStore
{
    private $db;
    function __construct($db) { $this->db = $db; }
    function begin() { if (!mysqli_query($this->db, 'START TRANSACTION')) throw new RuntimeException('Could not begin transaction'); }
    function commit() { if (!mysqli_query($this->db, 'COMMIT')) throw new RuntimeException('Could not commit transaction'); }
    function lockLedger() { if (!db_select_one($this->db,'SELECT id FROM finance_v2_locks WHERE id=1 FOR UPDATE')) throw new RuntimeException('Finance lock missing'); }
    function rollback() { mysqli_query($this->db, 'ROLLBACK'); }
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
        $locked_ids = array();
        // Lock *all* wallets before locking joined admissions or appointments.
        // Interleaving those locks with wallet locks can deadlock transfers that
        // share a source but have different destinations in the same admission.
        foreach ($ids as $id) {
            $locked = db_select_one($this->db, 'SELECT id FROM finance_v2_wallets WHERE id = ? FOR UPDATE', 'i', array($id));
            if ($locked) $locked_ids[] = $id;
        }
        foreach ($locked_ids as $id) {
            $row = db_select_one($this->db,
                "SELECT w.id, w.request_id, w.appointment_id, w.rate_rial_per_minute,
                        br.converted_admission_id, ap.admission_id, ap.status AS appointment_status, r.center_id,
                        ad.patient_person_id,
                        (SELECT COUNT(*) FROM finance_session_payments fsp WHERE fsp.appointment_id = ap.id) AS legacy_count
                   FROM finance_v2_wallets w
              LEFT JOIN booking_requests br ON br.id = w.request_id
              LEFT JOIN appointments ap ON ap.id = w.appointment_id
              LEFT JOIN admissions ad ON ad.id = ap.admission_id
              LEFT JOIN rooms r ON r.id = ap.room_id
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
                'center_id' => $row['center_id'] === null ? null : (int)$row['center_id'],
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
    function assignMissingRate($wallet_id, $amount, $reason, $actor_id) {
        $r = db_execute($this->db, 'UPDATE finance_v2_wallets SET rate_rial_per_minute = ? WHERE id = ? AND rate_rial_per_minute IS NULL',
            'si', array((string)$amount, $wallet_id));
        if ((int)$r['affected'] !== 1) throw new InvalidArgumentException('Rate is already set');
        db_execute($this->db, 'INSERT INTO finance_v2_rate_assignments (wallet_id,rate_rial_per_minute,reason,recorded_by_person_id,recorded_at) VALUES (?,?,?,?,?)',
            'issis', array($wallet_id,(string)$amount,$reason,$actor_id,now_dt()));
    }
    function accountActive($id) {
        return (bool) db_select_one($this->db, "SELECT id FROM finance_bank_accounts WHERE id = ? AND status = 'ACTIVE' FOR UPDATE", 'i', array($id));
    }
    function insertEvent($event) {
        $now = now_dt();
        $result = db_execute($this->db,
            'INSERT INTO finance_v2_events (operation_key,event_type,receipt_kind,wallet_id,destination_wallet_id,center_snapshot_recorded,source_center_id,destination_center_id,amount_rial,account_id,actual_minutes,actual_session_date,calculated_rial,reason,occurred_at,recorded_at,recorded_by_person_id,recorded_by_role_code) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            'sssiiiiisiisssssis',
            array($event['operation_key'], $event['event_type'], $event['receipt_kind'], $event['wallet_id'],
                $event['destination_wallet_id'], $event['center_snapshot_recorded'], $event['source_center_id'], $event['destination_center_id'], (string)$event['amount_rial'], $event['account_id'],
                $event['actual_minutes'], $event['actual_session_date'], $event['calculated_rial'] === null ? null : (string)$event['calculated_rial'],
                $event['reason'], $now, $now, $event['recorded_by_person_id'], $event['recorded_by_role_code']));
        return (int)$result['insert_id'];
    }
    /** Caller holds the global finance mutex. Never discard a real payment
     * merely because a same-day percentage or unclassified legacy settlement exists. */
    function recordLateSettlementFlags($eventId,$event) {
        if ($event['event_type']==='CHARGE') return;
        $centers=array();
        if (($event['event_type']==='RECEIPT' || $event['event_type']==='REFUND' || $event['event_type']==='TRANSFER') && $event['source_center_id']!==null) $centers[$event['source_center_id']]=$event['source_center_id'];
        if ($event['event_type']==='TRANSFER' && $event['destination_center_id']!==null) $centers[$event['destination_center_id']]=$event['destination_center_id'];
        if (!$centers) return;
        $stored=db_select_one($this->db,'SELECT occurred_at FROM finance_v2_events WHERE id=?','i',array($eventId));
        if (!$stored) throw new RuntimeException('Event missing');
        $local=(new DateTimeImmutable($stored['occurred_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Tehran'))->format('Y-m-d');
        sort($centers,SORT_NUMERIC);
        foreach ($centers as $center) {
            $settlements=db_select_all($this->db,'SELECT s.id FROM finance_center_settlements s LEFT JOIN finance_v2_percentage_details d ON d.settlement_id=s.id LEFT JOIN finance_v2_center_settlement_details h ON h.settlement_id=s.id WHERE s.center_id=? AND s.period_start<=? AND s.period_end>=? AND (d.settlement_id IS NOT NULL OR h.settlement_id IS NULL) FOR UPDATE','iss',array($center,$local,$local));
            foreach ($settlements as $settlement) db_execute($this->db,'INSERT INTO finance_v2_percentage_late_events (settlement_id,event_id,detected_at) VALUES (?,?,?)','iis',array((int)$settlement['id'],$eventId,now_dt()));
        }
    }
}
