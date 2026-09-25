<?php
function official_library() {
    return json_decode(file_get_contents(dirname(__FILE__) . '/../database/library_official.json'), true);
}

function copy_seed_to_user($user_id) {
    $now = joma_now();
    if (store_mode() === 'mysql') {
        $found = joma_query_one('SELECT id FROM joma_activities WHERE user_id=? AND is_seed=1 LIMIT 1', 'i', array((int) $user_id));
        if ($found) return;
        $seed = official_library();
        foreach ($seed as $r) {
            joma_exec(
                'INSERT INTO joma_activities (user_id,code,group_code,name,category,data_type,track_mode,unit,daily_target,weekly_target,monthly_target,weight,frequency,sticker,color,status,is_seed,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?,?)',
                'isssssssdddissssss',
                array(
                    (int) $user_id, $r['code'], $r['group_code'], $r['name'], $r['category'],
                    $r['data_type'], $r['track_mode'], $r['unit'],
                    $r['daily_target'], $r['weekly_target'], $r['monthly_target'],
                    (int) $r['weight'], $r['frequency'], $r['sticker'], $r['color'], $r['status'],
                    $now, $now,
                )
            );
        }
        return;
    }
    $data = store_load();
    foreach ($data['activities'] as $a) {
        if (!empty($a['user_id']) && (int) $a['user_id'] === (int) $user_id && !empty($a['is_seed'])) return;
    }
    $seed = official_library();
    foreach ($seed as $r) {
        $r['id'] = store_next_id($data);
        $r['user_id'] = (int) $user_id;
        $r['is_seed'] = 1;
        $r['created_at'] = $now;
        $r['updated_at'] = $now;
        $data['activities'][] = $r;
    }
    store_save($data);
}

function user_by_username($username) {
    $username = strtolower(trim($username));
    if (store_mode() === 'mysql') {
        return joma_query_one('SELECT * FROM joma_users WHERE username=? OR email=? LIMIT 1', 'ss', array($username, $username));
    }
    $data = store_load();
    foreach ($data['users'] as $u) {
        if ($u['username'] === $username || $u['email'] === $username) return $u;
    }
    return null;
}

function username_taken($username, $except = 0) {
    $username = strtolower(trim($username));
    if (store_mode() === 'mysql') {
        $row = joma_query_one('SELECT id FROM joma_users WHERE username=? AND id<>? LIMIT 1', 'si', array($username, (int) $except));
        return (bool) $row;
    }
    $data = store_load();
    foreach ($data['users'] as $u) {
        if ($u['username'] === $username && (int) $u['id'] !== (int) $except) return true;
    }
    return false;
}

function email_taken($email, $except = 0) {
    $email = strtolower(trim($email));
    if (store_mode() === 'mysql') {
        $row = joma_query_one('SELECT id FROM joma_users WHERE email=? AND id<>? LIMIT 1', 'si', array($email, (int) $except));
        return (bool) $row;
    }
    $data = store_load();
    foreach ($data['users'] as $u) {
        if ($u['email'] === $email && (int) $u['id'] !== (int) $except) return true;
    }
    return false;
}

function create_user($in) {
    $now = joma_now();
    $hash = password_hash($in['password'], PASSWORD_DEFAULT);
    $role = 'member';
    $level = 1;
    if (store_mode() === 'mysql') {
        joma_exec(
            'INSERT INTO joma_users (first_name,last_name,username,email,phone,job,password_hash,role_key,access_level,mobile_verified,created_at) VALUES (?,?,?,?,?,?,?,?,?,0,?)',
            'ssssssssis',
            array($in['first_name'], $in['last_name'], $in['username'], $in['email'], $in['phone'], $in['job'], $hash, $role, $level, $now)
        );
        $id = mysqli_insert_id(db());
        joma_exec('INSERT INTO joma_user_preferences (user_id,compact_cards,notifications_enabled) VALUES (?,0,0)', 'i', array($id));
    } else {
        $data = store_load();
        $id = store_next_id($data);
        $data['users'][] = array(
            'id' => $id,
            'first_name' => $in['first_name'],
            'last_name' => $in['last_name'],
            'username' => $in['username'],
            'email' => $in['email'],
            'phone' => $in['phone'],
            'job' => $in['job'],
            'password_hash' => $hash,
            'role_key' => $role,
            'access_level' => $level,
            'mobile_verified' => 0,
            'created_at' => $now,
        );
        $data['preferences'][] = array('user_id' => $id, 'compact_cards' => 0, 'notifications_enabled' => 0);
        store_save($data);
    }
    copy_seed_to_user($id);
    return get_user($id);
}

