<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/charts.php';
requireRole('doctor');
$db = getDB();
$uid = (int)$_SESSION['user_id']; $fid = (int)$_SESSION['facility_id'];

$mine = periodCounts('doctor_id = ?', 'i', [$uid]);
$fac  = periodCounts('facility_id = ?', 'i', [$fid]);

$info = $db->prepare("SELECT f.name, f.type, l.name AS locality, s.name AS state
                      FROM facilities f JOIN localities l ON l.id = f.locality_id JOIN states s ON s.id = l.state_id WHERE f.id = ?");
$info->bind_param('i', $fid); $info->execute(); $f = $info->get_result()->fetch_assoc();

// آخر 14 يوماً للمنشأة
$days = []; $vals = [];
$from = addDays(date('Y-m-d'), -13);
$r = $db->prepare("SELECT report_date, COUNT(*) c FROM cases WHERE facility_id = ? AND report_date >= ? GROUP BY report_date");
$r->bind_param('is', $fid, $from); $r->execute();
$map = []; foreach ($r->get_result() as $row) $map[$row['report_date']] = (int)$row['c'];
for ($d = $from; $d <= date('Y-m-d'); $d = addDays($d, 1)) { $days[] = date('m/d', strtotime($d)); $vals[] = $map[$d] ?? 0; }

$recent = $db->prepare("SELECT case_code, report_date, age_years, sex, classification, severity, outcome FROM cases WHERE doctor_id = ? ORDER BY id DESC LIMIT 6");
$recent->bind_param('i', $uid); $recent->execute(); $recent = $recent->get_result()->fetch_all(MYSQLI_ASSOC);

layoutStart('الرئيسية', '<a class="btn btn-primary btn-sm" href="new_case.php">➕ تسجيل حالة جديدة</a>');
echo flash();
?>
<div class="alert alert-info">منشأتك: <strong><?= e($f['name']) ?></strong> (<?= e(label(FACILITY_TYPES, $f['type'])) ?>) — محلية <?= e($f['locality']) ?>، ولاية <?= e($f['state']) ?></div>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-value"><?= $mine['day'] ?></div><div class="stat-label">حالاتي اليوم</div></div>
    <div class="stat-card"><div class="stat-value"><?= $mine['week'] ?></div><div class="stat-label">حالاتي هذا الأسبوع</div></div>
    <div class="stat-card"><div class="stat-value"><?= $mine['month'] ?></div><div class="stat-label">حالاتي هذا الشهر</div></div>
    <div class="stat-card orange"><div class="stat-value"><?= $fac['day'] ?></div><div class="stat-label">منشأتي اليوم</div></div>
    <div class="stat-card orange"><div class="stat-value"><?= $fac['week'] ?></div><div class="stat-label">منشأتي هذا الأسبوع</div></div>
    <div class="stat-card orange"><div class="stat-value"><?= $fac['month'] ?></div><div class="stat-label">منشأتي هذا الشهر</div></div>
</div>

<div class="grid-2">
    <div class="card"><div class="card-header"><h3>حالات المنشأة في آخر 14 يوماً</h3></div>
        <div class="card-body chart-wrap"><?= svgBarChart($days, $vals) ?></div></div>
    <div class="card"><div class="card-header"><h3>آخر ما سجّلتَه</h3><a class="btn btn-outline btn-sm" href="my_cases.php">كل الحالات</a></div>
        <div class="card-body table-responsive">
        <?php if (!$recent): ?><div class="empty">لم تسجّل حالات بعد.</div><?php else: ?>
        <table class="data"><thead><tr><th>الرمز</th><th>التاريخ</th><th>العمر</th><th>التصنيف</th><th>المآل</th></tr></thead><tbody>
        <?php foreach ($recent as $c): ?><tr><td><?= e($c['case_code']) ?></td><td><?= e($c['report_date']) ?></td><td class="num"><?= $c['age_years'] ?></td>
            <td><?= e(label(CLASSIFICATIONS, $c['classification'])) ?></td><td><?= e(label(OUTCOMES, $c['outcome'])) ?></td></tr><?php endforeach; ?>
        </tbody></table><?php endif; ?></div></div>
</div>
<?php layoutEnd();
