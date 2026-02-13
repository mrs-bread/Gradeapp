<?php
$config = require __DIR__ . '/config.php';

$dsn = "mysql:host={$config['db']['host']};dbname={$config['db']['dbname']};charset={$config['db']['charset']}";
try {
    $pdo = new PDO($dsn, $config['db']['user'], $config['db']['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $username = 'admin';
    $fullName = 'Администратор';
    $rawPass = '1234567890';
    $hash = password_hash($rawPass, PASSWORD_DEFAULT);
    echo "Hash: {$hash} ";

    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$username]);
    if ($stmt->fetchColumn()) {
        echo "Пользователь 'admin' уже существует. Удалите/обновите вручную, если нужно.\n";
        exit;
    }

    $stmt = $pdo->prepare("INSERT INTO users (username, full_name, password_hash, role, must_change_password) VALUES (?,?,?,?,?)");
    $stmt->execute([$username, $fullName, $hash, 'admin', 1]);
    echo "Admin created: username={$username} password={$rawPass}\n";
    echo "Не забудьте удалить файл make_admin.php после успешного запуска.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
