<?php
// Isolated Drupal API stubs; this is not a Drupal integration test.
define('JS_DEFAULT', 0);
$front = TRUE; $added = array();
function drupal_is_front_page() {global $front; return $front;}
function drupal_get_path($type, $name) {return 'sites/all/modules/custom/joma_owl_guide';}
function drupal_add_js($path, $opts) {global $added; $added[] = array($path, $opts);}
include __DIR__ . '/../joma_owl_guide/joma_owl_guide.module';
function verify($expr, $msg) {if (!$expr) {fwrite(STDERR, 'FAIL: ' . $msg . "\n"); exit(1);}}
function run_hook($host, $uri, $is_front) {global $front, $added; $front = $is_front; $added = array(); $_SERVER['HTTP_HOST'] = $host; $_SERVER['REQUEST_URI'] = $uri; $p = array(); joma_owl_guide_page_build($p); return $added;}
$x=run_hook('mirbolouki.com','/',TRUE);verify(count($x)===3,'home assets');verify($x[0][1]['preprocess']===FALSE && $x[0][1]['scope']==='footer','aggregation guard');verify(count(run_hook('mirbolouki.com','/page/test',FALSE))===0,'internal page');verify(count(run_hook('mirbolouki.com','/?q=admin',FALSE))===0,'query route');verify(count(run_hook('my.mirbolouki.com','/',TRUE))===0,'subdomain');verify(count(run_hook('example.test','/',TRUE))===0,'unconfigured host');putenv('JOMA_OWL_STAGING_HOST=example.test');verify(count(run_hook('example.test','/',TRUE))===3,'explicit isolated staging');verify(count(run_hook('my.mirbolouki.com','/',TRUE))===0,'subdomain guard even if staging enabled');echo "PASS: mocked PHP Drupal hook guards and asset ordering\n";
