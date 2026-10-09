<?php
// Flashcards — tarjetas de estudio con repaso espaciado.
// Copyright (C) 2026 Jordan Palacios. Licencia AGPL-3.0-only (ver LICENSE).

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
define('APP_VERSION', '1.0.0');

date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

$configFile = APP_ROOT . '/config/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Falta config/config.php. Copia config/config.example.php como config.php y complétalo.\n";
    exit;
}
$GLOBALS['FC_CONFIG'] = require $configFile;

require APP_ROOT . '/app/lib/helpers.php';
require APP_ROOT . '/app/lib/db.php';
require APP_ROOT . '/app/lib/ratelimit.php';
require APP_ROOT . '/app/lib/auth.php';
require APP_ROOT . '/app/lib/mailer.php';
require APP_ROOT . '/app/lib/google.php';
require APP_ROOT . '/app/lib/turnstile.php';
require APP_ROOT . '/app/lib/csv.php';
require APP_ROOT . '/app/lib/cards.php';

ini_set('display_errors', config('debug') ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', APP_ROOT . '/storage/logs/php-error.log');
error_reporting(E_ALL);

set_exception_handler(function (Throwable $e): void {
    app_log('error', get_class($e) . ': ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
    }
    if (is_api_request()) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['ok' => false, 'error' => 'Error del servidor. Inténtalo de nuevo.']);
        return;
    }
    $detail = config('debug') ? $e->getMessage() : '';
    echo render('error', ['title' => 'Algo salió mal', 'message' => 'Hubo un error del servidor. Inténtalo de nuevo en un momento.', 'detail' => $detail]);
});

send_security_headers();
session_boot();
