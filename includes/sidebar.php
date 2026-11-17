<?php
// includes/sidebar.php — القائمة الجانبية حسب الدور
$__role   = $_SESSION['role'] ?? '';
$__folder = basename(dirname($_SERVER['PHP_SELF']));
$__file   = basename($_SERVER['PHP_SELF']);
$__alias  = ['locality.php' => 'localities.php'];
$__active = $__alias[$__file] ?? $__file;

$__navs = [
    'doctor' => [
        ['doctor', 'dashboard.php', '🏠', 'الرئيسية'],
        ['doctor', 'new_case.php',  '➕', 'تسجيل حالة جديدة'],
        ['doctor', 'my_cases.php',  '📋', 'حالات وحدتي'],
    ],
    'manager' => [
        ['manager', 'dashboard.php',  '📊', 'لوحة البيانات'],
        ['manager', 'localities.php', '🗺️', 'المحليات'],
        ['manager', 'doctors.php',    '🩺', 'الأطباء'],
        ['manager', 'facilities.php', '🏥', 'المنشآت الصحية'],
        ['manager', 'alerts.php',     '🚨', 'تنبيهات التفشي', 'alerts'],
        ['manager', 'reports.php',    '📑', 'التقارير الأسبوعية'],
        ['manager', 'forecast.php',   '🤖', 'التنبؤ (تعلم الآلة)'],
    ],
];
$__labels = ['doctor' => 'واجهة طبيب الوحدة', 'manager' => 'إدارة الترصد — وزارة الصحة'];
$__alertCount = ($__role === 'manager') ? openAlertsCount() : 0;
$__pending = 0;
if ($__role === 'manager') $__pending = (int)getDB()->query("SELECT COUNT(*) c FROM users WHERE role='doctor' AND status='pending'")->fetch_assoc()['c'];
?>
    <aside class="sidebar">
        <div class="sidebar-logo">
            <img class="brand-mark" src="<?= BASE_URL ?>/assets/img/logo-mark.svg" alt="شعار المنصة">
            <h2>الترصد الذكي</h2>
            <p><?= e($__labels[$__role] ?? '') ?></p>
        </div>
        <nav class="sidebar-nav">
            <?php foreach ($__navs[$__role] ?? [] as $n):
                [$dir, $file, $icon, $lbl] = $n; $badge = $n[4] ?? null;
                $isActive = ($dir === $__folder && $file === $__active);
                $cnt = $badge === 'alerts' ? $__alertCount : (($file === 'doctors.php') ? $__pending : 0); ?>
                <a href="<?= BASE_URL ?>/<?= $dir ?>/<?= $file ?>"<?= $isActive ? ' class="active"' : '' ?>>
                    <span><?= $icon ?> <?= e($lbl) ?></span>
                    <?php if ($cnt > 0): ?><span class="nav-badge"><?= $cnt ?></span><?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-footer">
            👤 <?= e($_SESSION['full_name'] ?? '') ?><br>
            <?php if (!empty($_SESSION['facility_name'])): ?><small><?= e($_SESSION['facility_name']) ?></small><br><?php endif; ?>
            <a href="<?= BASE_URL ?>/auth/logout.php">تسجيل الخروج</a>
        </div>
    </aside>
