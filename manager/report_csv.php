<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('manager');
$week = weekStart($_GET['week'] ?? addDays(weekStart(), -7));
$level = ($_GET['level'] ?? 'state') === 'locality' ? 'locality' : 'state';
$rows = weeklyReport($week, $level);
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="dengue_weekly_' . $level . '_' . $week . '.csv"');
echo "\xEF\xBB\xBF"; // BOM ليفتح Excel العربية صحيحاً
$o = fopen('php://output', 'w');
$head = $level === 'locality' ? ['المحلية', 'الولاية'] : ['الولاية'];
fputcsv($o, array_merge($head, ['بداية الأسبوع', 'نهاية الأسبوع', 'الحالات', 'الأسبوع السابق', 'مؤكدة', 'شديدة', 'وفيات']));
foreach ($rows as $r) {
    $first = $level === 'locality' ? [$r['name'], $r['state']] : [$r['name']];
    fputcsv($o, array_merge($first, [$week, addDays($week, 6), $r['cur'], $r['prev'], $r['confirmed'], $r['severe'], $r['deaths']]));
}
fclose($o);
