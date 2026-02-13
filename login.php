<?php
session_start();
$config = require __DIR__ . '/config.php';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $err = 'Заполните все поля';
    } else {
        $dsn = "mysql:host={$config['db']['host']};dbname={$config['db']['dbname']};charset={$config['db']['charset']}";
        try {
            $pdo = new PDO($dsn, $config['db']['user'], $config['db']['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $stmt = $pdo->prepare("SELECT id, username, full_name, password_hash, role, must_change_password FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user && password_verify($password, $user['password_hash'])) {
                $_SESSION['user'] = [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'full_name' => $user['full_name'],
                    'role' => $user['role'],
                ];
                if ($user['must_change_password']) {
                    header('Location: /gradeapp/change_password.php');
                    exit;
                }
                $after = $_SESSION['after_login'] ?? '/gradeapp/';
                unset($_SESSION['after_login']);
                header("Location: $after");
                exit;
            } else {
                $err = 'Неправильный логин или пароль';
            }
        } catch (Exception $e) {
            $err = 'Ошибка сервера: ' . $e->getMessage();
        }
    }
}
?>
<!doctype html>
<html>

<head>
    <meta charset="utf-8">
    <title>Login</title>
</head>

<body>
    <h2>Вход</h2>
    <?php if ($err): ?>
        <p style="color:red;"><?= htmlspecialchars($err) ?></p><?php endif; ?>
    <form method="post" action="">
        <label>Логин: <input name="username" required></label><br><br>
        <label>Пароль: <input name="password" type="password" required></label><br><br>
        <button type="submit">Войти</button>
    </form>
</body>

</html>