<?php
// includes/auth.php — الجلسة والصلاحيات
require_once __DIR__ . '/functions.php';
if (session_status() === PHP_SESSION_NONE) {
    // على Vercel لا توجد ملفات دائمة: تُحفظ الجلسات في جدول sessions (SESSION_STORE=db، وهو الافتراضي هناك)
    if (strtolower((string)envOr('SESSION_STORE', envOr('VERCEL') ? 'db' : 'files')) === 'db') {
        require_once __DIR__ . '/session_handler.php';
        session_set_save_handler(new DengueDbSessionHandler(), true);
        ini_set('session.use_strict_mode', '1');
    }
    $https = (($_SERVER['HTTPS'] ?? '') === 'on') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => $https, 'path' => '/']);
    session_start();
}

function isLoggedIn() { return isset($_SESSION['user_id']); }

function requireRole($role) {
    if (!isLoggedIn()) { header('Location: ' . BASE_URL . '/auth/login.php'); exit; }
    if ($_SESSION['role'] !== $role) { redirectByRole(); }
    // تحقق أن الحساب ما زال نشطاً (قد يوقفه المدير أثناء الجلسة)
    $st = getDB()->prepare("SELECT status FROM users WHERE id = ?");
    $st->bind_param('i', $_SESSION['user_id']);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    if (!$row || $row['status'] !== 'active') {
        session_destroy();
        header('Location: ' . BASE_URL . '/auth/login.php?msg=suspended'); exit;
    }
}

function redirectByRole() {
    $role = $_SESSION['role'] ?? '';
    if ($role === 'doctor')  header('Location: ' . BASE_URL . '/doctor/dashboard.php');
    elseif ($role === 'manager') header('Location: ' . BASE_URL . '/manager/dashboard.php');
    else header('Location: ' . BASE_URL . '/auth/login.php');
    exit;
}
