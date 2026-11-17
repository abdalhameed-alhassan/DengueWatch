<?php
// includes/functions.php — دوال مشتركة
date_default_timezone_set('Africa/Khartoum');
require_once __DIR__ . '/../config/database.php';

function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ---------- حماية CSRF ---------- */
function csrfToken() {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function csrfField() { return '<input type="hidden" name="csrf" value="' . e(csrfToken()) . '">'; }
function checkCsrf() {
    // الدخول التجريبي (زر وزارة الصحة) للعرض فقط: يُمنع تعديل البيانات، ويُسمح فقط بتشغيل التنبؤ
    if (!empty($_SESSION['demo']) && basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'forecast.php') {
        http_response_code(403);
        exit('هذا حساب عرض تجريبي للقراءة فقط؛ لا يمكنه تعديل البيانات.');
    }
    $tok = (string)($_SESSION['csrf'] ?? '');
    if ($tok === '' || !hash_equals($tok, (string)($_POST['csrf'] ?? ''))) {   // الرمز الفارغ لا يُقبل أبداً
        http_response_code(400);
        exit('طلب غير صالح (CSRF). أعد تحميل الصفحة وحاول مجدداً.');
    }
}

/* ---------- التواريخ: الأسبوع يبدأ يوم الأحد ---------- */
function weekStart($date = null) {
    $t = $date ? strtotime($date) : time();
    $dow = (int)date('w', $t);            // 0 = الأحد
    return date('Y-m-d', strtotime("-$dow days", $t));
}
function addDays($date, $n) { return date('Y-m-d', strtotime(($n >= 0 ? '+' : '') . $n . ' days', strtotime($date))); }
function monthStart($date = null) { return date('Y-m-01', $date ? strtotime($date) : time()); }

/* ---------- قوائم القيم بالعربية ---------- */
const FACILITY_TYPES = ['hospital' => 'مستشفى', 'health_center' => 'مركز صحي', 'health_unit' => 'وحدة صحية'];
const CLASSIFICATIONS = ['suspected' => 'مشتبهة', 'probable' => 'محتملة', 'confirmed' => 'مؤكدة'];
const TEST_TYPES = ['none' => 'بدون فحص', 'rapid' => 'فحص سريع', 'ns1' => 'NS1', 'igm' => 'IgM', 'pcr' => 'PCR'];
const SEVERITIES = ['mild' => 'خفيفة', 'warning_signs' => 'علامات إنذار', 'severe' => 'شديدة'];
const OUTCOMES = ['under_treatment' => 'تحت العلاج', 'recovered' => 'تعافى', 'died' => 'وفاة'];
const SEXES = ['male' => 'ذكر', 'female' => 'أنثى'];
const USER_STATUS = ['pending' => 'بانتظار الاعتماد', 'active' => 'نشط', 'suspended' => 'موقوف', 'rejected' => 'مرفوض'];
const RISK_LABELS = ['low' => 'منخفض', 'medium' => 'متوسط', 'high' => 'مرتفع'];

function label($map, $key) { return $map[$key] ?? $key; }
function fmt($n) { return number_format((float)$n); }

function normalizePhone($p) {
    $p = preg_replace('/[^\d]/', '', strtr((string)$p, ['٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']));
    if (strpos($p, '249') === 0 && strlen($p) === 12) $p = '0' . substr($p, 3);
    return $p;
}

/* ---------- الإعدادات ---------- */
function getSettings() {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        $r = getDB()->query("SELECT `key`, `value` FROM settings");
        while ($row = $r->fetch_assoc()) $cache[$row['key']] = $row['value'];
    }
    return $cache;
}
function setting($key, $default = null) { $s = getSettings(); return $s[$key] ?? $default; }

/* ---------- عدّادات الحالات ---------- */
// يُرجع [اليوم، الأسبوع الحالي، الشهر الحالي] لشرط WHERE إضافي (مع أنواع/قيم bind)
function periodCounts($where = '1=1', $types = '', $vals = []) {
    $today = date('Y-m-d'); $ws = weekStart(); $ms = monthStart();
    $sql = "SELECT
              SUM(report_date = ?) AS d,
              SUM(report_date >= ?) AS w,
              SUM(report_date >= ?) AS m,
              COUNT(*) AS t
            FROM cases WHERE report_date <= ? AND $where";
    $st = getDB()->prepare($sql);
    $st->bind_param('ssss' . $types, $today, $ws, $ms, $today, ...$vals);
    $st->execute();
    $r = $st->get_result()->fetch_assoc();
    return ['day' => (int)$r['d'], 'week' => (int)$r['w'], 'month' => (int)$r['m'], 'total' => (int)$r['t']];
}

/* ---------- تنبيهات التفشي ---------- */
/**
 * تقيّم المحليات للأسبوع الحالي وتنشئ/تحدّث التنبيهات.
 * القواعد (قابلة للتعديل من صفحة التنبيهات):
 *   تفشٍّ   : عدد حالات المحلية في الأسبوع >= alert_outbreak_cases
 *   تحذير  : العدد >= alert_warning_cases، أو تضاعف عن الأسبوع السابق (نسبة alert_growth_ratio) بشرط حد أدنى.
 * $localityId = null يقيّم كل المحليات.
 */
