<?php
declare(strict_types=1);

// Límites simples basados en eventos guardados en la tabla rate_events.

function rate_hit(string $bucket): void
{
    q('INSERT INTO rate_events (bucket, created_at) VALUES (?, ?)', [mb_substr($bucket, 0, 190), now_utc()]);
    // Limpieza ocasional de eventos viejos.
    if (random_int(1, 100) === 1) {
        q('DELETE FROM rate_events WHERE created_at < ?', [gmdate('Y-m-d H:i:s', time() - 3 * 86400)]);
    }
}

function rate_count(string $bucket, int $seconds): int
{
    return (int) qv(
        'SELECT COUNT(*) FROM rate_events WHERE bucket = ? AND created_at >= ?',
        [mb_substr($bucket, 0, 190), gmdate('Y-m-d H:i:s', time() - $seconds)]
    );
}

function rate_allow(string $bucket, int $max, int $seconds): bool
{
    return rate_count($bucket, $seconds) < $max;
}

/** Segundos que faltan para que el evento más viejo de la ventana deje de contar. */
function rate_wait_seconds(string $bucket, int $seconds): int
{
    $oldest = qv(
        'SELECT MIN(created_at) FROM rate_events WHERE bucket = ? AND created_at >= ?',
        [mb_substr($bucket, 0, 190), gmdate('Y-m-d H:i:s', time() - $seconds)]
    );
    if (!$oldest) {
        return 0;
    }
    return max(0, strtotime($oldest . ' UTC') + $seconds - time());
}
