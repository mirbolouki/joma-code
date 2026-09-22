<?php
$count=0;
foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../clinic-app')) as $file) {
    if ($file->getExtension()!=='php') continue;
    token_get_all(file_get_contents($file->getPathname()),TOKEN_PARSE);$count++;
}
echo "PASS: $count PHP files parsed by PHP ".PHP_VERSION."\n";
