<?php
// Minimal native .xlsx reader: first sheet only, no formulas/merged cells.
// Avoids needing Composer/PhpSpreadsheet — uses PHP's built-in zip + SimpleXML extensions.

function readXlsxRows(string $path): array {
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Could not open the file as an .xlsx archive.');
    }

    $sharedStrings = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXml !== false) {
        $sx = simplexml_load_string($sharedXml);
        foreach ($sx->si as $si) {
            $sharedStrings[] = isset($si->t) ? (string) $si->t : implode('', array_map(fn($r) => (string) $r->t, iterator_to_array($si->r ?? [])));
        }
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if ($sheetXml === false) {
        throw new RuntimeException('Could not find the first worksheet in the .xlsx file.');
    }

    $sheet = simplexml_load_string($sheetXml);
    $rows = [];

    foreach ($sheet->sheetData->row as $rowXml) {
        $cells = [];
        $maxCol = 0;
        foreach ($rowXml->c as $cellXml) {
            $ref = (string) $cellXml['r'];
            $colIndex = columnLetterToIndex(preg_replace('/[0-9]/', '', $ref));
            $maxCol = max($maxCol, $colIndex);

            $value = isset($cellXml->v) ? (string) $cellXml->v : '';
            $type = (string) $cellXml['t'];

            if ($type === 's') {
                $value = $sharedStrings[(int) $value] ?? '';
            } elseif ($type === 'inlineStr') {
                $value = (string) ($cellXml->is->t ?? '');
            }

            $cells[$colIndex] = $value;
        }

        $row = [];
        for ($i = 0; $i <= $maxCol; $i++) {
            $row[] = $cells[$i] ?? '';
        }
        $rows[] = $row;
    }

    return $rows;
}

function columnLetterToIndex(string $letters): int {
    $index = 0;
    foreach (str_split(strtoupper($letters)) as $char) {
        $index = $index * 26 + (ord($char) - ord('A') + 1);
    }
    return $index - 1;
}

// Excel stores dates as serial day counts from 1899-12-30; converts to Y-m-d when the value is a pure integer/float.
function excelSerialToDate(string $value): ?string {
    if (!is_numeric($value)) {
        return null;
    }
    $unixTimestamp = ((float) $value - 25569) * 86400;
    return gmdate('Y-m-d', (int) $unixTimestamp);
}
