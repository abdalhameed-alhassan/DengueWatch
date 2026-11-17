<?php
// includes/ml.php — نموذج التنبؤ الأسبوعي بحمى الضنك، مكتوب بـ PHP الصرف (بلا بايثون) ليعمل على Vercel.
// هو ترجمة مطابقة لمنطق ml/forecast.py الأصلي (محفوظ مرجعاً في tools/ml_reference):
//   * انحدار ريدج (alpha = 1) لكل ولاية على log(1+y)، مع حدّ ثابت غير مُعاقَب (كما في scikit-learn).
//   * المتغيرات: أعداد الأسابيع الأربعة السابقة + متوسطها + نفس الأسبوع في السنة الماضية (إن توفر، وإلا متوسط آخر 8 أسابيع)
//     + موسمية السنة sin/cos لرقم الأسبوع.
//   * تنبؤ متتابع (recursive) لعدة أسابيع، وتقييم rolling-origin على آخر 12 أسبوعاً مقابل «التوقع الساذج».
//   * مستوى الخطورة: نسبة التوقع إلى أعلى أسبوع سُجّل للولاية (>= 70% مرتفع، >= 35% متوسط).

const ML_LAGS = [1, 2, 3, 4];
const ML_SEASON_LAG = 52;
const ML_HOLDOUT = 12;
const ML_ALPHA = 1.0;

/** رقم الأسبوع حسب ISO (مثل isocalendar()[1] في بايثون) */
function mlSeasonal($date) {
    $w = (int)date('W', strtotime($date));
    return [sin(2 * M_PI * $w / 52.18), cos(2 * M_PI * $w / 52.18)];
}

function mlBuildRow($hist, $t, $targetDate) {
    $row = [];
    foreach (ML_LAGS as $l) $row[] = $hist[$t - $l];
    $row[] = array_sum(array_map(fn($l) => $hist[$t - $l], ML_LAGS)) / count(ML_LAGS);
    if ($t - ML_SEASON_LAG >= 0) $row[] = $hist[$t - ML_SEASON_LAG];
    else { $sl = array_slice($hist, max(0, $t - 8), $t - max(0, $t - 8)); $row[] = array_sum($sl) / max(1, count($sl)); }
    [$s, $c] = mlSeasonal($targetDate);
    $row[] = $s; $row[] = $c;
    return $row;
}

/** حلّ جملة معادلات خطية A x = b بالحذف الغاوسي مع تدوير جزئي */
function mlSolve($A, $b) {
    $n = count($b);
    for ($i = 0; $i < $n; $i++) $A[$i][] = $b[$i];
    for ($c = 0; $c < $n; $c++) {
        $p = $c;
        for ($r = $c + 1; $r < $n; $r++) if (abs($A[$r][$c]) > abs($A[$p][$c])) $p = $r;
        if (abs($A[$p][$c]) < 1e-12) continue;
        if ($p !== $c) { $tmp = $A[$c]; $A[$c] = $A[$p]; $A[$p] = $tmp; }
        for ($r = $c + 1; $r < $n; $r++) {
            $f = $A[$r][$c] / $A[$c][$c];
            if ($f == 0) continue;
            for ($k = $c; $k <= $n; $k++) $A[$r][$k] -= $f * $A[$c][$k];
        }
    }
    $x = array_fill(0, $n, 0.0);
    for ($i = $n - 1; $i >= 0; $i--) {
        $s = $A[$i][$n];
        for ($k = $i + 1; $k < $n; $k++) $s -= $A[$i][$k] * $x[$k];
        $x[$i] = abs($A[$i][$i]) < 1e-12 ? 0.0 : $s / $A[$i][$i];
    }
    return $x;
}

