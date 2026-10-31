<?php
/* Prototype only. Tests against an actual MySQL server are still required. */
class JomaV2MysqliRateStore
{
    private $db;
    function __construct($db) { $this->db = $db; }
    function begin() { if (!mysqli_query($this->db, 'START TRANSACTION')) throw new RuntimeException('Begin failed'); }
    function commit() { if (!mysqli_query($this->db, 'COMMIT')) throw new RuntimeException('Commit failed'); }
    function rollback() { mysqli_query($this->db, 'ROLLBACK'); }
    private function money($value) {
        $s = (string)$value;
        if (!preg_match('/^(0|[1-9][0-9]{0,17})$/D', $s)) throw new UnexpectedValueException('Invalid stored amount');
        return (int)$s;
    }
    function lockRateSubject($therapist, $service) {
        $person = db_select_one($this->db, "SELECT id, status FROM persons WHERE id = ? FOR UPDATE", 'i', array($therapist));
        $svc = db_select_one($this->db, "SELECT id, is_active FROM service_types WHERE id = ? FOR UPDATE", 'i', array($service));
        $role = db_select_one($this->db,
            "SELECT id FROM role_assignments WHERE person_id = ? AND role_code = 'therapist' AND status = 'ACTIVE' LIMIT 1 FOR UPDATE",
            'i', array($therapist));
        return $person && $person['status'] === 'ACTIVE' && $svc && (int)$svc['is_active'] === 1 && $role;
    }
    function rateForUpdate($therapist, $service) {
        $row = db_select_one($this->db, 'SELECT id, rate_rial_per_minute, active FROM finance_v2_rates WHERE therapist_person_id = ? AND service_type_id = ? FOR UPDATE', 'ii', array($therapist, $service));
        return $row ? array('id'=>(int)$row['id'], 'amount'=>$this->money($row['rate_rial_per_minute']), 'active'=>((int)$row['active'] === 1)) : null;
    }
    function saveRate($therapist, $service, $amount, $active, $previous) {
        $now = now_dt();
        if ($previous !== null) {
            db_execute($this->db, 'UPDATE finance_v2_rates SET rate_rial_per_minute = ?, active = ?, updated_at = ? WHERE id = ?',
                'sisi', array((string)$amount, (int)$active, $now, $previous['id']));
            return $previous['id'];
        }
        $r = db_execute($this->db, 'INSERT INTO finance_v2_rates (therapist_person_id, service_type_id, rate_rial_per_minute, active, created_at) VALUES (?,?,?,?,?)',
            'iisis', array($therapist, $service, (string)$amount, (int)$active, $now));
        return (int)$r['insert_id'];
    }
    function appendRateChange($id, $previous, $amount, $active, $actor) {
        db_execute($this->db, 'INSERT INTO finance_v2_rate_changes (rate_id, old_rate_rial, old_active, new_rate_rial, new_active, recorded_by_person_id, recorded_at) VALUES (?,?,?,?,?,?,?)',
            'isisiis', array($id, $previous === null ? null : (string)$previous['amount'],
                $previous === null ? null : (int)$previous['active'], (string)$amount, (int)$active, $actor, now_dt()));
    }
    function newAppointmentForUpdate($id) {
        $row = db_select_one($this->db, 'SELECT id, therapist_person_id, service_type_id, rescheduled_from_id FROM appointments WHERE id = ? FOR UPDATE', 'i', array($id));
        return $row ? array('therapist_id'=>(int)$row['therapist_person_id'], 'service_id'=>(int)$row['service_type_id'],
            'rescheduled_from_id'=>$row['rescheduled_from_id'] === null ? null : (int)$row['rescheduled_from_id']) : null;
    }
    function walletForAppointment($appointment_id) {
        $row = db_select_one($this->db, 'SELECT rate_therapist_person_id, rate_service_type_id, rate_rial_per_minute FROM finance_v2_wallets WHERE appointment_id = ? FOR UPDATE', 'i', array($appointment_id));
        return $row ? array('therapist_id'=>$row['rate_therapist_person_id'] === null ? null : (int)$row['rate_therapist_person_id'],
            'service_id'=>$row['rate_service_type_id'] === null ? null : (int)$row['rate_service_type_id'],
            'amount'=>$row['rate_rial_per_minute'] === null ? null : $this->money($row['rate_rial_per_minute'])) : null;
    }
    function insertAppointmentWallet($id, $therapist, $service, $amount) {
        db_execute($this->db, 'INSERT INTO finance_v2_wallets (appointment_id, rate_therapist_person_id, rate_service_type_id, rate_rial_per_minute, created_at) VALUES (?,?,?,?,?)',
            'iiiss', array($id, $therapist, $service, $amount === null ? null : (string)$amount, now_dt()));
    }
}
