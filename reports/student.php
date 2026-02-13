<?php
session_start();
if (empty($_SESSION['user'])) {
    header('Location: /gradeapp/login.php');
    exit;
}

require __DIR__ . '/../vendor/autoload.php';
$config = require __DIR__ . '/../config.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

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

$user = $_SESSION['user'];
$isAdmin = ($user['role'] ?? '') === 'admin';
$teacherId = intval($user['id'] ?? 0);
$debug = !empty($_GET['debug']);

function normalize_for_compare($v)
{
    if ($v === null)
        return '';
    $s = (string) $v;
    $s = preg_replace('/[.,;:()"\']+/u', '', $s);
    $s = preg_replace('/\s+/u', ' ', $s);
    $s = trim($s);
    return mb_strtolower($s);
}

function detect_student_id_column(array $header)
{
    $cand = ['login', 'username', 'фио', 'fio', 'name', 'student', 'full_name', 'фамилия', 'имя', 'id', 'student_id'];
    foreach ($header as $h) {
        if (!is_string($h))
            continue;
        $low = mb_strtolower(trim($h));
        foreach ($cand as $c) {
            if ($c === '')
                continue;
            if (mb_strpos($low, $c) !== false)
                return $h;
        }
    }
    return $header[0] ?? null;
}


function inline_style_from_format($fmt)
{
    if (empty($fmt) || !is_array($fmt))
        return '';
    $styles = [];

    if (!empty($fmt['bg'])) {
        $bg = trim((string) $fmt['bg']);
        if ($bg !== '') {
            if ($bg[0] !== '#')
                $bg = '#' . $bg;
            if (preg_match('/^#[0-9A-Fa-f]{6}$/', $bg))
                $styles[] = "background-color: {$bg}";
        }
    }

    if (!empty($fmt['color'])) {
        $c = trim((string) $fmt['color']);
        if ($c !== '') {
            if ($c[0] !== '#')
                $c = '#' . $c;
            if (preg_match('/^#[0-9A-Fa-f]{6}$/', $c))
                $styles[] = "color: {$c}";
        }
    }

    $align = '';
    if (!empty($fmt['align']))
        $align = trim((string) $fmt['align']);
    if ($align === '' && !empty($fmt['hAlign']))
        $align = trim((string) $fmt['hAlign']);
    if ($align !== '') {
        $la = strtolower($align);
        if (in_array($la, ['left', 'center', 'right', 'justify']))
            $styles[] = "text-align: {$la}";
    }

    if (!empty($fmt['bold']))
        $styles[] = "font-weight: bold";
    if (!empty($fmt['italic']))
        $styles[] = "font-style: italic";

    if (!empty($fmt['vAlign'])) {
        $va = strtolower(trim((string) $fmt['vAlign']));
        if (in_array($va, ['top', 'middle', 'bottom', 'center'])) {
            if ($va === 'middle' || $va === 'center')
                $va = 'middle';
            $styles[] = "vertical-align: {$va}";
        }
    }

    if (empty($styles))
        return '';
    return implode('; ', $styles);
}

$groupId = intval($_REQUEST['group_id'] ?? 0);
$studentUsername = trim((string) ($_REQUEST['student_username'] ?? ''));
$export = $_REQUEST['export'] ?? ''; // csv or xlsx

if ($isAdmin) {
    $wbStmt = $pdo->prepare("SELECT * FROM workbooks ORDER BY id");
    $wbStmt->execute();
} else {
    $wbStmt = $pdo->prepare("SELECT * FROM workbooks WHERE owner_id = ? ORDER BY id");
    $wbStmt->execute([$teacherId]);
}
$workbooks = $wbStmt->fetchAll(PDO::FETCH_ASSOC);

$groupMap = [];
$groupIds = [];
foreach ($workbooks as $wb) {
    $gid = intval($wb['group_id'] ?? 0);
    if ($gid > 0)
        $groupIds[$gid] = true;
}
if (!empty($groupIds)) {
    $inList = implode(',', array_map('intval', array_keys($groupIds)));
    $gstmt = $pdo->query("SELECT id, short_name, title FROM `groups` WHERE id IN ($inList)");
    $groups = $gstmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($groups as $g) {
        $label = trim((string) ($g['title'] ?? ''));
        if ($label === '')
            $label = trim((string) ($g['short_name'] ?? ''));
        if ($label === '')
            $label = 'group_' . intval($g['id']);
        $groupMap[intval($g['id'])] = $label;
    }
    ksort($groupMap);
}

