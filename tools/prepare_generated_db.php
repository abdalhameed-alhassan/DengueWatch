<?php
// أداة محلية (لا تُنشر): تُجهّز القاعدة المولّدة محلياً قبل تصديرها إلى database/database.sql
//  1) كل كلمات مرور المستخدمين تصير عشوائية غير معروفة (الدخول للمراجعة عبر زر الدخول التجريبي فقط)
//  2) توليد تنبيهات الأسبوع الحالي  3) تشغيل التنبؤ مرة ليظهر في صفحة التنبؤ فوراً
// الاستعمال: DB_NAME=dengue_gen DB_HOST=localhost php tools/prepare_generated_db.php
putenv('SESSION_STORE=files');
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/ml.php';
$db = getDB();
$h = password_hash(bin2hex(random_bytes(24)), PASSWORD_BCRYPT);
$db->query("UPDATE users SET password_hash = '" . $db->real_escape_string($h) . "'");
evaluateAlerts();
// تنبيهات الأسابيع الثلاثة الماضية بنفس قواعد evaluateAlerts (ليعرض التطبيق سجلاً واقعياً): الأحدث مفتوح، والأقدم معالَج
$mid0 = (int)$db->query("SELECT id FROM users WHERE role='manager' LIMIT 1")->fetch_row()[0];
$warnTh = (int)setting('alert_warning_cases', 10); $outTh = (int)setting('alert_outbreak_cases', 25);
$ratio = (float)setting('alert_growth_ratio', 2); $minG = (int)setting('alert_growth_min_cases', 5);
for ($k = 1; $k <= 3; $k++) {
    $ws = addDays(weekStart(), -7 * $k); $we = addDays($ws, 7); $pws = addDays($ws, -7);
    $res = $db->query("SELECT locality_id, SUM(report_date >= '$ws' AND report_date < '$we') cur, SUM(report_date >= '$pws' AND report_date < '$ws') prev
                       FROM cases WHERE report_date >= '$pws' AND report_date < '$we' GROUP BY locality_id");
    while ($r = $res->fetch_assoc()) {
        $cur = (int)$r['cur']; $prev = (int)$r['prev']; $level = null; $reason = '';
        if ($cur >= $outTh) { $level = 'outbreak'; $reason = "بلغ عدد الحالات الأسبوعي $cur (حد التفشي $outTh)"; }
        elseif ($cur >= $warnTh) { $level = 'warning'; $reason = "بلغ عدد الحالات الأسبوعي $cur (حد التحذير $warnTh)"; }
        elseif ($cur >= $minG && $prev > 0 && $cur >= $ratio * $prev) { $level = 'warning'; $reason = "ارتفاع سريع: $cur حالات مقابل $prev في الأسبوع السابق"; }
        if (!$level) continue;
        $status = $k === 1 ? 'open' : ($k === 2 ? 'acknowledged' : 'closed');
        $by = $k === 1 ? 'NULL' : $mid0; $at = $k === 1 ? 'NULL' : "'" . addDays($ws, 9) . " 10:00:00'";
        $st = $db->prepare("INSERT IGNORE INTO alerts (locality_id, week_start, cases_count, prev_count, level, reason, status, handled_by, handled_at) VALUES (?,?,?,?,?,?,'$status',$by,$at)");
        $lid = (int)$r['locality_id']; $st->bind_param('isiiss', $lid, $ws, $cur, $prev, $level, $reason); $st->execute();
    }
}
$mid = (int)$db->query("SELECT id FROM users WHERE role='manager' LIMIT 1")->fetch_row()[0];
[$ok, $msg] = runForecast($mid);
echo ($ok ? 'OK ' : 'FAIL ') . $msg . "\n";
echo 'alerts: ' . $db->query("SELECT COUNT(*) FROM alerts")->fetch_row()[0] . "\n";
