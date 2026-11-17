<?php
// config/database.php — الاتصال وثوابت المشروع (نسخة النشر: القيم من متغيرات البيئة)
require_once __DIR__ . '/../includes/db_conn.php';

// مسار الجذر: على Vercel يكون الموقع في الجذر (APP_BASE_URL=/ ← بلا بادئة). محلياً على XAMPP: /dengue
if (!defined('BASE_URL')) {
    $b = envOr('APP_BASE_URL', '/dengue');
    define('BASE_URL', rtrim((string)$b, '/'));
}

if (!defined('ASSET_VER')) {
    define('ASSET_VER', max(@filemtime(__DIR__ . '/../public/assets/css/style.css'), @filemtime(__DIR__ . '/../public/assets/js/nav.js')));
}

// نجعل mysqli يرمي استثناءً عند أي خطأ ليعمل rollback بشكل صحيح
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function getDB() {
    static $conn = null;
    if ($conn === null) {
        $conn = newDbConnection();
        $conn->query("SET time_zone = '+02:00'"); // توقيت السودان
    }
    return $conn;
}
