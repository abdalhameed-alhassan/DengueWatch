<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
requireRole('manager');
$db = getDB();
$me = (int)$_SESSION['user_id'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $name = trim($_POST['full_name'] ?? ''); $phone = normalizePhone($_POST['phone'] ?? ''); $fid = (int)($_POST['facility_id'] ?? 0);
        $pass = $_POST['password'] ?? ''; $lic = trim($_POST['license_no'] ?? '') ?: null;
        if (mb_strlen($name) < 6) $errors[] = 'اكتب الاسم الكامل.';
        if (!preg_match('/^09\d{8}$/', $phone)) $errors[] = 'رقم الهاتف بصيغة 09XXXXXXXX.';
        if (strlen($pass) < 8) $errors[] = 'كلمة المرور 8 أحرف على الأقل.';
        $c = $db->prepare("SELECT id FROM facilities WHERE id=? AND is_active=1"); $c->bind_param('i', $fid); $c->execute();
        if (!$c->get_result()->fetch_assoc()) $errors[] = 'اختر المنشأة.';
        $c = $db->prepare("SELECT id FROM users WHERE phone=?"); $c->bind_param('s', $phone); $c->execute();
        if ($c->get_result()->fetch_assoc()) $errors[] = 'الرقم مسجَّل مسبقاً.';
        if (!$errors) {
            $h = password_hash($pass, PASSWORD_DEFAULT);
            $i = $db->prepare("INSERT INTO users (full_name, phone, password_hash, role, facility_id, license_no, status, approved_by, approved_at) VALUES (?,?,?,'doctor',?,?, 'active', ?, NOW())");
            $i->bind_param('sssisi', $name, $phone, $h, $fid, $lic, $me); $i->execute();
            flash('success', 'تمت إضافة الطبيب وتفعيل حسابه.'); header('Location: doctors.php'); exit;
        }
    } else {
        $uid = (int)($_POST['user_id'] ?? 0);
        $map = ['approve' => 'active', 'reject' => 'rejected', 'suspend' => 'suspended', 'activate' => 'active'];
        if (isset($map[$action])) {
            $new = $map[$action];
            $st = $db->prepare("UPDATE users SET status = ?, approved_by = IF(? = 'active', ?, approved_by), approved_at = IF(? = 'active' AND approved_at IS NULL, NOW(), approved_at) WHERE id = ? AND role = 'doctor'");
            $st->bind_param('ssisi', $new, $new, $me, $new, $uid); $st->execute();
            flash('success', 'تم تحديث حالة الطبيب.');
            header('Location: doctors.php?' . http_build_query(array_diff_key($_GET, ['page' => 1]))); exit;
        }
    }
}

