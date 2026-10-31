<?php
require __DIR__ . '/../src/money.php';
function eq($a,$b,$msg) { if ($a !== $b) throw new Exception($msg); }
function bad($f,$msg) { try { $f(); } catch (InvalidArgumentException $e) { return; } catch (OverflowException $e) { return; } throw new Exception($msg); }
eq(joma_v2_toman_to_rial('۱٬۲۳۴٬۵۶۷'),12345670,'Persian numerals');
eq(joma_v2_toman_to_rial('١٢٣'),1230,'Arabic numerals');
eq(joma_v2_toman_to_rial(' ۰۰۰ '),0,'zero');
eq(joma_v2_toman_to_rial('99999999999999999'),999999999999999990,'maximum');
bad(function(){joma_v2_toman_to_rial('100000000000000000');},'overflow');
bad(function(){joma_v2_toman_to_rial('1.5');},'fractional input rejected');
bad(function(){joma_v2_toman_to_rial('-1');},'negative rejected');
bad(function(){joma_v2_toman_to_rial(array());},'array rejected');
eq(joma_v2_rial_to_toman_string(12345671),'1,234,567.1','exact rial remainder');
eq(joma_v2_rial_to_toman_string(12345670),'1,234,567','no rounding');
eq(joma_v2_rial_to_toman_string(999999999999999999),'99,999,999,999,999,999.9','large amount exact');
eq(joma_v2_rial_to_toman_signed(-12345671),'-1,234,567.1','negative amount');
eq(joma_v2_rial_to_toman_signed(PHP_INT_MIN),'-922,337,203,685,477,580.8','minimum integer exact');
echo "Exact money conversion tests passed.\n";
