<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Calculation\Calculation;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Font;

try {
    if (empty($_SESSION['user'])) {
        http_response_code(401);
        echo json_encode(['error' => 'not authenticated']);
        exit;
    }

    $config = require __DIR__ . '/config.php';
    $pdo = new PDO(
        "mysql:host={$config['db']['host']};dbname={$config['db']['dbname']};charset={$config['db']['charset']}",
        $config['db']['user'],
        $config['db']['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid payload']);
        exit;
    }

    $wbId = intval($payload['workbook_id'] ?? 0);
    $sheetId = intval($payload['sheet_id'] ?? 0);
    $editedContent = $payload['content'] ?? null;
    if ($wbId <= 0 || $sheetId <= 0 || !is_array($editedContent)) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid params']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT * FROM workbooks WHERE id = ?");
    $stmt->execute([$wbId]);
    $wb = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$wb) {
        http_response_code(404);
        echo json_encode(['error' => 'wb not found']);
        exit;
    }
    if (!($_SESSION['user']['role'] === 'admin' || $_SESSION['user']['id'] == $wb['owner_id'])) {
        http_response_code(403);
        echo json_encode(['error' => 'access denied']);
        exit;
    }
    if ($wb['locked_by'] && intval($wb['locked_by']) !== intval($_SESSION['user']['id']) && $_SESSION['user']['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'locked by another user']);
        exit;
    }

    $hexToARGB = function ($hex) {
        if (!$hex)
            return null;
        $h = trim($hex);
        if (strpos($h, '#') === 0)
            $h = substr($h, 1);
        if (strlen($h) === 3) {
            $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
        }
        if (strlen($h) !== 6)
            return null;
        return 'FF' . strtoupper($h);
    };

    $argbToHex = function ($argb) {
        if (!$argb)
            return null;
        $a = strtoupper($argb);
        if (strlen($a) === 8)
            $a = substr($a, 2);
        if (strlen($a) !== 6)
            return null;
        return '#' . strtolower($a);
    };

    $allSheetsStmt = $pdo->prepare("SELECT * FROM sheets WHERE workbook_id = ? ORDER BY order_index");
    $allSheetsStmt->execute([$wbId]);
    $allSheets = $allSheetsStmt->fetchAll(PDO::FETCH_ASSOC);

    $spreadsheet = new Spreadsheet();
    $sheetIndex = 0;
    $dbSheetIdToTitle = [];

    foreach ($allSheets as $s) {
        if (intval($s['id']) === intval($sheetId) && is_array($editedContent)) {
            $sContent = $editedContent;
        } else {
            $sContent = [];
            if (!empty($s['content'])) {
                $sContent = json_decode($s['content'], true) ?: [];
            }
        }

        $header = $sContent['header'] ?? [];
        $rows = $sContent['rows'] ?? [];

        if ($sheetIndex === 0) {
            $ws = $spreadsheet->getActiveSheet();
        } else {
            $ws = $spreadsheet->createSheet();
        }

        $title = (string) ($s['name'] ?: ('Sheet' . ($sheetIndex + 1)));
        $baseTitle = $title;
        $k = 1;
        while ($spreadsheet->sheetNameExists($title)) {
            $title = $baseTitle . '_' . $k;
            $k++;
        }
        $ws->setTitle($title);
        $dbSheetIdToTitle[$s['id']] = $title;

        for ($r = 0; $r < count($rows); $r++) {
            $row = $rows[$r];
            if (!is_array($row))
                continue;
            foreach ($row as $col => $cell) {
                $addr = (string) $col . ($r + 1);
                if (!empty($cell['formula']) && is_string($cell['formula']) && mb_substr($cell['formula'], 0, 1) === '=') {
                    $ws->setCellValue($addr, $cell['formula']);
                } else {
                    $val = $cell['value'] ?? '';
                    if ($val === '') {
                        $ws->setCellValue($addr, '');
                    } elseif (is_numeric($val)) {
                        $ws->setCellValue($addr, $val + 0);
                    } else {
                        $ws->setCellValue($addr, $val);
                    }
                }

                if (!empty($cell['format']) && is_array($cell['format'])) {
                    $fmt = $cell['format'];
                    try {
                        if (!empty($fmt['bg'])) {
                            $argb = $hexToARGB($fmt['bg']);
                            if ($argb) {
                                $ws->getStyle($addr)->getFill()->setFillType(Fill::FILL_SOLID);
                                $ws->getStyle($addr)->getFill()->getStartColor()->setARGB($argb);
                            }
                        }
                        if (!empty($fmt['bold'])) {
                            $ws->getStyle($addr)->getFont()->setBold(true);
                        }
                        if (!empty($fmt['italic'])) {
                            $ws->getStyle($addr)->getFont()->setItalic(true);
                        }
                        if (!empty($fmt['align'])) {
                            $align = strtolower($fmt['align']);
                            if (in_array($align, ['left', 'center', 'right'], true)) {
                                $ws->getStyle($addr)->getAlignment()->setHorizontal(strtoupper($align) === 'LEFT' ? Alignment::HORIZONTAL_LEFT : ($align === 'center' ? Alignment::HORIZONTAL_CENTER : Alignment::HORIZONTAL_RIGHT));
                            }
                        }
                    } catch (Throwable $e) {
                    }
                }
            }
        }

        $sheetIndex++;
    }

    if ($sheetIndex === 0) {
        $ws = $spreadsheet->getActiveSheet();
        $ws->setTitle('Sheet1');
    }

    if (isset($dbSheetIdToTitle[$sheetId])) {
        $editedTitle = $dbSheetIdToTitle[$sheetId];
        $editedSheet = $spreadsheet->getSheetByName($editedTitle);
        if ($editedSheet) {
            $spreadsheet->setActiveSheetIndex($spreadsheet->getIndex($editedSheet));
        }
    }

    $calc = Calculation::getInstance($spreadsheet);
    $calc->flushInstance();

    $updatedSheetsContent = [];
    foreach ($allSheets as $s) {
        $dbId = $s['id'];
        $title = $dbSheetIdToTitle[$dbId] ?? ($s['name'] ?: null);
        $worksheet = $spreadsheet->getSheetByName($title);
        if (!$worksheet) {
            $worksheet = $spreadsheet->getActiveSheet();
        }

        $origContent = !empty($s['content']) ? (json_decode($s['content'], true) ?: []) : [];
        $origHeader = $origContent['header'] ?? [];
        $maxColIndex = 0;
        $highestCol = $worksheet->getHighestColumn(null);
        if ($highestCol) {
            $maxColIndex = Coordinate::columnIndexFromString($highestCol);
        }
        if (is_array($origHeader) && count($origHeader) > $maxColIndex) {
            $maxColIndex = count($origHeader);
        }
        if ($maxColIndex <= 0)
            $maxColIndex = 1;

        $newHeader = [];
        for ($ci = 1; $ci <= $maxColIndex; $ci++) {
            if (isset($origHeader[$ci - 1]) && $origHeader[$ci - 1] !== '') {
                $newHeader[] = $origHeader[$ci - 1];
            } else {
                $newHeader[] = Coordinate::stringFromColumnIndex($ci);
            }
        }

        $highestRow = (int) $worksheet->getHighestRow();
        if ($highestRow < 1)
            $highestRow = 1;

        $newRows = [];
        for ($r = 1; $r <= $highestRow; $r++) {
            $rowObj = [];
            for ($ci = 1; $ci <= $maxColIndex; $ci++) {
                $colLetter = Coordinate::stringFromColumnIndex($ci);
                $addr = $colLetter . $r;

                $raw = $worksheet->getCell($addr)->getValue();
                $formula = '';
                if (is_string($raw) && strlen($raw) > 0 && $raw[0] === '=') {
                    $formula = $raw;
                }

                try {
                    $calculated = $worksheet->getCell($addr)->getCalculatedValue();
                } catch (Throwable $e) {
                    try {
                        $calculated = $worksheet->getCell($addr)->getValue();
                    } catch (Throwable $_e) {
                        $calculated = null;
                    }
                }

                if ($calculated === null)
                    $calculated = '';

                $format = null;
                try {
                    $style = $worksheet->getStyle($addr);

                    $fill = $style->getFill();
                    $fillType = $fill->getFillType();
                    $bgHex = null;
                    if ($fillType === Fill::FILL_SOLID) {
                        $argb = $fill->getStartColor()->getARGB();
                        $bgHex = $argbToHex($argb);
                    }

                    $font = $style->getFont();
                    $isBold = $font->getBold();
                    $isItalic = $font->getItalic();

                    $alignObj = $style->getAlignment();
                    $hor = $alignObj->getHorizontal();
                    $align = null;
                    if ($hor === Alignment::HORIZONTAL_LEFT)
                        $align = 'left';
                    elseif ($hor === Alignment::HORIZONTAL_CENTER)
                        $align = 'center';
                    elseif ($hor === Alignment::HORIZONTAL_RIGHT)
                        $align = 'right';

                    $formatCandidate = [];
                    if ($bgHex)
                        $formatCandidate['bg'] = $bgHex;
                    if ($isBold)
                        $formatCandidate['bold'] = true;
                    if ($isItalic)
                        $formatCandidate['italic'] = true;
                    if ($align)
                        $formatCandidate['align'] = $align;

                    if (count($formatCandidate) > 0)
                        $format = $formatCandidate;
                    else
                        $format = null;
                } catch (Throwable $e) {
                    $format = null;
                }

                $rowObj[$colLetter] = [
                    'value' => $calculated,
                    'formula' => is_string($formula) ? $formula : '',
                    'format' => $format
                ];
            }
            $newRows[] = $rowObj;
        }

        $updatedSheetsContent[$dbId] = [
            'header' => $newHeader,
            'rows' => $newRows
        ];
    }

    $updStmt = $pdo->prepare("UPDATE sheets SET content = ? WHERE id = ?");
    foreach ($updatedSheetsContent as $dbId => $cnt) {
        $json = json_encode($cnt, JSON_UNESCAPED_UNICODE);
        $updStmt->execute([$json, $dbId]);
    }

    $log = $pdo->prepare("INSERT INTO audit_logs (user_id, action, details) VALUES (?,?,?)");
    $log->execute([$_SESSION['user']['id'], 'save_sheet', json_encode(['workbook' => $wbId, 'sheet' => $sheetId])]);

    $returned = $updatedSheetsContent[$sheetId] ?? ['header' => $editedContent['header'] ?? [], 'rows' => $editedContent['rows'] ?? []];
    echo json_encode(['ok' => true, 'content' => $returned], JSON_UNESCAPED_UNICODE);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    error_log("save_sheet fatal: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    echo json_encode(['error' => 'server error: ' . $e->getMessage()]);
    exit;
}
