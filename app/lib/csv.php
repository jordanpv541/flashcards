<?php
declare(strict_types=1);

// Lector de CSV de tarjetas. Acepta los formatos de los TARJETAS_CURSO.csv:
//   1;pregunta;respuesta            "1";"pregunta";"respuesta"
//   Semana 1;pregunta;respuesta     S1;pregunta;respuesta
//   semana,pregunta,respuesta (con encabezado y comas)
// También: solo pregunta;respuesta, tabuladores, BOM, fin de línea mixto y texto en Windows-1252.

const CSV_MAX_FIELD = 5000;

function csv_header_key(string $cell): ?string
{
    $c = mb_strtolower(trim($cell));
    $c = strtr($c, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);
    return match ($c) {
        'semana', 'sem', 'week', 'unidad', 's' => 'week',
        'pregunta', 'frente', 'anverso', 'front', 'question', 'termino', 'concepto' => 'front',
        'respuesta', 'reverso', 'back', 'answer', 'definicion' => 'back',
        default => null,
    };
}

function csv_parse_week(string $cell): ?int
{
    $cell = trim($cell);
    if ($cell === '') {
        return null;
    }
    if (preg_match('/^(?:semana|sem\.?|s|week|w|unidad|u)?\s*0*(\d{1,3})$/iu', $cell, $m)) {
        $n = (int) $m[1];
        return $n >= 0 && $n <= 999 ? $n : null;
    }
    return null;
}

function csv_looks_like_week(string $cell): bool
{
    return csv_parse_week($cell) !== null;
}

/** Lee todas las filas con un separador dado. */
/** Lee las filas con un separador dado. Se detiene al pasar $maxRows filas (para no gastar memoria). */
function csv_read_rows(string $text, string $delimiter, int $maxRows = PHP_INT_MAX): array
{
    $h = fopen('php://temp', 'r+');
    fwrite($h, $text);
    rewind($h);
    $rows = [];
    $line = 0;
    while (($fields = fgetcsv($h, 0, $delimiter, '"', '')) !== false) {
        $line++;
        if ($fields === [null] || (count($fields) === 1 && trim((string) $fields[0]) === '')) {
            continue;
        }
        $rows[] = ['line' => $line, 'fields' => array_map(fn($f) => (string) $f, $fields)];
        if (count($rows) > $maxRows) {
            break;
        }
    }
    fclose($h);
    return $rows;
}

function csv_detect_delimiter(string $text): string
{
    $lines = preg_split('/\r\n|\n|\r/', $text) ?: [];
    $sample = implode("\n", array_slice(array_values(array_filter($lines, fn($l) => trim($l) !== '')), 0, 30));
    $best = ';';
    $bestScore = -1.0;
    foreach ([';', "\t", ','] as $d) {
        $rows = csv_read_rows($sample, $d);
        if (!$rows) {
            continue;
        }
        $counts = array_count_values(array_map(fn($r) => count($r['fields']), $rows));
        arsort($counts);
        $mode = (int) array_key_first($counts);
        if ($mode < 2) {
            continue;
        }
        // Proporción de filas con la cantidad de columnas más común; se prefieren 2 o 3 columnas.
        $score = $counts[$mode] / count($rows) + ($mode <= 3 ? 0.05 : 0);
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $d;
        }
    }
    return $best;
}

/**
 * @return array{rows: list<array{line:int, week:?int, front:string, back:string}>, errors: list<string>, warnings: list<string>, format: array}
 */
