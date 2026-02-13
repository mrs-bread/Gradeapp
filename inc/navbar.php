<?php
if (session_status() === PHP_SESSION_NONE)
  session_start();

$user = $_SESSION['user'] ?? null;
$current_workbook_id = $current_workbook_id ?? (isset($_GET['id']) ? intval($_GET['id']) : null);
$current_workbook_owner_id = $current_workbook_owner_id ?? null;
$current_workbook_title = $current_workbook_title ?? null;

$config = file_exists(__DIR__ . '/../config.php') ? require __DIR__ . '/../config.php' : null;
$base = $config['paths']['base_url'] ?? '/gradeapp';

?>
<link rel="stylesheet" href="<?= htmlspecialchars($base) ?>/public/css/navbar.css">
<nav class="app-nav">
  <div class="nav-left">
    <a class="brand" href="<?= htmlspecialchars($base) ?>/">GradeApp</a>
    <a href="<?= htmlspecialchars($base) ?>/">Главная</a>
    <a href="<?= htmlspecialchars($base) ?>/manage_workbooks.php">Книги</a>
    <?php if ($user && $user['role'] === 'admin'): ?>
      <a href="<?= htmlspecialchars($base) ?>/admin_upload.php">Импорт студентов</a>
      <a href="<?= htmlspecialchars($base) ?>/admin_users.php">Панель администратора</a>
      <a href="<?= htmlspecialchars($base) ?>/admin/audit.php">Аудит и статистика</a>
    <?php endif; ?>
  </div>

  <div class="nav-right">
    <?php if ($current_workbook_id): ?>
      <span class="ctx">Книга:
        <?= htmlspecialchars($current_workbook_title ?? ("ID {$current_workbook_id}")) ?>
      </span>

      <?php
      $canEdit = false;
      if ($user) {
        if ($user['role'] === 'admin')
          $canEdit = true;
        if ($user['role'] === 'teacher' && $current_workbook_owner_id && intval($user['id']) === intval($current_workbook_owner_id))
          $canEdit = true;
      }
      ?>

      <?php if ($canEdit): ?>
        <a href="<?= htmlspecialchars($base) ?>/edit_workbook.php?id=<?= intval($current_workbook_id) ?>"
          class="btn">Редактировать</a>
        <button class="btn" id="openShareModalBtn" data-wid="<?= intval($current_workbook_id) ?>">Поделиться</button>
      <?php else: ?>
        <a href="<?= htmlspecialchars($base) ?>/view_workbook.php?id=<?= intval($current_workbook_id) ?>"
          class="btn">Просмотр</a>
      <?php endif; ?>

      <?php if ($user && $user['role'] === 'admin'): ?>
        <a href="<?= htmlspecialchars($base) ?>/unlock_workbook.php?id=<?= intval($current_workbook_id) ?>"
          class="btn danger" onclick="return confirm('Force unlock?')">Разблокировать</a>
      <?php endif; ?>
      <?php if ($user && $user['role'] === 'admin'): ?>
        <a href="<?= htmlspecialchars($base) ?>/share_link.php?workbook=<?= intval($current_workbook_id) ?>"
          class="btn">Ссылки</a>
      <?php endif; ?>
    <?php endif; ?>

    <?php if ($user): ?>
      <span class="user"> <?= htmlspecialchars($user['full_name'] ?: $user['username']) ?>
        (<?= htmlspecialchars($user['role']) ?>) </span>
      <a href="<?= htmlspecialchars($base) ?>/change_password.php" class="btn">Профиль</a>
      <a href="<?= htmlspecialchars($base) ?>/logout.php" class="btn">Выйти</a>
    <?php else: ?>
      <a href="<?= htmlspecialchars($base) ?>/login.php" class="btn">Войти</a>
    <?php endif; ?>
  </div>
</nav>

<div id="shareModal" class="nav-modal" aria-hidden="true" style="display:none;">
  <div class="nav-modal-content" role="dialog" aria-modal="true" aria-labelledby="shareTitle" style="max-width:720px;">
    <h3 id="shareTitle">Ссылка на книгу</h3>
    <div id="shareInfo" style="margin-bottom:8px;font-weight:600;"></div>

    <div style="display:flex;gap:10px;align-items:center;">
      <label>Длительность работы (дней, 0 = бесконечно): <input id="shareDays" value="7" size="4" type="number" min="0"
          style="width:80px;"></label>
      <button id="createShareBtn">Генерировать</button>
      <button id="refreshShareListBtn">Обновить список</button>
      <button id="closeShareBtn" style="margin-left:auto;">Закрыть</button>
    </div>

    <div id="shareResult" style="margin-top:12px;"></div>

    <h4 style="margin-top:14px;">Работающие ссылки</h4>
    <div id="shareListWrap" style="max-height:260px;overflow:auto;border:1px solid #eee;padding:8px;border-radius:6px;">
      <ul id="shareList" style="list-style:none;padding-left:0;margin:0;"></ul>
    </div>
  </div>
</div>

