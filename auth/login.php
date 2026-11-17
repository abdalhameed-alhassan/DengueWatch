<?php
require_once __DIR__ . '/../includes/auth.php';
if (isLoggedIn()) redirectByRole();

$error = '';
if (($_GET['msg'] ?? '') === 'suspended') $error = 'تم إنهاء الجلسة: الحساب غير نشط حالياً. تواصل مع إدارة الترصد.';
$phone = '';

// ── الدخول التجريبي إلى واجهة وزارة الصحة (بلا رقم هاتف أو كلمة مرور) ──
// يعمل فقط عند ضبط متغير البيئة DEMO_LOGIN=1، ولحساب مدير الترصد التجريبي فقط، وبصلاحية قراءة (لا تعديل للبيانات).
$demoEnabled = getenv('DEMO_LOGIN') === '1';
const DEMO_MANAGER_PHONE = '0911111111';
if ($demoEnabled && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['demo']) && $_POST['demo'] === 'manager') {
    checkCsrf();
    $st = getDB()->prepare("SELECT u.* FROM users u WHERE u.phone = ? AND u.role = 'manager' AND u.status = 'active'");
    $ph = DEMO_MANAGER_PHONE; $st->bind_param('s', $ph); $st->execute();
    $u = $st->get_result()->fetch_assoc();
    if ($u) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$u['id'];
        $_SESSION['role'] = $u['role'];
        $_SESSION['full_name'] = $u['full_name'];
        $_SESSION['facility_id'] = null;
        $_SESSION['facility_name'] = null;
        $_SESSION['demo'] = 1;
        redirectByRole();
    }
    $error = 'الحساب التجريبي غير متاح حالياً.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $phone = normalizePhone($_POST['phone'] ?? '');
    $pass  = $_POST['password'] ?? '';
    $st = getDB()->prepare("SELECT u.*, f.name AS facility_name FROM users u LEFT JOIN facilities f ON f.id = u.facility_id WHERE u.phone = ?");
    $st->bind_param('s', $phone);
    $st->execute();
    $u = $st->get_result()->fetch_assoc();
    if (!$u || !password_verify($pass, $u['password_hash'])) {
        $error = 'رقم الهاتف أو كلمة المرور غير صحيحة.';
    } elseif ($u['status'] === 'pending') {
        $error = 'حسابك بانتظار اعتماد إدارة الترصد. سيمكنك الدخول بعد الموافقة.';
    } elseif ($u['status'] !== 'active') {
        $error = $u['status'] === 'suspended' ? 'هذا الحساب موقوف. تواصل مع إدارة الترصد.' : 'تم رفض طلب التسجيل لهذا الحساب.';
    } else {
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$u['id'];
        $_SESSION['role'] = $u['role'];
        $_SESSION['full_name'] = $u['full_name'];
        $_SESSION['facility_id'] = $u['facility_id'] ? (int)$u['facility_id'] : null;
        $_SESSION['facility_name'] = $u['facility_name'];
        $up = getDB()->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
        $up->bind_param('i', $u['id']); $up->execute();
        redirectByRole();
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تسجيل الدخول — الترصد الذكي لحمى الضنك</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=<?= ASSET_VER ?>">
    <link rel="icon" type="image/svg+xml" href="<?= BASE_URL ?>/assets/img/favicon.svg">
</head>
<body class="auth-page">
<div class="auth-container">
    <div class="auth-card">
        <div class="auth-brand">
            <img src="<?= BASE_URL ?>/assets/img/logo-mark.svg" alt="" style="width:76px">
            <h2 style="margin:10px 0 2px">الترصد الذكي لحمى الضنك</h2>
            <p class="muted" style="font-size:.88rem">منصة تسجيل الحالات والتنبؤ بالتفشي في السودان</p>
        </div>
        <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
        <?php if ($demoEnabled): ?>
        <form method="post" class="demo-box">
            <?= csrfField() ?>
            <p class="muted" style="text-align:center;font-size:.85rem;margin:0 0 8px;font-weight:700">للمراجعة: ادخل مباشرة بلا رقم هاتف أو كلمة مرور</p>
            <button class="btn btn-primary btn-full" type="submit" name="demo" value="manager">🏛️ الدخول إلى واجهة وزارة الصحة</button>
        </form>
        <details class="demo-details"><summary class="muted" style="cursor:pointer;text-align:center;font-size:.8rem;margin:12px 0 4px">الدخول برقم الهاتف وكلمة المرور</summary>
        <?php endif; ?>
        <form method="post" autocomplete="off">
            <?= csrfField() ?>
            <div class="form-group"><label>رقم الهاتف</label>
                <input type="tel" name="phone" value="<?= e($phone) ?>" placeholder="09XXXXXXXX" inputmode="numeric" required></div>
            <div class="form-group"><label>كلمة المرور</label>
                <input type="password" name="password" required></div>
            <button class="btn btn-primary btn-full" type="submit">دخول</button>
        </form>
        <?php if ($demoEnabled): ?></details><?php endif; ?>
        <div class="auth-links">طبيب جديد؟ <a href="register.php">سجّل طلب انضمام</a></div>
    </div>
</div>
</body>
</html>
