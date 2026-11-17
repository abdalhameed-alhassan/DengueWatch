<?php
/**
 * reset_password.php — sets a new password for ONE existing DengueWatch user, identified by phone number (bcrypt). Run via dengue_tools.ps1 only.
 * Env: DB_* (as import_database.php) + RESET_PHONE + RESET_PASSWORD (min 8 chars).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$ph = preg_replace('/\D/', '', (string)getenv('RESET_PHONE')); $p = (string)getenv('RESET_PASSWORD');
if (strpos($ph, '249') === 0 && strlen($ph) === 12) $ph = '0' . substr($ph, 3);
if ($ph === '' || strlen($p) < 8 || count(array_unique(str_split($p))) < 3) { fwrite(STDERR, "Phone required; password must be 8+ chars and not trivial.\n"); exit(2); }
$db = mysqli_init(); $db->options(MYSQLI_OPT_CONNECT_TIMEOUT, 15); $flags = 0;
if (in_array(strtolower((string)getenv('DB_SSL')), ['1', 'true', 'yes', 'on'], true)) { $db->ssl_set(null, null, getenv('DB_SSL_CA') ?: null, null, null); $flags = MYSQLI_CLIENT_SSL; }
$db->real_connect(getenv('DB_HOST'), getenv('DB_USER'), getenv('DB_PASS'), getenv('DB_NAME') ?: 'dengue_ml', (int)(getenv('DB_PORT') ?: 3306), null, $flags);
$hash = password_hash($p, PASSWORD_BCRYPT);
$st = $db->prepare("UPDATE users SET password_hash=? WHERE phone=? AND status='active'");
$st->bind_param('ss', $hash, $ph); $st->execute();
echo $st->affected_rows === 1 ? "Password updated for phone '$ph'.\n" : "No active user with that phone (or password unchanged).\n";
