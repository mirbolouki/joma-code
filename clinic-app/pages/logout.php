<?php
$_SESSION = array();
if (session_id()) session_destroy();
joma_redirect('index.php?p=login');
