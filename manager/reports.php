<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
requireRole('manager');
$week = $_GET['week'] ?? addDays(weekStart(), -7);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $week)) $week = addDays(weekStart(), -7);
$week = weekStart($week);
$end = addDays($week, 6);
$partial = $end >= date('Y-m-d');
$rows = weeklyReport($week, 'state');
$loc = array_slice(weeklyReport($week, 'locality'), 0, 15);
$tot = ['cur' => 0, 'prev' => 0, 'confirmed' => 0, 'severe' => 0, 'deaths' => 0];
foreach ($rows as $r) foreach ($tot as $k => $_) $tot[$k] += (int)$r[$k];
function chg($cur, $prev) { if ($prev == 0) return $cur > 0 ? '<span class="badge badge-high">جديد</span>' : '—'; $p = round(($cur - $prev) / $prev * 100); return '<span class="badge badge-' . ($p > 10 ? 'high' : ($p < -10 ? 'low' : 'gray')) . '" dir="ltr">' . ($p > 0 ? '+' : '') . $p . '%</span>'; }
layoutStart('التقرير الأسبوعي',
    '<a class="btn btn-success btn-sm" href="report_csv.php?week=' . e($week) . '&level=state">⬇️ CSV الولايات</a> <a class="btn btn-success btn-sm" href="report_csv.php?week=' . e($week) . '&level=locality">⬇️ CSV المحليات</a> <button class="btn btn-outline btn-sm" onclick="window.print()">🖨️ طباعة</button>');
?>
<form class="filters" method="get">
    <div class="form-group"><label>الأسبوع (يبدأ الأحد)</label><input type="date" name="week" value="<?= e($week) ?>"></div>
    <button class="btn btn-primary" type="submit">عرض</button>
    <a class="btn btn-outline" href="?week=<?= e(addDays($week, -7)) ?>">← الأسبوع السابق</a>
    <?php if (!$partial): ?><a class="btn btn-outline" href="?week=<?= e(addDays($week, 7)) ?>">الأسبوع التالي →</a><?php endif; ?>
</form>
<?php if ($partial): ?><div class="alert alert-warning">هذا الأسبوع لم ينتهِ بعد؛ الأرقام جزئية.</div><?php endif; ?>
<div class="stats-grid">
    <div class="stat-card"><div class="stat-value"><?= fmt($tot['cur']) ?></div><div class="stat-label">إجمالي الحالات</div><div class="kpi-note">السابق: <?= fmt($tot['prev']) ?></div></div>
    <div class="stat-card green"><div class="stat-value"><?= fmt($tot['confirmed']) ?></div><div class="stat-label">مؤكدة مخبرياً</div></div>
    <div class="stat-card orange"><div class="stat-value"><?= fmt($tot['severe']) ?></div><div class="stat-label">حالات شديدة</div></div>
    <div class="stat-card red"><div class="stat-value"><?= fmt($tot['deaths']) ?></div><div class="stat-label">وفيات</div></div>
</div>
<div class="card"><div class="card-header"><h3>حسب الولاية</h3></div><div class="card-body table-responsive">
<table class="data"><thead><tr><th>الولاية</th><th class="num">الحالات</th><th class="num">الأسبوع السابق</th><th class="num">التغير</th><th class="num">مؤكدة</th><th class="num">شديدة</th><th class="num">وفيات</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td><?= e($r['name']) ?></td><td class="num"><strong><?= $r['cur'] ?></strong></td><td class="num"><?= $r['prev'] ?></td><td class="num"><?= chg($r['cur'], $r['prev']) ?></td><td class="num"><?= $r['confirmed'] ?></td><td class="num"><?= $r['severe'] ?></td><td class="num"><?= $r['deaths'] ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<div class="card"><div class="card-header"><h3>أعلى 15 محلية</h3></div><div class="card-body table-responsive">
<table class="data"><thead><tr><th>المحلية</th><th>الولاية</th><th class="num">الحالات</th><th class="num">السابق</th><th class="num">التغير</th><th class="num">وفيات</th></tr></thead><tbody>
<?php foreach ($loc as $r): ?><tr><td><?= e($r['name']) ?></td><td><?= e($r['state']) ?></td><td class="num"><strong><?= $r['cur'] ?></strong></td><td class="num"><?= $r['prev'] ?></td><td class="num"><?= chg($r['cur'], $r['prev']) ?></td><td class="num"><?= $r['deaths'] ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<?php layoutEnd();
