<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
requireRole('doctor');
$db = getDB();
$uid = (int)$_SESSION['user_id']; $fid = (int)$_SESSION['facility_id'];

// تحديث مآل حالة (الطبيب صاحب الحالة فقط)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $cid = (int)($_POST['case_id'] ?? 0); $out = $_POST['outcome'] ?? '';
    if (isset(OUTCOMES[$out])) {
        $st = $db->prepare("UPDATE cases SET outcome = ? WHERE id = ? AND doctor_id = ?");
        $st->bind_param('sii', $out, $cid, $uid); $st->execute();
        flash('success', $st->affected_rows ? 'تم تحديث المآل.' : 'لا يمكن تعديل هذه الحالة.');
    }
    header('Location: my_cases.php' . (isset($_GET['scope']) ? '?scope=' . urlencode($_GET['scope']) : '')); exit;
}

$scope = ($_GET['scope'] ?? 'mine') === 'facility' ? 'facility' : 'mine';
$from = $_GET['from'] ?? addDays(date('Y-m-d'), -30);
$to   = $_GET['to'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = addDays(date('Y-m-d'), -30);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $to = date('Y-m-d');
$page = max(1, (int)($_GET['page'] ?? 1)); $per = 20; $off = ($page - 1) * $per;

$col = $scope === 'mine' ? 'c.doctor_id' : 'c.facility_id'; $val = $scope === 'mine' ? $uid : $fid;
$cnt = $db->prepare("SELECT COUNT(*) FROM cases c WHERE $col = ? AND report_date BETWEEN ? AND ?");
$cnt->bind_param('iss', $val, $from, $to); $cnt->execute(); $total = (int)$cnt->get_result()->fetch_row()[0];
$st = $db->prepare("SELECT c.*, u.full_name AS doctor FROM cases c JOIN users u ON u.id = c.doctor_id WHERE $col = ? AND report_date BETWEEN ? AND ? ORDER BY c.report_date DESC, c.id DESC LIMIT $per OFFSET $off");
$st->bind_param('iss', $val, $from, $to); $st->execute(); $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);

layoutStart('حالات وحدتي');
echo flash();
?>
<form class="filters" method="get">
    <div class="form-group"><label>العرض</label><select name="scope"><option value="mine" <?= $scope === 'mine' ? 'selected' : '' ?>>حالاتي أنا</option><option value="facility" <?= $scope === 'facility' ? 'selected' : '' ?>>كل حالات المنشأة</option></select></div>
    <div class="form-group"><label>من</label><input type="date" name="from" value="<?= e($from) ?>"></div>
    <div class="form-group"><label>إلى</label><input type="date" name="to" value="<?= e($to) ?>"></div>
    <button class="btn btn-primary" type="submit">تصفية</button>
</form>
<div class="card"><div class="card-header"><h3><?= fmt($total) ?> حالة</h3></div><div class="card-body table-responsive">
<?php if (!$rows): ?><div class="empty">لا توجد حالات في هذه الفترة.</div><?php else: ?>
<table class="data"><thead><tr><th>الرمز</th><th>التاريخ</th><th>العمر</th><th>الجنس</th><th>التصنيف</th><th>الفحص</th><th>الشدة</th><th>تنويم</th><th>المآل</th><?php if ($scope === 'facility'): ?><th>الطبيب</th><?php endif; ?></tr></thead><tbody>
<?php foreach ($rows as $c): ?><tr>
    <td><?= e($c['case_code']) ?></td><td><?= e($c['report_date']) ?></td><td class="num"><?= $c['age_years'] ?></td><td><?= e(label(SEXES, $c['sex'])) ?></td>
    <td><?= e(label(CLASSIFICATIONS, $c['classification'])) ?></td><td><?= e(label(TEST_TYPES, $c['test_type'])) ?></td>
    <td class="<?= $c['severity'] === 'severe' ? 'sev-severe' : '' ?>"><?= e(label(SEVERITIES, $c['severity'])) ?></td><td><?= $c['hospitalized'] ? 'نعم' : 'لا' ?></td>
    <td><?php if ((int)$c['doctor_id'] === $uid): ?>
        <form method="post" class="inline-form"><?= csrfField() ?><input type="hidden" name="case_id" value="<?= $c['id'] ?>">
        <select name="outcome" onchange="this.form.submit()" style="padding:4px 6px;border-radius:8px;border:1px solid var(--border)">
            <?php foreach (OUTCOMES as $k => $l): ?><option value="<?= $k ?>" <?= $c['outcome'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></form>
        <?php else: echo e(label(OUTCOMES, $c['outcome'])); endif; ?></td>
    <?php if ($scope === 'facility'): ?><td><?= e($c['doctor']) ?></td><?php endif; ?>
</tr><?php endforeach; ?></tbody></table>
<?= pagerLinks($page, (int)ceil($total / $per), 'my_cases.php?' . http_build_query(['scope' => $scope, 'from' => $from, 'to' => $to])) ?>
<?php endif; ?></div></div>
<?php layoutEnd();