$groupStudents = [];
$groupFullNamesSet = [];
$groupUsernamesSet = [];
$groupSurnameSet = [];
if ($groupId > 0) {
    try {
        $sstmt = $pdo->prepare("SELECT u.id, u.username, u.full_name FROM users u INNER JOIN user_groups ug ON ug.user_id = u.id WHERE ug.group_id = ? ORDER BY u.full_name, u.username");
        $sstmt->execute([$groupId]);
        $srows = $sstmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($srows as $sr) {
            $u = trim((string) ($sr['username'] ?? ''));
            $fn = trim((string) ($sr['full_name'] ?? ''));
            $uNorm = $u !== '' ? normalize_for_compare($u) : '';
            $fnNorm = $fn !== '' ? normalize_for_compare($fn) : '';
            $surnameNorm = '';
            if ($fnNorm !== '') {
                $parts = preg_split('/\s+/u', $fnNorm, -1, PREG_SPLIT_NO_EMPTY);
                if (count($parts) > 0 && mb_strlen($parts[0]) >= 2)
                    $surnameNorm = $parts[0];
            }
            $groupStudents[] = [
                'username' => $u,
                'full_name' => $fn,
                'usernameNorm' => $uNorm,
                'fullNameNorm' => $fnNorm,
                'surnameNorm' => $surnameNorm
            ];
            if ($uNorm !== '')
                $groupUsernamesSet[$uNorm] = true;
            if ($fnNorm !== '')
                $groupFullNamesSet[$fnNorm] = true;
            if ($surnameNorm !== '')
                $groupSurnameSet[$surnameNorm] = true;
        }
    } catch (Throwable $e) {
    }
}

$studentFullName = '';
if ($studentUsername !== '' && !empty($groupStudents)) {
    foreach ($groupStudents as $gs) {
        if ($gs['username'] === $studentUsername) {
            $studentFullName = $gs['full_name'];
            break;
        }
    }
}

