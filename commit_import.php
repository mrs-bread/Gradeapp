<?php
session_start();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: /gradeapp/login.php');
    exit;
}
if (empty($_POST['csrf']) || $_POST['csrf'] !== ($_SESSION['csrf'] ?? '')) {
    die("CSRF validation failed");
}
if (empty($_SESSION['csv_import'])) {
    die("Нет данных для импорта. Загрузите CSV сначала.");
}
$import = $_SESSION['csv_import'];
$file = $import['file'];
$config = require __DIR__ . '/config.php';

$action = $_POST['action'] ?? 'cancel';
if ($action === 'cancel') {
    @unlink($file);
    unset($_SESSION['csv_import']);
    echo "Импорт отменён, временный файл удалён. <a href='/gradeapp/'>Назад</a>";
    exit;
}

$dsn = "mysql:host={$config['db']['host']};dbname={$config['db']['dbname']};charset={$config['db']['charset']}";
try {
    $pdo = new PDO($dsn, $config['db']['user'], $config['db']['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Exception $e) {
    @unlink($file);
    unset($_SESSION['csv_import']);
    die("DB connect error: " . $e->getMessage());
}

$processed = 0;
$errors = [];
try {
    $pdo->beginTransaction();
    if (($h = fopen($file, 'r')) === false)
        throw new Exception("Не удалось открыть файл");

    $header = fgetcsv($h, 0, ",");
    if ($header === false)
        throw new Exception("Пустой файл");
    $header = array_map('trim', $header);

    $lineNo = 1;
    while (($data = fgetcsv($h, 0, ",")) !== false) {
        $lineNo++;
        $assoc = [];
        foreach ($header as $i => $col)
            $assoc[$col] = isset($data[$i]) ? trim($data[$i]) : '';

        $login = $assoc['login'] ?? '';
        if ($login === '') {
            $errors[] = "Строка $lineNo: пустой логин";
            continue;
        }

        $fullName = $assoc['full_name'] ?? null;
        $groupName = $assoc['group'] ?? null;
        $role = in_array(($assoc['role'] ?? ''), ['student', 'teacher', 'admin']) ? $assoc['role'] : 'student';
        $rawPass = $assoc['password'] ?? '';

        if ($rawPass !== '') {
            $passwordHash = password_hash($rawPass, PASSWORD_DEFAULT);
            $mustChange = 0;
        } else {
            $temp = bin2hex(random_bytes(5));
            $passwordHash = password_hash($temp, PASSWORD_DEFAULT);
            $mustChange = 1;
        }

        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$login]);
        $userId = $stmt->fetchColumn();

        if ($userId) {
            $stmt = $pdo->prepare("UPDATE users SET full_name = ?, password_hash = ?, role = ?, must_change_password = ? WHERE id = ?");
            $stmt->execute([$fullName, $passwordHash, $role, $mustChange, $userId]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO users (username, full_name, password_hash, role, must_change_password) VALUES (?,?,?,?,?)");
            $stmt->execute([$login, $fullName, $passwordHash, $role, $mustChange]);
            $userId = $pdo->lastInsertId();
        }

        if ($groupName) {
            $stmt = $pdo->prepare("SELECT id FROM groups WHERE short_name = ?");
            $stmt->execute([$groupName]);
            $groupId = $stmt->fetchColumn();
            if (!$groupId) {
                $stmt = $pdo->prepare("INSERT INTO groups (short_name, title) VALUES (?,?)");
                $stmt->execute([$groupName, $groupName]);
                $groupId = $pdo->lastInsertId();
            }
            // link
            $stmt = $pdo->prepare("INSERT IGNORE INTO user_groups (user_id, group_id) VALUES (?,?)");
            $stmt->execute([$userId, $groupId]);
        }

        $processed++;
    }
    fclose($h);

    $logStmt = $pdo->prepare("INSERT INTO audit_logs (user_id, action, details) VALUES (?,?,?)");
    $details = json_encode(['file' => basename($file), 'processed' => $processed, 'uploader_id' => $import['uploader_id']]);
    $logStmt->execute([$_SESSION['user']['id'], 'csv_import', $details]);

    $pdo->commit();

    @unlink($file);
    unset($_SESSION['csv_import']);
    echo "Импорт завершён успешно. Строк обработано: $processed. <a href='/gradeapp/'>На главную</a>";

} catch (Exception $e) {
    if ($pdo->inTransaction())
        $pdo->rollBack();
    @unlink($file);
    unset($_SESSION['csv_import']);
    error_log("CSV import error: " . $e->getMessage());
    echo "Ошибка при импорте: " . htmlspecialchars($e->getMessage()) . ". Файл удалён.";
}
