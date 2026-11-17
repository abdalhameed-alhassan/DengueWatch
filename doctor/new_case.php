<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';
requireRole('doctor');
$db = getDB();
$uid = (int)$_SESSION['user_id']; $fid = (int)$_SESSION['facility_id'];
$q = $db->prepare("SELECT f.locality_id, l.state_id FROM facilities f JOIN localities l ON l.id = f.locality_id WHERE f.id = ?");
$q->bind_param('i', $fid); $q->execute(); $loc = $q->get_result()->fetch_assoc();

$errors = [];
$v = ['onset_date' => date('Y-m-d'), 'age_years' => '', 'sex' => '', 'classification' => 'suspected', 'test_type' => 'none',
      'severity' => 'mild', 'hospitalized' => '0', 'outcome' => 'under_treatment', 'notes' => ''];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    foreach ($v as $k => $_) $v[$k] = trim($_POST[$k] ?? '');
    $today = date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v['onset_date']) || $v['onset_date'] > $today) $errors[] = 'تاريخ بدء الأعراض غير صالح (لا يمكن أن يكون في المستقبل).';
    elseif ($v['onset_date'] < addDays($today, -30)) $errors[] = 'تاريخ بدء الأعراض أقدم من 30 يوماً؛ تحقق من التاريخ.';
    $age = (int)$v['age_years'];
    if ($v['age_years'] === '' || $age < 0 || $age > 110) $errors[] = 'العمر يجب أن يكون بين 0 و110 سنة.';
    if (!isset(SEXES[$v['sex']])) $errors[] = 'اختر الجنس.';
    if (!isset(CLASSIFICATIONS[$v['classification']])) $errors[] = 'تصنيف غير صالح.';
    if (!isset(TEST_TYPES[$v['test_type']])) $errors[] = 'نوع الفحص غير صالح.';
    if (!isset(SEVERITIES[$v['severity']])) $errors[] = 'الشدة غير صالحة.';
    if (!isset(OUTCOMES[$v['outcome']])) $errors[] = 'المآل غير صالح.';
    if ($v['classification'] === 'confirmed' && $v['test_type'] === 'none') $errors[] = 'الحالة المؤكدة تتطلب تحديد نوع الفحص المخبري.';
    if (mb_strlen($v['notes']) > 300) $errors[] = 'الملاحظات أطول من 300 حرف.';
    if (!$errors) {
        $db->begin_transaction();
        try {
            $report = date('Y-m-d');
            $hosp = $v['hospitalized'] === '1' ? 1 : 0;
            if ($v['severity'] === 'severe') $hosp = 1;
            $notes = $v['notes'] !== '' ? $v['notes'] : null;
            $code = 'DF-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
            $ins = $db->prepare("INSERT INTO cases (case_code, facility_id, doctor_id, locality_id, state_id, report_date, onset_date, age_years, sex, classification, test_type, severity, hospitalized, outcome, notes)
                                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $lid = (int)$loc['locality_id']; $sid = (int)$loc['state_id'];
            $ins->bind_param('siiiississssiss', $code, $fid, $uid, $lid, $sid, $report, $v['onset_date'], $age, $v['sex'], $v['classification'], $v['test_type'], $v['severity'], $hosp, $v['outcome'], $notes);
            $ins->execute();
            $db->commit();
        } catch (Throwable $ex) { $db->rollback(); throw $ex; }
        evaluateAlerts($lid);
        flash('success', "تم تسجيل الحالة برمز $code");
        header('Location: dashboard.php'); exit;
    }
}
function sel($map, $name, $cur) { $h = "<select name=\"$name\">"; foreach ($map as $k => $l) $h .= '<option value="' . e($k) . '"' . ($cur === (string)$k ? ' selected' : '') . '>' . e($l) . '</option>'; return $h . '</select>'; }
layoutStart('تسجيل حالة جديدة');
?>
<div class="alert alert-info">🔒 لحماية الخصوصية لا يطلب النظام اسم المريض؛ يُنشأ <strong>رمز حالة</strong> تلقائياً. تُسجَّل الحالة باسم منشأتك ومحليتك تلقائياً.</div>
<?php foreach ($errors as $er): ?><div class="alert alert-danger"><?= e($er) ?></div><?php endforeach; ?>
<form method="post" class="card" autocomplete="off"><div class="card-body">
    <?= csrfField() ?>
    <div class="form-row">
        <div class="form-group"><label>تاريخ بدء الأعراض</label><input type="date" name="onset_date" value="<?= e($v['onset_date']) ?>" max="<?= date('Y-m-d') ?>" required></div>
        <div class="form-group"><label>العمر (بالسنوات)</label><input type="number" name="age_years" min="0" max="110" value="<?= e($v['age_years']) ?>" required></div>
        <div class="form-group"><label>الجنس</label><select name="sex" required><option value="">اختر</option>
            <?php foreach (SEXES as $k => $l): ?><option value="<?= $k ?>" <?= $v['sex'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    </div>
    <div class="form-row">
        <div class="form-group"><label>تصنيف الحالة</label><?= sel(CLASSIFICATIONS, 'classification', $v['classification']) ?></div>
        <div class="form-group"><label>نوع الفحص المخبري</label><?= sel(TEST_TYPES, 'test_type', $v['test_type']) ?></div>
        <div class="form-group"><label>شدة الحالة</label><?= sel(SEVERITIES, 'severity', $v['severity']) ?></div>
    </div>
    <div class="form-row">
        <div class="form-group"><label>المآل الحالي</label><?= sel(OUTCOMES, 'outcome', $v['outcome']) ?></div>
        <div class="form-group"><label>التنويم</label><select name="hospitalized"><option value="0" <?= $v['hospitalized'] === '0' ? 'selected' : '' ?>>غير منوَّم</option><option value="1" <?= $v['hospitalized'] === '1' ? 'selected' : '' ?>>منوَّم</option></select></div>
    </div>
    <div class="form-group"><label>ملاحظات سريرية (اختياري — بدون أسماء)</label><textarea name="notes" maxlength="300"><?= e($v['notes']) ?></textarea></div>
    <button class="btn btn-primary" type="submit">💾 حفظ الحالة</button>
    <a class="btn btn-outline" href="dashboard.php">إلغاء</a>
</div></form>
<?php layoutEnd();
