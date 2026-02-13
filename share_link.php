<?php
session_start();
if (empty($_SESSION['user'])) {
    header('Location:/gradeapp/login.php');
    exit;
}

$configPath = __DIR__ . '/config.php';
if (!file_exists($configPath)) {
    echo "Config not found. Create config.php based on sample.";
    exit;
}
$config = require $configPath;

try {
    $dsn = "mysql:host={$config['db']['host']};dbname={$config['db']['dbname']};charset={$config['db']['charset']}";
    $pdo = new PDO($dsn, $config['db']['user'], $config['db']['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    echo "<h2>Database connection failed</h2>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    exit;
}

include __DIR__ . '/inc/navbar.php';

$user = $_SESSION['user'];
if (empty($_GET['workbook'])) {
    die('workbook id required');
}
$wbId = intval($_GET['workbook']);

try {
    $stmt = $pdo->prepare("SELECT * FROM workbooks WHERE id = ?");
    $stmt->execute([$wbId]);
    $wb = $stmt->fetch();
} catch (PDOException $e) {
    echo "<h2>DB error</h2><pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
    exit;
}

if (!$wb)
    die('Workbook not found');
if (!($user['role'] === 'admin' || $user['id'] == $wb['owner_id']))
    die('Access denied');

$err = '';
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $days = intval($_POST['expires_days'] ?? 0);
        $token = bin2hex(random_bytes(16));
        $expires = null;
        if ($days > 0) {
            $expires = (new DateTime())->modify("+{$days} days")->format('Y-m-d H:i:s');
        }
        try {
            $ins = $pdo->prepare("INSERT INTO workbook_shares (workbook_id, token, created_by, expires_at) VALUES (?,?,?,?)");
            $ins->execute([$wbId, $token, $user['id'], $expires]);
            $msg = 'Link created';
        } catch (PDOException $e) {
            $err = "DB error while creating share: " . $e->getMessage();
        }
    } elseif ($action === 'revoke') {
        $id = intval($_POST['id'] ?? 0);
        if ($id > 0) {
            try {
                $upd = $pdo->prepare("UPDATE workbook_shares SET revoked=1, revoked_by=?, revoked_at=NOW() WHERE id=?");
                $upd->execute([$user['id'], $id]);
                $msg = 'Revoked';
            } catch (PDOException $e) {
                $err = "DB error while revoking: " . $e->getMessage();
            }
        }
    }
}

try {
    $stmt = $pdo->prepare("SELECT ws.*, u.username as creator FROM workbook_shares ws LEFT JOIN users u ON u.id=ws.created_by WHERE ws.workbook_id=? ORDER BY ws.created_at DESC");
    $stmt->execute([$wbId]);
    $shares = $stmt->fetchAll();
} catch (PDOException $e) {
    echo "<h2>DB error</h2><pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
    exit;
}

$base = rtrim($config['paths']['base_url'] ?? 'http://localhost/gradeapp', '/');
?>
<!doctype html>
<html>

<head>
    <meta charset="utf-8">
    <title>Share links</title>
</head>

<body>
    <h2>Ссылки для: <?= htmlspecialchars($wb['title']) ?></h2>
    <p><a href="/gradeapp/manage_workbooks.php">Back</a></p>
    <?php if ($err): ?>
        <div style="color:red;"><?= htmlspecialchars($err) ?></div><?php endif; ?>
    <?php if ($msg): ?>
        <div style="color:green;"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

    <form method="post" action="">
        <input type="hidden" name="action" value="create">
        <label>Работает (дней, 0 = всегда): <input name="expires_days" value="0" size="4"></label>
        <button type="submit">Сгенерировать</button>
    </form>

    <h3>Сгенерированные ссылки</h3>
    <table border="1" cellpadding="6">
        <tr>
            <th>ID</th>
            <th>Токен</th>
            <th>Создана</th>
            <th>Дата создания</th>
            <th>Перестанет работать</th>
            <th>Отозвана</th>
            <th>Действия</th>
        </tr>
        <?php foreach ($shares as $s): ?>
            <tr>
                <td><?= intval($s['id']) ?></td>
                <td><?= htmlspecialchars(substr($s['token'], 0, 8)) ?>…</td>
                <td><?= htmlspecialchars($s['creator'] ?? '') ?></td>
                <td><?= htmlspecialchars($s['created_at']) ?></td>
                <td><?= htmlspecialchars($s['expires_at']) ?></td>
                <td><?= $s['revoked'] ? 'yes' : 'no' ?></td>
                <td>
                    <?php if (!$s['revoked']): ?>
                        <form method="post" style="display:inline" onsubmit="return confirm('Revoke?')">
                            <input type="hidden" name="action" value="revoke">
                            <input type="hidden" name="id" value="<?= intval($s['id']) ?>">
                            <button type="submit">Отозвать</button>
                        </form>
                    <?php endif; ?>
                    <br>
                    <small>URL:</small><br>
                    <input type="text" readonly style="width:420px"
                        value="<?= $base ?>/view_shared.php?token=<?= htmlspecialchars($s['token']) ?>">
                </td>
            </tr>
        <?php endforeach; ?>
    </table>

</body>

</html>