<?php
declare(strict_types=1);

/** Lee un valor de config.php. Acepta claves con punto: config('mail.driver'). */
function config(string $key, mixed $default = null): mixed
{
    $value = $GLOBALS['FC_CONFIG'];
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

/**
 * Clave secreta de la web. Si config.php no trae una, se crea una sola vez
 * y se guarda en storage/app_secret.key (fuera de la web y fuera de GitHub).
 */
function app_secret(): string
{
    static $secret = null;
    if ($secret !== null) {
        return $secret;
    }
    $fromConfig = (string) config('app_secret', '');
    if (strlen($fromConfig) >= 32 && !str_starts_with($fromConfig, 'CAMBIA')) {
        return $secret = $fromConfig;
    }
    $file = APP_ROOT . '/storage/app_secret.key';
    if (is_file($file)) {
        $saved = trim((string) file_get_contents($file));
        if (strlen($saved) >= 64) {
            return $secret = $saved;
        }
    }
    $secret = bin2hex(random_bytes(32));
    if (@file_put_contents($file, $secret, LOCK_EX) === false) {
        throw new RuntimeException('No se pudo guardar storage/app_secret.key: revisa los permisos de storage/.');
    }
    @chmod($file, 0600);
    return $secret;
}

function limit(string $name): int
{
    $defaults = [
        'max_decks' => 100,
        'max_cards' => 20000,
        'max_csv_bytes' => 2 * 1024 * 1024,
        'max_import_rows' => 5000,
        'max_imports_per_day' => 30,
    ];
    return (int) config('limits.' . $name, $defaults[$name] ?? 0);
}

/** Escapa texto para HTML. */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = '/'): string
{
    return rtrim((string) config('app_url', ''), '/') . $path;
}

function asset(string $path): string
{
    $public = defined('PUBLIC_ROOT') ? PUBLIC_ROOT : APP_ROOT . '/public';
    $file = $public . '/assets/' . ltrim($path, '/');
    $v = is_file($file) ? substr(md5((string) filemtime($file)), 0, 8) : APP_VERSION;
    return '/assets/' . ltrim($path, '/') . '?v=' . $v;
}

/** Devuelve $path si es una ruta interna segura ("/algo"); si no, $default. */
function safe_local_path(mixed $path, string $default): string
{
    if (!is_string($path) || $path === '' || strlen($path) > 500) {
        return $default;
    }
    if (!preg_match('#^/(?![/\\\\])#', $path) || preg_match('/[\\\\\x00-\x1F\x7F]/', $path)) {
        return $default;
    }
    return $path;
}

function redirect(string $path, int $status = 303): never
{
    header('Location: ' . $path, true, $status);
    exit;
}

function now_utc(): string
{
    return gmdate('Y-m-d H:i:s');
}

function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    if (($_SERVER['SERVER_PORT'] ?? '') === '443') {
        return true;
    }
    return strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

function request_path(): string
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $path = rawurldecode($path);
    if ($path !== '/' && str_ends_with($path, '/')) {
        $path = rtrim($path, '/');
    }
    return $path;
}

function is_api_request(): bool
{
    return str_starts_with(request_path(), '/api/');
}

function client_ip(): string
{
    $header = (string) config('trust_proxy_header', '');
    if ($header !== '') {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $header));
        if (!empty($_SERVER[$key])) {
            $ip = trim(explode(',', (string) $_SERVER[$key])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

function app_log(string $level, string $message): void
{
    $line = sprintf("[%s] %s: %s\n", gmdate('c'), strtoupper($level), $message);
    @file_put_contents(APP_ROOT . '/storage/logs/app.log', $line, FILE_APPEND | LOCK_EX);
}

// ---------- CSRF ----------

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_valid(): bool
{
    $sent = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return is_string($sent) && $sent !== '' && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $sent);
}

function require_csrf(): void
{
    if (!csrf_valid()) {
        if (is_api_request()) {
            json_response(['ok' => false, 'error' => 'La sesión expiró. Recarga la página.'], 403);
        }
        http_response_code(403);
        echo render('error', ['title' => 'La página expiró', 'message' => 'Vuelve atrás, recarga la página e inténtalo otra vez.']);
        exit;
    }
}

// ---------- Mensajes flash ----------

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $items = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $items;
}

// ---------- Vistas ----------

function render(string $__view, array $__data = [], ?string $__layout = 'layout'): string
{
    $__file = APP_ROOT . '/app/views/' . $__view . '.php';
    if (!is_file($__file)) {
        throw new RuntimeException('Vista no encontrada: ' . $__view);
    }
    // Nombres con __ para que las variables de la vista no choquen con las de esta función.
    extract($__data, EXTR_SKIP);
    ob_start();
    require $__file;
    $__content = (string) ob_get_clean();
    if ($__layout === null) {
        return $__content;
    }
    $__data['content'] = $__content;
    return render($__layout, $__data, null);
}

function view(string $view, array $data = [], int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    echo render($view, $data);
    exit;
}