function get_user($id) {
    if (store_mode() === 'mysql') {
        return joma_query_one('SELECT * FROM joma_users WHERE id=?', 'i', array((int) $id));
    }
    $data = store_load();
    foreach ($data['users'] as $u) if ((int) $u['id'] === (int) $id) return $u;
    return null;
}

function update_user($id, $patch) {
    $u = get_user($id);
    if (!$u) return null;
    foreach ($patch as $k => $v) $u[$k] = $v;
    if (store_mode() === 'mysql') {
        joma_exec('UPDATE joma_users SET first_name=?, last_name=?, phone=?, job=? WHERE id=?', 'ssssi', array($u['first_name'], $u['last_name'], $u['phone'], $u['job'], (int) $id));
        return get_user($id);
    }
    $data = store_load();
    foreach ($data['users'] as $i => $row) {
        if ((int) $row['id'] === (int) $id) $data['users'][$i] = $u;
    }
    store_save($data);
    return $u;
}

function reset_user_password($identifier, $password, $confirm) {
    if (strlen($password) < 6) return 'رمز عبور باید حداقل ۶ نویسه باشد.';
    if ($password !== $confirm) return 'رمز عبور و تکرار آن یکسان نیستند.';
    $u = user_by_username($identifier);
    if (!$u) return 'حسابی با این نام کاربری یا ایمیل پیدا نشد.';
    $hash = password_hash($password, PASSWORD_DEFAULT);
    if (store_mode() === 'mysql') {
        joma_exec('UPDATE joma_users SET password_hash=? WHERE id=?', 'si', array($hash, (int) $u['id']));
    } else {
        $data = store_load();
        foreach ($data['users'] as $i => $row) {
            if ((int) $row['id'] === (int) $u['id']) $data['users'][$i]['password_hash'] = $hash;
        }
        store_save($data);
    }
    return '';
}

function get_prefs($user_id) {
    $default = array('user_id' => (int) $user_id, 'compact_cards' => 0, 'notifications_enabled' => 0);
    if (store_mode() === 'mysql') {
        $row = joma_query_one('SELECT * FROM joma_user_preferences WHERE user_id=?', 'i', array((int) $user_id));
        return $row ? $row : $default;
    }
    $data = store_load();
    foreach ($data['preferences'] as $p) {
        if ((int) $p['user_id'] === (int) $user_id) return $p;
    }
    return $default;
}

function save_prefs($user_id, $compact, $notify) {
    $compact = $compact ? 1 : 0;
    $notify = $notify ? 1 : 0;
    if (store_mode() === 'mysql') {
        $ex = joma_query_one('SELECT user_id FROM joma_user_preferences WHERE user_id=?', 'i', array((int) $user_id));
        if ($ex) joma_exec('UPDATE joma_user_preferences SET compact_cards=?, notifications_enabled=? WHERE user_id=?', 'iii', array($compact, $notify, (int) $user_id));
        else joma_exec('INSERT INTO joma_user_preferences (user_id,compact_cards,notifications_enabled) VALUES (?,?,?)', 'iii', array((int) $user_id, $compact, $notify));
        return;
    }
    $data = store_load();
    $found = false;
    foreach ($data['preferences'] as $i => $p) {
        if ((int) $p['user_id'] === (int) $user_id) {
            $data['preferences'][$i]['compact_cards'] = $compact;
            $data['preferences'][$i]['notifications_enabled'] = $notify;
            $found = true;
        }
    }
    if (!$found) $data['preferences'][] = array('user_id' => (int) $user_id, 'compact_cards' => $compact, 'notifications_enabled' => $notify);
    store_save($data);
}

function session_user_array($u) {
    return array(
        'id' => $u['id'],
        'first_name' => $u['first_name'],
        'last_name' => $u['last_name'],
        'full_name' => trim($u['first_name'] . ' ' . $u['last_name']),
        'username' => $u['username'],
        'email' => $u['email'],
        'phone' => $u['phone'],
        'job' => $u['job'],
        'role_key' => $u['role_key'],
        'access_level' => $u['access_level'],
        'created_at' => $u['created_at'],
    );
}

