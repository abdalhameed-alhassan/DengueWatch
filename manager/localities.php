<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
requireRole('manager');
$db = getDB();
$stateId = (int)($_GET['state_id'] ?? 0);
$sort = $_GET['sort'] ?? 'week';
$sorts = ['name' => 'name', 'docs' => 'docs', 'day' => 'd', 'week' => 'w', 'month' => 'm', 'total' => 't', 'facs' => 'facs'];
$orderCol = $sorts[$sort] ?? 'w';
$q = trim($_GET['q'] ?? '');

$today = date('Y-m-d'); $ws = weekStart(); $ms = monthStart();
$where = '1=1'; $types = ''; $vals = [];
if ($stateId) { $where .= ' AND l.state_id = ?'; $types .= 'i'; $vals[] = $stateId; }
if ($q !== '') { $where .= ' AND l.name LIKE ?'; $types .= 's'; $vals[] = "%$q%"; }
$sql = "SELECT l.id, l.name, s.name AS state,
          (SELECT COUNT(*) FROM facilities f WHERE f.locality_id = l.id AND f.is_active = 1) AS facs,
          (SELECT COUNT(*) FROM users u JOIN facilities f ON f.id = u.facility_id WHERE f.locality_id = l.id AND u.role='doctor' AND u.status='active') AS docs,
          COALESCE(SUM(c.report_date = '$today'),0) AS d, COALESCE(SUM(c.report_date >= '$ws'),0) AS w,
          COALESCE(SUM(c.report_date >= '$ms'),0) AS m, COUNT(c.id) AS t
        FROM localities l JOIN states s ON s.id = l.state_id
        LEFT JOIN cases c ON c.locality_id = l.id AND c.report_date <= '$today'
        WHERE $where GROUP BY l.id, l.name, s.name
        ORDER BY " . ($orderCol === 'name' ? 'name ASC' : "$orderCol DESC, name ASC");
$st = $db->prepare($sql);
if ($vals) $st->bind_param($types, ...$vals);
$st->execute(); $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$states = $db->query("SELECT id, name FROM states ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$sum = ['facs' => 0, 'docs' => 0, 'd' => 0, 'w' => 0, 'm' => 0, 't' => 0];
foreach ($rows as $r) foreach ($sum as $k => $_) $sum[$k] += (int)$r[$k];

function sortLink($key, $label, $cur, $params) { $params['sort'] = $key; return '<a href="?' . e(http_build_query($params)) . '">' . e($label) . ($cur === $key ? ' ▾' : '') . '</a>'; }
$params = ['state_id' => $stateId, 'q' => $q];
layoutStart('المحليات: الأطباء والإصابات');
?>
<form class="filters" method="get">
    <div class="form-group"><label>الولاية</label><select name="state_id"><option value="0">كل الولايات</option>
        <?php foreach ($states as $s): ?><option value="<?= $s['id'] ?>" <?= $stateId == $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
    <div class="form-group"><label>بحث باسم المحلية</label><input name="q" value="<?= e($q) ?>"></div>
    <input type="hidden" name="sort" value="<?= e($sort) ?>">
    <button class="btn btn-primary" type="submit">تصفية</button>
</form>
<div class="card"><div class="card-header"><h3><?= count($rows) ?> محلية</h3><span class="muted" style="font-size:.85rem">اضغط على عنوان العمود للترتيب</span></div>
<div class="card-body table-responsive">
<table class="data"><thead><tr>
    <th><?= sortLink('name', 'المحلية', $sort, $params) ?></th><th>الولاية</th>
    <th class="num"><?= sortLink('facs', 'المنشآت', $sort, $params) ?></th><th class="num"><?= sortLink('docs', 'الأطباء', $sort, $params) ?></th>
    <th class="num"><?= sortLink('day', 'اليوم', $sort, $params) ?></th><th class="num"><?= sortLink('week', 'الأسبوع', $sort, $params) ?></th>
    <th class="num"><?= sortLink('month', 'الشهر', $sort, $params) ?></th><th class="num"><?= sortLink('total', 'الإجمالي', $sort, $params) ?></th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr>
    <td><a href="locality.php?id=<?= $r['id'] ?>"><strong><?= e($r['name']) ?></strong></a></td><td><?= e($r['state']) ?></td>
    <td class="num"><?= $r['facs'] ?></td><td class="num"><?= $r['docs'] ?></td>
    <td class="num"><?= fmt($r['d']) ?></td><td class="num"><strong><?= fmt($r['w']) ?></strong></td><td class="num"><?= fmt($r['m']) ?></td><td class="num"><?= fmt($r['t']) ?></td></tr><?php endforeach; ?>
<tr style="background:var(--navy-50);font-weight:700"><td colspan="2">المجموع</td><td class="num"><?= $sum['facs'] ?></td><td class="num"><?= $sum['docs'] ?></td><td class="num"><?= fmt($sum['d']) ?></td><td class="num"><?= fmt($sum['w']) ?></td><td class="num"><?= fmt($sum['m']) ?></td><td class="num"><?= fmt($sum['t']) ?></td></tr>
</tbody></table></div></div>
<?php layoutEnd();
