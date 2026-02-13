<?php
session_start();
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
  header('Location: /gradeapp/login.php');
  exit;
}
$config = require __DIR__ . '/../config.php';
$pdo = new PDO(
  "mysql:host={$config['db']['host']};dbname={$config['db']['dbname']};charset={$config['db']['charset']}",
  $config['db']['user'],
  $config['db']['pass'],
  [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$err = '';
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $login = trim($_POST['login'] ?? '');
  $pass = $_POST['password'] ?? '';
  $fullname = trim($_POST['full_name'] ?? '');
  $group_id = intval($_POST['group_id'] ?? 0);
  if ($login === '' || $pass === '' || $fullname === '' || $group_id <= 0) {
    $err = 'Заполните все поля';
  } else {
    $s = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $s->execute([$login]);
    if ($s->fetch()) {
      $err = 'Login already exists';
    } else {
      $hash = password_hash($pass, PASSWORD_DEFAULT);
      $ins = $pdo->prepare("INSERT INTO users (username, password_hash, full_name, role) VALUES (?,?,?, 'student')");
      $ins->execute([$login, $hash, $fullname]);
      $uid = $pdo->lastInsertId();
      $pdo->prepare("INSERT INTO user_groups (user_id, group_id) VALUES (?,?)")->execute([$uid, $group_id]);
      $msg = "Student created (id={$uid})";
    }
  }
}

$groups = $pdo->query("SELECT id, short_name FROM groups ORDER BY short_name")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html>

<head>
  <meta charset="utf-8">
  <title>Add student</title>
</head>

<body>
  <?php include __DIR__ . '/../inc/navbar.php'; ?>
  <h2>Add student (admin)</h2>
  <?php if ($err): ?>
    <div style="color:red;"><?= htmlspecialchars($err) ?></div><?php endif; ?>
  <?php if ($msg): ?>
    <div style="color:green;"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
  <form method="post" action="">
    <label>Login: <input name="login"></label><br>
    <label>Password: <input name="password" type="password"></label><br>
    <label>Full name: <input name="full_name"></label><br>
    <label>Group:
      <select name="group_id">
        <?php foreach ($groups as $g): ?>
          <option value="<?= intval($g['id']) ?>"><?= htmlspecialchars($g['short_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label><br><br>
    <button type="submit">Create student</button>
  </form>
</body>

</html>