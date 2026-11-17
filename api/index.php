<?php
/**
 * api/index.php — نقطة الدخول الوحيدة (Front Controller) لنشر Vercel عبر vercel-php،
 * وللتشغيل المحلي عبر:   php -S 127.0.0.1:8080 -t public api/index.php
 * يقبل صفحات المشروع المعروفة فقط ويضمّنها كما هي. أي مسار آخر (includes/، database/، tools/…) يُرفض بـ 404.
 * الملفات الساكنة (CSS/JS/صور) تُخدم مباشرة من public/ ولا تمر من هنا.
 */
$root = dirname(__DIR__);
$path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$path = is_string($path) ? rawurldecode($path) : '/';

// تشغيل محلي: دع خادم PHP المدمج يخدم الملفات الساكنة من public/
if (PHP_SAPI === 'cli-server' && $path !== '/' && is_file($root . '/public' . $path)) {
    return false;
}

$target = null;
if ($path === '/' || $path === '/index.php') {
    $target = $root . '/index.php';
} elseif (preg_match('~^/auth/(login|logout|register)\.php$~', $path, $m)) {
    $target = $root . '/auth/' . $m[1] . '.php';
} elseif (preg_match('~^/(doctor|manager)/([a-z_]+)\.php$~', $path, $m)) {
    $target = $root . '/' . $m[1] . '/' . $m[2] . '.php';
} elseif ($path === '/api/lookup.php') {
    $target = $root . '/endpoints/lookup.php';
}

if ($target === null || !is_file($target)) {
    http_response_code(404);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8"><title>404</title></head>'
       . '<body style="font-family:Arial,sans-serif;text-align:center;padding:60px"><h2>الصفحة غير موجودة</h2><p><a href="/auth/login.php">العودة لتسجيل الدخول</a></p></body></html>';
    exit;
}

$_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = $path === '/' ? '/index.php' : $path;
$_SERVER['SCRIPT_FILENAME'] = $target;
chdir(dirname($target));
require $target;
