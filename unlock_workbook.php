<?php
session_start();
if (empty($_SESSION['user'])) {
    header('Location:/gradeapp/login.php');
    exit;
}
if ($_SESSION['user']['role'] === 'student') {
    die('only admin');
}
$config = require __DIR__ . '/config.php';
$pdo = new PDO(
    "mysql:host={$config['db']['host']};dbname={$config['db']['dbname']};charset={$config['db']['charset']}",
    $config['db']['user'],
    $config['db']['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$id = intval($_GET['id'] ?? 0);
if ($id <= 0)
    die('invalid');
$pdo->prepare("UPDATE workbooks SET locked_by = NULL, locked_at = NULL WHERE id = ?")->execute([$id]);
header('Location: /gradeapp/manage_workbooks.php');
