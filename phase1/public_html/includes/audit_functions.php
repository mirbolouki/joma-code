<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — دفتر رویدادها (audit_log)
 *  تنها دفتر ثبت وقایع سامانه (ADR-009).
 * ═══════════════════════════════════════════════════════════════════ */

/**
 * ثبت یک رویداد.
 * مقادیر NULL (شناسهٔ کاربر، نقش، شناسهٔ موجودیت، فراداده) پشتیبانی می‌شوند؛
 * متغیرها پیش از bind در متغیر محلی قرار می‌گیرند تا ارجاع‌دهی درست باشد.
 */
function audit_log_write($db, $actor_person_id, $actor_role_code, $action_code,
                         $entity_type, $entity_id, $metadata_array)
{
    $metadata_json = ($metadata_array !== null)
        ? json_encode($metadata_array, JSON_UNESCAPED_UNICODE)
        : null;

    $actor_person_id = ($actor_person_id === null) ? null : (int)$actor_person_id;
    $entity_id = ($entity_id === null) ? null : (int)$entity_id;
    $created_at = now_dt();

    try {
        db_execute(
            $db,
            "INSERT INTO audit_log
             (actor_person_id, actor_role_code, action_code, entity_type, entity_id, metadata_json, created_at)
             VALUES (?,?,?,?,?,?,?)",
            'isssiss',
            array($actor_person_id, $actor_role_code, $action_code,
                  $entity_type, $entity_id, $metadata_json, $created_at)
        );
    } catch (Exception $ex) {
        /* نبودِ امکان ثبت لاگ نباید عملیات اصلی کاربر را متوقف کند */
        log_system_error('AUDIT_WRITE_FAILED', $ex);
    }
}

/** خواندن آخرین رویدادها (برای کاربرد آینده و عیب‌یابی) */
function audit_log_fetch_recent($db, $limit = 50)
{
    $limit = (int)$limit;
    return db_select_all(
        $db,
        "SELECT id, actor_person_id, actor_role_code, action_code, entity_type, entity_id, created_at
           FROM audit_log ORDER BY id DESC LIMIT " . $limit,
        '',
        array()
    );
}