$results = [];
if ($groupId > 0 && $studentUsername !== '') {
    $groupFullNamesSet = [];
    $groupUsernamesSet = [];
    $groupSurnameSet = [];
    foreach ($groupStudents as $gs) {
        if ($gs['fullNameNorm'] !== '')
            $groupFullNamesSet[$gs['fullNameNorm']] = true;
        if ($gs['usernameNorm'] !== '')
            $groupUsernamesSet[$gs['usernameNorm']] = true;
        if ($gs['surnameNorm'] !== '')
            $groupSurnameSet[$gs['surnameNorm']] = true;
    }

    $isGroupStudentCandidate = function ($candidateRaw) use ($groupFullNamesSet, $groupUsernamesSet, $groupSurnameSet, $groupStudents) {
        $candidateRaw = (string) $candidateRaw;
        if (trim($candidateRaw) === '')
            return false;
        $candNorm = normalize_for_compare($candidateRaw);
        if ($candNorm === '')
            return false;

        if (isset($groupFullNamesSet[$candNorm]))
            return true;
        if (isset($groupUsernamesSet[$candNorm]))
            return true;

        $parts = preg_split('/\s+/u', $candNorm, -1, PREG_SPLIT_NO_EMPTY);
        if (count($parts) >= 2) {
            $surname = $parts[0];
            if (isset($groupSurnameSet[$surname])) {
                return true;
            }
        }

        foreach ($groupStudents as $gs) {
            if ($gs['fullNameNorm'] !== '' && mb_strpos($candNorm, $gs['fullNameNorm']) !== false)
                return true;
        }

        return false;
    };

    $selectedTokens = [];
    if ($studentUsername !== '')
        $selectedTokens[] = normalize_for_compare($studentUsername);
    if ($studentFullName !== '')
        $selectedTokens[] = normalize_for_compare($studentFullName);
    if ($studentFullName !== '') {
        $parts = preg_split('/\s+/u', normalize_for_compare($studentFullName), -1, PREG_SPLIT_NO_EMPTY);
        if (count($parts) > 0)
            $selectedTokens[] = $parts[0]; // surname/token
    }
    $selectedTokens = array_values(array_filter(array_unique($selectedTokens), function ($v) {
        return $v !== ''; }));

    $isSelectedCandidate = function ($candidateRaw) use ($selectedTokens) {
        $candidateRaw = (string) $candidateRaw;
        if (trim($candidateRaw) === '')
            return false;
        $candNorm = normalize_for_compare($candidateRaw);
        if ($candNorm === '')
            return false;
        foreach ($selectedTokens as $tok) {
            if ($tok === '')
                continue;
            if ($candNorm === $tok)
                return true;
            if (mb_strpos($candNorm, $tok) !== false)
                return true;
            if (mb_strpos($tok, $candNorm) !== false)
                return true;
        }
        return false;
    };

    foreach ($workbooks as $wb) {
        if ($groupId > 0 && intval($wb['group_id'] ?? 0) !== $groupId)
            continue;

        $sStmt = $pdo->prepare("SELECT * FROM sheets WHERE workbook_id = ? ORDER BY order_index");
        $sStmt->execute([$wb['id']]);
        $sheets = $sStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($sheets as $s) {
            $content = [];
            if (!empty($s['content']))
                $content = json_decode($s['content'], true) ?: [];
            $header = is_array($content['header'] ?? null) ? $content['header'] : [];
            $rows = is_array($content['rows'] ?? null) ? $content['rows'] : [];

            if (empty($header)) {
                $maxCols = 1;
                foreach ($rows as $r) {
                    if (!is_array($r))
                        continue;
                    $maxCols = max($maxCols, count($r));
                }
                for ($ci = 1; $ci <= $maxCols; $ci++)
                    $header[] = Coordinate::stringFromColumnIndex($ci);
            }

            $idColName = null;
            if (!empty($s['student_id_column'])) {
                $idColName = $s['student_id_column'];
            } else {
                $idColName = detect_student_id_column($header);
            }
            if ($idColName === null && count($header) > 0)
                $idColName = $header[0];

            $idColKey = null;
            if ($idColName !== null) {
                if (preg_match('/^[A-Z]+$/i', $idColName)) {
                    $idColKey = strtoupper($idColName);
                } else {
                    foreach ($header as $hIdx => $hName) {
                        if (mb_strtolower(trim((string) $hName)) === mb_strtolower(trim((string) $idColName))) {
                            $idColKey = Coordinate::stringFromColumnIndex($hIdx + 1);
                            break;
                        }
                    }
                }
                if ($idColKey === null)
                    $idColKey = Coordinate::stringFromColumnIndex(1);
            }

            $maxCols = count($header);
            $alignedRows = [];
            for ($ri = 0; $ri < count($rows); $ri++) {
                $row = $rows[$ri];
                $aligned = [];
                for ($ci = 1; $ci <= $maxCols; $ci++) {
                    $colL = Coordinate::stringFromColumnIndex($ci);
                    $cellObj = null;
                    if (is_array($row) && isset($row[$colL])) {
                        $cellObj = $row[$colL];
                    } else {
                        $hName = $header[$ci - 1] ?? $colL;
                        if (is_array($row) && isset($row[$hName]))
                            $cellObj = $row[$hName];
                    }
                    if (!is_array($cellObj)) {
                        $aligned[$colL] = ['value' => $cellObj ?? '', 'formula' => '', 'format' => null];
                    } else {
                        $aligned[$colL] = [
                            'value' => $cellObj['value'] ?? '',
                            'formula' => $cellObj['formula'] ?? '',
                            'format' => isset($cellObj['format']) ? $cellObj['format'] : (isset($cellObj['style']) ? $cellObj['style'] : null)
                        ];
                        foreach (['bold', 'italic', 'fontColor', 'fillColor', 'hAlign', 'vAlign'] as $k) {
                            if (isset($cellObj[$k])) {
                                if (!is_array($aligned[$colL]['format']))
                                    $aligned[$colL]['format'] = [];
                                if ($k === 'fontColor')
                                    $aligned[$colL]['format']['color'] = $cellObj[$k];
                                elseif ($k === 'fillColor')
                                    $aligned[$colL]['format']['bg'] = $cellObj[$k];
                                else
                                    $aligned[$colL]['format'][$k] = $cellObj[$k];
                            }
                        }
                    }
                }
                $alignedRows[] = $aligned;
            }

            $groupIndices = [];
            foreach ($alignedRows as $idx => $aligned) {
                if ($idColKey === null)
                    continue;
                $candidateRaw = (string) ($aligned[$idColKey]['value'] ?? '');
                if (trim($candidateRaw) === '')
                    continue;
                if ($isGroupStudentCandidate($candidateRaw))
                    $groupIndices[] = $idx;
            }

            if (empty($groupIndices))
                continue;

            $selectedPresent = false;
            foreach ($groupIndices as $gi) {
                $cand = (string) ($alignedRows[$gi][$idColKey]['value'] ?? '');
                if ($isSelectedCandidate($cand)) {
                    $selectedPresent = true;
                    break;
                }
            }
            if (!$selectedPresent)
                continue;

            $blockStart = min($groupIndices);
            $blockEnd = max($groupIndices);

            $filteredRows = [];
            foreach ($alignedRows as $rIdx => $aligned) {
                $keep = true;
                $candidateRaw = (string) ($aligned[$idColKey]['value'] ?? '');

                if (trim($candidateRaw) !== '') {
                    if ($rIdx >= $blockStart && $rIdx <= $blockEnd) {
                        if ($isSelectedCandidate($candidateRaw)) {
                            $keep = true;
                        } else {
                            if ($isGroupStudentCandidate($candidateRaw)) {
                                $keep = false;
                            } else {
                                $keep = true;
                            }
                        }
                    } else {
                        $keep = true;
                    }
                } else {
                    $keep = true;
                }

                if ($keep)
                    $filteredRows[] = $aligned;
            }

            $containsSelected = false;
            foreach ($filteredRows as $r) {
                $cand = (string) ($r[$idColKey]['value'] ?? '');
                if ($isSelectedCandidate($cand)) {
                    $containsSelected = true;
                    break;
                }
            }
            if (!$containsSelected)
                continue;

            $results[] = [
                'workbook' => $wb,
                'sheet' => $s,
                'sheet_header' => $header,
                'filtered_rows' => $filteredRows,
                'debug_info' => $debug ? ['groupIndices' => $groupIndices, 'blockStart' => $blockStart, 'blockEnd' => $blockEnd] : null
            ];
        }
    }
}

