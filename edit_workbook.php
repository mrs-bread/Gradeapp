<?php
session_start();
if (empty($_SESSION['user'])) {
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


require_once __DIR__ . '/inc/audit.php';
if (empty($_SESSION['csrf']))
  $_SESSION['csrf'] = bin2hex(random_bytes(16));

$user = $_SESSION['user'];
$id = intval($_GET['id'] ?? 0);
if ($id <= 0) {
  die("Invalid id");
}

$stmt = $pdo->prepare("SELECT * FROM workbooks WHERE id = ?");
$stmt->execute([$id]);
$wb = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$wb)
  die("Workbook not found");

if (!($user['role'] === 'admin' || $user['id'] == $wb['owner_id'])) {
  header('Location: /gradeapp/view_workbook.php?id=' . $id);
  exit;
}

$err = '';
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_sheet') {
  if (!($user['role'] === 'admin' || ($user['role'] === 'teacher' && intval($user['id']) === intval($wb['owner_id'])))) {
    $err = 'Access denied';
  } else {
    if (empty($_POST['csrf']) || $_POST['csrf'] !== $_SESSION['csrf']) {
      $err = 'CSRF validation failed';
    } else {
      $name = trim($_POST['sheet_name'] ?? '');
      if ($name === '') {
        $err = 'Введите название листа';
      } else {
        try {
          $q = $pdo->prepare("SELECT COALESCE(MAX(order_index), -1) + 1 AS next_idx FROM sheets WHERE workbook_id = ?");
          $q->execute([$id]);
          $next = $q->fetch(PDO::FETCH_ASSOC);
          $nextIdx = intval($next['next_idx'] ?? 0);
          $defaultContent = json_encode(['header' => ['A'], 'rows' => []], JSON_UNESCAPED_UNICODE);
          $ins = $pdo->prepare("INSERT INTO sheets (workbook_id, name, order_index, content) VALUES (?,?,?,?)");
          $ins->execute([$id, $name, $nextIdx, $defaultContent]);
          $newId = $pdo->lastInsertId();
          if (function_exists('audit_log')) {
            audit_log($pdo, $_SESSION['user']['id'], 'create_sheet', [
              'workbook_id' => intval($id),
              'sheet_id' => intval($newId),
              'sheet_name' => (string) $name
            ]);
          }
          header('Location: /gradeapp/edit_workbook.php?id=' . $id . '&sheet=' . intval($newId));
          exit;
        } catch (PDOException $e) {
          $err = 'DB error while creating sheet: ' . $e->getMessage();
        }
      }
    }
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_sheet') {
  if (!($user['role'] === 'admin' || ($user['role'] === 'teacher' && intval($user['id']) === intval($wb['owner_id'])))) {
    $err = 'Access denied';
  } else {
    if (empty($_POST['csrf']) || $_POST['csrf'] !== $_SESSION['csrf']) {
      $err = 'CSRF validation failed';
    } else {
      $delId = intval($_POST['sheet_id'] ?? 0);
      if ($delId <= 0) {
        $err = 'Invalid sheet id';
      } else {
        $check = $pdo->prepare("SELECT id FROM sheets WHERE id = ? AND workbook_id = ?");
        $check->execute([$delId, $id]);
        if (!$check->fetch()) {
          $err = 'Sheet not found or does not belong to this workbook';
        } else {
          $countStmt = $pdo->prepare("SELECT COUNT(*) FROM sheets WHERE workbook_id = ?");
          $countStmt->execute([$id]);
          $count = intval($countStmt->fetchColumn() ?? 0);
          if ($count <= 1) {
            $err = 'Нельзя удалить единственный лист в книге';
          } else {
            try {
              $del = $pdo->prepare("DELETE FROM sheets WHERE id = ?");
              $del->execute([$delId]);
              if (function_exists('audit_log')) {
                audit_log($pdo, $_SESSION['user']['id'], 'delete_sheet', [
                  'workbook_id' => intval($id),
                  'sheet_id' => intval($delId)
                ]);
              }
              header('Location: /gradeapp/edit_workbook.php?id=' . $id);
              exit;
            } catch (PDOException $e) {
              $err = 'DB error while deleting sheet: ' . $e->getMessage();
            }
          }
        }
      }
    }
  }
}

if (empty($wb['locked_by']) || intval($wb['locked_by']) === intval($user['id'])) {
  $stmt = $pdo->prepare("UPDATE workbooks SET locked_by = ?, locked_at = NOW() WHERE id = ?");
  $stmt->execute([$user['id'], $id]);
  if (function_exists('audit_log')) {
    audit_log($pdo, $_SESSION['user']['id'], 'acquire_lock_workbook', [
      'workbook_id' => intval($id),
      'locked_by' => intval($user['id'])
    ]);
  }
}

