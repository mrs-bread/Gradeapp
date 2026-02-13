<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['error' => 'not_authenticated']);
    exit;
}

$config = require __DIR__ . '/../config.php';
try {
    $pdo = new PDO(
        "mysql:host={$config['db']['host']};dbname={$config['db']['dbname']};charset={$config['db']['charset']}",
        $config['db']['user'],
        $config['db']['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'db_connect', 'msg' => $e->getMessage()]);
    exit;
}

$user = $_SESSION['user'];
$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method']);
    exit;
}
$payload = json_decode(file_get_contents('php://input'), true) ?? $_POST;

$action = $payload['action'] ?? '';

if ($action === 'create') {
    $workbook_id = intval($payload['workbook_id'] ?? 0);
    $expires_days = intval($payload['expires_days'] ?? 0);

    if ($workbook_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_workbook']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT * FROM workbooks WHERE id = ?");
    $stmt->execute([$workbook_id]);
    $wb = $stmt->fetch();
    if (!$wb) {
        http_response_code(404);
        echo json_encode(['error' => 'wb_not_found']);
        exit;
    }
    if (!($user['role'] === 'admin' || intval($user['id']) === intval($wb['owner_id']))) {
        http_response_code(403);
        echo json_encode(['error' => 'forbidden']);
        exit;
    }

    $token = bin2hex(random_bytes(16));
    $expires_at = null;
    if ($expires_days > 0) {
        $expires_at = (new DateTime())->modify("+{$expires_days} days")->format('Y-m-d H:i:s');
    }
    try {
        $ins = $pdo->prepare("INSERT INTO workbook_shares (workbook_id, token, created_by, expires_at) VALUES (?,?,?,?)");
        $ins->execute([$workbook_id, $token, $user['id'], $expires_at]);
        $share_id = $pdo->lastInsertId();

        $base = rtrim($config['paths']['base_url'] ?? 'http://localhost/gradeapp', '/');
        $url = "{$base}/view_shared.php?token={$token}";

        echo json_encode(['ok' => true, 'share' => ['id' => $share_id, 'token' => $token, 'url' => $url, 'expires_at' => $expires_at]]);
        exit;
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'db_insert', 'msg' => $e->getMessage()]);
        exit;
    }
}

if ($action === 'revoke') {
    $id = intval($payload['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_id']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT ws.*, w.owner_id FROM workbook_shares ws JOIN workbooks w ON w.id = ws.workbook_id WHERE ws.id = ?");
    $stmt->execute([$id]);
    $share = $stmt->fetch();
    if (!$share) {
        http_response_code(404);
        echo json_encode(['error' => 'share_not_found']);
        exit;
    }

    if (!($user['role'] === 'admin' || intval($user['id']) === intval($share['owner_id']))) {
        http_response_code(403);
        echo json_encode(['error' => 'forbidden']);
        exit;
    }

    try {
        $upd = $pdo->prepare("UPDATE workbook_shares SET revoked = 1, revoked_by = ?, revoked_at = NOW() WHERE id = ?");
        $upd->execute([$user['id'], $id]);
        echo json_encode(['ok' => true, 'revoked_id' => $id]);
        exit;
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'db_update', 'msg' => $e->getMessage()]);
        exit;
    }
}

if ($action === 'list') {
    $workbook_id = intval($payload['workbook_id'] ?? 0);
    if ($workbook_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_workbook']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT * FROM workbooks WHERE id = ?");
    $stmt->execute([$workbook_id]);
    $wb = $stmt->fetch();
    if (!$wb) {
        http_response_code(404);
        echo json_encode(['error' => 'wb_not_found']);
        exit;
    }
    if (!($user['role'] === 'admin' || intval($user['id']) === intval($wb['owner_id']))) {
        http_response_code(403);
        echo json_encode(['error' => 'forbidden']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT ws.*, u.username as creator_name FROM workbook_shares ws LEFT JOIN users u ON u.id = ws.created_by WHERE ws.workbook_id = ? ORDER BY ws.created_at DESC");
    $stmt->execute([$workbook_id]);
    $rows = $stmt->fetchAll();
    $base = rtrim($config['paths']['base_url'] ?? 'http://localhost/gradeapp', '/');
    foreach ($rows as &$r) {
        $r['url'] = "{$base}/view_shared.php?token={$r['token']}";
    }
    echo json_encode(['ok' => true, 'shares' => $rows], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'unknown_action']);
exit;