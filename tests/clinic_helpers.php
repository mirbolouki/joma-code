<?php
require __DIR__.'/../clinic-app/functions/clinic.php';
foreach(array('09123456789','9123456789','+989123456789','00989123456789','989123456789','۰۹۱۲۳۴۵۶۷۸۹') as $m) {
    if(clinic_norm_mobile($m)!=='09123456789') throw new RuntimeException('Normalization failed: '.$m);
}
foreach(array('0912evil3456789','123','+981234567890','') as $m) if(clinic_norm_mobile($m)!=='') throw new RuntimeException('Invalid mobile accepted');
echo "PASS: 10 phone normalization cases\n";
