<?php
session_start();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit;
}
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
  header('Location: /gradeapp/login.php');
  exit;
}
if (empty($_POST['csrf']) || $_POST['csrf'] !== ($_SESSION['csrf'] ?? '')) {
  die("CSRF validation failed");
}
$config = require __DIR__ . '/config.php';
$uploadDir = rtrim($config['paths']['secure_imports'], DIRECTORY_SEPARATOR);

if (!isset($_FILES['csvfile']) || $_FILES['csvfile']['error'] !== UPLOAD_ERR_OK) {
  die("Файл не загружен или ошибка загрузки.");
}
$file = $_FILES['csvfile'];

$maxSize = 8 * 1024 * 1024;
if ($file['size'] > $maxSize)
  die("Файл слишком большой");

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);
$allowed = ['text/plain', 'text/csv', 'application/vnd.ms-excel', 'text/comma-separated-values'];
if (!in_array($mime, $allowed)) {
  $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
  if ($ext !== 'csv')
    die("Неверный тип файла: $mime");
}

if (!is_dir($uploadDir)) {
  if (!mkdir($uploadDir, 0700, true))
    die("Не удалось создать временную папку для импорта");
}

$token = bin2hex(random_bytes(12));
$dest = $uploadDir . DIRECTORY_SEPARATOR . "import_{$token}.csv";
if (!move_uploaded_file($file['tmp_name'], $dest)) {
  die("Не удалось сохранить файл для обработки");
}

$maxPreview = 300;
$header = null;
$rows = [];
if (($h = fopen($dest, 'r')) === false) {
  @unlink($dest);
  die("Не удалось открыть загруженный файл");
}
$lineNo = 0;
while (($data = fgetcsv($h, 0, ",")) !== false) {
  $lineNo++;
  if ($lineNo === 1) {
    $header = array_map(function ($v) {
      return trim($v); }, $data);
    continue;
  }
  if (empty($header))
    continue;
  $assoc = [];
  foreach ($header as $i => $col) {
    $assoc[$col] = isset($data[$i]) ? trim($data[$i]) : '';
  }
  $assoc['_has_password'] = ($assoc['password'] ?? '') !== '';
  unset($assoc['password']);
  $rows[] = $assoc;
  if (count($rows) >= $maxPreview)
    break;
}
fclose($h);

$_SESSION['csv_import'] = [
  'file' => $dest,
  'header' => $header,
  'preview' => $rows,
  'uploader_id' => $_SESSION['user']['id'],
  'created_at' => date('c'),
];

?>
<!doctype html>
<html>

<head>
  <meta charset="utf-8">
  <title>CSV Preview</title>
</head>

<body>
  <h2>Preview (первые <?= count($rows) ?> строк)</h2>
  <p>Файл: <?= htmlspecialchars(basename($dest)) ?></p>
  <table border="1" cellpadding="4">
    <tr>
      <?php foreach ($header as $h): ?>
        <th><?= htmlspecialchars($h) ?></th><?php endforeach; ?>
      <th>Пароль?</th>
    </tr>
    <?php foreach ($rows as $r): ?>
      <tr>
        <?php foreach ($header as $h): ?>
          <td><?= htmlspecialchars($r[$h] ?? '') ?></td>
        <?php endforeach; ?>
        <td><?= $r['_has_password'] ? 'yes' : 'no' ?></td>
      </tr>
    <?php endforeach; ?>
  </table>

  <form method="post" action="commit_import.php" style="margin-top:20px;">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
    <button name="action" value="commit" type="submit">Импортировать</button>
    <button name="action" value="cancel" type="submit">Отменить и удалить файл</button>
  </form>
  <p><a href="/gradeapp/admin_upload.php">Вернуться назад</a></p>
</body>

</html>