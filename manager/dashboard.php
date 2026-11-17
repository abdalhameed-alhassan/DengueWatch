<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/charts.php';
requireRole('manager');
$db = getDB();
evaluateAlerts();

$today = date('Y-m-d'); $ws = weekStart(); $ms = monthStart();
$p = periodCounts();
$deaths = (int)$db->query("SELECT COUNT(*) FROM cases WHERE outcome='died' AND report_date >= '$ms' AND report_date <= '$today'")->fetch_row()[0];
$docActive = (int)$db->query("SELECT COUNT(*) FROM users WHERE role='doctor' AND status='active'")->fetch_row()[0];
$openAlerts = openAlertsCount();

function topRows($db, $table, $join, $nameCol, $groupCol, $since, $limit = 8) {
    $today = date('Y-m-d');
    return $db->query("SELECT x.$nameCol AS name, COUNT(*) c FROM cases c JOIN $table x ON x.id = c.$groupCol
                       WHERE c.report_date >= '$since' AND c.report_date <= '$today' GROUP BY x.id, x.$nameCol ORDER BY c DESC LIMIT $limit")->fetch_all(MYSQLI_NUM);
}
$locDay  = topRows($db, 'localities', '', 'name', 'locality_id', $today, 5);
$locWeek = topRows($db, 'localities', '', 'name', 'locality_id', $ws, 8);
$stDay   = topRows($db, 'states', '', 'name', 'state_id', $today, 5);
$stWeek  = topRows($db, 'states', '', 'name', 'state_id', $ws, 8);
$stMonth = topRows($db, 'states', '', 'name', 'state_id', $ms, 1);

// آخر 30 يوماً على مستوى الدولة
$from = addDays($today, -29);
$map = [];
foreach ($db->query("SELECT report_date d, COUNT(*) c FROM cases WHERE report_date BETWEEN '$from' AND '$today' GROUP BY d") as $r) $map[$r['d']] = (int)$r['c'];
$labels = []; $vals = [];
for ($d = $from; $d <= $today; $d = addDays($d, 1)) { $labels[] = date('m/d', strtotime($d)); $vals[] = $map[$d] ?? 0; }

$alerts = $db->query("SELECT a.*, l.name AS locality, s.name AS state FROM alerts a JOIN localities l ON l.id = a.locality_id JOIN states s ON s.id = l.state_id
                      WHERE a.status = 'open' AND a.week_start >= '" . addDays($ws, -7) . "' ORDER BY FIELD(a.level,'outbreak','warning'), a.cases_count DESC LIMIT 6")->fetch_all(MYSQLI_ASSOC);

layoutStart('لوحة البيانات الوطنية', '<span class="muted">' . e(date('Y-m-d')) . '</span>');
echo flash();
?>

<div class="stats-grid">
    <div class="stat-card red"><div class="stat-value"><?= fmt($p['day']) ?></div><div class="stat-label">إصابات اليوم</div></div>
    <div class="stat-card"><div class="stat-value"><?= fmt($p['week']) ?></div><div class="stat-label">هذا الأسبوع</div><div class="kpi-note">منذ الأحد</div></div>
    <div class="stat-card"><div class="stat-value"><?= fmt($p['month']) ?></div><div class="stat-label">هذا الشهر</div></div>
    <div class="stat-card"><div class="stat-value"><?= $deaths ?></div><div class="stat-label">وفيات الشهر</div></div>
    <div class="stat-card orange"><div class="stat-value"><?= $openAlerts ?></div><div class="stat-label">تنبيهات مفتوحة</div></div>
    <div class="stat-card green"><div class="stat-value"><?= fmt($docActive) ?></div><div class="stat-label">أطباء نشطون</div></div>
</div>

<div class="grid-2" style="margin-bottom:20px">
    <div class="card"><div class="card-header"><h3>🏙️ أكثر المحليات إصابةً اليوم</h3></div><div class="card-body"><?= rankList($locDay) ?></div></div>
    <div class="card"><div class="card-header"><h3>🗺️ أكثر الولايات إصابةً اليوم</h3></div><div class="card-body"><?= rankList($stDay) ?>
        <?php if ($stMonth): ?><p class="muted" style="margin-top:10px;font-size:.88rem">الأعلى هذا الشهر: <strong><?= e($stMonth[0][0]) ?></strong> (<?= fmt($stMonth[0][1]) ?> حالة)</p><?php endif; ?></div></div>
</div>

<div class="card"><div class="card-header"><h3>📈 الإصابات اليومية — آخر 30 يوماً</h3></div><div class="card-body chart-wrap"><?= svgBarChart($labels, $vals) ?></div></div>

<div class="grid-2" style="margin-bottom:20px">
    <div class="card"><div class="card-header"><h3>أكثر المحليات هذا الأسبوع</h3><a class="btn btn-outline btn-sm" href="localities.php">كل المحليات</a></div><div class="card-body"><?= rankList($locWeek) ?></div></div>
    <div class="card"><div class="card-header"><h3>أكثر الولايات هذا الأسبوع</h3></div><div class="card-body"><?= rankList($stWeek) ?></div></div>
</div>

<div class="card"><div class="card-header"><h3>🚨 تنبيهات التفشي المفتوحة</h3><a class="btn btn-outline btn-sm" href="alerts.php">إدارة التنبيهات</a></div>
<div class="card-body table-responsive">
<?php if (!$alerts): ?><div class="empty">✅ لا توجد تنبيهات مفتوحة حالياً.</div><?php else: ?>
<table class="data"><thead><tr><th>المستوى</th><th>المحلية</th><th>الولاية</th><th>حالات الأسبوع</th><th>السبب</th></tr></thead><tbody>
<?php foreach ($alerts as $a): ?><tr class="row-<?= $a['level'] === 'outbreak' ? 'out' : 'warn' ?>">
    <td><span class="badge badge-<?= $a['level'] === 'outbreak' ? 'out' : 'warn' ?>"><?= $a['level'] === 'outbreak' ? 'تفشٍّ' : 'تحذير' ?></span></td>
    <td><a href="locality.php?id=<?= $a['locality_id'] ?>"><?= e($a['locality']) ?></a></td><td><?= e($a['state']) ?></td><td class="num"><?= $a['cases_count'] ?></td><td class="wrap"><?= e($a['reason']) ?></td></tr><?php endforeach; ?>
</tbody></table><?php endif; ?></div></div>
<?php layoutEnd();
