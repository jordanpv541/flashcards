<?php
declare(strict_types=1);

const REMEMBER_COOKIE = 'fc_recordar';
const REMEMBER_DAYS = 60;
const CODE_TTL_SECONDS = 600;   // el código vence en 10 minutos
const CODE_MAX_ATTEMPTS = 5;    // intentos por código

function session_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', '86400');
    session_name('fc_sesion');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function current_user(): ?array
{
    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }
    $cache = null;
    $uid = $_SESSION['uid'] ?? null;
    if ($uid) {
        $cache = q1('SELECT * FROM users WHERE id = ?', [(int) $uid]);
        if (!$cache) {
            unset($_SESSION['uid']);
        }
    }
    if (!$cache) {
        $remembered = remember_check();
        if ($remembered) {
            session_regenerate_id(true);
            $_SESSION['uid'] = (int) $remembered['id'];
            $cache = $remembered;
        }
    }
    return $cache;
}

function require_user(): array
{
    $user = current_user();
    if ($user) {
        return $user;
    }
    if (is_api_request()) {
        json_response(['ok' => false, 'error' => 'Tu sesión terminó. Vuelve a entrar.'], 401);
    }
    $_SESSION['after_login'] = $_SERVER['REQUEST_URI'] ?? '/hoy';
    redirect('/entrar');
}

function login_user(int $userId, bool $remember): void
{
    session_regenerate_id(true);
    $_SESSION['uid'] = $userId;
    unset($_SESSION['login_email']);
    q('UPDATE users SET last_login_at = ? WHERE id = ?', [now_utc(), $userId]);
    if ($remember) {
        remember_create($userId);
    }
}

function logout_user(): void
{
    $cookie = $_COOKIE[REMEMBER_COOKIE] ?? '';
    if (is_string($cookie) && str_contains($cookie, ':')) {
        [$selector] = explode(':', $cookie, 2);
        q('DELETE FROM remember_tokens WHERE selector = ?', [$selector]);
    }
    set_remember_cookie('', time() - 3600);
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

function after_login_path(): string
{
    $path = $_SESSION['after_login'] ?? '/hoy';
    unset($_SESSION['after_login']);
    return safe_local_path($path, '/hoy');
}

// ---------- "Mantener sesión iniciada" ----------

function set_remember_cookie(string $value, int $expires): void
{
    setcookie(REMEMBER_COOKIE, $value, [
        'expires' => $expires,
        'path' => '/',
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function remember_create(int $userId): void
{
    $selector = bin2hex(random_bytes(12));
    $validator = bin2hex(random_bytes(32));
    $expires = time() + REMEMBER_DAYS * 86400;
    q(
        'INSERT INTO remember_tokens (user_id, selector, validator_hash, expires_at, created_at) VALUES (?, ?, ?, ?, ?)',
        [$userId, $selector, hash('sha256', $validator), gmdate('Y-m-d H:i:s', $expires), now_utc()]
    );
    set_remember_cookie($selector . ':' . $validator, $expires);
}

function remember_check(): ?array
{
    $cookie = $_COOKIE[REMEMBER_COOKIE] ?? '';
    if (!is_string($cookie) || !preg_match('/^([a-f0-9]{24}):([a-f0-9]{64})$/', $cookie, $m)) {
        return null;
    }
    $row = q1('SELECT * FROM remember_tokens WHERE selector = ?', [$m[1]]);
    if (!$row) {
        set_remember_cookie('', time() - 3600);
        return null;
    }
    if (strtotime($row['expires_at'] . ' UTC') < time() || !hash_equals($row['validator_hash'], hash('sha256', $m[2]))) {
        q('DELETE FROM remember_tokens WHERE id = ?', [$row['id']]);
        set_remember_cookie('', time() - 3600);
        return null;
    }
    // Se usa una sola vez: se borra y se crea uno nuevo.
    q('DELETE FROM remember_tokens WHERE id = ?', [$row['id']]);
    remember_create((int) $row['user_id']);
    return q1('SELECT * FROM users WHERE id = ?', [$row['user_id']]);
}

// ---------- Usuarios ----------

function normalize_email(string $email): string
{
    return mb_strtolower(trim($email));
}

function valid_email(string $email): bool
{
    // Sin caracteres de control, espacios ni comillas (evita inyectar cabeceras o comandos SMTP).
    if (preg_match('/[\x00-\x20\x7F"\\\\]/', $email)) {
        return false;
    }
    return strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function default_name_from_email(string $email): string
{
    $local = explode('@', $email)[0] ?? '';
    $local = preg_replace('/[._\-+]+/', ' ', $local) ?? $local;
    return mb_substr(trim(mb_convert_case($local, MB_CASE_TITLE)), 0, 100);
}

function user_find_or_create_by_email(string $email, string $name = ''): array
{
    $user = q1('SELECT * FROM users WHERE email = ?', [$email]);
    if ($user) {
        return $user;
    }
    q(
        'INSERT INTO users (email, name, created_at) VALUES (?, ?, ?)',
        [$email, mb_substr($name !== '' ? $name : default_name_from_email($email), 0, 100), now_utc()]
    );
    return q1('SELECT * FROM users WHERE id = ?', [(int) db()->lastInsertId()]);
}

// ---------- Códigos por correo ----------

function code_hash(string $email, string $code): string
{
    return hash_hmac('sha256', $email . '|' . $code, app_secret());
}

function code_create(string $email): string
{
    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    // Solo vale el último código: los anteriores se anulan.
    q('UPDATE login_codes SET used_at = ? WHERE email = ? AND used_at IS NULL', [now_utc(), $email]);
    q(
        'INSERT INTO login_codes (email, code_hash, expires_at, created_at) VALUES (?, ?, ?, ?)',
        [$email, code_hash($email, $code), gmdate('Y-m-d H:i:s', time() + CODE_TTL_SECONDS), now_utc()]
    );
    return $code;
}

/** Devuelve 'ok', 'wrong', 'expired' o 'none'. */
function code_verify(string $email, string $code): string
{
    $row = q1(
        'SELECT * FROM login_codes WHERE email = ? AND used_at IS NULL ORDER BY id DESC LIMIT 1',
        [$email]
    );
    if (!$row) {
        return 'none';
    }
    if (strtotime($row['expires_at'] . ' UTC') < time() || (int) $row['attempts'] >= CODE_MAX_ATTEMPTS) {
        q('UPDATE login_codes SET used_at = ? WHERE id = ?', [now_utc(), $row['id']]);
        return 'expired';
    }
    if (!preg_match('/^\d{6}$/', $code) || !hash_equals($row['code_hash'], code_hash($email, $code))) {
        $attempts = (int) $row['attempts'] + 1;
        q('UPDATE login_codes SET attempts = ? WHERE id = ?', [$attempts, $row['id']]);
        if ($attempts >= CODE_MAX_ATTEMPTS) {
            q('UPDATE login_codes SET used_at = ? WHERE id = ?', [now_utc(), $row['id']]);
            return 'expired';
        }
        return 'wrong';
    }
    q('UPDATE login_codes SET used_at = ? WHERE id = ?', [now_utc(), $row['id']]);
    return 'ok';
}

function code_cleanup(): void
{
    if (random_int(1, 50) === 1) {
        q('DELETE FROM login_codes WHERE created_at < ?', [gmdate('Y-m-d H:i:s', time() - 2 * 86400)]);
        q('DELETE FROM remember_tokens WHERE expires_at < ?', [now_utc()]);
    }
}
