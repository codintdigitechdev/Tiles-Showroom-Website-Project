<?php
require_once __DIR__ . '/includes/functions.php';
if (user()) redirect('dashboard.php');
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $u = row('SELECT * FROM users WHERE username = ? AND role = ? AND is_active = 1',
        [trim($_POST['username'] ?? ''), $_POST['role'] ?? '']);
    if ($u && password_verify($_POST['password'] ?? '', $u['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user'] = ['id' => $u['id'], 'role' => $u['role'], 'username' => $u['username'], 'full_name' => $u['full_name']];
        redirect('dashboard.php');
    }
    $err = 'Invalid role, username or password.';
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login · Shree Enterprises</title><link rel="icon" href="assets/img/favicon.png"><link rel="stylesheet" href="assets/css/style.css"></head>
<body><div class="login-wrap"><form class="login" method="post">
<div style="text-align:center"><?= shop_logo(120) ?></div><h1 style="text-align:center;margin-top:8px">Shree Enterprises</h1><p style="margin-top:0;color:#777;text-align:center">Your Trusted Partner in Tiles · Sale &amp; Purchase System</p>
<?php if ($err): ?><div class="flash err" style="margin:0 0 14px"><?= e($err) ?></div><?php endif; ?>
<?= csrf_field() ?>
<div class="row"><label>User Role</label><select name="role"><option value="admin">Admin</option><option value="staff">Staff</option></select></div>
<div class="row"><label>Username</label><input name="username" required autofocus></div>
<div class="row"><label>Password</label><input type="password" name="password" required></div>
<button class="btn" style="width:100%">Login</button>
<p style="text-align:center;margin-bottom:0"><a href="index.php">← Back to website</a></p>
</form></div></body></html>