$status = $_GET['status'] ?? ''; $stateId = (int)($_GET['state_id'] ?? 0); $q = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1)); $per = 25; $off = ($page - 1) * $per;
$ws = weekStart(); $today = date('Y-m-d');
$where = "u.role = 'doctor'"; $types = ''; $vals = [];
if (isset(USER_STATUS[$status])) { $where .= ' AND u.status = ?'; $types .= 's'; $vals[] = $status; }
if ($stateId) { $where .= ' AND l.state_id = ?'; $types .= 'i'; $vals[] = $stateId; }
if ($q !== '') { $where .= ' AND (u.full_name LIKE ? OR u.phone LIKE ?)'; $types .= 'ss'; $vals[] = "%$q%"; $vals[] = "%$q%"; }
$cnt = $db->prepare("SELECT COUNT(*) FROM users u LEFT JOIN facilities f ON f.id = u.facility_id LEFT JOIN localities l ON l.id = f.locality_id WHERE $where");
if ($vals) $cnt->bind_param($types, ...$vals); $cnt->execute(); $total = (int)$cnt->get_result()->fetch_row()[0];
$st = $db->prepare("SELECT u.id, u.full_name, u.phone, u.status, u.created_at, f.name AS facility, l.name AS locality, s.name AS state,
        (SELECT COUNT(*) FROM cases c WHERE c.doctor_id = u.id AND c.report_date >= '$ws' AND c.report_date <= '$today') AS w,
        (SELECT COUNT(*) FROM cases c WHERE c.doctor_id = u.id) AS t,
        (SELECT MAX(report_date) FROM cases c WHERE c.doctor_id = u.id) AS last_report
        FROM users u LEFT JOIN facilities f ON f.id = u.facility_id LEFT JOIN localities l ON l.id = f.locality_id LEFT JOIN states s ON s.id = l.state_id
        WHERE $where ORDER BY FIELD(u.status,'pending','active','suspended','rejected'), u.id DESC LIMIT $per OFFSET $off");
if ($vals) $st->bind_param($types, ...$vals); $st->execute(); $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$states = $db->query("SELECT id, name FROM states ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$counts = []; foreach ($db->query("SELECT status, COUNT(*) c FROM users WHERE role='doctor' GROUP BY status") as $r) $counts[$r['status']] = (int)$r['c'];
$showAdd = isset($_GET['add']) || $errors;

layoutStart('إدارة الأطباء', '<a class="btn btn-primary btn-sm" href="?add=1">➕ إضافة طبيب</a>');
echo flash();
foreach ($errors as $er) echo '<div class="alert alert-danger">' . e($er) . '</div>';
?>
<div class="stats-grid">
    <?php foreach (['active' => 'green', 'pending' => 'orange', 'suspended' => 'red', 'rejected' => ''] as $k => $cls): ?>
    <a href="?status=<?= $k ?>" style="text-decoration:none"><div class="stat-card <?= $cls ?>"><div class="stat-value"><?= $counts[$k] ?? 0 ?></div><div class="stat-label"><?= e(USER_STATUS[$k]) ?></div></div></a>
    <?php endforeach; ?>
</div>

<?php if ($showAdd): ?>
<form method="post" class="card" autocomplete="off"><div class="card-header"><h3>إضافة طبيب (يُفعَّل مباشرة)</h3></div><div class="card-body">
    <?= csrfField() ?><input type="hidden" name="action" value="add">
    <div class="form-row">
        <div class="form-group"><label>الاسم الكامل</label><input name="full_name" required></div>
        <div class="form-group"><label>رقم الهاتف</label><input name="phone" placeholder="09XXXXXXXX" required></div>
        <div class="form-group"><label>كلمة مرور مؤقتة</label><input type="password" name="password" required></div>
    </div>
    <div class="form-row">
        <div class="form-group"><label>الولاية</label><select data-cascade="state"><option value="">اختر</option><?php foreach ($states as $s): ?><option value="<?= $s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label>المحلية</label><select data-cascade="locality"><option value="">اختر</option></select></div>
        <div class="form-group"><label>المنشأة</label><select name="facility_id" data-cascade="facility" required><option value="">اختر</option></select></div>
        <div class="form-group"><label>رقم التسجيل الطبي</label><input name="license_no"></div>
    </div>
    <button class="btn btn-primary" type="submit">حفظ</button> <a class="btn btn-outline" href="doctors.php">إلغاء</a>
</div></form>
<script>window.DENGUE_BASE = <?= json_encode(BASE_URL) ?>;</script><script src="<?= BASE_URL ?>/assets/js/cascade.js?v=<?= ASSET_VER ?>"></script>
<?php endif; ?>

<form class="filters" method="get">
    <div class="form-group"><label>الحالة</label><select name="status"><option value="">الكل</option><?php foreach (USER_STATUS as $k => $l): ?><option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    <div class="form-group"><label>الولاية</label><select name="state_id"><option value="0">كل الولايات</option><?php foreach ($states as $s): ?><option value="<?= $s['id'] ?>" <?= $stateId == $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
    <div class="form-group"><label>بحث (اسم/هاتف)</label><input name="q" value="<?= e($q) ?>"></div>
    <button class="btn btn-primary" type="submit">تصفية</button>
</form>
<div class="card"><div class="card-header"><h3><?= fmt($total) ?> طبيب</h3></div><div class="card-body table-responsive">
<?php if (!$rows): ?><div class="empty">لا توجد نتائج.</div><?php else: ?>
<table class="data"><thead><tr><th>الطبيب</th><th>الهاتف</th><th>المنشأة</th><th>الولاية</th><th>الحالة</th><th class="num">الأسبوع</th><th class="num">الإجمالي</th><th>آخر تسجيل</th><th>إجراء</th></tr></thead><tbody>
<?php foreach ($rows as $r): $cls = ['active' => 'ok', 'pending' => 'warn', 'suspended' => 'gray', 'rejected' => 'high'][$r['status']]; ?><tr>
<td><strong><?= e($r['full_name']) ?></strong></td><td dir="ltr" style="text-align:right"><?= e($r['phone']) ?></td><td class="wrap"><?= e($r['facility']) ?></td><td><?= e($r['state']) ?></td>
<td><span class="badge badge-<?= $cls ?>"><?= e(label(USER_STATUS, $r['status'])) ?></span></td>
<td class="num"><?= $r['w'] ?></td><td class="num"><?= fmt($r['t']) ?></td><td><?= e($r['last_report'] ?? '—') ?></td>
<td><form method="post" class="inline-form"><?= csrfField() ?><input type="hidden" name="user_id" value="<?= $r['id'] ?>">
    <?php if ($r['status'] === 'pending'): ?><button class="btn btn-success btn-sm" name="action" value="approve">اعتماد</button> <button class="btn btn-danger btn-sm" name="action" value="reject">رفض</button>
    <?php elseif ($r['status'] === 'active'): ?><button class="btn btn-warning btn-sm" name="action" value="suspend" onclick="return confirm('إيقاف هذا الطبيب؟ لن يستطيع الدخول.')">إيقاف</button>
    <?php else: ?><button class="btn btn-outline btn-sm" name="action" value="activate">تفعيل</button><?php endif; ?></form></td>
</tr><?php endforeach; ?></tbody></table>
<?= pagerLinks($page, (int)ceil($total / $per), 'doctors.php?' . http_build_query(['status' => $status, 'state_id' => $stateId, 'q' => $q])) ?>
<?php endif; ?></div></div>
<?php layoutEnd();
