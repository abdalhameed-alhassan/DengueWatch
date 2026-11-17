<?php
// includes/charts.php — رسوم SVG بسيطة بلا مكتبات خارجية (تعمل بدون إنترنت)

function niceStep($max, $ticks = 4) {
    $raw = max(1, $max) / $ticks; $mag = pow(10, floor(log10($raw)));
    foreach ([1, 2, 2.5, 5, 10] as $m) if ($raw <= $m * $mag) return (int)max(1, round($m * $mag));
    return (int)(10 * $mag);
}

function svgBarChart(array $labels, array $values, $color = '#247c88', $h = 220) {
    $n = count($values); if ($n === 0) return '<div class="empty">لا توجد بيانات</div>';
    $w = 720; $padL = 42; $padB = 34; $padT = 14;
    $max = max(1, max($values));
    $step = niceStep($max); $max = $step * 4;
    $bw = ($w - $padL - 10) / $n;
    $s = "<svg viewBox=\"0 0 $w $h\" role=\"img\" aria-label=\"رسم أعمدة\">";
    for ($i = 0; $i <= 4; $i++) {
        $y = $padT + ($h - $padT - $padB) * (1 - $i / 4);
        $s .= "<line x1=\"$padL\" x2=\"" . ($w - 4) . "\" y1=\"$y\" y2=\"$y\" stroke=\"#dfe8e7\"/>";
        $s .= "<text x=\"" . ($padL - 9) . "\" y=\"" . ($y + 4) . "\" font-size=\"11\" fill=\"#5f6b78\" text-anchor=\"end\">" . ($step * $i) . "</text>";
    }
    foreach ($values as $i => $v) {
        $bh = ($h - $padT - $padB) * ($v / $max);
        $x = $padL + $i * $bw + $bw * 0.15; $y = $h - $padB - $bh; $bwi = $bw * 0.7;
        $s .= "<rect x=\"" . round($x, 1) . "\" y=\"" . round($y, 1) . "\" width=\"" . round($bwi, 1) . "\" height=\"" . round($bh, 1) . "\" rx=\"3\" fill=\"$color\"><title>" . e($labels[$i]) . ": $v</title></rect>";
        if ($n <= 16 || $i % 2 == 0) $s .= "<text x=\"" . round($x + $bwi / 2, 1) . "\" y=\"" . ($h - $padB + 15) . "\" font-size=\"10.5\" fill=\"#5f6b78\" text-anchor=\"middle\">" . e($labels[$i]) . "</text>";
        if ($n <= 16 && $v > 0) $s .= "<text x=\"" . round($x + $bwi / 2, 1) . "\" y=\"" . round($y - 4, 1) . "\" font-size=\"10.5\" fill=\"#0d4a52\" text-anchor=\"middle\" font-weight=\"700\">$v</text>";
    }
    return $s . '</svg>';
}

/**
 * خط للتاريخ (أزرق مزرق) + توقع (برتقالي متقطع) مع نطاق ثقة.
 * $hist = [label => value], $fc = [label => [pred, lo, hi]]
 */