if (($export === 'csv' || $export === 'xlsx') && $studentUsername !== '') {
    $filenameBase = 'student_report_' . preg_replace('/[^a-z0-9-_]/i', '_', ($studentFullName ?: $studentUsername)) . '_' . date('Ymd_His');
    if ($export === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filenameBase . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");

        foreach ($results as $block) {
            $wb = $block['workbook'];
            $s = $block['sheet'];
            fputcsv($out, ["Workbook: " . ($wb['title'] ?? $wb['id'])]);
            fputcsv($out, ["Sheet: " . ($s['name'] ?? $s['id'])]);
            fputcsv($out, $block['sheet_header']);
            foreach ($block['filtered_rows'] as $row) {
                $cols = [];
                foreach ($block['sheet_header'] as $hIndex => $hName) {
                    $colKey = preg_match('/^[A-Z]+$/i', $hName) ? strtoupper($hName) : Coordinate::stringFromColumnIndex($hIndex + 1);
                    $cols[] = $row[$colKey]['value'] ?? '';
                }
                fputcsv($out, $cols);
            }
            fputcsv($out, []);
            if ($debug && !empty($block['debug_info'])) {
                fputcsv($out, ['DEBUG: groupIndices=' . implode(',', $block['debug_info']['groupIndices'])]);
            }
        }
        fclose($out);
        exit;
    } else {
        $spreadsheet = new Spreadsheet();
        $sheetIndex = 0;
        if (empty($results)) {
            $sht = $spreadsheet->getActiveSheet();
            $sht->setTitle('Report');
            $sht->setCellValue('A1', 'No results');
        } else {
            foreach ($results as $block) {
                if ($sheetIndex === 0)
                    $sht = $spreadsheet->getActiveSheet();
                else
                    $sht = $spreadsheet->createSheet();

                $titleCandidate = mb_substr(($block['workbook']['title'] ?? 'wb' . $block['workbook']['id']) . '_' . ($block['sheet']['name'] ?? 'sheet'), 0, 31);
                $base = $titleCandidate;
                $k = 1;
                while ($spreadsheet->sheetNameExists($titleCandidate)) {
                    $titleCandidate = mb_substr($base . '_' . $k, 0, 31);
                    $k++;
                }
                $sht->setTitle($titleCandidate);

                $r = 1;
                $sht->setCellValue('A' . $r, 'Workbook: ' . ($block['workbook']['title'] ?? $block['workbook']['id']));
                $r++;
                $sht->setCellValue('A' . $r, 'Sheet: ' . ($block['sheet']['name'] ?? $block['sheet']['id']));
                $r++;
                $c = 1;
                foreach ($block['sheet_header'] as $h) {
                    $addr = Coordinate::stringFromColumnIndex($c) . $r;
                    $sht->setCellValue($addr, $h);
                    $c++;
                }
                $r++;
                foreach ($block['filtered_rows'] as $row) {
                    $c = 1;
                    foreach ($block['sheet_header'] as $hIndex => $hName) {
                        $colKey = preg_match('/^[A-Z]+$/i', $hName) ? strtoupper($hName) : Coordinate::stringFromColumnIndex($hIndex + 1);
                        $addr = Coordinate::stringFromColumnIndex($c) . $r;
                        $sht->setCellValue($addr, $row[$colKey]['value'] ?? '');
                        $c++;
                    }
                    $r++;
                }

                if ($debug && !empty($block['debug_info'])) {
                    $sht->setCellValue('A' . $r, 'DEBUG: groupIndices=' . implode(',', $block['debug_info']['groupIndices']));
                }
                $sheetIndex++;
            }
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filenameBase . '.xlsx"');
        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }
}

