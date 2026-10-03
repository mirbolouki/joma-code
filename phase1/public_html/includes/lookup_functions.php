<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — فهرست‌های پایه و خدمات
 *  آیتم‌های فهرست هرگز حذف فیزیکی نمی‌شوند؛ فقط is_active = 0 (ADR-010).
 * ═══════════════════════════════════════════════════════════════════ */

function lookup_items_fetch_active($db, $list_code)
{
    return db_select_all(
        $db,
        "SELECT li.id, li.item_code, li.label
           FROM lookup_items li
           INNER JOIN lookup_lists ll ON ll.id = li.lookup_list_id
          WHERE ll.list_code = ? AND li.is_active = 1
          ORDER BY li.display_order ASC",
        's',
        array($list_code)
    );
}

function lookup_item_find($db, $list_code, $item_id)
{
    return db_select_one(
        $db,
        "SELECT li.id, li.item_code, li.label
           FROM lookup_items li
           INNER JOIN lookup_lists ll ON ll.id = li.lookup_list_id
          WHERE ll.list_code = ? AND li.id = ? LIMIT 1",
        'si',
        array($list_code, (int)$item_id)
    );
}

/** درمانگرهای فعال — برای کشویی انتخاب درمانگر */
function therapists_fetch_active($db)
{
    return db_select_all(
        $db,
        "SELECT p.id, p.first_name, p.last_name
           FROM persons p
           INNER JOIN role_assignments ra ON ra.person_id = p.id
          WHERE ra.role_code = 'therapist' AND ra.status = 'ACTIVE' AND p.status = 'ACTIVE'
          ORDER BY p.first_name",
        '',
        array()
    );
}

function service_types_fetch_active($db)
{
    return db_select_all(
        $db,
        "SELECT id, public_id, code, title, is_multi_person, default_duration_minutes
           FROM service_types WHERE is_active = 1 ORDER BY title",
        '',
        array()
    );
}

function service_type_find($db, $id)
{
    return db_select_one(
        $db,
        "SELECT id, public_id, code, title, is_multi_person, default_duration_minutes
           FROM service_types WHERE id = ? LIMIT 1",
        'i',
        array((int)$id)
    );
}
