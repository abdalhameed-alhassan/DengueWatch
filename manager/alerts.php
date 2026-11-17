<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
requireRole('manager');
$db = getDB(); $me = (int)$_SESSION['user_id'];
evaluateAlerts();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'settings') {
        $keys = ['alert_warning_cases' => [1, 1000], 'alert_outbreak_cases' => [1, 5000], 'alert_growth_ratio' => [1, 20], 'alert_growth_min_cases' => [1, 1000]];
        $ok = true; $new = [];
        foreach ($keys as $k => [$lo, $hi]) { $x = (float)($_POST[$k] ?? 0); if ($x < $lo || $x > $hi) { $ok = false; } $new[$k] = $x; }
        if ($ok && $new['alert_outbreak_cases'] < $new['alert_warning_cases']) $ok = false;
        if ($ok) {
            $st = $db->prepare("UPDATE settings SET `value` = ? WHERE `key` = ?");
            foreach ($new as $k => $x) { $s = (string)$x; $st->bind_param('ss', $s, $k); $st->execute(); }
            // أعد بناء تنبيهات الأسبوع الحالي بالحدود الجديدة
            $db->query("DELETE FROM alerts WHERE status = 'open' AND week_start = '" . weekStart() . "'");
            flash('success', 'تم حفظ الحدود وإعادة تقييم الأسبوع الحالي.');
        } else flash('danger', 'قيم غير صالحة (حد التفشي يجب ألا يقل عن حد التحذير).');
    } else {
        $id = (int)($_POST['alert_id'] ?? 0); $map = ['ack' => 'acknowledged', 'close' => 'closed', 'reopen' => 'open'];
        if (isset($map[$action])) {
            $s = $map[$action];
            $st = $db->prepare("UPDATE alerts SET status = ?, handled_by = ?, handled_at = NOW() WHERE id = ?"); $st->bind_param('sii', $s, $me, $id); $st->execute();
            flash('success', 'تم تحديث التنبيه.');
        }
    }
    header('Location: alerts.php?' . http_build_query(['show' => $_GET['show'] ?? ''])); exit;
}
$show = $_GET['show'] ?? 'open';
$wsql = $show === 'all' ? '1=1' : ($show === 'closed' ? "a.status IN ('acknowledged','closed')" : "a.status = 'open'");
$rows = $db->query("SELECT a.*, l.name AS locality, s.name AS state, u.full_name AS handler FROM alerts a JOIN localities l ON l.id = a.locality_id JOIN states s ON s.id = l.state_id
    LEFT JOIN users u ON u.id = a.handled_by WHERE $wsql ORDER BY a.week_start DESC, FIELD(a.level,'outbreak','warning'), a.cases_count DESC LIMIT 100")->fetch_all(MYSQLI_ASSOC);
$S = getSettings();
layoutStart('تنبيهات التفشي');
echo flash();
?>
<div class="alert alert-info">تُولَّد التنبيهات <strong>تلقائياً</strong> بقواعد بسيطة وشفافة (بدون تعلم آلة): <strong>تفشٍّ</strong> عندما يبلغ عدد حالات المحلية في الأسبوع حدّ التفشي، و<strong>تحذير</strong> عند حدّ التحذير أو عند التضاعف السريع عن الأسبوع السابق.</div>
<div class="grid-2" style="margin-bottom:20px">
<form method="post" class="card"><div class="card-header"><h3>⚙️ حدود التنبيه</h3></div><div class="card-body">
    <?= csrfField() ?><input type="hidden" name="action" value="settings">
    <div class="form-row">
        <div class="form-group"><label>حد التحذير (حالات/أسبوع)</label><input type="number" name="alert_warning_cases" value="<?= e($S['alert_warning_cases']) ?>" min="1"></div>
        <div class="form-group"><label>حد التفشي (حالات/أسبوع)</label><input type="number" name="alert_outbreak_cases" value="<?= e($S['alert_outbreak_cases']) ?>" min="1"></div>
    </div>
    <div class="form-row">
        <div class="form-group"><label>نسبة النمو للتحذير (×)</label><input type="number" step="0.1" name="alert_growth_ratio" value="<?= e($S['alert_growth_ratio']) ?>" min="1"></div>
        <div class="form-group"><label>أقل حالات لقاعدة النمو</label><input type="number" name="alert_growth_min_cases" value="<?= e($S['alert_growth_min_cases']) ?>" min="1"></div>
    </div>
    <button class="btn btn-primary" type="submit">حفظ الحدود</button></div></form>
<div class="card"><div class="card-header"><h3>عرض</h3></div><div class="card-body">
    <p style="margin-bottom:12px">
    <a class="btn <?= $show === 'open' ? 'btn-primary' : 'btn-outline' ?> btn-sm" href="?show=open">المفتوحة</a>
    <a class="btn <?= $show === 'closed' ? 'btn-primary' : 'btn-outline' ?> btn-sm" href="?show=closed">المعالَجة</a>
    <a class="btn <?= $show === 'all' ? 'btn-primary' : 'btn-outline' ?> btn-sm" href="?show=all">الكل</a></p>
    <p class="muted" style="font-size:.88rem">«تم الاطلاع» = اطّلعت الإدارة وتتابع، و«إغلاق» = انتهى التنبيه.</p></div></div>
</div>
<div class="card"><div class="card-body table-responsive">
<?php if (!$rows): ?><div class="empty">✅ لا توجد تنبيهات في هذا العرض.</div><?php else: ?>
<table class="data"><thead><tr><th>المستوى</th><th>المحلية</th><th>الولاية</th><th>الأسبوع</th><th class="num">الحالات</th><th class="num">السابق</th><th>السبب</th><th>الحالة</th><th>إجراء</th></tr></thead><tbody>
<?php foreach ($rows as $a): ?><tr class="row-<?= $a['level'] === 'outbreak' ? 'out' : 'warn' ?>">
<td><span class="badge badge-<?= $a['level'] === 'outbreak' ? 'out' : 'warn' ?>"><?= $a['level'] === 'outbreak' ? 'تفشٍّ' : 'تحذير' ?></span></td>
<td><a href="locality.php?id=<?= $a['locality_id'] ?>"><strong><?= e($a['locality']) ?></strong></a></td><td><?= e($a['state']) ?></td><td><?= e($a['week_start']) ?></td>
<td class="num"><strong><?= $a['cases_count'] ?></strong></td><td class="num"><?= $a['prev_count'] ?></td><td class="wrap"><?= e($a['reason']) ?></td>
<td><?= ['open' => 'مفتوح', 'acknowledged' => 'تم الاطلاع', 'closed' => 'مغلق'][$a['status']] ?><?php if ($a['handler']): ?><br><small class="muted"><?= e($a['handler']) ?></small><?php endif; ?></td>
<td><form method="post" class="inline-form"><?= csrfField() ?><input type="hidden" name="alert_id" value="<?= $a['id'] ?>">
<?php if ($a['status'] === 'open'): ?><button class="btn btn-outline btn-sm" name="action" value="ack">اطلعت</button> <button class="btn btn-outline btn-sm" name="action" value="close">إغلاق</button>
<?php elseif ($a['status'] === 'acknowledged'): ?><button class="btn btn-outline btn-sm" name="action" value="close">إغلاق</button>
<?php else: ?><button class="btn btn-outline btn-sm" name="action" value="reopen">إعادة فتح</button><?php endif; ?></form></td></tr><?php endforeach; ?>
</tbody></table><?php endif; ?></div></div>
<?php layoutEnd();