function svgForecastChart(array $hist, array $fc, $h = 280) {
    $labels = array_merge(array_keys($hist), array_keys($fc));
    $n = count($labels); if ($n < 2) return '<div class="empty">لا توجد بيانات</div>';
    $w = 760; $padL = 48; $padR = 12; $padT = 16; $padB = 34;
    $vals = array_merge(array_values($hist), array_map(fn($x) => $x[2], array_values($fc)));
    $max = max(1, max($vals)); $step = niceStep($max); $max = $step * 4;
    $X = fn($i) => $padL + ($w - $padL - $padR) * ($i / ($n - 1));
    $Y = fn($v) => $padT + ($h - $padT - $padB) * (1 - $v / $max);
    $s = "<svg viewBox=\"0 0 $w $h\" role=\"img\" aria-label=\"التاريخ والتوقع\">";
    for ($i = 0; $i <= 4; $i++) {
        $y = $Y($step * $i);
        $s .= "<line x1=\"$padL\" x2=\"" . ($w - $padR) . "\" y1=\"$y\" y2=\"$y\" stroke=\"#dfe8e7\"/><text x=\"" . ($padL - 9) . "\" y=\"" . ($y + 4) . "\" font-size=\"11\" fill=\"#5f6b78\" text-anchor=\"end\">" . ($step * $i) . "</text>";
    }
    $hn = count($hist); $pts = [];
    $i = 0; foreach ($hist as $v) { $pts[] = round($X($i), 1) . ',' . round($Y($v), 1); $i++; }
    // نطاق الثقة
    if ($fc) {
        $upper = []; $lower = []; $j = $hn - 1;
        $lastV = end($hist); $upper[] = round($X($j), 1) . ',' . round($Y($lastV), 1); $lower[] = $upper[0];
        foreach ($fc as $f) { $j++; $upper[] = round($X($j), 1) . ',' . round($Y($f[2]), 1); $lower[] = round($X($j), 1) . ',' . round($Y($f[1]), 1); }
        $s .= '<polygon points="' . implode(' ', $upper) . ' ' . implode(' ', array_reverse($lower)) . '" fill="#e07a3f" opacity=".16"/>';
    }
    $s .= '<polyline points="' . implode(' ', $pts) . '" fill="none" stroke="#247c88" stroke-width="2.6" stroke-linejoin="round" stroke-linecap="round"/>';
    if ($fc) {
        $fp = [round($X($hn - 1), 1) . ',' . round($Y(end($hist)), 1)]; $j = $hn - 1;
        foreach ($fc as $f) { $j++; $fp[] = round($X($j), 1) . ',' . round($Y($f[0]), 1); }
        $s .= '<polyline points="' . implode(' ', $fp) . '" fill="none" stroke="#e07a3f" stroke-width="2.6" stroke-dasharray="7 5" stroke-linecap="round"/>';
        $j = $hn - 1; foreach ($fc as $lab => $f) { $j++; $s .= '<circle cx="' . round($X($j), 1) . '" cy="' . round($Y($f[0]), 1) . '" r="4" fill="#e07a3f"><title>' . e($lab) . ': ' . round($f[0]) . ' (' . round($f[1]) . '–' . round($f[2]) . ')</title></circle>'; }
        $x0 = round($X($hn - 1), 1);
        $s .= "<line x1=\"$x0\" x2=\"$x0\" y1=\"$padT\" y2=\"" . ($h - $padB) . "\" stroke=\"#8a9a9c\" stroke-dasharray=\"2 4\"/><text x=\"" . ($x0 - 8) . "\" y=\"" . ($padT + 10) . "\" font-size=\"11\" fill=\"#5f6b78\" text-anchor=\"end\">الفعلي</text><text x=\"" . ($x0 + 8) . "\" y=\"" . ($padT + 10) . "\" font-size=\"11\" fill=\"#a8501b\" text-anchor=\"start\">المتوقع</text>";
    }
    $every = max(1, (int)ceil($n / 9));
    foreach ($labels as $k => $lab) {
        if ($k % $every == 0 && $k <= $n - 1 - max(1, (int)floor($every / 2)) || $k == $n - 1) $s .= '<text x="' . round($X($k), 1) . '" y="' . ($h - $padB + 16) . '" font-size="10.5" fill="#5f6b78" text-anchor="middle">' . e(date('m/d', strtotime($lab))) . '</text>';
    }
    return $s . '</svg>';
}

function rankList(array $items, $unit = '') {
    // $items = [[name, value], ...] مرتبة تنازلياً
    if (!$items) return '<div class="empty">لا توجد بيانات</div>';
    $max = max(1, max(array_column($items, 1)));
    $h = '<ul class="rank-list">'; $i = 1;
    foreach ($items as [$name, $val]) {
        $h .= '<li><span class="rank-n">' . $i++ . '</span><span class="rank-name">' . e($name) . '</span><span class="rank-bar"><i style="width:' . round($val / $max * 100) . '%"></i></span><span class="rank-val">' . fmt($val) . e($unit) . '</span></li>';
    }
    return $h . '</ul>';
}
