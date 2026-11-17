<?php
/**
 * tools/seed_demo_local.php — (أداة محلية للمطوّرة، لا تُنشر) توليد قاعدة محلية ببيانات تجريبية موسمية حتى تاريخ اليوم.
 * كانت install.php في المشروع الأصلي. تُستعمل لبناء database/database.sql؛ تثبيت قديم: إنشاء قاعدة البيانات + الولايات والمحليات + بيانات تجريبية موسمية.
 * التشغيل: افتح  http://localhost/dengue/install.php  أو من الطرفية:  php install.php
 * بعد النجاح يُنشأ ملف database/.installed لمنع إعادة التشغيل بالخطأ (احذفه إن أردت إعادة التثبيت).
 * ملاحظة: كل الحالات المولَّدة تجريبية (is_demo = 1) وبنمط موسمي واقعي، وليست بيانات رسمية.
 */
require_once __DIR__ . '/../config/database.php';
date_default_timezone_set('Africa/Khartoum');
set_time_limit(300);
$cli = PHP_SAPI === 'cli';
if (!$cli) header('Content-Type: text/html; charset=utf-8');
function out($m) { global $cli; echo $cli ? $m . "\n" : '<p>' . htmlspecialchars($m) . '</p>'; if (!$cli) { @ob_flush(); flush(); } }
if (!$cli) echo '<!DOCTYPE html><html lang="ar" dir="rtl"><meta charset="utf-8"><title>تثبيت</title><body style="font-family:Tahoma;max-width:720px;margin:30px auto;line-height:1.9">';

$lock = __DIR__ . '/../database/.installed';
if (file_exists($lock) && !isset($_GET['force']) && !in_array('--force', $argv ?? [])) {
    out('المشروع مثبَّت مسبقاً. لإعادة التثبيت احذف الملف database/.installed (سيمسح كل البيانات).');
    exit;
}

