<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['error' => 'auth']);
    exit;
}
$config = require __DIR__ . '/../config.php';
$pdo = new PDO(
    "mysql:host={$config['db']['host']};dbname={$config['db']['dbname']};charset={$config['db']['charset']}",
    $config['db']['user'],
    $config['db']['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$group_id = intval($_GET['group_id'] ?? 0);
if ($group_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_group']);
    exit;
}

$user = $_SESSION['user'];
if (!in_array($user['role'], ['admin', 'teacher'])) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT u.username, u.full_name FROM users u JOIN user_groups ug ON ug.user_id = u.id WHERE ug.group_id = ? AND (u.role = 'student' OR u.role = '') ORDER BY u.full_name ASC");
    $stmt->execute([$group_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['ok' => true, 'students' => $rows], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'db', 'msg' => $e->getMessage()]);
}