?><!doctype html>
<html>

<head>
    <meta charset="utf-8">
    <title>Отчёт по студенту — GradeApp</title>
    <style>
        body {
            font-family: Arial, Helvetica, sans-serif;
            padding: 14px
        }

        .block {
            margin-bottom: 18px;
            border: 1px solid #ddd;
            padding: 10px;
            border-radius: 6px;
            background: #fafafa
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
            vertical-align: top;
            min-width: 80px
        }

        thead th {
            background: #f1f5f9;
            font-weight: 600
        }

        .meta {
            font-size: 0.9em;
            color: #333;
            margin-bottom: 6px
        }

        .controls {
            margin-top: 8px
        }

        .btn {
            padding: 6px 12px;
            margin-right: 6px
        }

        .debug {
            font-size: 0.85em;
            color: #b00;
            margin-top: 6px
        }

        .msg {
            color: green
        }

        .err {
            color: red
        }

        .formula {
            color: #666;
            font-size: 0.9em;
            margin-top: 6px;
            font-family: monospace
        }

        @media (max-width:640px) {

            table,
            thead,
            tbody,
            th,
            td,
            tr {
                display: block
            }

            thead tr {
                display: none
            }

            td {
                border: none;
                padding: 8px 0
            }

            td:before {
                font-weight: 600;
                display: block
            }
        }
    </style>
</head>