function list_user_activities($user_id) {
    if (store_mode() === 'mysql') {
        return joma_query('SELECT * FROM joma_activities WHERE user_id=? ORDER BY code', 'i', array((int) $user_id));
    }
    $data = store_load();
    $out = array();
    foreach ($data['activities'] as $a) {
        if ((int) $a['user_id'] === (int) $user_id) $out[] = $a;
    }
    usort($out, function ($x, $y) { return strcmp($x['code'], $y['code']); });
    return $out;
}

function get_activity($id, $user_id) {
    foreach (list_user_activities($user_id) as $a) if ((int) $a['id'] === (int) $id) return $a;
    return null;
}

function save_activity($user_id, $in, $id = 0) {
    $now = joma_now();
    $in['track_mode'] = $in['data_type'];

    // Single-target storage: only the column that belongs to the selected
    // frequency is written; the two others go to 0.00 on a new record (the
    // pattern the official seeds use). No x7 / x30 conversion is invented.
    $existingRow = $id ? get_activity($id, $user_id) : null;
    $targetValue = isset($in['target'])
        ? $in['target']
        : (isset($in[frequency_target_column($in['frequency'])])
            ? $in[frequency_target_column($in['frequency'])]
            : ($existingRow ? target_of($existingRow) : 0));
    // A daily checkbox has no target field in the form: "one tick a day" is
    // stored as exactly 1.00 so the engine has a real number to work with.
    $__rule = activity_target_rule($in['data_type'], $in['unit'], $in['frequency']);
    if (!empty($__rule['hidden'])) $targetValue = 1;
    unset($__rule);
    $targets = normalize_activity_targets($in['frequency'], $targetValue, $existingRow);
    $in['daily_target'] = $targets['daily_target'];
    $in['weekly_target'] = $targets['weekly_target'];
    $in['monthly_target'] = $targets['monthly_target'];
    unset($in['target']);
    if (store_mode() === 'mysql') {
        if ($id) {
            joma_exec(
                'UPDATE joma_activities SET name=?,category=?,data_type=?,track_mode=?,unit=?,daily_target=?,weekly_target=?,monthly_target=?,weight=?,frequency=?,sticker=?,color=?,status=?,updated_at=? WHERE id=? AND user_id=?',
                'sssssdddisssssii',
                array(
                    $in['name'], $in['category'], $in['data_type'], $in['track_mode'], $in['unit'],
                    $in['daily_target'], $in['weekly_target'], $in['monthly_target'], (int) $in['weight'],
                    $in['frequency'], $in['sticker'], $in['color'], $in['status'], $now, (int) $id, (int) $user_id,
                )
            );
            return $id;
        }
        $code = 'ACTU' . time();
        $g = 'ACT_USR';
        joma_exec(
            'INSERT INTO joma_activities (user_id,code,group_code,name,category,data_type,track_mode,unit,daily_target,weekly_target,monthly_target,weight,frequency,sticker,color,status,is_seed,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,?,?)',
            'isssssssdddissssss',
            array(
                (int) $user_id, $code, $g, $in['name'], $in['category'], $in['data_type'], $in['track_mode'], $in['unit'],
                $in['daily_target'], $in['weekly_target'], $in['monthly_target'], (int) $in['weight'],
                $in['frequency'], $in['sticker'], $in['color'], $in['status'], $now, $now,
            )
        );
        return mysqli_insert_id(db());
    }
    $data = store_load();
    if ($id) {
        foreach ($data['activities'] as $i => $a) {
            if ((int) $a['id'] === (int) $id && (int) $a['user_id'] === (int) $user_id) {
                foreach ($in as $k => $v) $data['activities'][$i][$k] = $v;
                $data['activities'][$i]['updated_at'] = $now;
            }
        }
        store_save($data);
        return $id;
    }
    $row = $in;
    $row['id'] = store_next_id($data);
    $row['user_id'] = (int) $user_id;
    $row['code'] = 'ACTU' . $row['id'];
    $row['group_code'] = 'ACT_USR';
    $row['is_seed'] = 0;
    $row['created_at'] = $now;
    $row['updated_at'] = $now;
    $data['activities'][] = $row;
    store_save($data);
    return $row['id'];
}

function delete_activity($id, $user_id) {
    if (store_mode() === 'mysql') {
        joma_exec('DELETE FROM joma_activities WHERE id=? AND user_id=?', 'ii', array((int) $id, (int) $user_id));
        return;
    }
    $data = store_load();
    $keep = array();
    foreach ($data['activities'] as $a) {
        if (!((int) $a['id'] === (int) $id && (int) $a['user_id'] === (int) $user_id)) $keep[] = $a;
    }
    $data['activities'] = $keep;
    store_save($data);
}

