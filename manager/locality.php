<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/charts.php';
requireRole('manager');
$db = getDB();
$id = (int)($_GET['id'] ?? 0);
$st = $db->prepare("SELECT l.id, l.name, s.name AS state FROM localities l JOIN states s ON s.id = l.state_id WHERE l.id = ?");
$st->bind_param('i', $id); $st->execute(); $L = $st->get_result()->fetch_assoc();
if (!$L) { http_response_code(404); exit('المحلية غير موجودة'); }

$p = periodCounts('locality_id = ?', 'i', [$id]);
$today = date('Y-m-d'); $ws = weekStart(); $ms = monthStart();
$facs = $db->query("SELECT f.id, f.name, f.type, f.is_active,
        (SELECT COUNT(*) FROM users u WHERE u.facility_id = f.id AND u.role='doctor' AND u.status='active') AS docs,
        COALESCE(SUM(c.report_date = '$today'),0) AS d, COALESCE(SUM(c.report_date >= '$ws'),0) AS w, COALESCE(SUM(c.report_date >= '$ms'),0) AS m, COUNT(c.id) AS t,
        MAX(c.report_date) AS last_report
        FROM facilities f LEFT JOIN cases c ON c.facility_id = f.id AND c.report_date <= '$today'
        WHERE f.locality_id = $id GROUP BY f.id, f.name, f.type, f.is_active ORDER BY w DESC, f.name")->fetch_all(MYSQLI_ASSOC);
$docs = $db->query("SELECT u.full_name, u.phone, u.status, f.name AS facility,
        COALESCE(SUM(c.report_date >= '$ws' AND c.report_date <= '$today'),0) AS w, COUNT(c.id) AS t, MAX(c.report_date) AS last_report
        FROM users u JOIN facilities f ON f.id = u.facility_id LEFT JOIN cases c ON c.doctor_id = u.id
        WHERE f.locality_id = $id AND u.role='doctor' GROUP BY u.id, u.full_name, u.phone, u.status, f.name ORDER BY u.status, w DESC")->fetch_all(MYSQLI_ASSOC);

$from = addDays($today, -29); $map = [];
foreach ($db->query("SELECT report_date d, COUNT(*) c FROM cases WHERE locality_id = $id AND report_date BETWEEN '$from' AND '$today' GROUP BY d") as $r) $map[$r['d']] = (int)$r['c'];
$labels = []; $vals = [];
for ($d = $from; $d <= $today; $d = addDays($d, 1)) { $labels[] = date('m/d', strtotime($d)); $vals[] = $map[$d] ?? 0; }

layoutStart('محلية ' . $L['name'], '<a class="btn btn-outline btn-sm" href="localities.php">← كل المحليات</a>');
?>
<p class="muted" style="margin-bottom:14px">ولاية <?= e($L['state']) ?></p>
<div class="stats-grid">
    <div class="stat-card red"><div class="stat-value"><?= $p['day'] ?></div><div class="stat-label">اليوم</div></div>
    <div class="stat-card"><div class="stat-value"><?= $p['week'] ?></div><div class="stat-label">الأسبوع</div></div>
    <div class="stat-card"><div class="stat-value"><?= $p['month'] ?></div><div class="stat-label">الشهر</div></div>
    <div class="stat-card orange"><div class="stat-value"><?= fmt($p['total']) ?></div><div class="stat-label">الإجمالي</div></div>
</div>
<div class="card"><div class="card-header"><h3>الإصابات اليومية — آخر 30 يوماً</h3></div><div class="card-body chart-wrap"><?= svgBarChart($labels, $vals) ?></div></div>
<div class="card"><div class="card-header"><h3>🏥 المنشآت الصحية (كل منشأة وعدد ما سجلته)</h3></div><div class="card-body table-responsive">
<table class="data"><thead><tr><th>المنشأة</th><th>النوع</th><th class="num">الأطباء</th><th class="num">اليوم</th><th class="num">الأسبوع</th><th class="num">الشهر</th><th class="num">الإجمالي</th><th>آخر تسجيل</th></tr></thead><tbody>
<?php foreach ($facs as $f): ?><tr><td><?= e($f['name']) ?><?= $f['is_active'] ? '' : ' <span class="badge badge-gray">موقوفة</span>' ?></td><td><?= e(label(FACILITY_TYPES, $f['type'])) ?></td>
<td class="num"><?= $f['docs'] ?></td><td class="num"><?= $f['d'] ?></td><td class="num"><strong><?= $f['w'] ?></strong></td><td class="num"><?= $f['m'] ?></td><td class="num"><?= fmt($f['t']) ?></td><td><?= e($f['last_report'] ?? '—') ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<div class="card"><div class="card-header"><h3>🩺 الأطباء في المحلية</h3></div><div class="card-body table-responsive">
<table class="data"><thead><tr><th>الطبيب</th><th>الهاتف</th><th>المنشأة</th><th>الحالة</th><th class="num">حالات الأسبوع</th><th class="num">الإجمالي</th><th>آخر تسجيل</th></tr></thead><tbody>
<?php foreach ($docs as $d): ?><tr><td><?= e($d['full_name']) ?></td><td dir="ltr" style="text-align:right"><?= e($d['phone']) ?></td><td><?= e($d['facility']) ?></td>
<td><span class="badge badge-<?= $d['status'] === 'active' ? 'ok' : ($d['status'] === 'pending' ? 'warn' : 'gray') ?>"><?= e(label(USER_STATUS, $d['status'])) ?></span></td>
<td class="num"><?= $d['w'] ?></td><td class="num"><?= fmt($d['t']) ?></td><td><?= e($d['last_report'] ?? '—') ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<?php layoutEnd();
