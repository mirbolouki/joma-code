<?php
/* READ-ONLY PROTOTYPE. This inspector does not install, migrate or enable
 * anything. Passing it is necessary, not sufficient, for future deployment. */
function joma_v2_schema_preflight($db)
{
    $required=array(
        'persons'=>array('id','status'),
        'service_types'=>array('id','is_active'),
        'role_assignments'=>array('person_id','role_code','status'),
        'admissions'=>array('id','patient_person_id'),
        'booking_requests'=>array('id','converted_admission_id','public_id','first_name','last_name','status','created_at'),
        'appointments'=>array('id','admission_id','therapist_person_id','service_type_id','room_id','appointment_start_utc','duration_minutes','status','rescheduled_from_id'),
        'rooms'=>array('id','center_id','hourly_rate'),
        'finance_centers'=>array('id','status','commission_model','commission_percent'),
        'finance_bank_accounts'=>array('id','label','status'),
        'finance_session_payments'=>array('id','appointment_id','deposit_status','deposit_amount_rial','deposit_paid_at','balance_status','balance_amount_rial','balance_paid_at'),
        'finance_center_settlements'=>array('id','center_id','period_start','period_end','amount_due_rial','created_at')
    );
    $referenceIds=array('persons','service_types','booking_requests','appointments','finance_centers','finance_bank_accounts','finance_center_settlements');
    $tables=db_select_all($db,'SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()');
    $columns=db_select_all($db,'SELECT TABLE_NAME,COLUMN_NAME,DATA_TYPE,COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()');
    $found=array(); $details=array(); $conflicts=array(); $issues=array();
    foreach ($tables as $row) {
        $name=$row['TABLE_NAME']; $found[$name]=$row['ENGINE'];
        if (strpos($name,'finance_v2_')===0) $conflicts[]=$name;
    }
    foreach ($columns as $row) $details[$row['TABLE_NAME']][$row['COLUMN_NAME']]=$row;
    foreach ($required as $table=>$names) {
        if (!array_key_exists($table,$found)) { $issues[]='missing_table:'.$table; continue; }
        if (strtoupper((string)$found[$table])!=='INNODB') $issues[]='non_transactional:'.$table;
        foreach ($names as $name) if (!isset($details[$table][$name])) $issues[]='missing_column:'.$table.'.'.$name;
    }
    foreach ($referenceIds as $table) {
        if (!isset($details[$table]['id'])) continue;
        $id=$details[$table]['id'];
        if (strtolower($id['DATA_TYPE'])!=='bigint' || strpos(strtolower($id['COLUMN_TYPE']),'unsigned')===false) $issues[]='incompatible_id:'.$table.'.id';
    }
    sort($issues); sort($conflicts);
    return array('issues'=>$issues,'existing_v2_tables'=>$conflicts,
        // Never claim readiness from this limited inspection alone.
        'ready_for_install'=>false);
}