function ensure_period($user_id, $period_key) {
    if (!preg_match('/^\d{4}-\d{2}$/', $period_key)) {
        $period_key = jalali_period_key(jalali_today());
    }
    $b = jalali_period_bounds($period_key);
    $now = joma_now();
    if (store_mode() === 'mysql') {
        $period = joma_query_one('SELECT * FROM joma_periods WHERE user_id=? AND period_key=?', 'is', array((int) $user_id, $period_key));
        if (!$period) {
            joma_exec(
                'INSERT INTO joma_periods (user_id,period_key,year,month,start_date,end_date,created_at) VALUES (?,?,?,?,?,?,?)',
                'isiisss',
                array((int) $user_id, $period_key, $b['year'], $b['month'], $b['start'], $b['end'], $now)
            );
            $pid = mysqli_insert_id(db());
            $period = array('id' => $pid, 'user_id' => $user_id, 'period_key' => $period_key, 'year' => $b['year'], 'month' => $b['month'], 'start_date' => $b['start'], 'end_date' => $b['end']);
        }
        $plan = joma_query_one('SELECT * FROM joma_plans WHERE user_id=? AND period_id=?', 'ii', array((int) $user_id, (int) $period['id']));
        if (!$plan) {
            $st = 'DRAFT';
            joma_exec(
                'INSERT INTO joma_plans (user_id,period_id,period_key,status,created_at,updated_at) VALUES (?,?,?,?,?,?)',
                'iissss',
                array((int) $user_id, (int) $period['id'], $period_key, $st, $now, $now)
            );
            $plan = get_plan_by_period($user_id, $period['id']);
        }
        return array('period' => $period, 'plan' => $plan);
    }
    $data = store_load();
    $period = null;
    foreach ($data['periods'] as $p) if ((int) $p['user_id'] === (int) $user_id && $p['period_key'] === $period_key) $period = $p;
    if (!$period) {
        $period = array('id' => store_next_id($data), 'user_id' => (int) $user_id, 'period_key' => $period_key, 'year' => $b['year'], 'month' => $b['month'], 'start_date' => $b['start'], 'end_date' => $b['end'], 'created_at' => $now);
        $data['periods'][] = $period;
    }
    $plan = null;
    foreach ($data['plans'] as $p) if ((int) $p['user_id'] === (int) $user_id && (int) $p['period_id'] === (int) $period['id']) $plan = $p;
    if (!$plan) {
        $plan = array('id' => store_next_id($data), 'user_id' => (int) $user_id, 'period_id' => $period['id'], 'period_key' => $period_key, 'status' => 'DRAFT', 'created_at' => $now, 'updated_at' => $now, 'finalized_at' => null, 'started_at' => null, 'archived_at' => null);
        $data['plans'][] = $plan;
    }
    store_save($data);
    return array('period' => $period, 'plan' => $plan);
}

function get_plan_by_period($user_id, $period_id) {
    if (store_mode() === 'mysql') {
        return joma_query_one('SELECT * FROM joma_plans WHERE user_id=? AND period_id=?', 'ii', array((int) $user_id, (int) $period_id));
    }
    $data = store_load();
    foreach ($data['plans'] as $p) if ((int) $p['user_id'] === (int) $user_id && (int) $p['period_id'] === (int) $period_id) return $p;
    return null;
}

function list_periods($user_id) {
    $cur = jalali_period_key(jalali_today());
    ensure_period($user_id, $cur);
    if (store_mode() === 'mysql') {
        return joma_query('SELECT pe.*, pl.id AS plan_id, pl.status AS plan_status FROM joma_periods pe JOIN joma_plans pl ON pl.period_id=pe.id WHERE pe.user_id=? ORDER BY pe.period_key DESC', 'i', array((int) $user_id));
    }
    $data = store_load();
    $out = array();
    foreach ($data['periods'] as $pe) {
        if ((int) $pe['user_id'] !== (int) $user_id) continue;
        foreach ($data['plans'] as $pl) {
            if ((int) $pl['period_id'] === (int) $pe['id']) {
                $pe['plan_id'] = $pl['id'];
                $pe['plan_status'] = $pl['status'];
                $out[] = $pe;
            }
        }
    }
    usort($out, function ($a, $b) { return strcmp($b['period_key'], $a['period_key']); });
    return $out;
}