function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_body(): array
{
    $raw = file_get_contents('php://input') ?: '';
    if (strlen($raw) > 200000) {
        json_response(['ok' => false, 'error' => 'Petición demasiado grande.'], 413);
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function not_found(): never
{
    if (is_api_request()) {
        json_response(['ok' => false, 'error' => 'No encontrado.'], 404);
    }
    view('error', ['title' => 'No encontrado', 'message' => 'Esta página no existe o no es tuya.'], 404);
}

// ---------- Fechas ----------

function user_tz(?array $user): DateTimeZone
{
    $name = $user['timezone'] ?? 'America/Lima';
    try {
        return new DateTimeZone($name);
    } catch (Throwable) {
        return new DateTimeZone('America/Lima');
    }
}

/** Medianoche de hoy en la zona del usuario, expresada en UTC ('Y-m-d H:i:s'). */
function local_day_start_utc(DateTimeZone $tz, int $offsetDays = 0): string
{
    $d = new DateTimeImmutable('now', $tz);
    $d = $d->setTime(0, 0)->modify(($offsetDays >= 0 ? '+' : '') . $offsetDays . ' days');
    return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

function utc_to_iso(?string $utc): ?string
{
    if ($utc === null || $utc === '') {
        return null;
    }
    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
}

/** Convierte ISO 8601 (del navegador) a 'Y-m-d H:i:s' UTC. Devuelve null si no es válido. */
function iso_to_utc(mixed $iso): ?string
{
    if (!is_string($iso) || $iso === '' || strlen($iso) > 40) {
        return null;
    }
    try {
        $d = new DateTimeImmutable($iso);
    } catch (Throwable) {
        return null;
    }
    return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

function format_local(?string $utc, DateTimeZone $tz, string $format = 'd/m/Y H:i'): string
{
    if (!$utc) {
        return '—';
    }
    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone($tz)->format($format);
}

function short_text(string $text, int $width = 90): string
{
    $text = preg_replace('/\s+/u', ' ', trim($text)) ?? $text;
    return mb_strimwidth($text, 0, $width, '…', 'UTF-8');
}

function plural(int $n, string $one, string $many): string
{
    return $n . ' ' . ($n === 1 ? $one : $many);
}

// ---------- Cabeceras de seguridad ----------

function send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }
    $scriptSrc = "'self'";
    $frameSrc = "'none'";
    $connectSrc = "'self'";
    if (turnstile_enabled()) {
        $scriptSrc .= ' https://challenges.cloudflare.com';
        $frameSrc = 'https://challenges.cloudflare.com';
        $connectSrc .= ' https://challenges.cloudflare.com';
    }
    $csp = implode('; ', [
        "default-src 'self'",
        "script-src $scriptSrc",
        // KaTeX usa estilos en línea para dibujar las fórmulas.
        "style-src 'self' 'unsafe-inline'",
        "img-src 'self' data:",
        "font-src 'self'",
        "connect-src $connectSrc",
        "frame-src $frameSrc",
        "object-src 'none'",
        "base-uri 'self'",
        "form-action 'self'",
        "frame-ancestors 'none'",
    ]);
    header('Content-Security-Policy: ' . $csp);
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
    if (is_https()) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

// ---------- Íconos (SVG en línea, trazo simple) ----------

function icon(string $name, string $class = 'icon'): string
{
    $paths = [
        'hoy' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
        'mazos' => '<rect x="3" y="7" width="14" height="13" rx="2"/><path d="M7 4h11a2 2 0 0 1 2 2v11"/>',
        'importar' => '<path d="M12 3v12M7 10l5 5 5-5"/><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/>',
        'errores' => '<path d="M12 3 2 20h20L12 3z"/><path d="M12 10v4M12 17.5v.5"/>',
        'cuenta' => '<circle cx="12" cy="8" r="4"/><path d="M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6"/>',
        'editar' => '<path d="M4 20h4L19 9l-4-4L4 16v4z"/><path d="M13.5 6.5l4 4"/>',
        'cerrar' => '<path d="M6 6l12 12M18 6 6 18"/>',
        'mas' => '<path d="M12 5v14M5 12h14"/>',
        'bajar' => '<path d="M12 4v11M7 10l5 5 5-5M5 20h14"/>',
        'examen' => '<path d="M9 4h6l1 2h3v15H5V6h3l1-2z"/><path d="M9 13l2 2 4-4"/>',
        'google' => '<path d="M21.6 12.2c0-.7-.1-1.4-.2-2H12v3.8h5.4a4.6 4.6 0 0 1-2 3v2.5h3.2c1.9-1.7 3-4.3 3-7.3z" fill="#4285F4" stroke="none"/><path d="M12 22c2.7 0 5-.9 6.6-2.4l-3.2-2.5c-.9.6-2 1-3.4 1-2.6 0-4.8-1.8-5.6-4.1H3.1v2.6A10 10 0 0 0 12 22z" fill="#34A853" stroke="none"/><path d="M6.4 14c-.2-.6-.3-1.3-.3-2s.1-1.4.3-2V7.4H3.1a10 10 0 0 0 0 9.2L6.4 14z" fill="#FBBC05" stroke="none"/><path d="M12 5.9c1.5 0 2.8.5 3.8 1.5l2.9-2.9A10 10 0 0 0 3.1 7.4L6.4 10c.8-2.3 3-4.1 5.6-4.1z" fill="#EA4335" stroke="none"/>',
    ];
    $inner = $paths[$name] ?? '';
    return '<svg class="' . e($class) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $inner . '</svg>';
}
