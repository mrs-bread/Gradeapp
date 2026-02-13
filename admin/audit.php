<?php
session_start();
include __DIR__ . '/../inc/navbar.php';
if (empty($_SESSION['user']) || ($_SESSION['user']['role'] ?? '') !== 'admin') {
  header('Location: /gradeapp/login.php');
  exit;
}

require __DIR__ . '/../vendor/autoload.php';
$config = require __DIR__ . '/../config.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

try {
  $pdo = new PDO(
    "mysql:host={$config['db']['host']};dbname={$config['db']['dbname']};charset={$config['db']['charset']}",
    $config['db']['user'],
    $config['db']['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
  );
} catch (PDOException $e) {
  die("DB connect error: " . htmlspecialchars($e->getMessage()));
}

$errors = [];
$success = null;

try {
  $stmt = $pdo->query("SELECT role, COUNT(*) AS cnt FROM users GROUP BY role");
  $usersByRole = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); // role => cnt

  $groupsCnt = (int) $pdo->query("SELECT COUNT(*) FROM `groups`")->fetchColumn();

  $workbooksCnt = (int) $pdo->query("SELECT COUNT(*) FROM workbooks")->fetchColumn();

  $recentStmt = $pdo->prepare("
        SELECT a.*, u.username
          FROM audit_logs a
          LEFT JOIN users u ON u.id = a.user_id
         WHERE a.action LIKE :p1 OR a.action LIKE :p2 OR a.action LIKE :p3
         ORDER BY a.created_at DESC
         LIMIT 20
    ");
  $recentStmt->execute([':p1' => '%delete%', ':p2' => '%share%', ':p3' => '%revoke%']);
  $recentActions = $recentStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $errors[] = "Stats load error: " . $e->getMessage();
}

$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

$filter_user = isset($_GET['user_id']) ? intval($_GET['user_id']) : null;
$filter_action = trim((string) ($_GET['action'] ?? ''));
$filter_date_from = trim((string) ($_GET['date_from'] ?? ''));
$filter_date_to = trim((string) ($_GET['date_to'] ?? ''));

$where = [];
$params = [];

if ($filter_user) {
  $where[] = "a.user_id = :user_id";
  $params[':user_id'] = $filter_user;
}
if ($filter_action !== '') {
  if (strpos($filter_action, '%') !== false) {
    $where[] = "a.action LIKE :action";
    $params[':action'] = $filter_action;
  } else {
    $where[] = "a.action LIKE :action";
    $params[':action'] = '%' . $filter_action . '%';
  }
}
if ($filter_date_from !== '') {
  $d = date('Y-m-d H:i:s', strtotime($filter_date_from . ' 00:00:00'));
  $where[] = "a.created_at >= :df";
  $params[':df'] = $d;
}
if ($filter_date_to !== '') {
  $d = date('Y-m-d H:i:s', strtotime($filter_date_to . ' 23:59:59'));
  $where[] = "a.created_at <= :dt";
  $params[':dt'] = $d;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

try {
  $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM audit_logs a $whereSql");
  $cntStmt->execute($params);
  $totalRows = (int) $cntStmt->fetchColumn();
} catch (Throwable $e) {
  $errors[] = "Count error: " . $e->getMessage();
  $totalRows = 0;
}

$export = $_GET['export'] ?? '';
if (in_array($export, ['csv', 'xlsx']) && $totalRows >= 0) {
  $sql = "SELECT a.*, u.username FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id $whereSql ORDER BY a.created_at DESC";
  $expStmt = $pdo->prepare($sql);
  $expStmt->execute($params);
  $rows = $expStmt->fetchAll(PDO::FETCH_ASSOC);

  $filenameBase = 'audit_logs_' . date('Ymd_His');
  if ($export === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filenameBase . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM
    fputcsv($out, ['id', 'created_at', 'user_id', 'username', 'action', 'details']);
    foreach ($rows as $r) {
      $details = is_string($r['details']) ? $r['details'] : json_encode($r['details'], JSON_UNESCAPED_UNICODE);
      fputcsv($out, [$r['id'], $r['created_at'], $r['user_id'], $r['username'], $r['action'], $details]);
    }
    fclose($out);
    exit;
  } else {
    // XLSX export
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Audit logs');
    $sheet->fromArray(['id', 'created_at', 'user_id', 'username', 'action', 'details'], NULL, 'A1');
    $r = 2;
    foreach ($rows as $row) {
      $details = is_string($row['details']) ? $row['details'] : json_encode($row['details'], JSON_UNESCAPED_UNICODE);
      $sheet->setCellValue("A{$r}", $row['id']);
      $sheet->setCellValue("B{$r}", $row['created_at']);
      $sheet->setCellValue("C{$r}", $row['user_id']);
      $sheet->setCellValue("D{$r}", $row['username']);
      $sheet->setCellValue("E{$r}", $row['action']);
      $sheet->setCellValue("F{$r}", $details);
      $r++;
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filenameBase . '.xlsx"');
    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
  }
}

$auditRows = [];
try {
  $sql = "SELECT a.*, u.username FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id $whereSql ORDER BY a.created_at DESC LIMIT :lim OFFSET :off";
  $stmt = $pdo->prepare($sql);
  foreach ($params as $k => $v)
    $stmt->bindValue($k, $v);
  $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
  $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
  $stmt->execute();
  $auditRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $errors[] = "Audit fetch error: " . $e->getMessage();
}

$usersList = $pdo->query("SELECT id, username FROM users ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);

$totalPages = max(1, (int) ceil($totalRows / $perPage));

?>
<!doctype html>
<html>

<head>
  <meta charset="utf-8">
  <title>Администрирование — Аудит / Статистика</title>
  <style>
    body {
      font-family: Arial, Helvetica, sans-serif;
      padding: 14px
    }

    .row {
      display: flex;
      gap: 12px;
      flex-wrap: wrap
    }

    .card {
      border: 1px solid #ddd;
      padding: 10px;
      border-radius: 6px;
      background: #fff;
      min-width: 180px
    }

    table {
      border-collapse: collapse;
      width: 100%;
      margin-top: 8px
    }

    th,
    td {
      border: 1px solid #ccc;
      padding: 6px;
      text-align: left;
      vertical-align: top
    }

    .controls {
      margin-top: 8px
    }

    .btn {
      padding: 6px 10px;
      margin-right: 6px
    }

    .small {
      font-size: 0.9em;
      color: #666
    }

    .danger {
      color: #b00
    }

    .details {
      font-family: monospace;
      background: #f7f7f7;
      padding: 6px;
      border-radius: 4px;
      white-space: pre-wrap
    }

    form.filter {
      margin-top: 8px;
      border: 1px solid #eee;
      padding: 10px;
      border-radius: 6px;
      background: #fafafa
    }

    .pager {
      margin-top: 10px
    }
  </style>
</head>

<body>
  <h2>Администрирование: Аудит и Статистика</h2>
  <p><a href="/gradeapp/">На главную</a></p>

  <?php if (!empty($errors)): ?>
    <div class="danger">
      <strong>Errors:</strong>
      <ul><?php foreach ($errors as $e): ?>
          <li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <div class="row" style="margin-bottom:12px;">
    <div class="card">
      <div><strong>Пользователей</strong></div>
      <div class="small">Студенты: <?= intval($usersByRole['student'] ?? 0) ?></div>
      <div class="small">Преподаватели: <?= intval($usersByRole['teacher'] ?? 0) ?></div>
      <div class="small">Администраторы: <?= intval($usersByRole['admin'] ?? 0) ?></div>
    </div>
    <div class="card">
      <div><strong>Группы</strong></div>
      <div class="small"><?= $groupsCnt ?></div>
    </div>
    <div class="card">
      <div><strong>Рабочие книги</strong></div>
      <div class="small"><?= $workbooksCnt ?></div>
    </div>
    <div class="card">
      <div><strong>Последние важные действия</strong></div>
      <?php if (empty($recentActions)): ?>
        <div class="small">Нет последних важных действий</div>
      <?php else: ?>
        <ul class="small">
          <?php foreach ($recentActions as $ra): ?>
            <li><span><?= htmlspecialchars($ra['created_at']) ?></span>
              — <strong><?= htmlspecialchars($ra['action']) ?></strong>
              by <?= htmlspecialchars($ra['username'] ?? 'system') ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>

  <h3>Аудит</h3>
  <form class="filter" method="get" action="">
    <label>
      Пользователь:
      <select name="user_id">
        <option value="">-- все --</option>
        <?php foreach ($usersList as $u): ?>
          <option value="<?= intval($u['id']) ?>" <?= ($filter_user && intval($filter_user) === intval($u['id'])) ? 'selected' : '' ?>><?= htmlspecialchars($u['username']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label style="margin-left:12px;">
      Действие (содержит):
      <input type="text" name="action" value="<?= htmlspecialchars($filter_action) ?>" placeholder="e.g. delete_workbook">
    </label>
    <label style="margin-left:12px;">
      С:
      <input type="date" name="date_from" value="<?= htmlspecialchars($filter_date_from) ?>">
    </label>
    <label style="margin-left:12px;">
      По:
      <input type="date" name="date_to" value="<?= htmlspecialchars($filter_date_to) ?>">
    </label>
    <div class="controls">
      <button type="submit" class="btn">Фильтр</button>
      <a class="btn" href="?">Сброс</a>
      <?php
      $qs = $_GET;
      $qs['export'] = 'csv';
      $csvLink = '?' . http_build_query($qs);
      $qs['export'] = 'xlsx';
      $xlsxLink = '?' . http_build_query($qs);
      ?>
      <a href="<?= htmlspecialchars($csvLink) ?>" class="btn">Экспорт CSV</a>
      <a href="<?= htmlspecialchars($xlsxLink) ?>" class="btn">Экспорт XLSX</a>
    </div>
  </form>

  <div style="margin-top:12px;">
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Дата</th>
          <th>Пользователь</th>
          <th>Действие</th>
          <th>Детали</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($auditRows)): ?>
          <tr>
            <td colspan="5" class="small">Нет записей</td>
          </tr>
        <?php else: ?>
          <?php foreach ($auditRows as $r): ?>
            <tr>
              <td><?= intval($r['id']) ?></td>
              <td><?= htmlspecialchars($r['created_at']) ?></td>
              <td><?= htmlspecialchars($r['username'] ?? 'system') ?> (<?= htmlspecialchars($r['user_id']) ?>)</td>
              <td><?= htmlspecialchars($r['action']) ?></td>
              <td>
                <?php
                $det = $r['details'] ?? null;
                if ($det === null || $det === '') {
                  echo '<span class="small">—</span>';
                } else {
                  $pretty = null;
                  if (is_string($det)) {
                    $decoded = json_decode($det, true);
                    if (json_last_error() === JSON_ERROR_NONE)
                      $pretty = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                  }
                  if ($pretty === null)
                    $pretty = (string) $det;
                  echo '<div class="details">' . htmlspecialchars($pretty) . '</div>';
                }
                ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <div class="pager">
    <?php if ($page > 1): ?>
      <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">← Предыдущая</a>
    <?php endif; ?>
    <span> страница <?= $page ?> / <?= $totalPages ?> </span>
    <?php if ($page < $totalPages): ?>
      <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">Следующая →</a>
    <?php endif; ?>
  </div>

</body>

</html>