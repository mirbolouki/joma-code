<?php
function learn_data() {
    static $d = null;
    if ($d !== null) return $d;
    $f = dirname(__FILE__) . '/learn_guides.json';
    if (!is_file($f)) {
        $d = array();
        return $d;
    }
    $raw = json_decode(file_get_contents($f), true);
    $d = is_array($raw) ? $raw : array();
    return $d;
}

function learn_guide($code) {
    if (!preg_match('/^ACT\\d{3}$/', $code)) return null;
    $d = learn_data();
    if (empty($d['guides'][$code])) return null;
    return $d['guides'][$code];
}

function learn_group_codes($group) {
    $out = array();
    $d = learn_data();
    if (empty($d['guides']) || !is_array($d['guides'])) return $out;
    foreach ($d['guides'] as $code => $g) {
        if (isset($g['group']) && $g['group'] === $group) $out[] = $g;
    }
    return $out;
}
