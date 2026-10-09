<?php
// Prueba el lector de CSV con los 8 archivos reales.
declare(strict_types=1);
define('APP_ROOT', dirname(__DIR__));
$GLOBALS['FC_CONFIG'] = [];
require APP_ROOT . '/app/lib/helpers.php';
require APP_ROOT . '/app/lib/csv.php';
require APP_ROOT . '/app/lib/cards.php';
$total = 0;
foreach (glob(__DIR__ . '/fixtures/*.csv') as $f) {
    $raw = file_get_contents($f);
    $lines = count(array_filter(preg_split('/\r\n|\n|\r/', $raw), fn($l) => trim($l) !== ''));
    $p = csv_parse_cards($raw);
    $weeks = array_values(array_unique(array_filter(array_column($p['rows'], 'week'), fn($w) => $w !== null)));
    sort($weeks);
    $total += count($p['rows']);
    printf("%-48s líneas=%3d tarjetas=%3d errores=%d avisos=%d sep=%s enc=%s semanas=%s\n",
        basename($f), $lines, count($p['rows']), count($p['errors']), count($p['warnings']),
        $p['format']['delimiter'], $p['format']['header'] ? 'sí' : 'no', implode(',', $weeks));
    foreach (array_merge($p['errors'], $p['warnings']) as $m) echo "    · $m\n";
    // Muestra filas con comillas para revisar que se leyeron bien.
    $shown = 0;
    foreach ($p['rows'] as $r) {
        if ($shown < 2 && (str_contains($r['front'] . $r['back'], '"') )) {
            echo "    [L{$r['line']}] F: " . mb_substr($r['front'], 0, 90) . "\n            B: " . mb_substr($r['back'], 0, 110) . "\n";
            $shown++;
        }
    }
}
echo "TOTAL tarjetas: $total\n";
