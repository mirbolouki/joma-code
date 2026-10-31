<?php
/* Exact integer monetary conversion. No floating point or implicit rounding. */
function joma_v2_toman_to_rial($input)
{
    if (!is_string($input)) throw new InvalidArgumentException('مبلغ نامعتبر است.');
    $input = strtr($input, array('۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
        '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9'));
    $input = str_replace(array(',', '،', '٬', ' ', "\xc2\xa0"), '', trim($input));
    if (!preg_match('/^[0-9]+$/D', $input)) throw new InvalidArgumentException('مبلغ را به تومان و به‌صورت عدد صحیح وارد کنید.');
    $input = ltrim($input, '0');
    if ($input === '') return 0;
    if (strlen($input) > 17) throw new OverflowException('مبلغ بیش از حد مجاز است.');
    return ((int)$input) * 10;
}

function joma_v2_rial_to_toman_string($rial)
{
    if (!is_int($rial) || $rial < 0) throw new InvalidArgumentException('Invalid rial amount');
    $whole = intdiv($rial, 10);
    $grouped = preg_replace('/\B(?=(?:[0-9]{3})+(?![0-9]))/', ',', (string)$whole);
    return $grouped . ($rial % 10 === 0 ? '' : '.' . ($rial % 10));
}