<body>
    <h2>Отчёт по студенту (по всем рабочим книгам)</h2>
    <p><a href="/gradeapp/manage_workbooks.php">← Back</a></p>

    <div class="block">
        <form id="reportForm" method="get" action="">
            <label>
                Группа:
                <select id="groupSelect" name="group_id">
                    <option value="0">-- выберите группу --</option>
                    <?php foreach ($groupMap as $gid => $gLabel): ?>
                        <option value="<?= intval($gid) ?>" <?= $gid === $groupId ? 'selected' : '' ?>>
                            <?= htmlspecialchars($gLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label style="margin-left:12px;">
                Студент:
                <select id="studentSelect" name="student_username">
                    <option value="">-- выберите студента --</option>
                    <?php if (!empty($groupStudents)): ?>
                        <?php foreach ($groupStudents as $gs):
                            $label = ($gs['full_name'] && trim($gs['full_name']) !== '') ? $gs['full_name'] . ' (' . $gs['username'] . ')' : $gs['username'];
                            $isSel = ($studentUsername !== '' && $studentUsername === $gs['username']);
                            ?>
                            <option value="<?= htmlspecialchars($gs['username']) ?>" <?= $isSel ? 'selected' : '' ?>>
                                <?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </label>

            <div class="controls">
                <button type="submit" class="btn">Показать</button>
                <?php if ($studentUsername !== ''): ?>
                    <button type="submit" name="export" value="csv" class="btn">Экспорт CSV</button>
                    <button type="submit" name="export" value="xlsx" class="btn">Экспорт XLSX</button>
                <?php endif; ?>
            </div>
        </form>
        <div style="margin-top:8px;color:#666">Сначала выберите группу, затем из списка — студента. Отчёт покажет
            таблицы во всех ваших рабочих книгах, удалив строки других студентов из выбранной группы (шапки и
            вспомогательные строки сохраняются).</div>
    </div>

    <?php if (!empty($results)): ?>
        <h3>Результат для <?= htmlspecialchars($studentFullName ?: $studentUsername) ?> — <?= count($results) ?> секций</h3>

        <?php foreach ($results as $block): ?>
            <div class="block">
                <div class="meta">
                    <strong>Workbook:</strong> <?= htmlspecialchars($block['workbook']['title'] ?? $block['workbook']['id']) ?>
                    &nbsp; | &nbsp;
                    <strong>Sheet:</strong> <?= htmlspecialchars($block['sheet']['name'] ?? $block['sheet']['id']) ?>
                </div>

                <table>
                    <thead>
                        <tr>
                            <?php foreach ($block['sheet_header'] as $h): ?>
                                <th><?= htmlspecialchars($h) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($block['filtered_rows'] as $row): ?>
                            <tr>
                                <?php foreach ($block['sheet_header'] as $hIndex => $hName):
                                    $colKey = preg_match('/^[A-Z]+$/i', $hName) ? strtoupper($hName) : Coordinate::stringFromColumnIndex($hIndex + 1);
                                    $cell = $row[$colKey] ?? ['value' => '', 'formula' => '', 'format' => null];
                                    $val = $cell['value'] ?? '';
                                    $formula = $cell['formula'] ?? '';
                                    $fmt = $cell['format'] ?? null;
                                    $styleAttr = inline_style_from_format($fmt);
                                    ?>
                                    <td <?= $styleAttr !== '' ? 'style="' . htmlspecialchars($styleAttr, ENT_QUOTES) . '"' : '' ?>>
                                        <div><?= htmlspecialchars((string) $val) ?></div>
                                        <?php if ($formula !== ''): ?>
                                            <div class="formula">Formula: <?= htmlspecialchars($formula) ?></div>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if ($debug && !empty($block['debug_info'])): ?>
                    <div class="debug">DEBUG: groupIndices =
                        <?= htmlspecialchars(implode(',', $block['debug_info']['groupIndices'])) ?>;
                        blockStart=<?= intval($block['debug_info']['blockStart']) ?>;
                        blockEnd=<?= intval($block['debug_info']['blockEnd']) ?></div>
                <?php endif; ?>

            </div>
        <?php endforeach; ?>

    <?php elseif ($groupId > 0 && $studentUsername !== ''): ?>
        <div class="block">По выбранному студенту ничего не найдено.</div>
    <?php endif; ?>

    <script>
        const groupSelect = document.getElementById('groupSelect');
        const studentSelect = document.getElementById('studentSelect');

        function clearStudents() {
            studentSelect.innerHTML = '';
            const opt = document.createElement('option'); opt.value = ''; opt.textContent = '-- выберите студента --';
            studentSelect.appendChild(opt);
        }
        function loadStudents(groupId, preselectUsername) {
            clearStudents();
            if (!groupId || groupId === '0') return;
            fetch('/gradeapp/api/get_students_by_group.php?group_id=' + encodeURIComponent(groupId))
                .then(r => r.json())
                .then(data => {
                    if (!data.ok) return;
                    const list = data.students || [];
                    list.sort((a, b) => {
                        const na = (a.full_name && a.full_name.trim() !== '') ? a.full_name.trim() : a.username;
                        const nb = (b.full_name && b.full_name.trim() !== '') ? b.full_name.trim() : b.username;
                        return na.localeCompare(nb, undefined, { sensitivity: 'base' });
                    });
                    list.forEach(s => {
                        const opt = document.createElement('option');
                        opt.value = s.username;
                        opt.dataset.fullname = s.full_name || '';
                        opt.textContent = (s.full_name && s.full_name.trim() !== '') ? s.full_name.trim() + ' (' + s.username + ')' : s.username;
                        studentSelect.appendChild(opt);
                    });

                    if (preselectUsername) {
                        for (let i = 0; i < studentSelect.options.length; i++) {
                            if (studentSelect.options[i].value === preselectUsername) {
                                studentSelect.selectedIndex = i;
                                break;
                            }
                        }
                    }
                }).catch(err => {
                    console.error('failed to load students', err);
                });
        }

        groupSelect.addEventListener('change', () => {
            const gid = groupSelect.value;
            const preselect = '';
            loadStudents(gid, preselect);
        });

        (function init() {
            const gid = groupSelect.value;
            const preUser = "<?php echo htmlspecialchars($studentUsername, ENT_QUOTES); ?>";
            if (gid && gid !== '0') loadStudents(gid, preUser);
        })();
    </script>
</body>

</html>