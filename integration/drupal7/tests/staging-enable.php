<?php
chdir('/tmp/joma-drupal7');
$_SERVER += array('HTTP_HOST'=>'127.0.0.1:8135','REMOTE_ADDR'=>'127.0.0.1','SERVER_NAME'=>'127.0.0.1','SCRIPT_NAME'=>'/index.php','REQUEST_URI'=>'/','REQUEST_METHOD'=>'GET');
define('DRUPAL_ROOT',getcwd());require_once DRUPAL_ROOT.'/includes/bootstrap.inc';drupal_bootstrap(DRUPAL_BOOTSTRAP_FULL);
if (!module_enable(array('joma_owl_guide'))) {fwrite(STDERR,"Module enable failed\n");exit(1);}drupal_flush_all_caches();echo "Module enabled on synthetic Drupal\n";