<script>
  (function () {
    const base = "<?= addslashes($base) ?>";
    const modal = document.getElementById('shareModal');
    const openBtn = document.getElementById('openShareModalBtn');
    const createBtn = document.getElementById('createShareBtn');
    const closeBtn = document.getElementById('closeShareBtn');
    const refreshBtn = document.getElementById('refreshShareListBtn');
    const shareInfo = document.getElementById('shareInfo');
    const shareResult = document.getElementById('shareResult');
    const shareList = document.getElementById('shareList');
    const daysInput = document.getElementById('shareDays');

    function apiCall(action, payload) {
      const body = Object.assign({ action: action }, payload || {});
      return fetch(base + '/api/share_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
        credentials: 'same-origin'
      }).then(resp => resp.json());
    }

    function showMessage(html, isError) {
      shareResult.innerHTML = '<div style="padding:8px;border-radius:6px;' + (isError ? 'background:#ffecec;color:#900;' : 'background:#eef7ea;color:#063;') + '">' + html + '</div>';
    }

    function clearMessage() { shareResult.innerHTML = ''; }

    if (openBtn) {
      openBtn.addEventListener('click', function () {
        const wid = this.dataset.wid;
        if (!wid) return alert('Workbook id missing');
        modal.style.display = 'block';
        modal.setAttribute('aria-hidden', 'false');
        modal.dataset.wid = wid;
        shareInfo.textContent = 'Workbook ID: ' + wid;
        clearMessage();
        refreshList();
      });
    }

    if (closeBtn) closeBtn.addEventListener('click', function () {
      modal.style.display = 'none';
      modal.setAttribute('aria-hidden', 'true');
    });

    if (createBtn) createBtn.addEventListener('click', function () {
      const wid = modal.dataset.wid;
      if (!wid) { showMessage('Workbook id not set', true); return; }
      const days = parseInt(daysInput.value || '0', 10) || 0;
      createBtn.disabled = true;
      apiCall('create', { workbook_id: parseInt(wid, 10), expires_days: days })
        .then(res => {
          createBtn.disabled = false;
          if (!res || !res.ok) {
            const err = res && (res.error || res.msg) ? (res.error || res.msg) : 'Unknown error';
            showMessage('Error: ' + String(err), true);
            return;
          }
          const url = res.share.url;
          showMessage('Ссылка создана: <input readonly style="width:420px" value="' + url.replace(/"/g, '&quot;') + '"> <button id="copyNewShareBtn">Копировать</button>');
          const copyBtn = document.getElementById('copyNewShareBtn');
          if (copyBtn) copyBtn.addEventListener('click', function () { navigator.clipboard && navigator.clipboard.writeText(url); });
          refreshList();
        }).catch(err => {
          createBtn.disabled = false;
          showMessage('Network error: ' + (err && err.message ? err.message : String(err)), true);
        });
    });

    if (refreshBtn) refreshBtn.addEventListener('click', refreshList);

    function refreshList() {
      const wid = modal.dataset.wid;
      if (!wid) { shareList.innerHTML = '<li>No workbook selected</li>'; return; }
      shareList.innerHTML = '<li>Loading...</li>';
      apiCall('list', { workbook_id: parseInt(wid, 10) })
        .then(res => {
          if (!res || !res.ok) {
            shareList.innerHTML = '<li style="color:red;">Error loading shares: ' + (res && res.error ? res.error : 'unknown') + '</li>';
            return;
          }
          const rows = res.shares || [];
          if (!rows.length) {
            shareList.innerHTML = '<li><em>No shares</em></li>';
            return;
          }
          shareList.innerHTML = '';
          rows.forEach(r => {
            const li = document.createElement('li');
            li.style.padding = '6px 0';
            const revoked = r.revoked ? '<strong style="color:#900"> Отозвать</strong>' : '';
            const createdBy = r.creator_name ? ' создана ' + escapeHtml(r.creator_name) : '';
            const expires = r.expires_at ? ' (сгенерирована: ' + escapeHtml(r.expires_at) + ')' : '';
            const short = '<div style="margin-bottom:6px;">ID: ' + (r.id ? parseInt(r.id, 10) : '') + revoked + createdBy + expires + '</div>';
            const urlInput = '<input readonly style="width:420px" value="' + escapeHtml((r.url || '').toString()) + '"> ';
            const copyBtn = '<button class="copyShareBtn" data-url="' + escapeHtml((r.url || '').toString()) + '">Копировать</button> ';
            const revokeBtn = (!r.revoked ? '<button class="revokeShareBtn" data-id="' + parseInt(r.id, 10) + '">Отозвать</button>' : '');
            li.innerHTML = short + '<div>' + urlInput + '&nbsp;' + copyBtn + revokeBtn + '</div>';
            shareList.appendChild(li);
          });

          document.querySelectorAll('.copyShareBtn').forEach(b => {
            b.addEventListener('click', function () { const u = this.dataset.url; navigator.clipboard && navigator.clipboard.writeText(u); });
          });
          document.querySelectorAll('.revokeShareBtn').forEach(b => {
            b.addEventListener('click', function () {
              const id = parseInt(this.dataset.id, 10);
              if (!confirm('Revoke link #' + id + '?')) return;
              this.disabled = true;
              apiCall('revoke', { id: id })
                .then(res => {
                  if (!res || !res.ok) {
                    alert('Error: ' + (res && (res.error || res.msg) ? (res.error || res.msg) : 'unknown'));
                  }
                  refreshList();
                }).catch(() => { alert('Network error'); this.disabled = false; });
            });
          });
        }).catch(err => {
          shareList.innerHTML = '<li style="color:red;">Network error</li>';
        });
    }

    function escapeHtml(s) {
      if (!s && s !== 0) return '';
      return String(s).replace(/[&<>"']/g, function (m) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]); });
    }

    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { modal.style.display = 'none'; modal.setAttribute('aria-hidden', 'true'); } });

    window.addEventListener('click', function (e) {
      if (e.target === modal) { modal.style.display = 'none'; modal.setAttribute('aria-hidden', 'true'); }
    });

  })();
</script>