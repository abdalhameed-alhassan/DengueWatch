<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/charts.php';
require_once __DIR__ . '/../includes/ml.php';
requireRole('manager');
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    set_time_limit(180);
    [$ok, $msg] = runForecast((int)$_SESSION['user_id']);
    flash($ok ? 'success' : 'danger', $msg);
    header('Location: forecast.php'); exit;
}

$run = $db->query("SELECT r.*, u.full_name FROM forecast_runs r LEFT JOIN users u ON u.id = r.run_by ORDER BY r.id DESC LIMIT 1")->fetch_assoc();
layoutStart('التنبؤ بالإصابات (تعلم الآلة)');
echo flash();
?>

<form method="post" class="card"><div class="card-body" style="display:flex;gap:16px;align-items:center;flex-wrap:wrap">
    <?= csrfField() ?>
    <button class="btn btn-primary" type="submit" onclick="this.disabled=true;this.textContent='⏳ جارٍ تدريب النموذج...';this.form.submit();">🤖 تشغيل التنبؤ الآن</button>
    <span class="muted" style="font-size:.9rem">يُدرَّب النموذج على السجل الأسبوعي لكل ولاية (3 سنوات) ثم يتوقع الأسابيع القادمة. يستغرق بضع ثوانٍ.</span>
</div></form>

<?php if (!$run): ?>
    <div class="card"><div class="card-body empty">لم يُشغَّل التنبؤ بعد. اضغط «تشغيل التنبؤ الآن».</div></div>
<?php else:
    $rows = $db->query("SELECT f.*, s.name AS state FROM forecasts f JOIN states s ON s.id = f.state_id WHERE f.run_id = {$run['id']} ORDER BY f.state_id, f.week_start")->fetch_all(MYSQLI_ASSOC);
    $by = []; $weeksF = [];
    foreach ($rows as $r) { $by[$r['state_id']]['name'] = $r['state']; $by[$r['state_id']]['w'][$r['week_start']] = $r; $weeksF[$r['week_start']] = true; }
    $weeksF = array_keys($weeksF); sort($weeksF);
    // مجموع وطني
    $nat = [];
    foreach ($weeksF as $w) { $nat[$w] = [0, 0, 0]; foreach ($by as $s) { $x = $s['w'][$w]; $nat[$w][0] += $x['predicted']; $nat[$w][1] += $x['lower_bound']; $nat[$w][2] += $x['upper_bound']; } }
    $hist = weeklySeries(null, 26);
    // آخر أسبوع كامل لكل ولاية
    $last = addDays(weekStart(), -7); $lastBy = [];
    foreach ($db->query("SELECT state_id, COUNT(*) c FROM cases WHERE report_date >= '$last' AND report_date < '" . weekStart() . "' GROUP BY state_id") as $r) $lastBy[$r['state_id']] = (int)$r['c'];
    $rank = ['low' => 1, 'medium' => 2, 'high' => 3];
    $riskCount = ['low' => 0, 'medium' => 0, 'high' => 0];
    foreach ($by as $sid => &$s) { $mx = 'low'; foreach ($s['w'] as $x) if ($rank[$x['risk']] > $rank[$mx]) $mx = $x['risk']; $s['risk'] = $mx; $riskCount[$mx]++; $s['sum'] = array_sum(array_column($s['w'], 'predicted')); }
    unset($s);
    uasort($by, fn($a, $b) => $b['sum'] <=> $a['sum']);
    $impr = $run['mae_naive'] > 0 ? round((1 - $run['mae_model'] / $run['mae_naive']) * 100) : 0;
?>
<div class="stats-grid">
    <div class="stat-card"><div class="stat-value"><?= fmt(array_sum(array_column($nat, 0))) ?></div><div class="stat-label">المتوقع خلال <?= count($weeksF) ?> أسابيع</div><div class="kpi-note">على مستوى الدولة</div></div>
    <div class="stat-card red"><div class="stat-value"><?= $riskCount['high'] ?></div><div class="stat-label">ولايات خطورة مرتفعة</div></div>
    <div class="stat-card orange"><div class="stat-value"><?= $riskCount['medium'] ?></div><div class="stat-label">ولايات خطورة متوسطة</div></div>
    <div class="stat-card green"><div class="stat-value"><?= $riskCount['low'] ?></div><div class="stat-label">ولايات خطورة منخفضة</div></div>
</div>

