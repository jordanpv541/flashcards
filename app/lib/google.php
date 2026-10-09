<?php
declare(strict_types=1);

// Login con Google (OpenID Connect, flujo de código con PKCE). Sin librerías externas.
// Endpoints según https://developers.google.com/identity/openid-connect/openid-connect

const GOOGLE_AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
const GOOGLE_TOKEN_URL = 'https://oauth2.googleapis.com/token';

function google_enabled(): bool
{
    return (string) config('google.client_id', '') !== '' && (string) config('google.client_secret', '') !== '';
}

function google_redirect_uri(): string
{
    return url('/auth/google/callback');
}

function base64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode(string $data): string|false
{
    $data = strtr($data, '-_', '+/');
    $pad = strlen($data) % 4;
    if ($pad) {
        $data .= str_repeat('=', 4 - $pad);
    }
    return base64_decode($data, true);
}

function google_auth_url(bool $remember): string
{
    $state = bin2hex(random_bytes(24));
    $nonce = bin2hex(random_bytes(24));
    $verifier = base64url_encode(random_bytes(48));
    $_SESSION['google_oauth'] = [
        'state' => $state,
        'nonce' => $nonce,
        'verifier' => $verifier,
        'remember' => $remember,
        'created' => time(),
    ];
    return GOOGLE_AUTH_URL . '?' . http_build_query([
        'client_id' => config('google.client_id'),
        'redirect_uri' => google_redirect_uri(),
        'response_type' => 'code',
        'scope' => 'openid email profile',
        'state' => $state,
        'nonce' => $nonce,
        'code_challenge' => base64url_encode(hash('sha256', $verifier, true)),
        'code_challenge_method' => 'S256',
        'prompt' => 'select_account',
    ]);
}

function http_post_form(string $url, array $fields): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($body === false) {
        throw new RuntimeException('HTTP: ' . $error);
    }
    $json = json_decode((string) $body, true);
    return ['status' => $status, 'json' => is_array($json) ? $json : []];
}

/**
 * Procesa la vuelta desde Google. Devuelve ['sub','email','name','remember'].
 * Lanza RuntimeException con un mensaje para el usuario si algo no cuadra.
 */
function google_handle_callback(array $query): array
{
    $saved = $_SESSION['google_oauth'] ?? null;
    unset($_SESSION['google_oauth']);

    if (!empty($query['error'])) {
        throw new RuntimeException('Cancelaste el inicio de sesión con Google.');
    }
    if (!is_array($saved) || time() - (int) $saved['created'] > 600) {
        throw new RuntimeException('El intento de inicio de sesión expiró. Vuelve a intentarlo.');
    }
    $state = (string) ($query['state'] ?? '');
    if ($state === '' || !hash_equals($saved['state'], $state)) {
        throw new RuntimeException('No se pudo verificar el inicio de sesión. Vuelve a intentarlo.');
    }
    $code = (string) ($query['code'] ?? '');
    if ($code === '' || strlen($code) > 2048) {
        throw new RuntimeException('Google no devolvió un código válido.');
    }

    $res = http_post_form(GOOGLE_TOKEN_URL, [
        'code' => $code,
        'client_id' => config('google.client_id'),
        'client_secret' => config('google.client_secret'),
        'redirect_uri' => google_redirect_uri(),
        'grant_type' => 'authorization_code',
        'code_verifier' => $saved['verifier'],
    ]);
    $idToken = $res['json']['id_token'] ?? null;
    if ($res['status'] !== 200 || !is_string($idToken)) {
        app_log('warning', 'Google token: estado ' . $res['status'] . ' ' . json_encode($res['json']['error'] ?? null));
        throw new RuntimeException('Google no confirmó tu cuenta. Vuelve a intentarlo.');
    }

    // El id_token llega directo de Google por HTTPS; se validan sus datos (iss, aud, exp, nonce).
    $parts = explode('.', $idToken);
    $payload = count($parts) === 3 ? json_decode((string) base64url_decode($parts[1]), true) : null;
    if (!is_array($payload)) {
        throw new RuntimeException('Respuesta de Google no válida.');
    }
    $issOk = in_array($payload['iss'] ?? '', ['https://accounts.google.com', 'accounts.google.com'], true);
    $audOk = ($payload['aud'] ?? '') === config('google.client_id');
    $expOk = (int) ($payload['exp'] ?? 0) > time() - 60;
    $nonceOk = isset($payload['nonce']) && hash_equals($saved['nonce'], (string) $payload['nonce']);
    if (!$issOk || !$audOk || !$expOk || !$nonceOk || empty($payload['sub'])) {
        throw new RuntimeException('No se pudo verificar tu cuenta de Google.');
    }
    $verified = $payload['email_verified'] ?? false;
    if (!($verified === true || $verified === 'true') || empty($payload['email'])) {
        throw new RuntimeException('Tu correo de Google no está verificado.');
    }
    return [
        'sub' => (string) $payload['sub'],
        'email' => normalize_email((string) $payload['email']),
        'name' => mb_substr((string) ($payload['name'] ?? ''), 0, 100),
        'remember' => (bool) $saved['remember'],
    ];
}

/** Busca al usuario por su id de Google; si no existe, lo une por correo o lo crea. */
function google_login_user(array $info): array
{
    $user = q1('SELECT * FROM users WHERE google_sub = ?', [$info['sub']]);
    if ($user) {
        return $user;
    }
    $user = q1('SELECT * FROM users WHERE email = ?', [$info['email']]);
    if ($user) {
        q('UPDATE users SET google_sub = ? WHERE id = ?', [$info['sub'], $user['id']]);
        return q1('SELECT * FROM users WHERE id = ?', [$user['id']]);
    }
    q(
        'INSERT INTO users (email, name, google_sub, created_at) VALUES (?, ?, ?, ?)',
        [$info['email'], $info['name'] !== '' ? $info['name'] : default_name_from_email($info['email']), $info['sub'], now_utc()]
    );
    return q1('SELECT * FROM users WHERE id = ?', [(int) db()->lastInsertId()]);
}