/** يدرّب ريدج على السلسلة ويُرجع [النموذج، الانحراف المعياري للبواقي] */
function mlFit($yLog, $dates) {
    $X = []; $Y = [];
    for ($t = max(ML_LAGS); $t < count($yLog); $t++) { $X[] = mlBuildRow($yLog, $t, $dates[$t]); $Y[] = $yLog[$t]; }
    $n = count($X); $p = count($X[0]);
    $mx = array_fill(0, $p, 0.0); $my = array_sum($Y) / $n;
    foreach ($X as $r) foreach ($r as $j => $v) $mx[$j] += $v / $n;
    $A = array_fill(0, $p, array_fill(0, $p, 0.0)); $b = array_fill(0, $p, 0.0);
    foreach ($X as $i => $r) {
        $xc = []; foreach ($r as $j => $v) $xc[$j] = $v - $mx[$j];
        $yc = $Y[$i] - $my;
        for ($j = 0; $j < $p; $j++) { $b[$j] += $xc[$j] * $yc; for ($k = $j; $k < $p; $k++) $A[$j][$k] += $xc[$j] * $xc[$k]; }
    }
    for ($j = 0; $j < $p; $j++) { for ($k = 0; $k < $j; $k++) $A[$j][$k] = $A[$k][$j]; $A[$j][$j] += ML_ALPHA; }
    $w = mlSolve($A, $b);
    $icpt = $my; foreach ($w as $j => $wj) $icpt -= $mx[$j] * $wj;
    $model = ['w' => $w, 'b' => $icpt];
    $ss = 0.0; $m = 0.0; $res = [];
    foreach ($X as $i => $r) { $res[] = $Y[$i] - mlPredictRow($model, $r); }
    $mean = array_sum($res) / $n; foreach ($res as $v) $ss += ($v - $mean) ** 2;
    return [$model, $n > 2 ? sqrt($ss / $n) : 0.3];
}

function mlPredictRow($model, $row) {
    $s = $model['b']; foreach ($model['w'] as $j => $wj) $s += $wj * $row[$j];
    return $s;
}

/** تنبؤ متتابع لعدة أسابيع (قيم log1p) */
function mlForecastSeries($model, $yLog, $dates, $horizon) {
    $h = $yLog; $d = $dates; $out = [];
    for ($i = 0; $i < $horizon; $i++) {
        $t = count($h);
        $target = date('Y-m-d', strtotime('+7 days', strtotime(end($d))));
        $d[] = $target; $h[] = 0.0;
        $pred = max(0.0, mlPredictRow($model, mlBuildRow($h, $t, $target)));
        $h[$t] = $pred; $out[] = $pred;
    }
    return $out;
}

