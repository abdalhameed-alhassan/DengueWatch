<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
requireRole('manager');
$db = getDB();
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $name = trim($_POST['name'] ?? ''); $lid = (int)($_POST['locality_id'] ?? 0); $type = $_POST['type'] ?? '';
        if (mb_strlen($name) < 3) $errors[] = 'اكتب اسم المنشأة.';
        if (!isset(FACILITY_TYPES[$type])) $errors[] = 'اختر نوع المنشأة.';
        $c = $db->prepare("SELECT id FROM localities WHERE id=?"); $c->bind_param('i', $lid); $c->execute();
        if (!$c->get_result()->fetch_assoc()) $errors[] = 'اختر الولاية والمحلية.';
        if (!$errors) {
            $i = $db->prepare("INSERT INTO facilities (locality_id, name, type) VALUES (?,?,?)"); $i->bind_param('iss', $lid, $name, $type); $i->execute();
            flash('success', 'تمت إضافة المنشأة.'); header('Location: facilities.php'); exit;
        }
    } elseif ($action === 'toggle') {
        $fid = (int)($_POST['facility_id'] ?? 0);
        $st = $db->prepare("UPDATE facilities SET is_active = 1 - is_active WHERE id = ?"); $st->bind_param('i', $fid); $st->execute();
        flash('success', 'تم تغيير حالة المنشأة.'); header('Location: facilities.php?' . http_build_query(array_diff_key($_GET, ['page' => 1]))); exit;
    }
}
$stateId = (int)($_GET['state_id'] ?? 0); $q = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1)); $per = 25; $off = ($page - 1) * $per;
$ws = weekStart(); $today = date('Y-m-d');
$where = '1=1'; $types = ''; $vals = [];
if ($stateId) { $where .= ' AND l.state_id = ?'; $types .= 'i'; $vals[] = $stateId; }
if ($q !== '') { $where .= ' AND (f.name LIKE ? OR l.name LIKE ?)'; $types .= 'ss'; $vals[] = "%$q%"; $vals[] = "%$q%"; }
$cnt = $db->prepare("SELECT COUNT(*) FROM facilities f JOIN localities l ON l.id = f.locality_id WHERE $where");
if ($vals) $cnt->bind_param($types, ...$vals); $cnt->execute(); $total = (int)$cnt->get_result()->fetch_row()[0];
$st = $db->prepare("SELECT f.id, f.name, f.type, f.is_active, l.name AS locality, s.name AS state,
    (SELECT COUNT(*) FROM users u WHERE u.facility_id = f.id AND u.role='doctor' AND u.status='active') AS docs,
    (SELECT COUNT(*) FROM cases c WHERE c.facility_id = f.id AND c.report_date >= '$ws' AND c.report_date <= '$today') AS w,
    (SELECT COUNT(*) FROM cases c WHERE c.facility_id = f.id) AS t
    FROM facilities f JOIN localities l ON l.id = f.locality_id JOIN states s ON s.id = l.state_id WHERE $where ORDER BY s.name, l.name, f.name LIMIT $per OFFSET $off");
if ($vals) $st->bind_param($types, ...$vals); $st->execute(); $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$states = $db->query("SELECT id, name FROM states ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$showAdd = isset($_GET['add']) || $errors;
layoutStart('المنشآت الصحية', '<a class="btn btn-primary btn-sm" href="?add=1">➕ إضافة منشأة</a>');
echo flash(); foreach ($errors as $er) echo '<div class="alert alert-danger">' . e($er) . '</div>';
if ($showAdd): ?>
<form method="post" class="card" autocomplete="off"><div class="card-header"><h3>إضافة منشأة صحية</h3></div><div class="card-body">
    <?= csrfField() ?><input type="hidden" name="action" value="add">
    <div class="form-row">
        <div class="form-group"><label>الولاية</label><select data-cascade="state"><option value="">اختر</option><?php foreach ($states as $s): ?><option value="<?= $s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label>المحلية</label><select name="locality_id" data-cascade="locality" required><option value="">اختر</option></select></div>
        <div class="form-group"><label>النوع</label><select name="type"><?php foreach (FACILITY_TYPES as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label>اسم المنشأة</label><input name="name" required></div>
    </div>
    <button class="btn btn-primary" type="submit">حفظ</button> <a class="btn btn-outline" href="facilities.php">إلغاء</a>
</div></form>
<script>window.DENGUE_BASE = <?= json_encode(BASE_URL) ?>;</script><script src="<?= BASE_URL ?>/assets/js/cascade.js?v=<?= ASSET_VER ?>"></script>
<?php endif; ?>
<form class="filters" method="get">
    <div class="form-group"><label>الولاية</label><select name="state_id"><option value="0">كل الولايات</option><?php foreach ($states as $s): ?><option value="<?= $s['id'] ?>" <?= $stateId == $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
    <div class="form-group"><label>بحث</label><input name="q" value="<?= e($q) ?>"></div>
    <button class="btn btn-primary" type="submit">تصفية</button>
</form>
<div class="card"><div class="card-header"><h3><?= fmt($total) ?> منشأة</h3></div><div class="card-body table-responsive">
<table class="data"><thead><tr><th>المنشأة</th><th>النوع</th><th>المحلية</th><th>الولاية</th><th class="num">الأطباء</th><th class="num">الأسبوع</th><th class="num">الإجمالي</th><th>الحالة</th><th>إجراء</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td><?= e($r['name']) ?></td><td><?= e(label(FACILITY_TYPES, $r['type'])) ?></td><td><?= e($r['locality']) ?></td><td><?= e($r['state']) ?></td>
<td class="num"><?= $r['docs'] ?></td><td class="num"><?= $r['w'] ?></td><td class="num"><?= fmt($r['t']) ?></td>
<td><span class="badge badge-<?= $r['is_active'] ? 'ok' : 'gray' ?>"><?= $r['is_active'] ? 'نشطة' : 'موقوفة' ?></span></td>
<td><form method="post" class="inline-form"><?= csrfField() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="facility_id" value="<?= $r['id'] ?>"><button class="btn btn-outline btn-sm"><?= $r['is_active'] ? 'إيقاف' : 'تفعيل' ?></button></form></td></tr><?php endforeach; ?>
</tbody></table>
<?= pagerLinks($page, (int)ceil($total / $per), 'facilities.php?' . http_build_query(['state_id' => $stateId, 'q' => $q])) ?>
</div></div>
<?php layoutEnd();
