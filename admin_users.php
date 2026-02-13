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
    if ($action === 'change_role') {
      $uid = intval($_POST['user_id'] ?? 0);
      $role = in_array($_POST['role'] ?? '', ['student', 'teacher', 'admin']) ? $_POST['role'] : 'student';
      if ($uid <= 0)
        $err = 'Invalid user';
      else {
        $stmt = $pdo->prepare("UPDATE users SET role = ? WHERE id = ?");
        $stmt->execute([$role, $uid]);
        $msg = "Роль обновлена";
      }
    } elseif ($action === 'delete_user') {
      $uid = intval($_POST['user_id'] ?? 0);
      if ($uid <= 0)
        $err = 'Invalid user';
      else {
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$uid]);
        $msg = "Пользователь удалён";
      }
    } elseif ($action === 'update_groups') {
      $uid = intval($_POST['user_id'] ?? 0);
      $groups = $_POST['groups'] ?? [];
      if ($uid <= 0)
        $err = 'Invalid user';
      else {
        $pdo->prepare("DELETE FROM user_groups WHERE user_id = ?")->execute([$uid]);
        $ins = $pdo->prepare("INSERT INTO user_groups (user_id, group_id) VALUES (?,?)");
        foreach ($groups as $g) {
          $gid = intval($g);
          if ($gid > 0)
            $ins->execute([$uid, $gid]);
        }
        $msg = "Группы обновлены";
      }
    }
  }
}

$users = $pdo->query("SELECT id, username, full_name, role, must_change_password, created_at FROM users ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$groups = $pdo->query("SELECT id, short_name FROM groups ORDER BY short_name")->fetchAll(PDO::FETCH_ASSOC);

function getUserGroupIds($pdo, $uid)
{
  $stmt = $pdo->prepare("SELECT group_id FROM user_groups WHERE user_id = ?");
  $stmt->execute([$uid]);
  return $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
}
?>
<!doctype html>
<html>

<head>
  <meta charset="utf-8">
  <title>Admin — Users</title>
</head>

<body>
  <h2>Администрирование — Пользователи</h2>
  <p><a href="/gradeapp/">На главную</a> | <a href="/gradeapp/admin_groups.php">Группы</a></p>

  <?php if ($err): ?>
    <div style="color:red;"><?= $err ?></div><?php endif; ?>
  <?php if ($msg): ?>
    <div style="color:green;"><?= $msg ?></div><?php endif; ?>

  <h3>Список пользователей</h3>
  <table border="1" cellpadding="6">
    <tr>
      <th>ID</th>
      <th>Логин</th>
      <th>ФИО</th>
      <th>Роль</th>
      <th>Необходимо поменять пароль</th>
      <th>Группа</th>
      <th>Действия</th>
    </tr>
    <?php foreach ($users as $u): ?>
      <?php $ug = getUserGroupIds($pdo, $u['id']); ?>
      <tr>
        <td><?= intval($u['id']) ?></td>
        <td><?= htmlspecialchars($u['username']) ?></td>
        <td><?= htmlspecialchars($u['full_name']) ?></td>
        <td><?= htmlspecialchars($u['role']) ?></td>
        <td><?= $u['must_change_password'] ? 'Да' : 'Нет' ?></td>
        <td><?php
        $names = [];
        if ($ug) {
          $s = $pdo->prepare("SELECT short_name FROM groups WHERE id IN (" . implode(',', array_map('intval', $ug)) . ")");
          $s->execute();
          $names = $s->fetchAll(PDO::FETCH_COLUMN, 0);
        }
        echo htmlspecialchars(implode(', ', $names));
        ?></td>
        <td>
          <!-- change role -->
          <form method="post" action="" style="display:inline">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
            <input type="hidden" name="action" value="change_role">
            <input type="hidden" name="user_id" value="<?= intval($u['id']) ?>">
            <select name="role">
              <option value="student" <?= $u['role'] == 'student' ? 'selected' : '' ?>>студент</option>
              <option value="teacher" <?= $u['role'] == 'teacher' ? 'selected' : '' ?>>преподаватель</option>
              <option value="admin" <?= $u['role'] == 'admin' ? 'selected' : '' ?>>администратор</option>
            </select>
            <button type="submit">Сменить</button>
          </form>

          <form method="post" action="" style="display:inline" onsubmit="return confirm('Удалить пользователя?');">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
            <input type="hidden" name="action" value="delete_user">
            <input type="hidden" name="user_id" value="<?= intval($u['id']) ?>">
            <button type="submit">Удалить</button>
          </form>

          <details style="margin-top:6px;">
            <summary>Ред. группы</summary>
            <form method="post" action="">
              <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
              <input type="hidden" name="action" value="update_groups">
              <input type="hidden" name="user_id" value="<?= intval($u['id']) ?>">
              <?php foreach ($groups as $g): ?>
                <label style="display:inline-block; margin-right:8px;">
                  <input type="checkbox" name="groups[]" value="<?= intval($g['id']) ?>" <?= in_array($g['id'], $ug) ? 'checked' : '' ?>>
                  <?= htmlspecialchars($g['short_name']) ?>
                </label>
              <?php endforeach; ?>
              <div style="margin-top:6px;"><button type="submit">Сохранить</button></div>
            </form>
          </details>

        </td>
      </tr>
    <?php endforeach; ?>
  </table>

</body>

</html>