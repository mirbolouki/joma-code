<?php
/* WIP: not integrated with live MySQL. All caller-facing dates are clinic-local. */
class JomaV2MysqliCenterSettlementStore
{
    private $db;
    function __construct($db) { $this->db=$db; }
    function begin() { if (!mysqli_query($this->db,'START TRANSACTION')) throw new RuntimeException('Begin failed'); }
    function commit() { if (!mysqli_query($this->db,'COMMIT')) throw new RuntimeException('Commit failed'); }
    function rollback() { mysqli_query($this->db,'ROLLBACK'); }
    function lockCenter($id) {
        return db_select_one($this->db,'SELECT id,status,commission_model FROM finance_centers WHERE id=? FOR UPDATE','i',array($id));
    }
    function overlappingSettlement($id,$start,$end) {
        return (bool) db_select_one($this->db,'SELECT id FROM finance_center_settlements WHERE center_id=? AND period_start<=? AND period_end>=? LIMIT 1 FOR UPDATE','iss',array($id,$end,$start));
    }
    function centerBasis($id) {
        $row=db_select_one($this->db,'SELECT time_basis FROM finance_v2_center_basis WHERE center_id=? FOR UPDATE','i',array($id));
        return $row ? $row['time_basis'] : null;
    }
    function lockSessions($center,$start,$end,$basis) {
        $from=local_day_bounds_utc($start); $until=local_day_bounds_utc(date_add_days($end,1));
        // Actual-date mode must not infer a date from the planned appointment.
        // An unknown actual date for ANY completed appointment in this center
        // blocks settlement: otherwise it might belong to the requested period.
        // This conservative WIP policy needs a historical cutover/reconciliation.
        $sql="SELECT ap.id,ap.duration_minutes,r.hourly_rate FROM appointments ap JOIN rooms r ON r.id=ap.room_id WHERE r.center_id=?";
        if ($basis === 'ACTUAL') {
            $sql.=" AND ap.status='COMPLETED' ORDER BY ap.id FOR UPDATE";
            $rows=db_select_all($this->db,$sql,'i',array($center));
        } else {
            $sql.=" AND ap.status IN ('COMPLETED','SCHEDULED') AND ap.appointment_start_utc>=? AND ap.appointment_start_utc<? ORDER BY ap.id FOR UPDATE";
            $rows=db_select_all($this->db,$sql,'iss',array($center,$from['start'],$until['start']));
        }
        $out=array();
        foreach ($rows as $row) {
            $actual=null; $event_id=null;
            if ($basis === 'ACTUAL') {
                $wallet=db_select_one($this->db,'SELECT id FROM finance_v2_wallets WHERE appointment_id=? FOR UPDATE','i',array((int)$row['id']));
                $event=$wallet ? db_select_one($this->db,"SELECT id,actual_minutes,actual_session_date FROM finance_v2_events WHERE wallet_id=? AND event_type='CHARGE' ORDER BY id DESC LIMIT 1 FOR UPDATE",'i',array((int)$wallet['id'])) : null;
                if (!$event || $event['actual_minutes'] === null || $event['actual_session_date'] === null) throw new InvalidArgumentException('تاریخ یا مدت واقعی یک نوبت انجام‌شده در مرکز مشخص نیست؛ ابتدا سوابق را بررسی کنید.');
                $actualDate=$event['actual_session_date'];
                joma_v2_center_date($actualDate);
                if ($actualDate < $start || $actualDate > $end) continue;
                $actual=(int)$event['actual_minutes']; $event_id=(int)$event['id'];
            }
            $out[]=array('appointment_id'=>(int)$row['id'], 'reserved_minutes'=>(int)$row['duration_minutes'],
                'actual_minutes'=>$actual, 'hourly_rate_toman'=>$row['hourly_rate'] === null ? null : (string)$row['hourly_rate'],
                'source_charge_event_id'=>$event_id);
        }
        return $out;
    }
    function appointmentAlreadySettled($id) {
        return (bool) db_select_one($this->db,'SELECT id FROM finance_v2_center_settlement_items WHERE appointment_id=? LIMIT 1 FOR UPDATE','i',array($id));
    }
    function insertSettlement($center,$start,$end,$amount,$actor) {
        $public=generate_public_id('STL');
        $r=db_execute($this->db,'INSERT INTO finance_center_settlements (public_id,center_id,period_start,period_end,amount_due_rial,recorded_by_person_id,created_at) VALUES (?,?,?,?,?,?,?)',
            'sisssis',array($public,$center,$start,$end,(string)$amount,$actor,now_dt()));
        audit_log_write($this->db,$actor,'admin','FINANCE_CENTER_SETTLEMENT_CREATED','finance_center_settlement',(int)$r['insert_id'],array('center_id'=>$center,'period_start'=>$start,'period_end'=>$end,'amount_due_rial'=>$amount));
        return (int)$r['insert_id'];
    }
    function insertSettlementDetails($id,$basis,$exact,$reason) {
        db_execute($this->db,'INSERT INTO finance_v2_center_settlement_details (settlement_id,time_basis,exact_whole_rial,exact_remainder,exact_denominator,total_minutes,manual_reason) VALUES (?,?,?,?,?,?,?)',
            'issiiis',array($id,$basis,(string)$exact['whole_rial'],$exact['remainder'],$exact['denominator'],$exact['minutes'],$reason));
    }
    function insertSettlementItem($id,$basis,$row) {
        $minutes=$basis === 'ACTUAL' ? $row['actual_minutes'] : $row['reserved_minutes'];
        db_execute($this->db,'INSERT INTO finance_v2_center_settlement_items (settlement_id,appointment_id,minutes,hourly_rate_toman,source_charge_event_id) VALUES (?,?,?,?,?)',
            'iiisi',array($id,$row['appointment_id'],$minutes,$row['hourly_rate_toman'],$row['source_charge_event_id']));
    }
}