<div class="card"><div class="card-header"><h3>📈 الإصابات الأسبوعية على مستوى الدولة: الفعلي والمتوقع</h3></div><div class="card-body">
    <div class="chart-wrap"><?= svgForecastChart($hist, $nat) ?></div>
    <div class="legend"><span><i style="background:#247c88"></i>الفعلي (26 أسبوعاً)</span><span><i style="background:#e07a3f"></i>المتوقع</span><span><i style="background:#e07a3f;opacity:.3"></i>نطاق تقريبي (80%)</span></div>
    <p class="muted" style="font-size:.84rem;margin-top:8px">أول أسبوع متوقع هو الأسبوع الجاري (غير المكتمل بعد)، يليه الأسابيع القادمة.</p>
</div></div>

<div class="card"><div class="card-header"><h3>🗺️ التوقع حسب الولاية (حالات/أسبوع)</h3></div><div class="card-body table-responsive">
<table class="data"><thead><tr><th>الولاية</th><th class="num">آخر أسبوع فعلي</th>
<?php foreach ($weeksF as $i => $w): ?><th class="num"><?= $i === 0 ? 'الجاري' : '+' . $i ?><br><small><?= e(date('m/d', strtotime($w))) ?></small></th><?php endforeach; ?><th>مستوى الخطورة</th></tr></thead><tbody>
<?php foreach ($by as $sid => $s): ?><tr><td><strong><?= e($s['name']) ?></strong></td><td class="num"><?= $lastBy[$sid] ?? 0 ?></td>
<?php foreach ($weeksF as $w): $x = $s['w'][$w]; ?><td class="num" title="النطاق: <?= round($x['lower_bound']) ?>–<?= round($x['upper_bound']) ?>"><?= round($x['predicted']) ?></td><?php endforeach; ?>
<td><span class="badge badge-<?= $s['risk'] ?>"><?= e(RISK_LABELS[$s['risk']]) ?></span></td></tr><?php endforeach; ?>
</tbody></table>
<p class="muted" style="font-size:.84rem;margin-top:10px">مستوى الخطورة = نسبة التوقع إلى أعلى أسبوع سُجّل تاريخياً لنفس الولاية: <strong>مرتفع</strong> إن بلغ 70% فأكثر، <strong>متوسط</strong> إن بلغ 35% فأكثر.</p>
</div></div>

<div class="grid-2">
<div class="card"><div class="card-header"><h3>🎯 جودة النموذج (على بيانات الاختبار)</h3></div><div class="card-body">
    <table class="data"><tbody>
    <tr><td>متوسط الخطأ المطلق للنموذج</td><td class="num"><strong><?= e($run['mae_model']) ?></strong> حالة</td></tr>
    <tr><td>متوسط الخطأ للتوقع الساذج (تكرار آخر أسبوع)</td><td class="num"><?= e($run['mae_naive']) ?> حالة</td></tr>
    <tr><td>التحسن عن التوقع الساذج</td><td class="num"><span class="badge badge-<?= $impr > 0 ? 'ok' : 'high' ?>"><?= $impr ?>%</span></td></tr>
    <tr><td>أسابيع التدريب لكل ولاية</td><td class="num"><?= e($run['n_train_weeks']) ?></td></tr>
    <tr><td>آخر تشغيل</td><td class="num"><?= e($run['run_at']) ?><?= $run['full_name'] ? ' — ' . e($run['full_name']) : '' ?></td></tr>
    </tbody></table>
    <p class="muted" style="font-size:.84rem;margin-top:8px"><?= e($run['note']) ?>. الخطأ يُقاس بعدد الحالات في الأسبوع الواحد للولاية الواحدة.</p>
</div></div>
<div class="card"><div class="card-header"><h3>ℹ️ كيف يعمل النموذج؟</h3></div><div class="card-body" style="line-height:2">
    <ol style="padding-inline-start:20px">
        <li>يجمع عدد الحالات الأسبوعي لكل ولاية من السجل.</li>
        <li>يتعلم العلاقة بين عدد أسبوع معين وما قبله (آخر 4 أسابيع) ونفس الأسبوع من السنة الماضية وموسم السنة.</li>
        <li>يتوقع الأسبوع التالي، ثم يعتمد عليه لتوقع الذي بعده.</li>
        <li>يقارن نفسه بتوقع بسيط جداً؛ فإن لم يتفوق عليه فلا قيمة له.</li>
    </ol>
    <p class="muted" style="font-size:.84rem">النموذج: <?= e($run['model_name']) ?></p>
</div></div>
</div>
<?php endif; layoutEnd();
