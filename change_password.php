<?php
session_start();
include __DIR__ . '/inc/navbar.php';
$config = require __DIR__ . '/config.php';

if (empty($_SESSION['user'])) {
  $_SESSION['after_login'] = '/gradeapp/change_password.php';
  header('Location: /gradeapp/login.php');
  exit;
}

if (empty($_SESSION['csrf']))
  $_SESSION['csrf'] = bin2hex(random_bytes(16));

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (empty($_POST['csrf']) || $_POST['csrf'] !== ($_SESSION['csrf'] ?? '')) {
    $errors[] = 'CSRF validation failed.';
  } else {
    $current = $_POST['current_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if ($current === '' || $new === '' || $confirm === '') {
      $errors[] = 'Заполните все поля.';
    } elseif ($new !== $confirm) {
      $errors[] = 'Новый пароль и подтверждение не совпадают.';
    } else {
      if (mb_strlen($new) < 8) {
        $errors[] = 'Пароль должен быть не менее 8 символов.';
      }
      if (!preg_match('/[A-Za-zА-Яа-яЁё]/u', $new) || !preg_match('/[0-9]/', $new)) {
        $errors[] = 'Пароль должен содержать буквы и цифры.';
      }
    }

    if (empty($errors)) {
      try {
        $dsn = "mysql:host={$config['db']['host']};dbname={$config['db']['dbname']};charset={$config['db']['charset']}";
        $pdo = new PDO($dsn, $config['db']['user'], $config['db']['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        include __DIR__ . '/inc/navbar.php';


        $stmt = $pdo->prepare("SELECT id, password_hash FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user']['id']]);
        $u = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$u) {
          $errors[] = 'Пользователь не найден (обратитесь к администратору).';
        } elseif (!password_verify($current, $u['password_hash'])) {
          $errors[] = 'Текущий пароль введён неверно.';
        } else {
          $newHash = password_hash($new, PASSWORD_DEFAULT);
          $upd = $pdo->prepare("UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?");
          $upd->execute([$newHash, $u['id']]);


          $log = $pdo->prepare("INSERT INTO audit_logs (user_id, action, details) VALUES (?,?,?)");
          $details = json_encode(['info' => 'password_changed']);
          $log->execute([$u['id'], 'change_password', $details]);

          $success = true;
          $_SESSION['user']['password_changed_at'] = date('c');
          header('Location: /gradeapp/');
          exit;
        }
      } catch (Exception $e) {
        error_log("change_password error: " . $e->getMessage());
        $errors[] = 'Произошла ошибка сервера. Попробуйте позже.';
      }
    }
  }
}
?>
<!doctype html>
<html>

<head>
  <meta charset="utf-8">
  <title>Смена пароля</title>
  <style>
    body {
      font-family: Arial, sans-serif;
      padding: 20px;
    }

    .err {
      color: #b00;
    }

    .ok {
      color: #080;
    }

    form {
      max-width: 420px;
    }

    label {
      display: block;
      margin-top: 10px;
    }

    input[type=password] {
      width: 100%;
      padding: 8px;
      box-sizing: border-box;
    }

    .help {
      font-size: 0.9em;
      color: #666;
    }
  </style>
</head>

<body>
  <h2>Смена пароля</h2>

  <?php if (!empty($errors)): ?>
    <div class="err">
      <ul><?php foreach ($errors as $e)
        echo '<li>' . htmlspecialchars($e) . '</li>'; ?></ul>
    </div>
  <?php endif; ?>

  <?php if ($success): ?>
    <div class="ok">Пароль успешно изменён. Перенаправляем...</div>
  <?php endif; ?>

  <form method="post" action="">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
    <label>Текущий пароль:
      <input name="current_password" type="password" required>
    </label>

    <label>Новый пароль:
      <input name="new_password" type="password" required>
      <div class="help">Минимум 8 символов, содержит буквы и цифры.</div>
    </label>

    <label>Подтверждение нового пароля:
      <input name="confirm_password" type="password" required>
    </label>

    <div style="margin-top:12px;">
      <button type="submit">Сменить пароль</button>
      &nbsp;<a href="/gradeapp/logout.php">Выйти</a>
    </div>
  </form>
</body>

</html>