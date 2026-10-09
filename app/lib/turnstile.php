<?php
declare(strict_types=1);

// Cloudflare Turnstile (opcional). Verificación según
// https://developers.cloudflare.com/turnstile/get-started/server-side-validation/

function turnstile_enabled(): bool
{
    return (string) config('turnstile.site_key', '') !== '' && (string) config('turnstile.secret_key', '') !== '';
}

function turnstile_verify(string $token): bool
{
    if (!turnstile_enabled()) {
        return true;
    }
    if ($token === '' || strlen($token) > 2048) {
        return false;
    }
    try {
        $res = http_post_form('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
            'secret' => config('turnstile.secret_key'),
            'response' => $token,
            'remoteip' => client_ip(),
        ]);
    } catch (Throwable $e) {
        app_log('warning', 'Turnstile sin respuesta: ' . $e->getMessage());
        return false;
    }
    return ($res['json']['success'] ?? false) === true;
}
