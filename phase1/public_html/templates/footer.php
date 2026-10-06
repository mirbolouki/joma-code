<?php
/* جوما — پاورقی مشترک */
$asset_base = defined('APP_BASE_URL') ? APP_BASE_URL : '';
if (!isset($layout)) { $layout = 'app'; }
$joma_version_label = 'نسخهٔ ۱.۰.۱ (فاز ۱)';
if (isset($GLOBALS['db'])) {
    if (function_exists('phase4_2_ready') && phase4_2_ready($GLOBALS['db'])) {
        $joma_version_label = 'نسخهٔ ۴.۲.۰ (وصلهٔ ۴.۲ — درخواست نوبت اینترنتی)';
    } elseif (function_exists('phase4_ready') && phase4_ready($GLOBALS['db'])) {
        $joma_version_label = 'نسخهٔ ۴.۱.۱ (فاز ۴ — فرم‌ها، پورتال مراجع و دفترچهٔ مراجعان)';
    } elseif (function_exists('phase3_ready') && phase3_ready($GLOBALS['db'])) {
        $joma_version_label = 'نسخهٔ ۳.۰.۴ (فاز ۳ — پروندهٔ بالینی)';
    } elseif (function_exists('phase2_ready') && phase2_ready($GLOBALS['db'])) {
        $joma_version_label = 'نسخهٔ ۲.۰.۱ (فاز ۲ — نوبت‌دهی و تقویم)';
    }
}
?>
<?php if ($layout === 'auth') { ?>
  </div>
  <div class="auth-footer">
    سامانهٔ مدیریت کلینیک جوما — <?php echo e($joma_version_label); ?>
  </div>
</div>
<?php } else { ?>
    </main>
  </div>
  <footer class="footer">
    سامانهٔ مدیریت کلینیک جوما — <?php echo e($joma_version_label); ?>
  </footer>
</div>
<?php } ?>
<script src="<?php echo e($asset_base); ?>/assets/js/app.js"></script>
</body>
</html>