$stmt = $pdo->prepare("SELECT * FROM workbooks WHERE id = ?");
$stmt->execute([$id]);
$wb = $stmt->fetch(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT * FROM sheets WHERE workbook_id = ? ORDER BY order_index");
$stmt->execute([$id]);
$sheets = $stmt->fetchAll(PDO::FETCH_ASSOC);

$sheet_id = intval($_GET['sheet'] ?? ($sheets[0]['id'] ?? 0));
$sheet = null;
foreach ($sheets as $s)
  if (intval($s['id']) === $sheet_id) {
    $sheet = $s;
    break;
  }
if (!$sheet && !empty($sheets)) {
  $sheet = $sheets[0];
  $sheet_id = $sheet['id'];
}
$content = $sheet['content'] ? json_decode($sheet['content'], true) : ['header' => [], 'rows' => []];

$readonly = ($wb['locked_by'] && intval($wb['locked_by']) !== intval($user['id'])) && $user['role'] !== 'admin';
if (!isset($content['header']) || !is_array($content['header']))
  $content['header'] = [];
if (!isset($content['rows']) || !is_array($content['rows']))
  $content['rows'] = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_sheet_settings') {
  if (!($user['role'] === 'admin' || ($user['role'] === 'teacher' && intval($user['id']) === intval($wb['owner_id'])))) {
    $err = 'Access denied';
  } else {
    $mode = in_array($_POST['row_visibility_mode'] ?? 'all_rows', ['all_rows', 'own_row']) ? $_POST['row_visibility_mode'] : 'all_rows';
    $col = trim($_POST['student_id_column'] ?? '');
    try {
      $upd = $pdo->prepare("UPDATE sheets SET row_visibility_mode = ?, student_id_column = ? WHERE id = ?");
      $upd->execute([$mode, $col === '' ? null : $col, $sheet_id]);
      if (function_exists('audit_log')) {
        audit_log($pdo, $_SESSION['user']['id'], 'update_sheet_settings', [
          'workbook_id' => intval($id),
          'sheet_id' => intval($sheet_id),
          'row_visibility_mode' => $mode,
          'student_id_column' => ($col === '' ? null : (string) $col)
        ]);
      }
      $msg = 'Settings saved';
      $stmt = $pdo->prepare("SELECT * FROM sheets WHERE id = ?");
      $stmt->execute([$sheet_id]);
      $sheet = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
      $m = $e->getMessage();
      if (strpos($m, 'Unknown column') !== false || strpos($m, '42S22') !== false) {
        try {
          $pdo->exec("ALTER TABLE sheets ADD COLUMN IF NOT EXISTS row_visibility_mode VARCHAR(32) DEFAULT 'all_rows'");
          $pdo->exec("ALTER TABLE sheets ADD COLUMN IF NOT EXISTS student_id_column VARCHAR(64) DEFAULT NULL");
          $upd = $pdo->prepare("UPDATE sheets SET row_visibility_mode = ?, student_id_column = ? WHERE id = ?");
          $upd->execute([$mode, $col === '' ? null : $col, $sheet_id]);
          if (function_exists('audit_log')) {
            audit_log($pdo, $_SESSION['user']['id'], 'update_sheet_settings_with_schema_fix', [
              'workbook_id' => intval($id),
              'sheet_id' => intval($sheet_id),
              'row_visibility_mode' => $mode,
              'student_id_column' => ($col === '' ? null : (string) $col),
              'schema_fixed' => true
            ]);
          }
          $msg = 'Columns added and settings saved';
          $stmt = $pdo->prepare("SELECT * FROM sheets WHERE id = ?");
          $stmt->execute([$sheet_id]);
          $sheet = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e2) {
          $err = 'DB error while adding columns: ' . $e2->getMessage();
        }
      } else {
        $err = 'DB error: ' . $e->getMessage();
      }
    }
  }
}

$sheet_row_mode = $sheet['row_visibility_mode'] ?? 'all_rows';
$sheet_student_col = $sheet['student_id_column'] ?? '';

$current_workbook_id = $wb['id'];
$current_workbook_owner_id = $wb['owner_id'];
$current_workbook_title = $wb['title'];
include __DIR__ . '/inc/navbar.php';

?><!doctype html>
<html>

<head>
  <meta charset="utf-8">
  <title>Edit: <?= htmlspecialchars($wb['title']) ?></title>
  <style>
    body {
      font-family: Arial, Helvetica, sans-serif;
      padding: 12px
    }

    table {
      border-collapse: collapse;
      margin-top: 8px
    }

    td,
    th {
      border: 1px solid #999;
      padding: 6px;
      min-width: 80px
    }

    input.cell {
      width: 100%;
      box-sizing: border-box;
      padding: 4px;
      border: 0;
      background: transparent
    }

    input.cell:focus {
      outline: 2px solid #74b0ff;
      background: #fff
    }

    .controls {
      margin-top: 8px
    }

    .sheet-settings {
      border: 1px solid #ddd;
      padding: 10px;
      margin-top: 12px;
      border-radius: 6px;
      background: #f9f9f9
    }

    .msg {
      color: green
    }

    .err {
      color: red
    }

    input.cell.selected-cell {
      background: #dbeeff !important;
    }

    input.cell.selected-cell:focus {
      background: #c7e4ff !important;
    }

    .sheet-list {
      margin-top: 8px;
    }

    .sheet-item {
      margin-right: 8px;
    }

    .toolbar {
      display: inline-block;
      margin-left: 12px;
      vertical-align: middle
    }

    .btn-mini {
      padding: 4px 8px;
      margin-right: 6px;
    }

    .format-toolbar {
      display: inline-block;
      margin-left: 12px;
      vertical-align: middle;
      margin-top: 8px;
    }

    .format-toolbar button {
      padding: 4px 8px;
      margin-right: 6px;
    }

    .modal-overlay {
      position: fixed;
      left: 0;
      top: 0;
      right: 0;
      bottom: 0;
      background: rgba(0, 0, 0, 0.35);
      display: none;
      align-items: center;
      justify-content: center;
      z-index: 9999;
    }

    .modal-box {
      background: #fff;
      padding: 14px;
      border-radius: 6px;
      min-width: 320px;
      box-shadow: 0 6px 24px rgba(0, 0, 0, 0.25);
    }
  </style>
</head>

