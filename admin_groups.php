<?php
session_start();
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
  header('Location: /gradeapp/login.php');
  exit;
}
$config = require __DIR__ . '/config.php';
$dsn = "mysql:host={$config['db']['host']};dbname={$config['db']['dbname']};charset={$config['db']['charset']}";
$pdo = new PDO($dsn, $config['db']['user'], $config['db']['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

include __DIR__ . '/inc/navbar.php';

if (empty($_SESSION['csrf']))
  $_SESSION['csrf'] = bin2hex(random_bytes(16));

$err = $msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (empty($_POST['csrf']) || $_POST['csrf'] !== $_SESSION['csrf']) {
    $err = 'CSRF failed';
  } else {
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
      $short = trim($_POST['short_name'] ?? '');
      $title = trim($_POST['title'] ?? '');
      if ($short === '')
        $err = 'Короткое имя обязательно';
      else {
        $stmt = $pdo->prepare("INSERT INTO groups (short_name, title) VALUES (?,?)");
        $stmt->execute([$short, $title]);
        $msg = "Группа создана";
      }
    } elseif ($action === 'update') {
      $id = intval($_POST['id'] ?? 0);
      $short = trim($_POST['short_name'] ?? '');
      $title = trim($_POST['title'] ?? '');
      if ($id <= 0)
        $err = 'Неверный id';
      elseif ($short === '')
        $err = 'Короткое имя обязательно';
      else {
        $stmt = $pdo->prepare("UPDATE groups SET short_name = ?, title = ? WHERE id = ?");
        $stmt->execute([$short, $title, $id]);
        $msg = "Группа обновлена";
      }
    } elseif ($action === 'delete') {
      $id = intval($_POST['id'] ?? 0);
      if ($id <= 0)
        $err = 'Неверный id';
      else {
        $stmt = $pdo->prepare("DELETE FROM groups WHERE id = ?");
        $stmt->execute([$id]);
        $msg = "Группа удалена";
      }
    }
  }
}

$edit = null;
if (!empty($_GET['edit'])) {
  $id = intval($_GET['edit']);
  $stmt = $pdo->prepare("SELECT * FROM groups WHERE id = ?");
  $stmt->execute([$id]);
  $edit = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$stmt = $pdo->query("SELECT * FROM groups ORDER BY short_name");
$groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html>

<head>
  <meta charset="utf-8">
  <title>Admin — Groups</title>
</head>

<body>
  <h2>Администрирование — Группы</h2>
  <p><a href="/gradeapp/">На главную</a> | <a href="/gradeapp/admin_users.php">Управление пользователями</a></p>

  <?php if ($err): ?>
    <div style="color:red;"><?= $err ?></div><?php endif; ?>
  <?php if ($msg): ?>
    <div style="color:green;"><?= $msg ?></div><?php endif; ?>

  <h3><?= $edit ? "Редактировать группу" : "Создать группу" ?></h3>
  <form method="post" action="">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
    <?php if ($edit): ?><input type="hidden" name="action" value="update"><input type="hidden" name="id"
        value="<?= intval($edit['id']) ?>"><?php else: ?><input type="hidden" name="action" value="create"><?php endif; ?>
    <label>Короткое имя (например KT-31):<br><input name="short_name"
        value="<?= htmlspecialchars($edit['short_name'] ?? '') ?>" required></label><br><br>
    <label>Название (полное):<br><input name="title"
        value="<?= htmlspecialchars($edit['title'] ?? '') ?>"></label><br><br>
    <button type="submit"><?= $edit ? "Сохранить" : "Создать" ?></button>
    <?php if ($edit): ?><a href="/gradeapp/admin_groups.php" style="margin-left:10px;">Отмена</a><?php endif; ?>
  </form>

  <h3>Список групп</h3>
  <table border="1" cellpadding="6">
    <tr>
      <th>ID</th>
      <th>Короткое имя</th>
      <th>Название</th>
      <th>Действия</th>
    </tr>
    <?php foreach ($groups as $g): ?>
      <tr>
        <td><?= intval($g['id']) ?></td>
        <td><?= htmlspecialchars($g['short_name']) ?></td>
        <td><?= htmlspecialchars($g['title']) ?></td>
        <td>
          <a href="/gradeapp/admin_groups.php?edit=<?= intval($g['id']) ?>">Изменить</a>
          &nbsp;|&nbsp;
          <form method="post" action="" style="display:inline" onsubmit="return confirm('Удалить группу?');">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= intval($g['id']) ?>">
            <button type="submit">Удалить</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
</body>

</html>