/** السلاسل الأسبوعية لكل ولاية (أسابيع كاملة فقط، الأصفار مملوءة): [state_id => ['name'=>, 'dates'=>[], 'y'=>[]]] */
function mlLoadSeries() {
    $db = getDB();
    $end = addDays(weekStart(), -7);                 // آخر أسبوع كامل
    $first = $db->query("SELECT MIN(report_date) FROM cases")->fetch_row()[0];
    if (!$first) throw new RuntimeException('لا توجد حالات مسجلة بعد.');
    $startW = weekStart($first);
    if ($startW < $first) $startW = addDays($startW, 7);   // تجاهل أول أسبوع ناقص
    $states = $db->query("SELECT id, name FROM states ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    $m = [];
    $r = $db->query("SELECT state_id, DATE_SUB(report_date, INTERVAL (DAYOFWEEK(report_date)-1) DAY) ws, COUNT(*) c
                     FROM cases WHERE report_date >= '$startW' AND report_date < '" . weekStart() . "' GROUP BY state_id, ws");
    while ($row = $r->fetch_assoc()) $m[$row['state_id']][$row['ws']] = (int)$row['c'];
    $out = [];
    foreach ($states as $s) {
        $dates = []; $y = [];
        for ($d = $startW; $d <= $end; $d = addDays($d, 7)) { $dates[] = $d; $y[] = $m[$s['id']][$d] ?? 0; }
        $out[(int)$s['id']] = ['name' => $s['name'], 'dates' => $dates, 'y' => $y];
    }
    return $out;
}

/** يُشغّل التدريب والتقييم والتنبؤ لكل الولايات ويُرجع ['meta'=>…, 'forecasts'=>[…]] */
function mlRun($series, $horizon) {
    $errM = 0.0; $errN = 0.0; $nEval = 0; $forecasts = []; $nWeeks = 0;
    foreach ($series as $sid => $S) {
        $y = $S['y']; $dates = $S['dates']; $n = count($y); $nWeeks = max($nWeeks, $n);
        if ($n < ML_HOLDOUT + max(ML_LAGS) + 20) throw new RuntimeException('البيانات غير كافية: نحتاج على الأقل ' . (ML_HOLDOUT + max(ML_LAGS) + 20) . ' أسبوعاً لكل ولاية.');
        $yLog = array_map(fn($v) => log(1 + $v), $y);
        for ($origin = $n - ML_HOLDOUT; $origin <= $n - $horizon; $origin++) {
            [$mdl] = mlFit(array_slice($yLog, 0, $origin), array_slice($dates, 0, $origin));
            $pred = mlForecastSeries($mdl, array_slice($yLog, 0, $origin), array_slice($dates, 0, $origin), $horizon);
            for ($k = 0; $k < $horizon; $k++) {
                $actual = $y[$origin + $k];
                $errM += abs(expm1($pred[$k]) - $actual);
                $errN += abs($y[$origin - 1] - $actual);
                $nEval++;
            }
        }
        [$model, $sigma] = mlFit($yLog, $dates);
        $pred = mlForecastSeries($model, $yLog, $dates, $horizon);
        $peak = max($y); $p35 = 0.35 * $peak; $p70 = 0.70 * $peak; $last = end($dates);
        foreach ($pred as $i => $p) {
            $k = $i + 1; $val = expm1($p);
            $lo = max(0.0, expm1($p - 1.28 * $sigma * sqrt($k)));
            $hi = expm1($p + 1.28 * $sigma * sqrt($k));
            $risk = $val >= $p70 ? 'high' : ($val >= $p35 ? 'medium' : 'low');
            $forecasts[] = ['state_id' => $sid, 'week_start' => addDays($last, 7 * $k), 'predicted' => round($val, 1),
                            'lower' => round($lo, 1), 'upper' => round($hi, 1), 'risk' => $risk];
        }
    }
    return ['meta' => [
        'model' => 'Ridge على log(1+y): 4 تأخيرات + متوسطها + نفس الأسبوع بالسنة السابقة + موسمية',
        'horizon_weeks' => $horizon, 'n_train_weeks' => $nWeeks, 'n_states' => count($series),
        'mae_model' => round($errM / $nEval, 2), 'mae_naive' => round($errN / $nEval, 2), 'n_eval_points' => $nEval,
    ], 'forecasts' => $forecasts];
}

/** يشغّل التنبؤ كاملاً ويحفظ النتيجة. يُرجع [ok, رسالة] */
function runForecast($userId) {
    $db = getDB();
    @set_time_limit(120);
    $horizon = max(1, min(8, (int)setting('forecast_horizon', 4)));
    try { $res = mlRun(mlLoadSeries(), $horizon); } catch (Throwable $e) { return [false, $e->getMessage()]; }
    $m = $res['meta'];
    $db->begin_transaction();
    try {
        $ins = $db->prepare("INSERT INTO forecast_runs (run_at, run_by, model_name, horizon_weeks, n_train_weeks, mae_model, mae_naive, note) VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?)");
        $note = 'تقييم على ' . $m['n_eval_points'] . ' نقطة اختبار (آخر 12 أسبوعاً، أفق ' . $m['horizon_weeks'] . ' أسابيع)';
        $ins->bind_param('isiidds', $userId, $m['model'], $m['horizon_weeks'], $m['n_train_weeks'], $m['mae_model'], $m['mae_naive'], $note);
        $ins->execute();
        $rid = $db->insert_id;
        $f = $db->prepare("INSERT INTO forecasts (run_id, state_id, week_start, predicted, lower_bound, upper_bound, risk) VALUES (?,?,?,?,?,?,?)");
        foreach ($res['forecasts'] as $x) {
            $f->bind_param('iisddds', $rid, $x['state_id'], $x['week_start'], $x['predicted'], $x['lower'], $x['upper'], $x['risk']);
            $f->execute();
        }
        // احتفظ بآخر 10 تشغيلات فقط
        $db->query("DELETE FROM forecast_runs WHERE id NOT IN (SELECT id FROM (SELECT id FROM forecast_runs ORDER BY id DESC LIMIT 10) t)");
        $db->commit();
    } catch (Throwable $e) { $db->rollback(); return [false, 'تعذر حفظ النتائج: ' . $e->getMessage()]; }
    return [true, 'اكتمل التنبؤ: متوسط الخطأ ' . $m['mae_model'] . ' حالة/ولاية/أسبوع مقابل ' . $m['mae_naive'] . ' للتوقع الساذج.'];
}