function get_plan($plan_id, $user_id) {
    if (store_mode() === 'mysql') {
        return joma_query_one('SELECT * FROM joma_plans WHERE id=? AND user_id=?', 'ii', array((int) $plan_id, (int) $user_id));
    }
    $data = store_load();
    foreach ($data['plans'] as $p) if ((int) $p['id'] === (int) $plan_id && (int) $p['user_id'] === (int) $user_id) return $p;
    return null;
}

function list_plan_activities($plan_id, $user_id) {
    if (store_mode() === 'mysql') {
        return joma_query('SELECT * FROM joma_plan_activities WHERE plan_id=? AND user_id=? ORDER BY sort_order, id', 'ii', array((int) $plan_id, (int) $user_id));
    }
    $data = store_load();
    $out = array();
    foreach ($data['plan_activities'] as $a) if ((int) $a['plan_id'] === (int) $plan_id && (int) $a['user_id'] === (int) $user_id) $out[] = $a;
    usort($out, function ($x, $y) { return ((int) $x['sort_order']) - ((int) $y['sort_order']); });
    return $out;
}

function add_plan_activity($user_id, $plan, $activity, $over) {
    if (!plan_editable($plan['status'])) return 'برنامه این دوره قفل است.';
    foreach (list_plan_activities($plan['id'], $user_id) as $ex) {
        if ((int) $ex['activity_id'] === (int) $activity['id']) return 'این فعالیت قبلاً اضافه شده است.';
    }
    $freq = !empty($over['frequency']) ? $over['frequency'] : $activity['frequency'];
    if (!isset(frequencies_list()[$freq])) return 'تناوب فقط می‌تواند روزانه، هفتگی یا ماهانه باشد.';
    $tmp = $activity;
    $tmp['frequency'] = $freq;
    $target = isset($over['target_value']) && $over['target_value'] !== '' ? (float) $over['target_value'] : target_of($tmp);
    $weight = isset($over['weight']) && $over['weight'] !== '' ? (int) $over['weight'] : (int) $activity['weight'];
    if ($target <= 0) return 'هدف باید عددی بزرگ‌تر از صفر باشد.';
    if ($weight < 0) return 'وزن فعالیت نمی‌تواند منفی باشد.';
    $now = joma_now();
    $sort = count(list_plan_activities($plan['id'], $user_id));
    if (store_mode() === 'mysql') {
        joma_exec(
            'INSERT INTO joma_plan_activities (user_id,plan_id,period_key,activity_id,activity_code,name,category,frequency,data_type,unit,daily_target,weekly_target,monthly_target,target_value,weight,sticker,color,sort_order,snapshot_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            'iisissssssddddissis',
            array(
                (int) $user_id, (int) $plan['id'], $plan['period_key'], (int) $activity['id'], $activity['code'],
                $activity['name'], $activity['category'], $freq, $activity['data_type'], $activity['unit'],
                $activity['daily_target'], $activity['weekly_target'], $activity['monthly_target'], $target,
                $weight, $activity['sticker'], $activity['color'], $sort, $now,
            )
        );
        return '';
    }
    $data = store_load();
    $data['plan_activities'][] = array(
        'id' => store_next_id($data),
        'user_id' => (int) $user_id,
        'plan_id' => (int) $plan['id'],
        'period_key' => $plan['period_key'],
        'activity_id' => (int) $activity['id'],
        'activity_code' => $activity['code'],
        'name' => $activity['name'],
        'category' => $activity['category'],
        'frequency' => $freq,
        'data_type' => $activity['data_type'],
        'unit' => $activity['unit'],
        'daily_target' => $activity['daily_target'],
        'weekly_target' => $activity['weekly_target'],
        'monthly_target' => $activity['monthly_target'],
        'target_value' => $target,
        'weight' => $weight,
        'sticker' => $activity['sticker'],
        'color' => $activity['color'],
        'sort_order' => $sort,
        'snapshot_at' => $now,
    );
    store_save($data);
    return '';
}