function csv_parse_cards(string $raw): array
{
    // BOM y codificación.
    if (str_starts_with($raw, "\xEF\xBB\xBF")) {
        $raw = substr($raw, 3);
    }
    $encoding = 'UTF-8';
    if (!mb_check_encoding($raw, 'UTF-8')) {
        $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        $encoding = 'Windows-1252 (convertido)';
    }

    $delimiter = csv_detect_delimiter($raw);
    $maxRows = limit('max_import_rows');
    // +1 por un posible encabezado; si sigue habiendo más filas, el archivo es muy grande.
    $rows = csv_read_rows($raw, $delimiter, $maxRows + 1);
    if (count($rows) > $maxRows + 1) {
        return ['rows' => [], 'errors' => ['El archivo tiene más de ' . $maxRows . ' filas; el máximo por importación es ' . $maxRows . '.'], 'warnings' => [], 'format' => []];
    }
    $errors = [];
    $warnings = [];
    $result = [];

    if (!$rows) {
        return ['rows' => [], 'errors' => ['El archivo está vacío.'], 'warnings' => [], 'format' => []];
    }

    // ¿Tiene encabezado?
    $map = null;
    $first = $rows[0]['fields'];
    $keys = array_map('csv_header_key', $first);
    if (in_array('front', $keys, true) || in_array('back', $keys, true)) {
        $map = ['week' => array_search('week', $keys, true), 'front' => array_search('front', $keys, true), 'back' => array_search('back', $keys, true)];
        if ($map['front'] === false || $map['back'] === false) {
            return ['rows' => [], 'errors' => ['El encabezado debe tener las columnas "pregunta" y "respuesta".'], 'warnings' => [], 'format' => []];
        }
        array_shift($rows);
        $hasHeader = true;
    } else {
        $hasHeader = false;
        $counts = array_count_values(array_map(fn($r) => count($r['fields']), $rows));
        arsort($counts);
        $mode = (int) array_key_first($counts);
        if ($mode >= 3) {
            // ¿La primera columna es la semana?
            $weekLike = 0;
            foreach ($rows as $r) {
                if (csv_looks_like_week($r['fields'][0])) {
                    $weekLike++;
                }
            }
            $map = $weekLike >= count($rows) / 2
                ? ['week' => 0, 'front' => 1, 'back' => 2]
                : ['week' => false, 'front' => 0, 'back' => 1];
        } else {
            $map = ['week' => false, 'front' => 0, 'back' => 1];
        }
    }

    $lastIndex = max(array_filter([$map['week'], $map['front'], $map['back']], fn($v) => $v !== false));
    $joined = 0;
    $seen = [];      // hash => número de fila
    $indexOf = [];   // hash => posición en $result
    foreach ($rows as $r) {
        $f = $r['fields'];
        // Si hay más columnas de las esperadas y la respuesta es la última, se juntan
        // (pasa cuando una respuesta trae el separador sin comillas).
        if (count($f) > $lastIndex + 1 && $map['back'] === $lastIndex) {
            $f[$map['back']] = implode($delimiter, array_slice($f, $map['back']));
            $joined++;
        }
        $front = trim(str_replace(["\r\n", "\r"], "\n", $f[$map['front']] ?? ''));
        $back = trim(str_replace(["\r\n", "\r"], "\n", $f[$map['back']] ?? ''));
        $week = $map['week'] !== false ? csv_parse_week($f[$map['week']] ?? '') : null;

        if ($front === '' && $back === '') {
            continue;
        }
        if ($front === '' || $back === '') {
            $errors[] = 'Fila ' . $r['line'] . ': falta la ' . ($front === '' ? 'pregunta' : 'respuesta') . '.';
            continue;
        }
        if (mb_strlen($front) > CSV_MAX_FIELD || mb_strlen($back) > CSV_MAX_FIELD) {
            $errors[] = 'Fila ' . $r['line'] . ': el texto pasa de ' . CSV_MAX_FIELD . ' caracteres.';
            continue;
        }
        $hash = card_front_hash($front);
        if (isset($seen[$hash])) {
            if (count($warnings) < 50) {
                $warnings[] = 'Fila ' . $r['line'] . ': la pregunta se repite (fila ' . $seen[$hash] . '); se usa la última.';
            }
            unset($result[$indexOf[$hash]]);
        }
        $seen[$hash] = $r['line'];
        $result[] = ['line' => $r['line'], 'week' => $week, 'front' => $front, 'back' => $back, 'hash' => $hash];
        $indexOf[$hash] = array_key_last($result);
    }
    if ($joined > 0) {
        $warnings[] = plural($joined, 'fila tenía', 'filas tenían') . ' el separador dentro de la respuesta sin comillas; se unió al texto de la respuesta.';
    }

    $names = [';' => 'punto y coma (;)', ',' => 'coma (,)', "\t" => 'tabulador'];
    $columns = $map['week'] !== false ? 'semana, pregunta, respuesta' : 'pregunta, respuesta';
    return [
        'rows' => array_values($result),
        'errors' => $errors,
        'warnings' => $warnings,
        'format' => [
            'delimiter' => $names[$delimiter] ?? $delimiter,
            'header' => $hasHeader,
            'columns' => $columns,
            'encoding' => $encoding,
        ],
    ];
}

/** Texto CSV para exportar (se puede volver a importar). */
function csv_export(array $cards, bool $withDeck = false): string
{
    $h = fopen('php://temp', 'r+');
    fwrite($h, "\xEF\xBB\xBF");
    fputcsv($h, $withDeck ? ['mazo', 'semana', 'pregunta', 'respuesta'] : ['semana', 'pregunta', 'respuesta'], ';', '"', '');
    foreach ($cards as $c) {
        $row = [$c['week'] !== null ? (string) $c['week'] : '', $c['front'], $c['back']];
        if ($withDeck) {
            array_unshift($row, $c['deck_name']);
        }
        fputcsv($h, $row, ';', '"', '');
    }
    rewind($h);
    $out = stream_get_contents($h);
    fclose($h);
    return (string) $out;
}
