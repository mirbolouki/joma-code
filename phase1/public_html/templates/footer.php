<?php
/* جوما — پاورقی مشترک */
$asset_base = defined('APP_BASE_URL') ? APP_BASE_URL : '';
if (!isset($layout)) { $layout = 'app'; }
?>
<?php if ($layout === 'auth') { ?>
  </div>
  <div class="auth-footer">
    سامانهٔ مدیریت کلینیک جوما — نسخهٔ ۱.۰ (فاز ۱)
  </div>
</div>
<?php } else { ?>
    </main>
  </div>
  <footer class="footer">
    سامانهٔ مدیریت کلینیک جوما — نسخهٔ ۱.۰ (فاز ۱)
  </footer>
</div>
<?php } ?>
<script src="<?php echo e($asset_base); ?>/assets/js/app.js"></script>
</body>
</html>