function update_plan_activity($user_id, $plan, $pa_id, $patch) {
    if (!plan_editable($plan['status'])) return 'برنامه قفل است.';
    if (isset($patch['frequency']) && !isset(frequencies_list()[$patch['frequency']])) return 'تناوب نامعتبر است.';
    if (isset($patch['target_value']) && (float) $patch['target_value'] <= 0) return 'هدف باید عددی بزرگ‌تر از صفر باشد.';
    if (isset($patch['weight']) && (int) $patch['weight'] < 0) return 'وزن نمی‌تواند منفی باشد.';
    if (store_mode() === 'mysql') {
        $cur = joma_query_one('SELECT * FROM joma_plan_activities WHERE id=? AND user_id=?', 'ii', array((int) $pa_id, (int) $user_id));
        if (!$cur) return 'فعالیت برنامه پیدا نشد.';
        $freq = isset($patch['frequency']) ? $patch['frequency'] : $cur['frequency'];
        $target = isset($patch['target_value']) ? $patch['target_value'] : $cur['target_value'];
        $weight = isset($patch['weight']) ? $patch['weight'] : $cur['weight'];
        $sort = isset($patch['sort_order']) ? $patch['sort_order'] : $cur['sort_order'];
        joma_exec('UPDATE joma_plan_activities SET frequency=?, target_value=?, weight=?, sort_order=? WHERE id=? AND user_id=?', 'sdiiii', array($freq, $target, (int) $weight, (int) $sort, (int) $pa_id, (int) $user_id));
        return '';
    }
    $data = store_load();
    foreach ($data['plan_activities'] as $i => $a) {
        if ((int) $a['id'] === (int) $pa_id && (int) $a['user_id'] === (int) $user_id) {
            foreach ($patch as $k => $v) $data['plan_activities'][$i][$k] = $v;
        }
    }
    store_save($data);
    return '';
}

function remove_plan_activity($user_id, $plan, $pa_id) {
    if (!plan_editable($plan['status'])) return 'برنامه قفل است.';
    if (store_mode() === 'mysql') {
        joma_exec('DELETE FROM joma_plan_activities WHERE id=? AND user_id=?', 'ii', array((int) $pa_id, (int) $user_id));
        return '';
    }
    $data = store_load();
    $keep = array();
    foreach ($data['plan_activities'] as $a) {
        if (!((int) $a['id'] === (int) $pa_id && (int) $a['user_id'] === (int) $user_id)) $keep[] = $a;
    }
    $data['plan_activities'] = $keep;
    store_save($data);
    return '';
}

function can_transition($from, $to) {
    $ok = array(
        'DRAFT' => array('PLANNING', 'ARCHIVED'),
        'PLANNING' => array('RUNNING', 'DRAFT', 'ARCHIVED'),
        'RUNNING' => array('ARCHIVED'),
        'ARCHIVED' => array(),
    );
    return isset($ok[$from]) && in_array($to, $ok[$from], true);
}

function transition_plan($user_id, $plan, $next) {
    if (!can_transition($plan['status'], $next)) return 'این تغییر وضعیت مجاز نیست.';
    if (($next === 'PLANNING' || $next === 'RUNNING') && count(list_plan_activities($plan['id'], $user_id)) === 0) {
        return 'برای نهایی‌سازی یا شروع اجرا حداقل یک فعالیت لازم است.';
    }
    $now = joma_now();
    if (store_mode() === 'mysql') {
        if ($next === 'PLANNING') joma_exec('UPDATE joma_plans SET status=?, updated_at=?, finalized_at=? WHERE id=? AND user_id=?', 'sssii', array($next, $now, $now, (int) $plan['id'], (int) $user_id));
        elseif ($next === 'RUNNING') joma_exec('UPDATE joma_plans SET status=?, updated_at=?, started_at=? WHERE id=? AND user_id=?', 'sssii', array($next, $now, $now, (int) $plan['id'], (int) $user_id));
        elseif ($next === 'ARCHIVED') joma_exec('UPDATE joma_plans SET status=?, updated_at=?, archived_at=? WHERE id=? AND user_id=?', 'sssii', array($next, $now, $now, (int) $plan['id'], (int) $user_id));
        else joma_exec('UPDATE joma_plans SET status=?, updated_at=? WHERE id=? AND user_id=?', 'ssii', array($next, $now, (int) $plan['id'], (int) $user_id));
        return '';
    }
    $data = store_load();
    foreach ($data['plans'] as $i => $p) {
        if ((int) $p['id'] === (int) $plan['id']) {
            $data['plans'][$i]['status'] = $next;
            $data['plans'][$i]['updated_at'] = $now;
            if ($next === 'PLANNING') $data['plans'][$i]['finalized_at'] = $now;
            if ($next === 'RUNNING') $data['plans'][$i]['started_at'] = $now;
            if ($next === 'ARCHIVED') $data['plans'][$i]['archived_at'] = $now;
        }
    }
    store_save($data);
    return '';
}

