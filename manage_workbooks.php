<?php
session_start();
if (empty($_SESSION['user'])) {
  header('Location: /gradeapp/login.php');
  exit;
}

$config = require __DIR__ . '/config.php';

try {
  $dsn = "mysql:host={$config['db']['host']};dbname={$config['db']['dbname']};charset={$config['db']['charset']}";
  $pdo = new PDO($dsn, $config['db']['user'], $config['db']['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
} catch (PDOException $e) {
  echo "<h2>Database connection error</h2>";
  echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
  exit;
}

include __DIR__ . '/inc/navbar.php';

$user = $_SESSION['user'];
$err = $msg = '';

if (empty($_SESSION['csrf']))
  $_SESSION['csrf'] = bin2hex(random_bytes(16));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($user['role']) && ($user['role'] === 'teacher' || $user['role'] === 'admin'))) {
  if (empty($_POST['csrf']) || $_POST['csrf'] !== $_SESSION['csrf']) {
    $err = 'CSRF validation failed';
  } else {
    $title = trim($_POST['title'] ?? '');
    $group_id = intval($_POST['group_id'] ?? 0);
    if ($title === '' || $group_id <= 0) {
      $err = 'Введите название и группу';
    } else {
      try {
        $stmt = $pdo->prepare("INSERT INTO workbooks (title, owner_id, group_id, created_at) VALUES (?,?,?,NOW())");
        $stmt->execute([$title, $user['id'], $group_id]);
        $wbId = $pdo->lastInsertId();
        $defaultContent = json_encode(['header' => ['A'], 'rows' => []], JSON_UNESCAPED_UNICODE);
        $stmt = $pdo->prepare("INSERT INTO sheets (workbook_id, name, order_index, content) VALUES (?,?,?,?)");
        $stmt->execute([$wbId, 'Sheet1', 0, $defaultContent]);
        $msg = 'Книга создана';
      } catch (PDOException $e) {
        $err = "DB error while creating workbook: " . $e->getMessage();
      }
    }
  }
}

try {
  $groups = $pdo->query("SELECT id, short_name FROM groups ORDER BY short_name")->fetchAll();
} catch (PDOException $e) {
  echo "<h2>Database query error</h2>";
  echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
  exit;
}

try {
  if ($user['role'] === 'admin') {
    $stmt = $pdo->query("SELECT w.*, u.username as owner_name, g.short_name FROM workbooks w JOIN users u ON u.id = w.owner_id JOIN groups g ON g.id = w.group_id ORDER BY w.id DESC");
    $workbooks = $stmt->fetchAll();
  } elseif ($user['role'] === 'teacher') {
    $stmt = $pdo->prepare("SELECT w.*, u.username as owner_name, g.short_name FROM workbooks w JOIN users u ON u.id = w.owner_id JOIN groups g ON g.id = w.group_id WHERE w.owner_id = ? ORDER BY w.id DESC");
    $stmt->execute([$user['id']]);
    $workbooks = $stmt->fetchAll();
  } else {
    $stmt = $pdo->prepare("SELECT w.*, u.username as owner_name, g.short_name FROM workbooks w JOIN users u ON u.id = w.owner_id JOIN groups g ON g.id = w.group_id JOIN user_groups ug ON ug.group_id = w.group_id WHERE ug.user_id = ? ORDER BY w.id DESC");
    $stmt->execute([$user['id']]);
    $workbooks = $stmt->fetchAll();
  }
} catch (PDOException $e) {
  echo "<h2>Database query error</h2>";
  echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
  exit;
}
?>
<!doctype html>
<html>

<head>
  <meta charset="utf-8">
  <title>Workbooks — Manage</title>
  <style>
    body {
      font-family: Arial, sans-serif;
      padding: 14px
    }

    table {
      border-collapse: collapse
    }

    td,
    th {
      border: 1px solid #ccc;
      padding: 6px
    }
  </style>
</head>

<body>
  <h2>Рабочие книги</h2>
  <p><a href="/gradeapp/">Главная</a> | Добро пожаловать: <?= htmlspecialchars($user['username']) ?>
    (<?= htmlspecialchars($user['role']) ?>)</p>

  <?php if ($err): ?>
    <div style="color:red;"><?= htmlspecialchars($err) ?></div><?php endif; ?>
  <?php if ($msg): ?>
    <div style="color:green;"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

  <?php if ($user['role'] === 'teacher' || $user['role'] === 'admin'): ?>
    <h3>Создать рабочую книгу</h3>
    <form method="post" action="">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
      <label>Название: <input name="title" required></label><br><br>
      <label>Группа:
        <select name="group_id" required>
          <option value="">-- выберите --</option>
          <?php foreach ($groups as $g): ?>
            <option value="<?= intval($g['id']) ?>"><?= htmlspecialchars($g['short_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label><br><br>
      <button type="submit">Создать</button>
    </form>
  <?php endif; ?>

  <h3>Список книг</h3>
  <table cellpadding="6">
    <tr>
      <th>ID</th>
      <th>Название</th>
      <th>Владелец</th>
      <th>Группа</th>
      <th>Блокировка</th>
      <th>Действия</th>
    </tr>
    <?php foreach ($workbooks as $w): ?>
      <?php
      $canEdit = (isset($user['role']) && isset($user['id'])) && (
        ($user['role'] === 'teacher' && intval($user['id']) === intval($w['owner_id'])) || $user['role'] === 'admin'
      );
      if ($canEdit) {
        $openUrl = '/gradeapp/edit_workbook.php?id=' . intval($w['id']);
        $openText = 'Открыть (редактировать)';
      } else {
        $openUrl = '/gradeapp/view_workbook.php?id=' . intval($w['id']);
        $openText = 'Открыть (просмотр)';
      }
      ?>
      <tr>
        <td><?= intval($w['id']) ?></td>
        <td><?= htmlspecialchars($w['title']) ?></td>
        <td><?= htmlspecialchars($w['owner_name'] ?? '') ?></td>
        <td><?= htmlspecialchars($w['short_name'] ?? '') ?></td>
        <td><?= $w['locked_by'] ? 'Да' : 'Нет' ?></td>
        <td>
          <a href="<?= htmlspecialchars($openUrl) ?>"><?= htmlspecialchars($openText) ?></a>
          <?php if ($user['role'] === 'admin' || ($user['role'] === 'teacher' && intval($user['id']) === intval($w['owner_id']))): ?>
            &nbsp;|&nbsp;<a href="/gradeapp/unlock_workbook.php?id=<?= intval($w['id']) ?>"
              onclick="return confirm('Разблокировать?');">Разблокировать</a>
            &nbsp;|&nbsp;<a href="/gradeapp/share_link.php?workbook=<?= intval($w['id']) ?>">Поделиться</a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>

</body>

</html>