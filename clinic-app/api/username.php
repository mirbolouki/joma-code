<?php
require dirname(__FILE__) . '/../includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
$u = isset($_GET['u']) ? $_GET['u'] : '';
echo json_encode(array('ok' => $u !== '' && !username_taken($u)));