mysqli_report(MYSQLI_REPORT_OFF);
$root = new mysqli((string)envOr('DB_HOST','localhost'), (string)envOr('DB_USER','root'), (string)envOr('DB_PASS',''), null, (int)envOr('DB_PORT',3306));
if ($root->connect_error) { out('تعذر الاتصال بـ MySQL: ' . $root->connect_error . ' — تأكد من تشغيل MySQL في XAMPP.'); exit(1); }
$root->set_charset('utf8mb4');
$root->query("CREATE DATABASE IF NOT EXISTS `" . envOr('DB_NAME','dengue_ml') . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$root->select_db((string)envOr('DB_NAME','dengue_ml'));
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function runSqlFile($db, $file) {
    $sql = file_get_contents($file);
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    $db->multi_query($sql);
    do { if ($r = $db->store_result()) $r->free(); } while ($db->more_results() && $db->next_result());
}
out('1) إنشاء الجداول...');
runSqlFile($root, __DIR__ . '/../database/schema.sql');
out('2) تحميل الولايات والمحليات...');
runSqlFile($root, __DIR__ . '/../database/locations.sql');
$db = $root;

mt_srand(20261007); // نتائج ثابتة قابلة للتكرار

/* ---------- المنشآت ---------- */
out('3) إنشاء المنشآت الصحية والأطباء...');
$prefix = ['hospital' => 'مستشفى', 'health_center' => 'مركز', 'health_unit' => 'وحدة'];
$names1 = ['الأمل','السلام','الشفاء','النور','الرحمة','الفاتح','المدينة','الوحدة','الشرق','الأمان','البركة','التضامن','الصداقة','الأطباء','العروبة','الجنينة','السوق','المركزي','التيجاني','الوفاق'];
$firstM = ['محمد','أحمد','عمر','علي','حسن','إبراهيم','خالد','يوسف','عثمان','طارق','مصطفى','عبدالله','صلاح','بكري','أمين','ياسر','هاشم','مجدي','عادل','مأمون'];
$firstF = ['فاطمة','آمنة','سارة','مريم','هدى','أسماء','رشا','نهى','إيمان','سلمى','تسنيم','ابتسام','هنادي','وفاء','رباب'];
$mid  = ['الطيب','عبدالرحمن','الأمين','حامد','إدريس','الصادق','بشير','موسى','آدم','النور','سليمان','جعفر','عبدالله','الحسن','كمال'];
$last = ['أحمد','محمد','عثمان','إسماعيل','البشير','الزين','دفع الله','عبدالقادر','حسين','فضل','التوم','مالك','الجاك','عيسى','سالم'];

$loc = $db->query("SELECT l.id, l.name, l.state_id FROM localities l ORDER BY l.id")->fetch_all(MYSQLI_ASSOC);
$passDoctor  = password_hash('doctor123', PASSWORD_DEFAULT);
$passManager = password_hash('manager123', PASSWORD_DEFAULT);

$insF = $db->prepare("INSERT INTO facilities (locality_id, name, type) VALUES (?,?,?)");
$insU = $db->prepare("INSERT INTO users (full_name, phone, password_hash, role, facility_id, license_no, status, approved_at, last_login) VALUES (?,?,?,?,?,?,?,?,?)");
$facilities = []; // id => [locality_id, state_id]
$doctorsByFac = [];
$usedPhones = [];
$phoneSeq = 100000;
function newPhone() { global $phoneSeq; $phoneSeq += mt_rand(1, 9); return '09' . ($phoneSeq % 2 ? '1' : '2') . str_pad((string)$phoneSeq, 7, '0', STR_PAD_LEFT); }
$localityFacs = [];
foreach ($loc as $L) {
    $nf = mt_rand(1, 100) <= 40 ? 2 : 1;
    if (in_array((int)$L['state_id'], [1], true)) $nf = 3; // الخرطوم: منشآت أكثر
    for ($i = 0; $i < $nf; $i++) {
        $type = $i === 0 ? (mt_rand(1, 100) <= 35 ? 'hospital' : 'health_center') : 'health_unit';
        $fname = $prefix[$type] . ' ' . $names1[array_rand($names1)] . ' - ' . $L['name'];
        $lid = (int)$L['id'];
        $insF->bind_param('iss', $lid, $fname, $type); $insF->execute();
        $fid = $db->insert_id;
        $facilities[$fid] = [$lid, (int)$L['state_id']];
        $localityFacs[$lid][] = $fid;
        $nd = mt_rand(1, 3);
        for ($d = 0; $d < $nd; $d++) {
            $female = mt_rand(1, 100) <= 38;
            $full = ($female ? $firstF : $firstM)[array_rand($female ? $firstF : $firstM)] . ' ' . $mid[array_rand($mid)] . ' ' . $last[array_rand($last)];
            $phone = newPhone();
            $r = mt_rand(1, 100);
            $status = $r <= 88 ? 'active' : 'suspended';
            $role = 'doctor'; $lic = 'SMC-' . mt_rand(10000, 99999);
            $apr = date('Y-m-d H:i:s', strtotime('-' . mt_rand(60, 900) . ' days'));
            $ll = $status === 'active' ? date('Y-m-d H:i:s', strtotime('-' . mt_rand(0, 12) . ' days')) : null;
            $insU->bind_param('ssssissss', $full, $phone, $passDoctor, $role, $fid, $lic, $status, $apr, $ll);
            $insU->execute();
            $uid = $db->insert_id;
            if ($status === 'active') $doctorsByFac[$fid][] = $uid;
        }
    }
}
// تأكد أن لكل منشأة طبيباً نشطاً واحداً على الأقل لاستقبال الحالات
foreach ($facilities as $fid => $_) {
    if (empty($doctorsByFac[$fid])) {
        $full = $firstM[array_rand($firstM)] . ' ' . $mid[array_rand($mid)] . ' ' . $last[array_rand($last)];
        $phone = newPhone(); $role = 'doctor'; $lic = 'SMC-' . mt_rand(10000, 99999); $status = 'active';
        $apr = date('Y-m-d H:i:s', strtotime('-200 days')); $ll = date('Y-m-d H:i:s', strtotime('-3 days'));
        $insU->bind_param('ssssissss', $full, $phone, $passDoctor, $role, $fid, $lic, $status, $apr, $ll);
        $insU->execute();
        $doctorsByFac[$fid][] = $db->insert_id;
    }
}
// حسابات العرض الثابتة
$khFac = $localityFacs[1][0];
$demo = [
    ['د. فاطمة الطيب عثمان', '0922222222', $passDoctor, 'doctor', $khFac, 'SMC-12345', 'active'],
    ['أ. د. عبدالحميد الحسن', '0911111111', $passManager, 'manager', null, null, 'active'],
];
foreach ($demo as [$full, $phone, $hash, $role, $fid, $lic, $status]) {
    $apr = date('Y-m-d H:i:s', strtotime('-30 days')); $ll = date('Y-m-d H:i:s');
    $insU->bind_param('ssssissss', $full, $phone, $hash, $role, $fid, $lic, $status, $apr, $ll);
    $insU->execute();
    if ($role === 'doctor') $doctorsByFac[$khFac][] = $db->insert_id;
}
// طلبات انضمام معلّقة (لعرض الاعتماد)
for ($i = 0; $i < 5; $i++) {
    $L = $loc[array_rand($loc)]; $fid = $localityFacs[(int)$L['id']][0];
    $full = $firstM[array_rand($firstM)] . ' ' . $mid[array_rand($mid)] . ' ' . $last[array_rand($last)];
    $phone = newPhone(); $role = 'doctor'; $lic = null; $status = 'pending'; $apr = null; $ll = null;
    $insU->bind_param('ssssissss', $full, $phone, $passDoctor, $role, $fid, $lic, $status, $apr, $ll);
    $insU->execute();
}
out('   المنشآت: ' . count($facilities) . ' — الأطباء: ' . $db->query("SELECT COUNT(*) c FROM users WHERE role='doctor'")->fetch_assoc()['c']);

/* ---------- الحالات الموسمية التجريبية ---------- */
out('4) توليد حالات تجريبية بنمط موسمي (3 سنوات)...');
// الذروة الأسبوعية التقريبية لكل ولاية (حسب رقم الولاية في الجدول)
$peak = [1=>120, 2=>45, 3=>22, 4=>12, 5=>26, 6=>20, 7=>60, 8=>52, 9=>34, 10=>18, 11=>9, 12=>8, 13=>7, 14=>8, 15=>6, 16=>14, 17=>8, 18=>10];
// وزن كل محلية داخل ولايتها
$locW = []; $stateLocs = [];
foreach ($loc as $L) { $w = mt_rand(30, 160) / 100; $locW[(int)$L['id']] = $w; $stateLocs[(int)$L['state_id']][] = (int)$L['id']; }
$stateWsum = [];
foreach ($stateLocs as $s => $ls) $stateWsum[$s] = array_sum(array_map(fn($l) => $locW[$l], $ls));

$today = strtotime(date('Y-m-d'));
$startWeek = strtotime('-' . (int)date('w', $today) . ' days', $today);   // أحد هذا الأسبوع
$weeks = 156;
$firstWeek = strtotime('-' . ($weeks - 1) . ' weeks', $startWeek);
$yearMult = [];
$cols = '(case_code, facility_id, doctor_id, locality_id, state_id, report_date, onset_date, age_years, sex, classification, test_type, severity, hospitalized, outcome, is_demo)';
$batch = []; $total = 0; $seq = 1;
$flush = function () use (&$batch, $db, $cols) {
    if (!$batch) return;
    $db->query("INSERT INTO cases $cols VALUES " . implode(',', $batch));
    $batch = [];
};
function poisson($lam) { if ($lam <= 0) return 0; if ($lam > 60) return max(0, (int)round($lam + sqrt($lam) * (mt_rand(-1000, 1000) / 1000) * 1.2)); $L = exp(-$lam); $k = 0; $p = 1.0; do { $k++; $p *= mt_rand(1, 1000000) / 1000000; } while ($p > $L); return $k - 1; }
function lognorm($sd) { $u = (mt_rand(1, 100000) / 100000 + mt_rand(1, 100000) / 100000 + mt_rand(1, 100000) / 100000 - 1.5) / 0.5; return exp($u * $sd); }

$stateNoise = array_fill_keys(array_keys($peak), 1.0);
for ($w = 0; $w < $weeks; $w++) {
    $ws = strtotime("+$w weeks", $firstWeek);
    $woy = (int)date('W', $ws); $yr = (int)date('o', $ws);
    if (!isset($yearMult[$yr])) $yearMult[$yr] = mt_rand(55, 150) / 100;
    // ذروة الموسم حوالي الأسبوع 42 (أواخر أكتوبر) بعرض 6 أسابيع
    $d = $woy - 42; if ($d > 26) $d -= 52; if ($d < -26) $d += 52;
    $season = 0.04 + 0.96 * exp(-($d * $d) / (2 * 6.5 * 6.5));
    $elapsed = ($w === $weeks - 1) ? (int)(($today - $ws) / 86400) + 1 : 7;   // الأسبوع الحالي جزئي
    foreach ($peak as $sid => $pk) {
        // انجراف ضوضاء متصل بين الأسابيع (ذاكرة قصيرة)
        $stateNoise[$sid] = 0.55 * $stateNoise[$sid] + 0.45 * lognorm(0.28);
        $lam = $pk * $season * $yearMult[$yr] * $stateNoise[$sid] * ($elapsed / 7);
        $n = poisson($lam);
        for ($i = 0; $i < $n; $i++) {
            // اختيار محلية موزونة
            $x = mt_rand() / mt_getrandmax() * $stateWsum[$sid]; $acc = 0; $lid = $stateLocs[$sid][0];
            foreach ($stateLocs[$sid] as $cand) { $acc += $locW[$cand]; if ($x <= $acc) { $lid = $cand; break; } }
            $fid = $localityFacs[$lid][array_rand($localityFacs[$lid])];
            $doc = $doctorsByFac[$fid][array_rand($doctorsByFac[$fid])];
            $dayOff = mt_rand(0, $elapsed - 1); $rt = strtotime("+$dayOff days", $ws);
            $report = date('Y-m-d', $rt); $onset = date('Y-m-d', $rt - mt_rand(1, 6) * 86400);
            $age = (int)min(90, max(1, round(abs(14 + 22 * (mt_rand(-1000, 1000) / 1000) + mt_rand(0, 25)))));
            $sex = mt_rand(1, 100) <= 52 ? 'male' : 'female';
            $r = mt_rand(1, 100);
            $class = $r <= 48 ? 'suspected' : ($r <= 70 ? 'probable' : 'confirmed');
            $test = $class === 'confirmed' ? ['ns1', 'pcr', 'igm', 'rapid'][mt_rand(0, 3)] : ($class === 'probable' ? 'rapid' : (mt_rand(1, 100) <= 25 ? 'rapid' : 'none'));
            $r = mt_rand(1, 1000);
            $sev = $r <= 840 ? 'mild' : ($r <= 960 ? 'warning_signs' : 'severe');
            $hosp = $sev === 'mild' ? (mt_rand(1, 100) <= 12 ? 1 : 0) : 1;
            $rt2 = mt_rand(1, 1000);
            $age_days = ($today - $rt) / 86400;
            if ($sev === 'severe' && $rt2 <= 120) $outc = 'died';
            elseif ($age_days >= 8 || $rt2 <= 800 && $age_days >= 4) $outc = 'recovered';
            else $outc = 'under_treatment';
            $code = 'DF-' . date('Ymd', $rt) . '-' . str_pad((string)$seq++, 6, '0', STR_PAD_LEFT);
            $batch[] = "('$code',$fid,$doc,$lid,$sid,'$report','$onset',$age,'$sex','$class','$test','$sev',$hosp,'$outc',1)";
            $total++;
            if (count($batch) >= 800) $flush();
        }
    }
}
$flush();
out("   عدد الحالات التجريبية: " . number_format($total));
@file_put_contents($lock, date('c'));
out('5) تم التثبيت بنجاح.');
out('حساب المدير: 0911111111 / manager123');
out('حساب طبيب وحدة: 0922222222 / doctor123');
out('ثم افتح: ' . BASE_URL . '/auth/login.php');
