<?php
require_once __DIR__ . '/../includes/auth.php';
if (isLoggedIn()) redirectByRole();
$db = getDB();
$errors = []; $done = false;
$v = ['full_name' => '', 'phone' => '', 'license_no' => '', 'state_id' => '', 'locality_id' => '', 'facility_id' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    foreach ($v as $k => $_) $v[$k] = trim($_POST[$k] ?? '');
    $v['phone'] = normalizePhone($v['phone']);
    $pass = $_POST['password'] ?? ''; $pass2 = $_POST['password2'] ?? '';
    if (mb_strlen($v['full_name']) < 6) $errors[] = 'اكتب الاسم الكامل (ثلاثي على الأقل).';
    if (!preg_match('/^09\d{8}$/', $v['phone'])) $errors[] = 'رقم الهاتف يجب أن يكون بصيغة 09XXXXXXXX.';
    if (strlen($pass) < 8) $errors[] = 'كلمة المرور 8 أحرف على الأقل.';
    if ($pass !== $pass2) $errors[] = 'تأكيد كلمة المرور غير مطابق.';
    $fid = (int)$v['facility_id'];
    $chk = $db->prepare("SELECT id FROM facilities WHERE id = ? AND is_active = 1"); $chk->bind_param('i', $fid); $chk->execute();
    if (!$chk->get_result()->fetch_assoc()) $errors[] = 'اختر الولاية والمحلية والمنشأة الصحية التي تعمل بها.';
    if (!$errors) {
        $ex = $db->prepare("SELECT id FROM users WHERE phone = ?"); $ex->bind_param('s', $v['phone']); $ex->execute();
        if ($ex->get_result()->fetch_assoc()) $errors[] = 'هذا الرقم مسجَّل مسبقاً.';
    }
    if (!$errors) {
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $lic = $v['license_no'] ?: null;
        $ins = $db->prepare("INSERT INTO users (full_name, phone, password_hash, role, facility_id, license_no, status) VALUES (?,?,?,'doctor',?,?, 'pending')");
        $ins->bind_param('sssis', $v['full_name'], $v['phone'], $hash, $fid, $lic);
        $ins->execute();
        $done = true;
    }
}
$states = $db->query("SELECT id, name FROM states ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$locs = $v['state_id'] ? $db->query("SELECT id, name FROM localities WHERE state_id = " . (int)$v['state_id'] . " ORDER BY name")->fetch_all(MYSQLI_ASSOC) : [];
$facs = $v['locality_id'] ? $db->query("SELECT id, name FROM facilities WHERE is_active=1 AND locality_id = " . (int)$v['locality_id'] . " ORDER BY name")->fetch_all(MYSQLI_ASSOC) : [];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>طلب انضمام طبيب — الترصد الذكي لحمى الضنك</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=<?= ASSET_VER ?>">
    <link rel="icon" type="image/svg+xml" href="<?= BASE_URL ?>/assets/img/favicon.svg">
    <script>window.DENGUE_BASE = <?= json_encode(BASE_URL) ?>;</script>
    <script src="<?= BASE_URL ?>/assets/js/cascade.js?v=<?= ASSET_VER ?>" defer></script>
</head>
<body class="auth-page">
<div class="auth-container wide">
    <div class="auth-card">
        <h2>طلب انضمام طبيب وحدة</h2>
        <?php if ($done): ?>
            <div class="alert alert-success">تم استلام طلبك. سيراجعه مدير الترصد، وبعد الاعتماد تستطيع الدخول برقم هاتفك.</div>
            <div class="auth-links"><a href="login.php">العودة لتسجيل الدخول</a></div>
        <?php else: ?>
            <?php foreach ($errors as $er): ?><div class="alert alert-danger"><?= e($er) ?></div><?php endforeach; ?>
            <form method="post" autocomplete="off">
                <?= csrfField() ?>
                <div class="form-row">
                    <div class="form-group"><label>الاسم الكامل</label><input name="full_name" value="<?= e($v['full_name']) ?>" required></div>
                    <div class="form-group"><label>رقم الهاتف</label><input type="tel" name="phone" value="<?= e($v['phone']) ?>" placeholder="09XXXXXXXX" required></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label>رقم التسجيل الطبي (اختياري)</label><input name="license_no" value="<?= e($v['license_no']) ?>"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label>الولاية</label>
                        <select name="state_id" data-cascade="state" required><option value="">اختر الولاية</option>
                        <?php foreach ($states as $s): ?><option value="<?= $s['id'] ?>" <?= $v['state_id'] == $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label>المحلية</label>
                        <select name="locality_id" data-cascade="locality" required><option value="">اختر المحلية</option>
                        <?php foreach ($locs as $s): ?><option value="<?= $s['id'] ?>" <?= $v['locality_id'] == $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label>المنشأة الصحية</label>
                        <select name="facility_id" data-cascade="facility" required><option value="">اختر المنشأة</option>
                        <?php foreach ($facs as $s): ?><option value="<?= $s['id'] ?>" <?= $v['facility_id'] == $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label>كلمة المرور</label><input type="password" name="password" required></div>
                    <div class="form-group"><label>تأكيد كلمة المرور</label><input type="password" name="password2" required></div>
                </div>
                <button class="btn btn-primary btn-full" type="submit">إرسال الطلب</button>
            </form>
            <div class="auth-links"><a href="login.php">لدي حساب</a></div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
