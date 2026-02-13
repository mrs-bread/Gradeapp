<?php
session_start();
include __DIR__ . '/inc/navbar.php';
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
  header('Location: /gradeapp/login.php');
  exit;
}
if (empty($_SESSION['csrf']))
  $_SESSION['csrf'] = bin2hex(random_bytes(16));
?>
<!doctype html>
<html>

<head>
  <meta charset="utf-8">
  <title>CSV Импорт — Загрузка</title>
</head>

<body>
  <h2>Импорт пользователей (CSV)</h2>
  <p>Формат: <code>логин,ФИО,группа,Пароль,Роль</code></p>
  <form action="parse_csv.php" method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
    <input type="file" name="csvfile" accept=".csv,text/csv" required>
    <button type="submit">Загрузить и показать</button>
  </form>
  <p><a href="/gradeapp/">На главную</a></p>
</body>

</html>