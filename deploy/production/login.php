<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/includes/app_bootstrap.php';

$loginError = null;

if (isset($_GET['logout'])) {
    unset($_SESSION['joma_demo_user'], $_SESSION['joma_principal'], $_SESSION['joma_account_cache'], $_SESSION['joma_snapshot']);
    header('Location: /login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $u = trim($_POST['username'] ?? '');
    $p = (string)($_POST['password'] ?? '');

    if ($u === '' || $p === '') {
        $loginError = 'لطفاً نام کاربری و رمز عبور را وارد نمایید.';
    } else {
        if ($liveMode && $liveDb) {
            $nowUtc = gmdate('Y-m-d H:i:s.000000');
            $res = joma_auth_login($liveDb, $u, $p, $nowUtc);
            if ($res['ok'] ?? false) {
                $account = $res['account'];
                $_SESSION['joma_account_cache'] = $account;
                if (function_exists('joma_session_store_principal')) {
                    joma_session_store_principal($account);
                }

                $accountId = $account['id'];
                $bin = hex2bin(str_replace('-', '', $accountId));
                $stmt = $liveDb->prepare("SELECT id FROM joma_role_assignments WHERE account_id=? LIMIT 1");
                $stmt->bind_param('s', $bin);
                $stmt->execute();
                $res2 = $stmt->get_result();
                $row = $res2 ? $res2->fetch_assoc() : null;
                $stmt->close();

                if ($row && isset($row['id'])) {
                    $raBin = $row['id'];
                    $raUuid = bin2hex($raBin);
                    $raUuid = substr($raUuid,0,8).'-'.substr($raUuid,8,4).'-'.substr($raUuid,12,4).'-'.substr($raUuid,16,4).'-'.substr($raUuid,20);
                    $loaded = joma_context_load($liveDb, $accountId, $raUuid, $nowUtc);
                    if ($loaded['ok'] ?? false) {
                        $_SESSION['joma_snapshot'] = $loaded['snapshot'];
                    }
                }

                $_SESSION['joma_demo_user'] = [
                    'name' => $account['username'] ?? $u,
                    'role' => 'therapist',
                    'live' => true,
                    'account_id' => $accountId,
                    'person_id' => $account['person_id'] ?? null
                ];

                header('Location: /therapist/index.php');
                exit;
            } else {
                $loginError = 'نام کاربری یا کلمه عبور نادرست است.';
            }
        } else {
            // Mock fallback
            $_SESSION['joma_demo_user'] = ['name' => $u, 'role' => 'therapist', 'live' => false];
            header('Location: /therapist/index.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ورود به سامانه مدیریت کلینیک جوما</title>
<link rel="stylesheet" href="/assets/css/joma-theme.css">
<style>
body {
  background: radial-gradient(circle at 10% 20%, rgb(240, 249, 255) 0%, rgb(248, 250, 252) 90%);
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 100vh;
  padding: 20px;
}
.login-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-xl);
  padding: 40px;
  width: 100%;
  max-width: 440px;
  box-shadow: var(--shadow-modal);
}
.login-header {
  text-align: center;
  margin-bottom: 28px;
}
.brand-symbol {
  width: 58px;
  height: 58px;
  background: linear-gradient(135deg, var(--primary), var(--accent));
  color: #fff;
  border-radius: var(--radius-lg);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 28px;
  font-weight: 800;
  margin-bottom: 14px;
  box-shadow: var(--shadow-glow);
}
.login-header h2 {
  font-size: 22px;
  font-weight: 800;
  color: var(--text-main);
  margin-bottom: 6px;
}
.login-header p {
  font-size: 13px;
  color: var(--text-muted);
}
.login-tabs {
  display: flex;
  gap: 8px;
  background: #f1f5f9;
  padding: 4px;
  border-radius: var(--radius-md);
  margin-bottom: 24px;
}
.login-tab {
  flex: 1;
  text-align: center;
  padding: 8px;
  font-size: 13px;
  font-weight: 700;
  color: var(--text-muted);
  cursor: pointer;
  border-radius: var(--radius-sm);
  transition: all .2s;
}
.login-tab.active {
  background: #fff;
  color: var(--primary);
  box-shadow: var(--shadow-subtle);
}
.cred-box {
  margin-top: 24px;
  padding: 16px;
  background: var(--primary-light);
  border: 1px dashed var(--primary-border);
  border-radius: var(--radius-md);
  font-size: 12px;
  color: #0369a1;
  line-height: 1.8;
}
</style>
</head>
<body>

<div class="login-card">
  <div class="login-header">
    <div class="brand-symbol">ج</div>
    <h2>سامانه کلینیک جوما</h2>
    <p>پلتفرم جامع مدیریت بالینی و سلامت روان</p>
  </div>

  <div class="login-tabs">
    <div class="login-tab active">کادر درمان و پذیرش</div>
    <div class="login-tab" onclick="location.href='/portal/index.php'">ورود مراجعین</div>
  </div>

  <?php if ($loginError): ?>
    <div style="background:var(--danger-bg);color:var(--danger-text);border:1px solid var(--danger-border);padding:12px 14px;border-radius:var(--radius-md);font-size:13px;margin-bottom:18px;display:flex;align-items:center;gap:8px">
      <span>⚠️</span>
      <span><?= htmlspecialchars($loginError) ?></span>
    </div>
  <?php endif; ?>

  <form method="post">
    <div class="form-group">
      <label class="form-label">نام کاربری</label>
      <input class="form-control" name="username" placeholder="demo-therapist" value="demo-therapist" required autofocus>
    </div>

    <div class="form-group" style="margin-bottom:24px">
      <label class="form-label">کلمه عبور</label>
      <input class="form-control" name="password" type="password" placeholder="••••••••" value="Demo1234!" required>
    </div>

    <button class="btn btn-primary btn-block" name="login" value="1" style="padding:14px;font-size:15px">
      ورود به سامانه
    </button>
  </form>

  <div class="cred-box">
    <div><strong>مشخصات ورود پیش‌فرض:</strong></div>
    <div>نام کاربری: <code>demo-therapist</code></div>
    <div>رمز عبور: <code>Demo1234!</code></div>
  </div>
</div>

</body>
</html>