function list_events($plan_id, $user_id) {
    if (store_mode() === 'mysql') {
        return joma_query('SELECT * FROM joma_performance_events WHERE plan_id=? AND user_id=? ORDER BY performance_date, created_at', 'ii', array((int) $plan_id, (int) $user_id));
    }
    $data = store_load();
    $out = array();
    foreach ($data['events'] as $e) if ((int) $e['plan_id'] === (int) $plan_id && (int) $e['user_id'] === (int) $user_id) $out[] = $e;
    return $out;
}

function can_register_performance($plan, $pa, $date, $value, $existing) {
    if (!is_numeric($value)) return 'مقدار عملکرد معتبر نیست.';
    $value = (float) $value;
    $err = validate_performance_value($pa['data_type'], $value);
    if ($err) return $err;
    if (!jalali_is_valid($date)) return 'تاریخ عملکرد معتبر نیست.';
    if ($plan['status'] !== 'RUNNING') return 'فقط در دوره در حال اجرا می‌توان عملکرد ثبت کرد.';
    if (!jalali_in_period($date, $plan['period_key'])) return 'تاریخ داخل این دوره نیست.';
    if ($pa['frequency'] === 'DAILY') {
        foreach ($existing as $e) {
            if ((int) $e['plan_activity_id'] === (int) $pa['id'] && $e['performance_date'] === $date) {
                return 'برای این فعالیت روزانه، در این تاریخ قبلاً عملکرد ثبت شده است.';
            }
        }
    }
    return '';
}

function register_performance($user_id, $plan, $pa, $date, $value) {
    $existing = list_events($plan['id'], $user_id);
    $err = can_register_performance($plan, $pa, $date, $value, $existing);
    if ($err) return $err;
    $now = joma_now();
    if (store_mode() === 'mysql') {
        $et = 'PERFORMANCE_REGISTERED';
        joma_exec(
            'INSERT INTO joma_performance_events (user_id,plan_id,plan_activity_id,period_key,frequency,data_type,event_type,performance_date,actual_value,created_at) VALUES (?,?,?,?,?,?,?,?,?,?)',
            'iiisssssds',
            array((int) $user_id, (int) $plan['id'], (int) $pa['id'], $plan['period_key'], $pa['frequency'], $pa['data_type'], $et, $date, (float) $value, $now)
        );
        return '';
    }
    $data = store_load();
    $data['events'][] = array(
        'id' => store_next_id($data),
        'user_id' => (int) $user_id,
        'plan_id' => (int) $plan['id'],
        'plan_activity_id' => (int) $pa['id'],
        'period_key' => $plan['period_key'],
        'frequency' => $pa['frequency'],
        'data_type' => $pa['data_type'],
        'event_type' => 'PERFORMANCE_REGISTERED',
        'performance_date' => $date,
        'actual_value' => (float) $value,
        'created_at' => $now,
    );
    store_save($data);
    return '';
}

function events_for($evs, $pa_id) {
    $out = array();
    foreach ($evs as $e) if ((int) $e['plan_activity_id'] === (int) $pa_id) $out[] = $e;
    return $out;
}

function sum_actual($evs) {
    $sum = 0;
    foreach ($evs as $e) $sum += (float) $e['actual_value'];
    return $sum;
}

function weekly_actual($evs, $date) {
    $sum = 0;
    foreach ($evs as $e) {
        if (jalali_same_week($e['performance_date'], $date)) $sum += (float) $e['actual_value'];
    }
    return $sum;
}

function displayed_actual($pa, $evs, $date) {
    $related = events_for($evs, $pa['id']);
    if ($pa['frequency'] === 'WEEKLY') return weekly_actual($related, $date);
    return sum_actual($related);
}

function has_daily_registration($evs, $date) {
    foreach ($evs as $e) if ($e['performance_date'] === $date) return true;
    return false;
}

function get_mood($user_id, $date) {
    if (store_mode() === 'mysql') {
        return joma_query_one('SELECT * FROM joma_mood_records WHERE user_id=? AND jalali_date=?', 'is', array((int) $user_id, $date));
    }
    $data = store_load();
    foreach ($data['moods'] as $m) if ((int) $m['user_id'] === (int) $user_id && $m['jalali_date'] === $date) return $m;
    return null;
}