<body>
  <h2>Редактирование: <?= htmlspecialchars($wb['title']) ?></h2>
  <p><a href="/gradeapp/manage_workbooks.php">Назад</a> | ID Книги: <?= $wb['id'] ?> | Заблокирована:
    <?= htmlspecialchars($wb['locked_by'] ?: 'none') ?></p>

  <div>
    <strong>Листы:</strong>
    <span class="sheet-list">
      <?php foreach ($sheets as $s): ?>
        <span class="sheet-item" style="margin-right:10px;">
          <a href="/gradeapp/edit_workbook.php?id=<?= $wb['id'] ?>&sheet=<?= $s['id'] ?>"
            <?= $s['id'] == $sheet_id ? 'style="font-weight:bold"' : '' ?>>
            <?= htmlspecialchars($s['name']) ?>
          </a>

          <?php if (!$readonly && ($_SESSION['user']['role'] === 'admin' || ($_SESSION['user']['role'] === 'teacher' && intval($_SESSION['user']['id']) === intval($wb['owner_id'])))): ?>
            <form method="post" action="" style="display:inline"
              onsubmit="return confirm('Удалить лист «<?= htmlspecialchars($s['name']) ?>»? Это действие необратимо.');">
              <input type="hidden" name="action" value="delete_sheet">
              <input type="hidden" name="sheet_id" value="<?= intval($s['id']) ?>">
              <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
              <button type="submit" style="margin-left:6px;">Удалить</button>
            </form>
          <?php endif; ?>
        </span>
      <?php endforeach; ?>
    </span>

    <?php if (!($readonly)): ?>
      <form method="post" action="" style="display:inline-block; margin-left:12px;">
        <input type="hidden" name="action" value="create_sheet">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
        <label>Имя нового листа: <input name="sheet_name" required></label>
        <button type="submit">Добавить лист</button>
      </form>
    <?php endif; ?>

    <div class="toolbar">
      <button class="btn-mini" id="copyBtn" title="Copy (Ctrl+C)" src="">Копировать</button>
      <button class="btn-mini" id="pasteBtn" title="Paste (Ctrl+V)">Вставить</button>
      <button class="btn-mini" id="cutBtn" title="Cut (Ctrl+X)">Вырезать</button>
      <button class="btn-mini" id="fillDownBtn" title="Fill down">Заполнить вниз</button>
      <button class="btn-mini" id="undoBtn" title="Undo (Ctrl+Z)">Назад</button>
      <button class="btn-mini" id="redoBtn" title="Redo (Ctrl+Y)">Вперёд</button>
    </div>

    <div class="format-toolbar" role="toolbar" aria-label="Formatting">
      <label title="Background">
        <input type="color" id="bgColorPicker" value="#ffffff" style="vertical-align:middle">
      </label>
      <button id="setBgBtn" title="Set background">Установить фон</button>
      <button id="clearBgBtn" title="Clear background">Очистить фон</button>

      <button id="boldBtn" title="Bold">Полужирный</button>
      <button id="italicBtn" title="Italic">Курсив</button>

      <button id="alignLeftBtn" title="Align left">←</button>
      <button id="alignCenterBtn" title="Align center">↔</button>
      <button id="alignRightBtn" title="Align right">→</button>
    </div>

  </div>

  <?php if ($readonly): ?>
    <div style="color:red;margin-top:10px;">Книга в данный момент редактируется другим преподавателем. Вы в режиме
      просмотра.</div>
  <?php endif; ?>

  <?php if ($err): ?>
    <div class="err"><?= htmlspecialchars($err) ?></div><?php endif; ?>
  <?php if ($msg): ?>
    <div class="msg"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

  <div class="sheet-settings">
    <h3>Настройка листа (видимость)</h3>
    <p>Выбери кто сможет видеть этот лист. <strong>Персональный просмотр</strong> режим в котром студент видит только
      свою строку из таблицы.</p>
    <form method="post" action="">
      <input type="hidden" name="action" value="save_sheet_settings">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
      <label>Режим видимости:
        <select name="row_visibility_mode">
          <option value="all_rows" <?= $sheet_row_mode === 'all_rows' ? 'selected' : '' ?>>Все строки (базовый)</option>
          <option value="own_row" <?= $sheet_row_mode === 'own_row' ? 'selected' : '' ?>>Персональный просмотр (студент
            видит только свои строки)</option>
        </select>
      </label>
      <div style="margin-top:8px;">
        <label>Столбец идентифицирования студента (название заголовка, или &quot;login&quot; или &quot;ФИО&quot;):
          <input type="text" name="student_id_column" value="<?= htmlspecialchars($sheet_student_col) ?>"
            placeholder="Оставить пустым для автоматичского нахождения">
        </label>
      </div>
      <div style="margin-top:8px;">
        <button type="submit" <?= $readonly ? 'disabled' : '' ?>>Сохранить настройки листа</button>
      </div>
    </form>
  </div>

  <div style="margin-top:10px;">
    <label>Строка формулы: <input id="formulaInput" style="width:60%"></label>
    <button id="applyFormulaBtn">Применить</button>
  </div>

  <div class="controls">
    <button id="addRowBtn" <?= $readonly ? 'disabled' : '' ?>>Добавить строку</button>
    <button id="addColBtn" <?= $readonly ? 'disabled' : '' ?>>Добавить столбец</button>
    <button id="saveBtn" <?= $readonly ? 'disabled' : '' ?>>Сохранить</button>

    <button id="insertStudentsBtn" <?= $readonly ? 'disabled' : '' ?>>Вставить список студентов</button>

    <?php if ($_SESSION['user']['role'] === 'admin'): ?>
      &nbsp;<a href="/gradeapp/unlock_workbook.php?id=<?= $wb['id'] ?>"
        onclick="return confirm('Force unlock?')">Разблокировать</a>
    <?php endif; ?>
  </div>

  <div id="editor" style="margin-top:12px;">
    <table id="grid"></table>
  </div>

  <div id="insertStudentsModal" class="modal-overlay" role="dialog" aria-hidden="true">
    <div class="modal-box">
      <h3>Вставить студентов из группы</h3>
      <p>Группа рабочей книги: <strong><?= htmlspecialchars($wb['group_id'] ?? 'N/A') ?></strong></p>
      <p>Начиная с клетки: <span id="selectedCellPreview">A1</span></p>
      <label>Сортировать:
        <select id="studentsOrder">
          <option value="asc">A → Я</option>
          <option value="desc">Я → A</option>
        </select>
      </label>
      <div style="margin-top:10px;">
        <button id="doInsertStudentsBtn">Вставить</button>
        <button id="cancelInsertStudentsBtn">Отмена</button>
      </div>
      <div id="insertStudentsMsg" style="margin-top:8px;color:green"></div>
    </div>
  </div>

  <script>

    let content = <?= json_encode($content, JSON_UNESCAPED_UNICODE) ?>;
    if (!content.header) content.header = [];
    if (!content.rows) content.rows = [];
    const readonly = <?= $readonly ? 'true' : 'false' ?>;
    let selectedCell = null;
    let selection = null;
    let jsClipboard = null;
    let preserveFocus = null;

    const undoStack = [];
    const redoStack = [];
    const MAX_STACK = 200;

    let cellEditOriginalSnapshot = null;

    let isPointerSelecting = false;
    let pointerAnchor = null;
    let activePointerId = null;

    let lastPointerDown = { shift: false, time: 0 };

    function pushStateForUndoSnapshot() {
      try {
        const snap = JSON.parse(JSON.stringify(content));
        undoStack.push(snap);
        if (undoStack.length > MAX_STACK) undoStack.shift();
        redoStack.length = 0;
      } catch (e) { }
    }

    function undo() {
      if (!undoStack.length) { alert('Nothing to undo'); return; }
      try {
        redoStack.push(JSON.parse(JSON.stringify(content)));
        content = undoStack.pop();
        render();
      } catch (e) { alert('Undo failed: ' + e.message); }
    }

    function redo() {
      if (!redoStack.length) { alert('Nothing to redo'); return; }
      try {
        undoStack.push(JSON.parse(JSON.stringify(content)));
        content = redoStack.pop();
        render();
      } catch (e) { alert('Redo failed: ' + e.message); }
    }

    function colLetter(n) { let s = ''; while (n > 0) { let m = (n - 1) % 26; s = String.fromCharCode(65 + m) + s; n = Math.floor((n - 1) / 26); } return s; }
    function colToIndex(letter) {
      if (!letter) return 0;
      let index = 0;
      for (let i = 0; i < letter.length; i++) {
        index = index * 26 + (letter.charCodeAt(i) - 64);
      }
      return index - 1;
    }
    function indexToCol(idx) {
      idx = idx + 1;
      let s = '';
      while (idx > 0) { let m = (idx - 1) % 26; s = String.fromCharCode(65 + m) + s; idx = Math.floor((idx - 1) / 26); }
      return s;
    }

    function ensureRect() {
      let cols = content.header.length || 0;
      for (let r = 0; r < content.rows.length; r++) {
        const keys = Object.keys(content.rows[r] || {});
        if (keys.length > cols) cols = keys.length;
      }
      if (cols <= 0) cols = 1;
      for (let c = 0; c < cols; c++) { if (!content.header[c]) content.header[c] = colLetter(c + 1); }
      for (let r = 0; r < Math.max(1, content.rows.length); r++) {
        if (!content.rows[r]) content.rows[r] = {};
        for (let c = 0; c < cols; c++) {
          const col = colLetter(c + 1);
          if (!content.rows[r][col]) content.rows[r][col] = { value: '', formula: '', format: null };
          if (!('format' in content.rows[r][col])) content.rows[r][col].format = (content.rows[r][col].format || null);
        }
      }
    }

    function inSelection(r, cIdx) {
      if (!selection) return false;
      return r >= selection.r1 && r <= selection.r2 && cIdx >= selection.c1 && cIdx <= selection.c2;
    }
    function setSelectionSingle(r, cIdx, focusDom) {
      selection = { r1: r, r2: r, c1: cIdx, c2: cIdx };
      if (focusDom) { selectedCell = focusDom; try { selectedCell.focus() } catch (e) { } }
      applySelectionStyles();
    }
    function setSelectionFromCoords(r1, c1, r2, c2) {
      selection = {
        r1: Math.min(r1, r2),
        r2: Math.max(r1, r2),
        c1: Math.min(c1, c2),
        c2: Math.max(c1, c2)
      };
      applySelectionStyles();
    }
    function clearSelection() { selection = null; applySelectionStyles(); }

    function applySelectionStyles() {
      document.querySelectorAll('input.cell').forEach(inp => {
        const rr = parseInt(inp.dataset.r, 10);
        const cc = colToIndex(inp.dataset.c);
        if (inSelection(rr, cc)) inp.classList.add('selected-cell'); else inp.classList.remove('selected-cell');
      });
    }

    function applyFormatToDomInput(inpEl, format) {
      if (!format) {
        inpEl.style.backgroundColor = '';
        inpEl.style.fontWeight = '';
        inpEl.style.fontStyle = '';
        inpEl.style.textAlign = '';
        return;
      }
      if (format.bg) inpEl.style.backgroundColor = format.bg; else inpEl.style.backgroundColor = '';
      inpEl.style.fontWeight = format.bold ? 'bold' : '';
      inpEl.style.fontStyle = format.italic ? 'italic' : '';
      inpEl.style.textAlign = format.align || '';
    }

    function render() {
      ensureRect();
      let focusCoords = null;
      const active = document.activeElement;
      if (active && active.classList && active.classList.contains('cell')) {
        focusCoords = { r: parseInt(active.dataset.r, 10), c: active.dataset.c };
      } else if (preserveFocus) {
        focusCoords = preserveFocus;
      }

      const tbl = document.getElementById('grid'); tbl.innerHTML = '';
      const cols = content.header.length;
      const rows = content.rows.length;

      let tr = document.createElement('tr');
      tr.appendChild(document.createElement('th'));
      for (let c = 0; c < cols; c++) { let th = document.createElement('th'); th.textContent = content.header[c] || colLetter(c + 1); tr.appendChild(th); }
      tbl.appendChild(tr);

      for (let r = 0; r < rows; r++) {
        let tr = document.createElement('tr');
        let rn = document.createElement('th'); rn.textContent = r + 1; tr.appendChild(rn);
        for (let c = 0; c < cols; c++) {
          let col = colLetter(c + 1);
          let cell = content.rows[r][col] || { value: '', formula: '', format: null };
          let td = document.createElement('td');

          let inp = document.createElement('input');
          inp.className = 'cell';
          inp.dataset.r = r; inp.dataset.c = col;
          if (!('format' in cell)) cell.format = cell.format || null;
          const cellVal = (cell.value !== null && cell.value !== undefined && cell.value !== '') ? cell.value : '';
          const display = cellVal !== '' ? cellVal : (cell.formula && typeof cell.formula === 'string' && cell.formula.startsWith('=') ? cell.formula : '');
          inp.value = display;
          inp.readOnly = readonly;

          applyFormatToDomInput(inp, cell.format);

          (function (rr, cc, inpRef) {
            inpRef.addEventListener('focus', (ev) => {
              const cur = content.rows[rr][cc] || { value: '', formula: '', format: null };
              const formulaText = (cur.formula && typeof cur.formula === 'string' && cur.formula.startsWith('=')) ? cur.formula : (cur.value ?? '');
              document.getElementById('formulaInput').value = formulaText;
              selectedCell = inpRef;

              try { cellEditOriginalSnapshot = JSON.stringify(content); } catch (e) { cellEditOriginalSnapshot = null; }

              if (Date.now() - lastPointerDown.time < 250 && lastPointerDown.shift) {
                lastPointerDown.shift = false;
                lastPointerDown.time = 0;
                return;
              }

              if (!ev.shiftKey) setSelectionSingle(rr, colToIndex(cc), inpRef);
            });

            inpRef.addEventListener('blur', (ev) => {
              try {
                const nowSnap = JSON.stringify(content);
                if (cellEditOriginalSnapshot && cellEditOriginalSnapshot !== nowSnap) {
                  undoStack.push(JSON.parse(cellEditOriginalSnapshot));
                  if (undoStack.length > MAX_STACK) undoStack.shift();
                  redoStack.length = 0;
                }
              } catch (e) { }
              cellEditOriginalSnapshot = null;
            });

            inpRef.addEventListener('pointerdown', (ev) => {
              if (ev.button !== 0) return;
              ev.preventDefault();
              lastPointerDown.shift = !!ev.shiftKey;
              lastPointerDown.time = Date.now();

              const activeEl = document.activeElement;
              if (ev.shiftKey) {
                let anchorR, anchorC;
                if (selectedCell && selectedCell.dataset) {
                  anchorR = parseInt(selectedCell.dataset.r, 10);
                  anchorC = colToIndex(selectedCell.dataset.c);
                } else if (activeEl && activeEl.classList && activeEl.classList.contains('cell')) {
                  anchorR = parseInt(activeEl.dataset.r, 10);
                  anchorC = colToIndex(activeEl.dataset.c);
                } else if (selection) {
                  anchorR = selection.r1; anchorC = selection.c1;
                } else {
                  anchorR = rr; anchorC = colToIndex(cc);
                }
                setSelectionFromCoords(anchorR, anchorC, rr, colToIndex(cc));
                try { inpRef.focus() } catch (e) { }
                isPointerSelecting = false;
                pointerAnchor = null;
                activePointerId = null;
              } else if (ev.ctrlKey || ev.metaKey) {
                setSelectionSingle(rr, colToIndex(cc), inpRef);
                isPointerSelecting = false;
                pointerAnchor = null;
                activePointerId = null;
                try { inpRef.focus() } catch (e) { }
              } else {
                setSelectionSingle(rr, colToIndex(cc), inpRef);
                isPointerSelecting = true;
                pointerAnchor = { r: rr, c: colToIndex(cc) };
                activePointerId = ev.pointerId;
                try { inpRef.setPointerCapture && inpRef.setPointerCapture(ev.pointerId); } catch (e) { }
                try { inpRef.focus() } catch (e) { }
              }
            });

            inpRef.addEventListener('input', () => {
              const v = inpRef.value;
              if (!content.rows[rr]) content.rows[rr] = {};
              if (!content.rows[rr][cc]) content.rows[rr][cc] = { value: '', formula: '', format: null };
              if (typeof v === 'string' && v.startsWith('=')) {
                content.rows[rr][cc].formula = v;
                content.rows[rr][cc].value = '';
              } else {
                content.rows[rr][cc].value = v;
                content.rows[rr][cc].formula = '';
              }
            });

            inpRef.addEventListener('keydown', (ev) => {
              if (ev.key === 'ArrowLeft' || (ev.key === 'Tab' && ev.shiftKey)) {
                ev.preventDefault(); moveFocus(rr, colToIndex(cc) - 1); return;
              }
              if (ev.key === 'ArrowRight' || (ev.key === 'Tab' && !ev.shiftKey)) {
                ev.preventDefault(); moveFocus(rr, colToIndex(cc) + 1); return;
              }
              if (ev.key === 'ArrowUp') {
                ev.preventDefault(); moveFocus(rr - 1, colToIndex(cc)); return;
              }
              if (ev.key === 'ArrowDown' || ev.key === 'Enter') {
                ev.preventDefault(); moveFocus(rr + 1, colToIndex(cc)); return;
              }
            });

          })(r, col, inp);

          td.appendChild(inp);
          tr.appendChild(td);
        }
        tbl.appendChild(tr);
      }

      if (focusCoords) {
        const selector = 'input.cell[data-r="' + focusCoords.r + '"][data-c="' + focusCoords.c + '"]';
        const el = document.querySelector(selector);
        if (el) { try { el.focus(); } catch (e) { } selectedCell = el; }
      }

      applySelectionStyles();
    }

    document.addEventListener('pointermove', function (e) {
      if (!isPointerSelecting) return;
      if (activePointerId != null && e.pointerId !== activePointerId) return;
      const el = document.elementFromPoint(e.clientX, e.clientY);
      if (!el) return;
      const cellEl = el.closest ? el.closest('input.cell') : (el.classList && el.classList.contains('cell') ? el : null);
      if (!cellEl) return;
      const rr = parseInt(cellEl.dataset.r, 10);
      const cc = colToIndex(cellEl.dataset.c);
      setSelectionFromCoords(pointerAnchor.r, pointerAnchor.c, rr, cc);
    });

    document.addEventListener('pointerup', function (e) {
      if (activePointerId != null && e.pointerId !== activePointerId) return;
      if (isPointerSelecting) {
        isPointerSelecting = false;
        pointerAnchor = null;
        activePointerId = null;
      }
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { clearSelection(); }
    });

    function moveFocus(r, cIdx) {
      if (r < 0) r = 0;
      if (cIdx < 0) cIdx = 0;
      if (r >= content.rows.length) { while (content.rows.length <= r) content.rows.push({}); }
      if (cIdx >= content.header.length) { while (content.header.length <= cIdx) content.header.push(colLetter(content.header.length + 1)); ensureRect(); }
      render();
      const sel = document.querySelector('input.cell[data-r="' + r + '"][data-c="' + indexToCol(cIdx) + '"]');
      if (sel) { sel.focus(); setSelectionSingle(r, cIdx, sel); }
    }

    function getSelectionBounds() {
      if (!selection) {
        if (selectedCell && selectedCell.dataset) {
          const r = parseInt(selectedCell.dataset.r, 10);
          const c = colToIndex(selectedCell.dataset.c);
          return { r1: r, r2: r, c1: c, c2: c };
        } else return null;
      }
      return selection;
    }

    function copySelectionToJSClipboard() {
      const b = getSelectionBounds();
      if (!b) return false;
      const rows = [];
      for (let r = b.r1; r <= b.r2; r++) {
        const rowArr = [];
        for (let c = b.c1; c <= b.c2; c++) {
          const col = indexToCol(c);
          const cell = (content.rows[r] && content.rows[r][col]) ? content.rows[r][col] : { value: '', formula: '', format: null };
          rowArr.push({ value: cell.value ?? '', formula: cell.formula ?? '', format: (cell.format ? JSON.parse(JSON.stringify(cell.format)) : null) });
        }
        rows.push(rowArr);
      }
      jsClipboard = { rows: rows, height: rows.length, width: rows[0].length };
      try {
        const tsv = rows.map(r => r.map(cell => (cell.formula && typeof cell.formula === 'string' && cell.formula.startsWith('=')) ? cell.formula : (cell.value ?? '')).join('\t')).join('\n');
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(tsv).catch(() => {/* ignore */ });
        }
      } catch (e) { }
      return true;
    }

    function pasteFromJSClipboard() {
      const b = getSelectionBounds();
      if (!b) return false;
      if (!jsClipboard) return false;
      pushStateForUndoSnapshot();
      const startR = b.r1, startC = b.c1;
      for (let r = 0; r < jsClipboard.rows.length; r++) {
        for (let c = 0; c < jsClipboard.rows[r].length; c++) {
          const destR = startR + r;
          const destC = startC + c;
          const col = indexToCol(destC);
          if (!content.rows[destR]) content.rows[destR] = {};
          const srcCell = jsClipboard.rows[r][c] || { value: '', formula: '', format: null };
          content.rows[destR][col] = { value: srcCell.value, formula: srcCell.formula, format: (srcCell.format ? JSON.parse(JSON.stringify(srcCell.format)) : null) };
        }
      }
      render();
      return true;
    }

    function cutSelectionToJSClipboard() {
      const ok = copySelectionToJSClipboard();
      if (!ok) return false;
      pushStateForUndoSnapshot();
      const b = getSelectionBounds();
      for (let r = b.r1; r <= b.r2; r++) {
        for (let c = b.c1; c <= b.c2; c++) {
          const col = indexToCol(c);
          if (!content.rows[r]) content.rows[r] = {};
          content.rows[r][col] = { value: '', formula: '', format: null };
        }
      }
      render();
      return true;
    }

    function fillDownSelection() {
      const b = getSelectionBounds();
      if (!b) return;
      pushStateForUndoSnapshot();
      const top = content.rows[b.r1] && content.rows[b.r1][indexToCol(b.c1)] ? content.rows[b.r1][indexToCol(b.c1)] : { value: '', formula: '', format: null };
      for (let r = b.r1; r <= b.r2; r++) {
        for (let c = b.c1; c <= b.c2; c++) {
          const col = indexToCol(c);
          if (!content.rows[r]) content.rows[r] = {};
          content.rows[r][col] = { value: top.value, formula: top.formula, format: (top.format ? JSON.parse(JSON.stringify(top.format)) : null) };
        }
      }
      render();
    }

    function normalizeFormat(obj) {
      if (!obj) return null;
      const f = {};
      if (obj.bg) f.bg = obj.bg;
      if (obj.bold) f.bold = true;
      if (obj.italic) f.italic = true;
      if (obj.align) f.align = obj.align;
      return f;
    }

    function applyFormatToSelection(format) {
      const b = getSelectionBounds();
      if (!b) return;
      pushStateForUndoSnapshot();
      for (let r = b.r1; r <= b.r2; r++) {
        for (let c = b.c1; c <= b.c2; c++) {
          const col = indexToCol(c);
          if (!content.rows[r]) content.rows[r] = {};
          if (!content.rows[r][col]) content.rows[r][col] = { value: '', formula: '', format: null };
          const curFmt = content.rows[r][col].format || {};
          if ('bg' in format) { curFmt.bg = format.bg || null; if (!curFmt.bg) delete curFmt.bg; }
          if ('bold' in format) { if (format.bold) curFmt.bold = true; else delete curFmt.bold; }
          if ('italic' in format) { if (format.italic) curFmt.italic = true; else delete curFmt.italic; }
          if ('align' in format) { if (format.align) curFmt.align = format.align; else delete curFmt.align; }
          if (Object.keys(curFmt).length === 0) content.rows[r][col].format = null;
          else content.rows[r][col].format = curFmt;
        }
      }
      render();
    }

    document.getElementById('applyFormulaBtn').addEventListener('click', () => {
      if (!selectedCell) return alert('Выберите ячейку');
      const f = document.getElementById('formulaInput').value.trim();
      const r = parseInt(selectedCell.dataset.r, 10); const c = selectedCell.dataset.c;
      if (!content.rows[r]) content.rows[r] = {};
      if (!content.rows[r][c]) content.rows[r][c] = { value: '', formula: '', format: null };
      pushStateForUndoSnapshot();
      if (f.startsWith('=')) {
        content.rows[r][c].formula = f;
        content.rows[r][c].value = '';
      } else {
        content.rows[r][c].formula = '';
        content.rows[r][c].value = f;
      }
      render();
    });

    document.getElementById('addRowBtn').addEventListener('click', () => { if (readonly) return; pushStateForUndoSnapshot(); content.rows.push({}); render(); });
    document.getElementById('addColBtn').addEventListener('click', () => { if (readonly) return; pushStateForUndoSnapshot(); const n = content.header.length + 1; content.header.push(colLetter(n)); render(); });

    document.getElementById('saveBtn').addEventListener('click', () => saveAction());

    function saveAction() {
      if (readonly) return alert('Read-only');
      if (!confirm('Сохранить и пересчитать формулы?')) return;
      if (selectedCell && selectedCell.dataset) preserveFocus = { r: parseInt(selectedCell.dataset.r, 10), c: selectedCell.dataset.c };
      fetch('/gradeapp/save_sheet.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ workbook_id: <?= intval($wb['id']) ?>, sheet_id: <?= intval($sheet_id) ?>, content: content })
      }).then(r => r.json()).then(res => {
        if (res.error) return alert('Error: ' + res.error);
        if (res.content) content = res.content;
        render();
        alert('Saved and recalculated.');
      }).catch(e => { alert('Save failed: ' + e.message); });
    }

    document.getElementById('copyBtn').addEventListener('click', () => {
      if (!copySelectionToJSClipboard()) alert('Nothing selected to copy');
    });
    document.getElementById('pasteBtn').addEventListener('click', () => {
      if (!pasteFromJSClipboard()) alert('Clipboard empty or nothing selected');
    });
    document.getElementById('cutBtn').addEventListener('click', () => {
      if (!cutSelectionToJSClipboard()) alert('Nothing selected to cut');
    });
    document.getElementById('fillDownBtn').addEventListener('click', () => {
      fillDownSelection();
    });
    document.getElementById('undoBtn').addEventListener('click', () => undo());
    document.getElementById('redoBtn').addEventListener('click', () => redo());

    document.getElementById('setBgBtn').addEventListener('click', () => {
      const color = document.getElementById('bgColorPicker').value || '';
      applyFormatToSelection({ bg: color });
    });
    document.getElementById('clearBgBtn').addEventListener('click', () => {
      applyFormatToSelection({ bg: '' });
    });
    document.getElementById('boldBtn').addEventListener('click', () => {
      const b = getSelectionBounds();
      if (!b) return;
      let anyBold = false;
      for (let r = b.r1; r <= b.r2 && !anyBold; r++) {
        for (let c = b.c1; c <= b.c2; c++) {
          const col = indexToCol(c);
          const cell = content.rows[r] && content.rows[r][col] ? content.rows[r][col] : null;
          if (cell && cell.format && cell.format.bold) { anyBold = true; break; }
        }
      }
      applyFormatToSelection({ bold: !anyBold });
    });
    document.getElementById('italicBtn').addEventListener('click', () => {
      const b = getSelectionBounds();
      if (!b) return;
      let anyItalic = false;
      for (let r = b.r1; r <= b.r2 && !anyItalic; r++) {
        for (let c = b.c1; c <= b.c2; c++) {
          const col = indexToCol(c);
          const cell = content.rows[r] && content.rows[r][col] ? content.rows[r][col] : null;
          if (cell && cell.format && cell.format.italic) { anyItalic = true; break; }
        }
      }
      applyFormatToSelection({ italic: !anyItalic });
    });
    document.getElementById('alignLeftBtn').addEventListener('click', () => applyFormatToSelection({ align: 'left' }));
    document.getElementById('alignCenterBtn').addEventListener('click', () => applyFormatToSelection({ align: 'center' }));
    document.getElementById('alignRightBtn').addEventListener('click', () => applyFormatToSelection({ align: 'right' }));

    document.addEventListener('keydown', function (e) {
      const active = document.activeElement;
      const isCell = active && active.classList && active.classList.contains('cell');

      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'z') {
        e.preventDefault();
        undo();
        return;
      }
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'y') {
        e.preventDefault();
        redo();
        return;
      }

      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
        e.preventDefault();
        saveAction();
        return;
      }
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'c') {
        if (isCell || selection) { e.preventDefault(); copySelectionToJSClipboard(); }
        return;
      }
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'v') {
        if (isCell || selection) {
          e.preventDefault();
          if (navigator.clipboard && navigator.clipboard.readText) {
            navigator.clipboard.readText().then(text => {
              if (text && (text.indexOf('\t') !== -1 || text.indexOf('\n') !== -1)) {
                const rows = text.split(/\r?\n/).filter(r => r.length > 0).map(r => r.split('\t'));
                jsClipboard = { rows: rows.map(r => r.map(v => ({ value: v, formula: '', format: null }))), height: rows.length, width: rows[0].length };
                pasteFromJSClipboard();
              } else {
                if (!selection && isCell) setSelectionSingle(parseInt(active.dataset.r, 10), colToIndex(active.dataset.c), active);
                const b = getSelectionBounds();
                const startR = b.r1, startC = b.c1;
                if (!content.rows[startR]) content.rows[startR] = {};
                pushStateForUndoSnapshot();
                content.rows[startR][indexToCol(startC)] = { value: text, formula: '', format: null };
                render();
              }
            }).catch(() => { if (!pasteFromJSClipboard()) alert('Paste failed: no clipboard data'); });
          } else {
            if (!pasteFromJSClipboard()) alert('Paste failed: no clipboard data');
          }
        }
        return;
      }
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'x') {
        if (isCell || selection) { e.preventDefault(); cutSelectionToJSClipboard(); }
        return;
      }
    });

    const insertBtn = document.getElementById('insertStudentsBtn');
    const modal = document.getElementById('insertStudentsModal');
    const selectedCellPreview = document.getElementById('selectedCellPreview');
    const doInsertBtn = document.getElementById('doInsertStudentsBtn');
    const cancelInsertBtn = document.getElementById('cancelInsertStudentsBtn');
    const studentsOrder = document.getElementById('studentsOrder');
    const insertMsg = document.getElementById('insertStudentsMsg');

    function getSelectedPosition() {
      if (!selectedCell) return { r: 0, c: 'A' };
      return { r: parseInt(selectedCell.dataset.r, 10), c: selectedCell.dataset.c };
    }

    insertBtn.addEventListener('click', () => {
      const pos = getSelectedPosition();
      selectedCellPreview.textContent = pos.c + (pos.r + 1);
      insertMsg.textContent = '';
      modal.style.display = 'flex';
    });

    cancelInsertBtn.addEventListener('click', () => modal.style.display = 'none');

    doInsertBtn.addEventListener('click', () => {
      const pos = getSelectedPosition();
      const startRow = pos.r;
      const col = pos.c;
      const order = studentsOrder.value || 'asc';
      const groupId = <?= intval($wb['group_id'] ?? 0) ?>;
      if (!groupId) { insertMsg.style.color = 'red'; insertMsg.textContent = 'Group not set for workbook'; return; }

      doInsertBtn.disabled = true;
      insertMsg.style.color = 'black'; insertMsg.textContent = 'Loading...';

      fetch('/gradeapp/api/get_students_by_group.php?group_id=' + encodeURIComponent(groupId))
        .then(r => r.json())
        .then(data => {
          doInsertBtn.disabled = false;
          if (!data.ok) { insertMsg.style.color = 'red'; insertMsg.textContent = 'Error: ' + (data.error || 'unknown'); return; }
          let students = data.students || [];
          students = students.map(s => s.full_name && s.full_name.trim() !== '' ? s.full_name.trim() : s.username);
          students.sort((a, b) => a.localeCompare(b, undefined, { sensitivity: 'base' }));
          if (order === 'desc') students.reverse();
          const colIdx = colToIndex(col);

          pushStateForUndoSnapshot();

          while (content.rows.length < startRow + students.length) {
            content.rows.push({});
          }
          for (let i = 0; i < students.length; i++) {
            const r = startRow + i;
            const co = indexToCol(colIdx);
            if (!content.rows[r]) content.rows[r] = {};
            content.rows[r][co] = content.rows[r][co] || { value: '', formula: '', format: null };
            content.rows[r][co].value = students[i];
            content.rows[r][co].formula = '';
          }
          render();
          insertMsg.style.color = 'green'; insertMsg.textContent = 'Inserted ' + students.length + ' students.';
          setTimeout(() => modal.style.display = 'none', 700);
        }).catch(err => {
          doInsertBtn.disabled = false;
          insertMsg.style.color = 'red';
          insertMsg.textContent = 'Fetch failed: ' + err.message;
        });
    });

    render();
    let formulaEditMode = false;
    const formulaInputEl = document.getElementById('formulaInput');

    function setFormulaEditMode(on) {
      formulaEditMode = !!on;
      if (formulaEditMode) {
        formulaInputEl.style.outline = '2px solid #7fb1ff';
      } else {
        formulaInputEl.style.outline = '';
      }
    }

    function insertAtCursor(inputEl, text) {
      try {
        const start = inputEl.selectionStart ?? inputEl.value.length;
        const end = inputEl.selectionEnd ?? start;
        const v = inputEl.value;
        inputEl.value = v.slice(0, start) + text + v.slice(end);
        const pos = start + text.length;
        inputEl.setSelectionRange(pos, pos);
        inputEl.focus();
      } catch (e) {
        inputEl.value += text;
        inputEl.focus();
      }
    }

    function lastCellRefToken(str) {
      const m = str.match(/([A-Z]+[0-9]+)$/);
      return m ? m[1] : null;
    }


    formulaInputEl.addEventListener('focus', () => {
      if ((formulaInputEl.value || '').startsWith('=')) setFormulaEditMode(true);
      else {
        setFormulaEditMode(false);
      }
    });

    formulaInputEl.addEventListener('input', () => {
      if ((formulaInputEl.value || '').startsWith('=')) setFormulaEditMode(true);
    });

    formulaInputEl.addEventListener('blur', () => {
      setTimeout(() => setFormulaEditMode(false), 200);
    });

    document.addEventListener('pointerdown', function (ev) {
      if (!formulaEditMode) return;
      const el = ev.target;
      const cellEl = el.closest ? el.closest('input.cell') : (el.classList && el.classList.contains('cell') ? el : null);
      if (!cellEl) return;

      ev.stopPropagation();
      if (ev.stopImmediatePropagation) ev.stopImmediatePropagation();
      ev.preventDefault();

      const rr = parseInt(cellEl.dataset.r, 10);
      const cc = cellEl.dataset.c; // 'A', 'B', ...
      const ref = cc + (rr + 1); // A1 style

      const cur = formulaInputEl.value || '';
      const caretPos = formulaInputEl.selectionStart ?? cur.length;
      const before = cur.slice(0, caretPos);
      const after = cur.slice(caretPos);

      if (before.length > 0 && before[before.length - 1] === ':') {
        insertAtCursor(formulaInputEl, ref);
        cellEl.classList.add('selected-cell');
        setTimeout(() => cellEl.classList.remove('selected-cell'), 450);
        return;
      }

      const lastToken = lastCellRefToken(before);
      if (lastToken && ev.shiftKey) {
        insertAtCursor(formulaInputEl, ':' + ref);
        cellEl.classList.add('selected-cell');
        setTimeout(() => cellEl.classList.remove('selected-cell'), 450);
        return;
      }

      insertAtCursor(formulaInputEl, ref);
      cellEl.classList.add('selected-cell');
      setTimeout(() => cellEl.classList.remove('selected-cell'), 450);

    }, true);
  </script>
</body>

</html>