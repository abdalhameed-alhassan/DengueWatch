<?php
// includes/layout.php — هيكل الصفحة المشترك: layoutStart('العنوان'); ... layoutEnd();
function layoutStart($title, $topbarActions = '') {
    ?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title) ?> — الترصد الذكي لحمى الضنك</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=<?= ASSET_VER ?>">
    <link rel="icon" type="image/svg+xml" href="<?= BASE_URL ?>/assets/img/favicon.svg">
    <meta name="theme-color" content="#0d4a52">
    <script src="<?= BASE_URL ?>/assets/js/nav.js?v=<?= ASSET_VER ?>" defer></script>
</head>
<body>
<div class="layout">
    <?php require __DIR__ . '/sidebar.php'; ?>
    <div class="main-content">
        <div class="topbar">
            <h1><?= e($title) ?></h1>
            <?php if ($topbarActions): ?><div class="topbar-actions"><?= $topbarActions ?></div><?php endif; ?>
        </div>
        <div class="page-content">
<?php
}

function layoutEnd() {
    ?>
        </div>
    </div>
</div>
</body>
</html>
<?php
}