function list_moods($user_id, $start, $end) {
    if (store_mode() === 'mysql') {
        return joma_query('SELECT * FROM joma_mood_records WHERE user_id=? AND jalali_date>=? AND jalali_date<=? ORDER BY jalali_date', 'iss', array((int) $user_id, $start, $end));
    }
    $data = store_load();
    $out = array();
    foreach ($data['moods'] as $m) {
        if ((int) $m['user_id'] === (int) $user_id && $m['jalali_date'] >= $start && $m['jalali_date'] <= $end) $out[] = $m;
    }
    return $out;
}

function save_mood($user_id, $date, $scores, $note) {
    $now = joma_now();
    $ex = get_mood($user_id, $date);
    if (store_mode() === 'mysql') {
        if ($ex) {
            joma_exec(
                'UPDATE joma_mood_records SET energy=?, general_mood=?, focus=?, sleep_quality=?, stress=?, note=? WHERE id=?',
                'iiiiisi',
                array((int) $scores['energy'], (int) $scores['general'], (int) $scores['focus'], (int) $scores['sleep'], (int) $scores['stress'], $note, (int) $ex['id'])
            );
        } else {
            joma_exec(
                'INSERT INTO joma_mood_records (user_id,jalali_date,energy,general_mood,focus,sleep_quality,stress,note,created_at) VALUES (?,?,?,?,?,?,?,?,?)',
                'isiiiiiss',
                array((int) $user_id, $date, (int) $scores['energy'], (int) $scores['general'], (int) $scores['focus'], (int) $scores['sleep'], (int) $scores['stress'], $note, $now)
            );
        }
        return;
    }
    $data = store_load();
    if ($ex) {
        foreach ($data['moods'] as $i => $m) {
            if ((int) $m['id'] === (int) $ex['id']) {
                $data['moods'][$i]['energy'] = $scores['energy'];
                $data['moods'][$i]['general_mood'] = $scores['general'];
                $data['moods'][$i]['focus'] = $scores['focus'];
                $data['moods'][$i]['sleep_quality'] = $scores['sleep'];
                $data['moods'][$i]['stress'] = $scores['stress'];
                $data['moods'][$i]['note'] = $note;
            }
        }
    } else {
        $data['moods'][] = array(
            'id' => store_next_id($data),
            'user_id' => (int) $user_id,
            'jalali_date' => $date,
            'energy' => $scores['energy'],
            'general_mood' => $scores['general'],
            'focus' => $scores['focus'],
            'sleep_quality' => $scores['sleep'],
            'stress' => $scores['stress'],
            'note' => $note,
            'created_at' => $now,
        );
    }
    store_save($data);
}

function weight_sum($acts) {
    $s = 0;
    foreach ($acts as $a) $s += (int) $a['weight'];
    return $s;
}

function build_report($user_id, $plan) {
    $acts = list_plan_activities($plan['id'], $user_id);
    $evs = list_events($plan['id'], $user_id);
    $b = jalali_period_bounds($plan['period_key']);
    $moods = list_moods($user_id, $b['start'], $b['end']);
    $rows = array();
    foreach ($acts as $a) {
        $list = events_for($evs, $a['id']);
        $a['actual'] = sum_actual($list);
        $a['event_count'] = count($list);
        $a['events'] = $list;
        $a['achievement'] = 'UNSPECIFIED';
        $rows[] = $a;
    }
    $hasE = array();
    $daySum = array();
    foreach ($evs as $e) {
        $d = $e['performance_date'];
        if (!isset($hasE[$d])) $hasE[$d] = 0;
        $hasE[$d]++;
        if (!isset($daySum[$d])) $daySum[$d] = 0;
        $daySum[$d] += (float) $e['actual_value'];
    }
    $hasM = array();
    foreach ($moods as $m) $hasM[$m['jalali_date']] = $m;
    $calendar = array();
    for ($d = 1; $d <= $b['days']; $d++) {
        $ds = $b['year'] . '-' . jalali_pad($b['month']) . '-' . jalali_pad($d);
        $calendar[] = array(
            'date' => $ds,
            'event_count' => isset($hasE[$ds]) ? $hasE[$ds] : 0,
            'actual_total' => isset($daySum[$ds]) ? $daySum[$ds] : 0,
            'has_mood' => isset($hasM[$ds]),
        );
    }
    return array(
        'period_key' => $plan['period_key'],
        'plan' => $plan,
        'activities' => $rows,
        'events' => $evs,
        'moods' => $moods,
        'bounds' => $b,
        'calendar' => $calendar,
        'weight_sum' => weight_sum($acts),
        'source_event_count' => count($evs),
        'achievement' => 'UNSPECIFIED',
        'overall_success' => 'UNSPECIFIED',
    );
}
