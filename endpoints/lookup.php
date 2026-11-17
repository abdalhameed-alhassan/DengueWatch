<?php
// api/lookup.php — قوائم متسلسلة (ولاية ← محلية ← منشأة) للتسجيل والفلاتر
require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json; charset=utf-8');
$db = getDB();
$type = $_GET['type'] ?? '';
$id = (int)($_GET['id'] ?? 0);
$rows = [];
if ($type === 'localities') {
    $st = $db->prepare("SELECT id, name FROM localities WHERE state_id = ? ORDER BY name");
    $st->bind_param('i', $id); $st->execute(); $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
} elseif ($type === 'facilities') {
    $st = $db->prepare("SELECT id, name FROM facilities WHERE locality_id = ? AND is_active = 1 ORDER BY name");
    $st->bind_param('i', $id); $st->execute(); $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
}
echo json_encode($rows, JSON_UNESCAPED_UNICODE);
