<?php
// Flashcards — punto de entrada único. Copyright (C) 2026 Jordan Palacios. AGPL-3.0-only.

declare(strict_types=1);

// Servidor de pruebas de PHP: deja pasar los archivos que existen.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if ($file !== __DIR__ . '/' && is_file($file)) {
        return false;
    }
}

// En SiteGround, app/ está una carpeta arriba de public_html.
// Si subiste todo dentro de public_html, también funciona (app/ queda protegida por su .htaccess).
define('PUBLIC_ROOT', __DIR__);
$appDir = is_dir(__DIR__ . '/../app') ? __DIR__ . '/../app' : __DIR__ . '/app';
require $appDir . '/bootstrap.php';
require $appDir . '/routes.php';

$path = request_path();
if ($path === '/index.php') {
    redirect('/', 301);
}
dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $path);
