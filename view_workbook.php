<?php
session_start();
if (empty($_SESSION['user'])) {
    $_SESSION['after_login'] = $_SERVER['REQUEST_URI'];
    header('Location: /gradeapp/login.php');
    exit;
}
$config = require __DIR__ . '/config.php';
$pdo = new PDO(
    "mysql:host={$config['db']['host']};dbname={$config['db']['dbname']};charset={$config['db']['charset']}",
    $config['db']['user'],
    $config['db']['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$user = $_SESSION['user'];
$id = intval($_GET['id'] ?? 0);
if ($id <= 0)
    die('Invalid workbook id');

$stmt = $pdo->prepare("SELECT * FROM workbooks WHERE id = ?");
$stmt->execute([$id]);
$wb = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$wb)
    die('Workbook not found');

if ($user['role'] === 'admin' || ($user['role'] === 'teacher' && intval($user['id']) === intval($wb['owner_id']))) {
    header('Location: /gradeapp/edit_workbook.php?id=' . $id);
    exit;
}

if ($user['role'] !== 'admin' && $user['role'] !== 'teacher') {
    $stmt = $pdo->prepare("SELECT 1 FROM user_groups WHERE user_id = ? AND group_id = ?");
    $stmt->execute([$user['id'], $wb['group_id']]);
    if (!$stmt->fetchColumn()) {
        die('Access denied: you are not a member of the workbook group');
    }
}

$stmt = $pdo->prepare("SELECT * FROM sheets WHERE workbook_id = ? ORDER BY order_index");
$stmt->execute([$id]);
$sheets = $stmt->fetchAll(PDO::FETCH_ASSOC);

$groupStudents = [];
try {
    $gs = $pdo->prepare("SELECT u.username, u.full_name FROM users u JOIN user_groups ug ON ug.user_id = u.id WHERE ug.group_id = ? AND (u.role = 'student' OR u.role = '')");
    $gs->execute([$wb['group_id']]);
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
                } else {
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

function cell_format_to_style($fmt)
{
    if (!is_array($fmt) || empty($fmt))
        return '';
    $parts = [];

    if (!empty($fmt['bg']) && is_string($fmt['bg'])) {
        $bg = trim($fmt['bg']);
        if (strpos($bg, '#') === 0)
            $bg = $bg;
        else
            $bg = '#' . ltrim($bg, '#');
        if (preg_match('/^#?[0-9a-fA-F]{6}$/', $bg)) {
            if ($bg[0] !== '#')
                $bg = '#' . $bg;
            $parts[] = "background-color: " . htmlspecialchars($bg, ENT_QUOTES, 'UTF-8');
        }
    }

    if (!empty($fmt['bold'])) {
        $parts[] = "font-weight: bold";
    }
    if (!empty($fmt['italic'])) {
        $parts[] = "font-style: italic";
    }
    if (!empty($fmt['align'])) {
        $a = strtolower(trim((string) $fmt['align']));
        if (in_array($a, ['left', 'center', 'right'], true)) {
            $parts[] = "text-align: " . $a;
        }
    }

    return implode('; ', $parts);
}

$current_workbook_id = $wb['id'] ?? null;
$current_workbook_owner_id = $wb['owner_id'] ?? null;
$current_workbook_title = $wb['title'] ?? null;
include __DIR__ . '/inc/navbar.php';
?>
<!doctype html>
<html>

<head>
    <meta charset="utf-8">
    <title>Просмотр книги - <?= htmlspecialchars($wb['title']) ?></title>
</head>

<body>
    <h2>Рабочая книга: <?= htmlspecialchars($wb['title']) ?></h2>
    <p>Добро пожаловать: <?= htmlspecialchars($user['username']) ?> (<?= htmlspecialchars($user['role']) ?>) — просмотр</p>
    <p><a href="/gradeapp/">Главная</a></p>

    <?php if (!$sheets): ?>
        <p>No sheets</p>
    <?php endif; ?>

    <?php foreach ($sheets as $s):
        $content = $s['content'] ? json_decode($s['content'], true) : ['header' => [], 'rows' => []];
        $visible = filter_rows_for_user($content, $user, $s, $groupStudents);
        $showFormulas = !empty($wb['show_formulas_for_students']) ? true : false;
        ?>
        <h3><?= htmlspecialchars($s['name']) ?></h3>

        <?php if (empty($visible['rows'])): ?>
            <p><em>Нет видимой информации для вас (возможно вашей строки нет в этой книге)</em></p>
        <?php else: ?>
            <table border="1" cellpadding="6">
                <tr>
                    <?php foreach ($visible['header'] as $h): ?>
                        <th><?= htmlspecialchars($h) ?></th><?php endforeach; ?>
                </tr>
                <?php foreach ($visible['rows'] as $rIndex => $r): ?>
                    <tr>
                        <?php foreach ($visible['header'] as $i => $h):
                            $col = chr(65 + $i);
                            $cell = $r[$col] ?? null;

                            $out = '';
                            $style = '';
                            if ($cell) {
                                if ($showFormulas && !empty($cell['formula'])) {
                                    $out = (string) $cell['formula'];
                                } else {
                                    $out = isset($cell['value']) ? (string) $cell['value'] : '';
                                }
                                if (!empty($cell['format']) && is_array($cell['format'])) {
                                    $style = cell_format_to_style($cell['format']);
                                }
                            } else {
                                $out = '';
                            }
                            ?>
                            <td<?= $style !== '' ? ' style="' . $style . '"' : '' ?>><?= htmlspecialchars($out) ?></td>
                            <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    <?php endforeach; ?>
</body>

</html>