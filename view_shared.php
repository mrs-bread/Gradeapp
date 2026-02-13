<?php
session_start();
$config = require __DIR__ . '/config.php';
$pdo = new PDO(
    "mysql:host={$config['db']['host']};dbname={$config['db']['dbname']};charset={$config['db']['charset']}",
    $config['db']['user'],
    $config['db']['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$token = $_GET['token'] ?? '';
if (!$token)
    die('token required');

$stmt = $pdo->prepare("SELECT ws.*, w.id as workbook_id, w.title, w.group_id, w.show_formulas_for_students FROM workbook_shares ws JOIN workbooks w ON w.id = ws.workbook_id WHERE ws.token = ? LIMIT 1");
$stmt->execute([$token]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row)
    die('Invalid or revoked link');

if ($row['revoked'])
    die('Link revoked');
if ($row['expires_at'] && (new DateTime($row['expires_at'])) < new DateTime())
    die('Link expired');

if (empty($_SESSION['user'])) {
    $_SESSION['after_login'] = '/gradeapp/view_shared.php?token=' . urlencode($token);
    header('Location:/gradeapp/login.php');
    exit;
}
$user = $_SESSION['user'];

if ($user['role'] !== 'admin' && $user['role'] !== 'teacher') {
    $stmt = $pdo->prepare("SELECT 1 FROM user_groups ug WHERE ug.user_id = ? AND ug.group_id = ?");
    $stmt->execute([$user['id'], $row['group_id']]);
    if (!$stmt->fetchColumn())
        die('Access denied: you are not in the allowed group');
}

$sheetStmt = $pdo->prepare("SELECT * FROM sheets WHERE workbook_id = ? ORDER BY order_index");
$sheetStmt->execute([$row['workbook_id']]);
$sheets = $sheetStmt->fetchAll(PDO::FETCH_ASSOC);
if (!$sheets)
    die('No sheets');

$groupStudents = [];
try {
    $gs = $pdo->prepare("SELECT u.username, u.full_name FROM users u JOIN user_groups ug ON ug.user_id = u.id WHERE ug.group_id = ? AND (u.role = 'student' OR u.role = '')");
    $gs->execute([$row['group_id']]);
    $rows = $gs->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        if (!empty($r['username']))
            $groupStudents[mb_strtolower(trim($r['username']))] = true;
        if (!empty($r['full_name']))
            $groupStudents[mb_strtolower(trim($r['full_name']))] = true;
    }
} catch (Exception $e) {
    $groupStudents = [];
}

function filter_rows_for_user($content, $user, $sheetRow, $groupStudents)
{
    if (in_array($user['role'] ?? '', ['admin', 'teacher'])) {
        return $content;
    }
    if (!is_array($content))
        return ['header' => [], 'rows' => []];
    if (empty($sheetRow['row_visibility_mode']) || $sheetRow['row_visibility_mode'] !== 'own_row') {
        return $content;
    }

    $header = $content['header'] ?? [];
    $rows = $content['rows'] ?? [];

    $colName = trim((string) ($sheetRow['student_id_column'] ?? ''));
    $colLetter = null;
    if ($colName !== '') {
        foreach ($header as $i => $h) {
            if (mb_strtolower(trim((string) $h)) === mb_strtolower($colName)) {
                $colLetter = chr(65 + $i);
                break;
            }
        }
    }

    $uname = mb_strtolower(trim($user['username'] ?? ''));
    $fname = mb_strtolower(trim($user['full_name'] ?? ''));

    $filtered = [];
    foreach ($rows as $r) {
        if (!is_array($r))
            continue;

        if ($colLetter) {
            $raw = trim((string) ($r[$colLetter]['value'] ?? ''));
            $low = mb_strtolower($raw);
            if ($raw === '') {
                $filtered[] = $r;
                continue;
            }
            if (!empty($groupStudents) && isset($groupStudents[$low])) {
                if ($low === $uname || $low === $fname) {
                    $filtered[] = $r;
                }
                continue;
            } else {
                $filtered[] = $r;
                continue;
            }
        } else {
            $foundMatchToAnyStudent = false;
            $foundMatchToCurrentUser = false;
            foreach ($r as $cell) {
                $v = trim((string) ($cell['value'] ?? ''));
                if ($v === '')
                    continue;
                $vlow = mb_strtolower($v);
                if (!empty($groupStudents) && isset($groupStudents[$vlow])) {
                    $foundMatchToAnyStudent = true;
                    if ($vlow === $uname || $vlow === $fname)
                        $foundMatchToCurrentUser = true;
                }
            }
            if ($foundMatchToAnyStudent) {
                if ($foundMatchToCurrentUser)
                    $filtered[] = $r;
                continue;
            } else {
                $filtered[] = $r;
                continue;
            }
        }
    }

    return ['header' => $header, 'rows' => $filtered];
}

$current_workbook_id = $row['workbook_id'] ?? null;
$current_workbook_owner_id = null;
$current_workbook_title = $row['title'] ?? null;
include __DIR__ . '/inc/navbar.php';
?>
<!doctype html>
<html>

<head>
    <meta charset="utf-8">
    <title>View workbook - <?= htmlspecialchars($row['title']) ?></title>
</head>

<body>
    <h2>Workbook: <?= htmlspecialchars($row['title']) ?></h2>
    <p>Shared by link. You are: <?= htmlspecialchars($user['username']) ?> (<?= htmlspecialchars($user['role']) ?>)</p>
    <p><a href="/gradeapp/">Main</a></p>

    <?php foreach ($sheets as $s):
        $content = $s['content'] ? json_decode($s['content'], true) : ['header' => [], 'rows' => []];
        $visible = filter_rows_for_user($content, $user, $s, $groupStudents);
        $showFormulas = !empty($row['show_formulas_for_students']) ? true : false;
        ?>
        <h3><?= htmlspecialchars($s['name']) ?></h3>
        <?php if (empty($visible['rows'])): ?>
            <p><em>Нет данных для просмотра (возможно, вам не назначена строка)</em></p>
        <?php else: ?>
            <table border="1" cellpadding="6">
                <tr>
                    <?php foreach ($visible['header'] as $h): ?>
                        <th><?= htmlspecialchars($h) ?></th><?php endforeach; ?>
                </tr>
                <?php foreach ($visible['rows'] as $r): ?>
                    <tr>
                        <?php foreach ($visible['header'] as $i => $h):
                            $col = chr(65 + $i);
                            $cell = $r[$col] ?? null;
                            $out = '';
                            if ($cell) {
                                if ($showFormulas && !empty($cell['formula']))
                                    $out = htmlspecialchars($cell['formula']);
                                else
                                    $out = htmlspecialchars((string) ($cell['value'] ?? ''));
                            }
                            ?>
                            <td><?= $out ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    <?php endforeach; ?>

</body>

</html>