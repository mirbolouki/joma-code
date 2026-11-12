<?php
// CI ONLY: create synthetic Drupal 7 SQLite site in /tmp; never production.
chdir('/tmp/joma-drupal7');
$_SERVER += array('HTTP_HOST'=>'127.0.0.1:8135','REMOTE_ADDR'=>'127.0.0.1','SERVER_SOFTWARE'=>'test','SERVER_NAME'=>'127.0.0.1','SCRIPT_NAME'=>'/install.php','REQUEST_URI'=>'/install.php','REQUEST_METHOD'=>'GET');
define('DRUPAL_ROOT', getcwd());
require_once DRUPAL_ROOT . '/includes/install.core.inc';
install_drupal(array('parameters'=>array('profile'=>'standard','locale'=>'en'),'forms'=>array('install_configure_form'=>array('site_name'=>'Synthetic staging', 'site_mail'=>'no-reply@example.test','account'=>array('name'=>'staging_admin','mail'=>'no-reply@example.test','pass'=>array('pass1'=>'Synthetic123!','pass2'=>'Synthetic123!')),'site_default_country'=>'','date_default_timezone'=>'UTC','update_status_module'=>array()))));
echo "Drupal synthetic install completed\n";