function evaluateAlerts($localityId = null) {
    $db = getDB();
    $ws = weekStart();
    $pws = addDays($ws, -7);
    $warnTh = (int)setting('alert_warning_cases', 10);
    $outTh  = (int)setting('alert_outbreak_cases', 25);
    $ratio  = (float)setting('alert_growth_ratio', 2);
    $minG   = (int)setting('alert_growth_min_cases', 5);
    $today = date('Y-m-d');

    $filter = $localityId ? ' AND locality_id = ' . (int)$localityId : '';
    $sql = "SELECT locality_id,
                   SUM(report_date >= '$ws') AS cur,
                   SUM(report_date >= '$pws' AND report_date < '$ws') AS prev
            FROM cases
            WHERE report_date >= '$pws' AND report_date <= '$today' $filter
            GROUP BY locality_id";
    $res = $db->query($sql);
    $up = $db->prepare("INSERT INTO alerts (locality_id, week_start, cases_count, prev_count, level, reason)
                        VALUES (?,?,?,?,?,?)
                        ON DUPLICATE KEY UPDATE cases_count = VALUES(cases_count), prev_count = VALUES(prev_count),
                           level = VALUES(level), reason = VALUES(reason)");
    while ($r = $res->fetch_assoc()) {
        $cur = (int)$r['cur']; $prev = (int)$r['prev'];
        $level = null; $reason = '';
        if ($cur >= $outTh) { $level = 'outbreak'; $reason = "بلغ عدد الحالات الأسبوعي $cur (حد التفشي $outTh)"; }
        elseif ($cur >= $warnTh) { $level = 'warning'; $reason = "بلغ عدد الحالات الأسبوعي $cur (حد التحذير $warnTh)"; }
        elseif ($cur >= $minG && $prev > 0 && $cur >= $ratio * $prev) { $level = 'warning'; $reason = "ارتفاع سريع: $cur حالات مقابل $prev في الأسبوع السابق"; }
        elseif ($cur >= $minG && $prev == 0) { $level = 'warning'; $reason = "ظهور $cur حالات بعد أسبوع بلا حالات"; }
        if ($level) {
            $lid = (int)$r['locality_id'];
            $up->bind_param('isiiss', $lid, $ws, $cur, $prev, $level, $reason);
            $up->execute();
        }
    }
}

function openAlertsCount() {
    return (int)getDB()->query("SELECT COUNT(*) c FROM alerts WHERE status='open' AND week_start >= '" . addDays(weekStart(), -7) . "'")->fetch_assoc()['c'];
}

/* ---------- سلسلة أسبوعية جاهزة للرسم/التنبؤ ---------- */
// سلسلة (week_start => count) للأسابيع الكاملة الأخيرة بدءاً من $fromWeek وحتى الأسبوع السابق للحالي
function weeklySeries($stateId = null, $weeks = 26) {
    $db = getDB();
    $end = addDays(weekStart(), -7);               // آخر أسبوع كامل
    $from = addDays($end, -7 * ($weeks - 1));
    $where = $stateId ? ' AND state_id = ' . (int)$stateId : '';
    $r = $db->query("SELECT DATE_SUB(report_date, INTERVAL (DAYOFWEEK(report_date)-1) DAY) AS ws, COUNT(*) c
                     FROM cases WHERE report_date >= '$from' AND report_date < '" . weekStart() . "' $where GROUP BY ws");
    $m = [];
    while ($row = $r->fetch_assoc()) $m[$row['ws']] = (int)$row['c'];
    $out = [];
    for ($d = $from; $d <= $end; $d = addDays($d, 7)) $out[$d] = $m[$d] ?? 0;
    return $out;
}

function pagerLinks($page, $pages, $baseUrl) {
    if ($pages <= 1) return '';
    $h = '<div class="pager">';
    $sep = strpos($baseUrl, '?') === false ? '?' : '&';
    for ($i = 1; $i <= $pages; $i++) {
        if ($i == $page) $h .= "<span class=\"cur\">$i</span>";
        elseif ($i == 1 || $i == $pages || abs($i - $page) <= 2) $h .= '<a href="' . e($baseUrl . $sep . 'page=' . $i) . "\">$i</a>";
        elseif (abs($i - $page) == 3) $h .= '<span>…</span>';
    }
    return $h . '</div>';
}

function flash($type = null, $msg = null) {
    if ($type) { $_SESSION['flash'] = [$type, $msg]; return ''; }
    if (!empty($_SESSION['flash'])) {
        [$t, $m] = $_SESSION['flash']; unset($_SESSION['flash']);
        return '<div class="alert alert-' . e($t) . '">' . e($m) . '</div>';
    }
    return '';
}

/* ---------- التقرير الأسبوعي (يُستخدم في الصفحة وفي تصدير CSV) ---------- */
function weeklyReport($week, $level = 'state') {
    $db = getDB();
    $prev = addDays($week, -7); $end = addDays($week, 6); $pend = addDays($week, -1);
    if ($level === 'locality') { $tbl = 'localities'; $key = 'locality_id'; $extra = ", (SELECT name FROM states s WHERE s.id = x.state_id) AS state"; }
    else { $tbl = 'states'; $key = 'state_id'; $extra = ", '' AS state"; }
    $sql = "SELECT x.id, x.name $extra,
        COALESCE(SUM(c.report_date BETWEEN '$week' AND '$end'),0) AS cur,
        COALESCE(SUM(c.report_date BETWEEN '$prev' AND '$pend'),0) AS prev,
        COALESCE(SUM(c.report_date BETWEEN '$week' AND '$end' AND c.classification='confirmed'),0) AS confirmed,
        COALESCE(SUM(c.report_date BETWEEN '$week' AND '$end' AND c.severity='severe'),0) AS severe,
        COALESCE(SUM(c.report_date BETWEEN '$week' AND '$end' AND c.outcome='died'),0) AS deaths
        FROM $tbl x LEFT JOIN cases c ON c.$key = x.id AND c.report_date BETWEEN '$prev' AND '$end'
        GROUP BY x.id, x.name ORDER BY cur DESC, x.name";
    return $db->query($sql)->fetch_all(MYSQLI_ASSOC);
}
