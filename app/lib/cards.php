<?php
declare(strict_types=1);

// Lógica de mazos, tarjetas y repaso. Toda consulta filtra por user_id.

const LEARN_AHEAD_SECONDS = 1200; // tarjetas en aprendizaje que vencen en los próximos 20 min cuentan para hoy
const FIELD_MAX = 5000;

function card_front_hash(string $front): string
{
    $norm = mb_strtolower(preg_replace('/\s+/u', ' ', trim($front)) ?? $front);
    return hash('sha256', $norm);
}

function deck_for_user(int $userId, int $deckId): ?array
{
    return q1('SELECT * FROM decks WHERE id = ? AND user_id = ?', [$deckId, $userId]);
}

function card_for_user(int $userId, int $cardId): ?array
{
    return q1(
        'SELECT c.*, d.name AS deck_name FROM cards c JOIN decks d ON d.id = c.deck_id WHERE c.id = ? AND c.user_id = ?',
        [$cardId, $userId]
    );
}

function due_condition_params(): array
{
    return [gmdate('Y-m-d H:i:s', time() + LEARN_AHEAD_SECONDS), now_utc()];
}

/** Mazos del usuario con sus cuentas (total, pendientes, nuevas). */
function decks_with_counts(int $userId): array
{
    [$ahead, $now] = due_condition_params();
    return qall(
        'SELECT d.id, d.name,
                COUNT(c.id) AS total,
                COALESCE(SUM(c.state = 0), 0) AS new_total,
                COALESCE(SUM(CASE WHEN c.state IN (1,3) AND c.due <= ? THEN 1
                                  WHEN c.state = 2 AND c.due <= ? THEN 1 ELSE 0 END), 0) AS due_now
           FROM decks d
           LEFT JOIN cards c ON c.deck_id = d.id
          WHERE d.user_id = ?
          GROUP BY d.id, d.name
          ORDER BY d.name',
        [$ahead, $now, $userId]
    );
}

function deck_weeks(int $userId, int $deckId): array
{
    return array_map('intval', array_column(qall(
        'SELECT DISTINCT week FROM cards WHERE user_id = ? AND deck_id = ? AND week IS NOT NULL ORDER BY week',
        [$userId, $deckId]
    ), 'week'));
}

function new_introduced_today(array $user): int
{
    return (int) qv(
        'SELECT COUNT(DISTINCT card_id) FROM review_log WHERE user_id = ? AND state_before = 0 AND cram = 0 AND reviewed_at >= ?',
        [$user['id'], local_day_start_utc(user_tz($user))]
    );
}

function new_remaining_today(array $user): int
{
    return max(0, (int) $user['new_per_day'] - new_introduced_today($user));
}

/** Resumen para la pantalla Hoy. */
function today_summary(array $user): array
{
    [$ahead, $now] = due_condition_params();
    $uid = (int) $user['id'];
    $due = (int) qv(
        'SELECT COUNT(*) FROM cards WHERE user_id = ? AND ((state IN (1,3) AND due <= ?) OR (state = 2 AND due <= ?))',
        [$uid, $ahead, $now]
    );
    $newAvailable = (int) qv('SELECT COUNT(*) FROM cards WHERE user_id = ? AND state = 0', [$uid]);
    $remaining = new_remaining_today($user);
    $tz = user_tz($user);
    $tomorrow = (int) qv(
        'SELECT COUNT(*) FROM cards WHERE user_id = ? AND state > 0 AND due > ? AND due < ?',
        [$uid, $now, local_day_start_utc($tz, 2)]
    );
    $reviewedToday = (int) qv(
        'SELECT COUNT(*) FROM review_log WHERE user_id = ? AND reviewed_at >= ?',
        [$uid, local_day_start_utc($tz)]
    );
    return [
        'due' => $due,
        'new_today' => min($remaining, $newAvailable),
        'new_available' => $newAvailable,
        'new_remaining' => $remaining,
        'tomorrow' => $tomorrow,
        'reviewed_today' => $reviewedToday,
        'streak' => study_streak($user),
    ];
}

/** Días seguidos con al menos un repaso (contando hoy o ayer). */
function study_streak(array $user): int
{
    $tz = user_tz($user);
    $rows = qall(
        'SELECT reviewed_at FROM review_log WHERE user_id = ? AND reviewed_at >= ? ORDER BY reviewed_at DESC',
        [$user['id'], gmdate('Y-m-d H:i:s', time() - 400 * 86400)]
    );
    $days = [];
    foreach ($rows as $r) {
        $days[format_local($r['reviewed_at'], $tz, 'Y-m-d')] = true;
    }
    $streak = 0;
    $day = new DateTimeImmutable('now', $tz);
    if (!isset($days[$day->format('Y-m-d')])) {
        $day = $day->modify('-1 day');
    }
    while (isset($days[$day->format('Y-m-d')])) {
        $streak++;
        $day = $day->modify('-1 day');
    }
    return $streak;
}

function card_filters_sql(int $userId, ?int $deckId, array $weeks, string $alias = 'c'): array
{
    $sql = "$alias.user_id = ?";
    $params = [$userId];
    if ($deckId) {
        $sql .= " AND $alias.deck_id = ?";
        $params[] = $deckId;
    }
    $weeks = array_values(array_unique(array_filter(array_map('intval', $weeks), fn($w) => $w >= 0 && $w <= 999)));
    if ($weeks) {
        $sql .= " AND $alias.week IN (" . implode(',', array_fill(0, count($weeks), '?')) . ')';
        array_push($params, ...$weeks);
    }
    return [$sql, $params];
}

function card_to_client(array $c): array
{
    return [
        'id' => (int) $c['id'],
        'deck_id' => (int) $c['deck_id'],
        'deck_name' => (string) ($c['deck_name'] ?? ''),
        'week' => $c['week'] !== null ? (int) $c['week'] : null,
        'front' => (string) $c['front'],
        'back' => (string) $c['back'],
        'card' => [
            'due' => utc_to_iso($c['due']),
            'stability' => (float) $c['stability'],
            'difficulty' => (float) $c['difficulty'],
            'elapsed_days' => (int) $c['elapsed_days'],
            'scheduled_days' => (int) $c['scheduled_days'],
            'learning_steps' => (int) $c['learning_steps'],
            'reps' => (int) $c['reps'],
            'lapses' => (int) $c['lapses'],
            'state' => (int) $c['state'],
            'last_review' => utc_to_iso($c['last_review']),
        ],
    ];
}

/**
 * Arma la sesión de repaso.
 * $mode: 'hoy' (FSRS), 'examen' (repaso total, no mueve el horario) o 'errores' (cuaderno de errores).
 */
function build_session(array $user, string $mode, ?int $deckId, array $weeks, ?int $limit): array
{
    $uid = (int) $user['id'];
    [$where, $params] = card_filters_sql($uid, $deckId, $weeks);
    $select = 'SELECT c.*, d.name AS deck_name FROM cards c JOIN decks d ON d.id = c.deck_id';

    if ($mode === 'examen') {
        $n = min(max($limit ?? 500, 1), 1000);
        $rows = qall("$select WHERE $where ORDER BY RAND() LIMIT $n", $params);
        return array_map('card_to_client', $rows);
    }

    if ($mode === 'errores') {
        $n = min(max($limit ?? 100, 1), 500);
        $since = gmdate('Y-m-d H:i:s', time() - 30 * 86400);
        $rows = qall(
            "SELECT c.*, d.name AS deck_name, COALESCE(f.fails, 0) AS fails
               FROM cards c
               JOIN decks d ON d.id = c.deck_id
               LEFT JOIN (SELECT card_id, COUNT(*) AS fails FROM review_log
                           WHERE user_id = ? AND rating = 1 AND reviewed_at >= ? GROUP BY card_id) f ON f.card_id = c.id
              WHERE $where AND (COALESCE(f.fails, 0) > 0 OR c.lapses > 0)
              ORDER BY fails DESC, c.lapses DESC, c.id
              LIMIT $n",
            array_merge([$uid, $since], $params)
        );
        return array_map('card_to_client', $rows);
    }

    // Modo hoy: pendientes + nuevas (con tope diario), mezclando cursos.
    [$ahead, $now] = due_condition_params();
    $due = qall(
        "$select WHERE $where AND ((c.state IN (1,3) AND c.due <= ?) OR (c.state = 2 AND c.due <= ?)) ORDER BY c.due LIMIT 1000",
        array_merge($params, [$ahead, $now])
    );

    $newRemaining = new_remaining_today($user);
    $new = [];
    if ($newRemaining > 0) {
        $candidates = qall(
            "$select WHERE $where AND c.state = 0 ORDER BY (c.week IS NULL), c.week, c.position, c.id LIMIT " . min($newRemaining * 10, 2000),
            $params
        );
        // Ronda entre mazos para que las nuevas vengan de todos los cursos, respetando el orden de semana.
        $byDeck = [];
        foreach ($candidates as $c) {
            $byDeck[$c['deck_id']][] = $c;
        }
        $lists = array_values($byDeck);
        $next = array_fill(0, count($lists), 0);
        while (count($new) < $newRemaining) {
            $added = false;
            foreach ($lists as $k => $list) {
                if (count($new) >= $newRemaining) {
                    break;
                }
                if ($next[$k] < count($list)) {
                    $new[] = $list[$next[$k]++];
                    $added = true;
                }
            }
            if (!$added) {
                break;
            }
        }
    }

    // Una nueva cada 3 pendientes.
    $queue = [];
    $i = 0;
    while ($due || $new) {
        if ($due && ($i % 4 !== 3 || !$new)) {
            $queue[] = array_shift($due);
        } elseif ($new) {
            $queue[] = array_shift($new);
        }
        $i++;
    }
    if ($limit !== null) {
        $queue = array_slice($queue, 0, min(max($limit, 1), 500));
    }
    return array_map('card_to_client', $queue);
}

/**
 * Guarda una respuesta. Devuelve ['status' => 200|409|422, ...].
 */
function apply_answer(array $user, array $in): array
{
    $uid = (int) $user['id'];
    $uuid = (string) ($in['uuid'] ?? '');
    $cardId = (int) ($in['card_id'] ?? 0);
    $rating = (int) ($in['rating'] ?? 0);
    $mode = (string) ($in['mode'] ?? 'hoy');
    $duration = min(max((int) ($in['duration_ms'] ?? 0), 0), 600000);

    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
        return ['status' => 422, 'error' => 'Respuesta sin identificador válido.'];
    }
    if (!in_array($mode, ['hoy', 'examen', 'errores'], true)) {
        return ['status' => 422, 'error' => 'Modo no válido.'];
    }
    if ($rating < 1 || $rating > 4) {
        return ['status' => 422, 'error' => 'Calificación no válida.'];
    }

    return transaction(function () use ($uid, $uuid, $cardId, $rating, $mode, $duration, $in) {
        if (qv('SELECT id FROM review_log WHERE client_uuid = ? AND user_id = ?', [$uuid, $uid])) {
            return ['status' => 200, 'duplicate' => true];
        }
        $card = q1('SELECT * FROM cards WHERE id = ? AND user_id = ? FOR UPDATE', [$cardId, $uid]);
        if (!$card) {
            return ['status' => 404, 'error' => 'La tarjeta ya no existe.'];
        }

        if ($mode !== 'hoy') {
            // Modo examen o errores: se guarda en el historial pero no cambia el horario FSRS.
            q(
                'INSERT INTO review_log (user_id, card_id, client_uuid, rating, state_before, due_before, stability, difficulty, scheduled_days, cram, duration_ms, reviewed_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)',
                [$uid, $cardId, $uuid, $rating === 1 ? 1 : 3, $card['state'], $card['due'], $card['stability'], $card['difficulty'], $card['scheduled_days'], $duration, now_utc()]
            );
            return ['status' => 200];
        }

        $repsBefore = (int) ($in['reps_before'] ?? -1);
        if ($repsBefore !== (int) $card['reps']) {
            // La tarjeta cambió en otro dispositivo: se devuelve el estado actual.
            $fresh = card_for_user($uid, $cardId);
            return ['status' => 409, 'error' => 'Esta tarjeta ya se repasó en otro dispositivo.', 'card' => $fresh ? card_to_client($fresh) : null];
        }

        $clientNow = iso_to_utc($in['now'] ?? null);
        if ($clientNow === null || abs(strtotime($clientNow . ' UTC') - time()) > 86400) {
            return ['status' => 422, 'error' => 'La hora de tu dispositivo no es correcta. Revísala y vuelve a intentar.'];
        }

        $s = is_array($in['card'] ?? null) ? $in['card'] : [];
        $due = iso_to_utc($s['due'] ?? null);
        $lastReview = iso_to_utc($s['last_review'] ?? null);
        $state = (int) ($s['state'] ?? -1);
        $stability = (float) ($s['stability'] ?? -1);
        $difficulty = (float) ($s['difficulty'] ?? -1);
        $elapsed = (int) ($s['elapsed_days'] ?? -1);
        $scheduled = (int) ($s['scheduled_days'] ?? -1);
        $steps = (int) ($s['learning_steps'] ?? -1);
        $reps = (int) ($s['reps'] ?? -1);
        $lapses = (int) ($s['lapses'] ?? -1);

        $valid = $due !== null && $lastReview !== null
            && $state >= 1 && $state <= 3
            && is_finite($stability) && $stability > 0 && $stability < 1e6
            && is_finite($difficulty) && $difficulty >= 0 && $difficulty <= 11
            && $elapsed >= 0 && $elapsed <= 100000
            && $scheduled >= 0 && $scheduled <= 40000
            && $steps >= 0 && $steps <= 100
            && $reps === (int) $card['reps'] + 1
            && $lapses >= (int) $card['lapses'] && $lapses <= (int) $card['lapses'] + 1
            && strtotime($due . ' UTC') >= time() - 86400
            && strtotime($due . ' UTC') <= time() + 101 * 365 * 86400;
        if (!$valid) {
            return ['status' => 422, 'error' => 'Datos de repaso no válidos.'];
        }

        q(
            'INSERT INTO review_log (user_id, card_id, client_uuid, rating, state_before, due_before, stability, difficulty, scheduled_days, cram, duration_ms, reviewed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?)',
            [$uid, $cardId, $uuid, $rating, $card['state'], $card['due'], $stability, $difficulty, $scheduled, $duration, now_utc()]
        );
        q(
            'UPDATE cards SET due = ?, stability = ?, difficulty = ?, elapsed_days = ?, scheduled_days = ?, learning_steps = ?,
                    reps = ?, lapses = ?, state = ?, last_review = ?, updated_at = ?
              WHERE id = ? AND user_id = ?',
            [$due, $stability, $difficulty, $elapsed, $scheduled, $steps, $reps, $lapses, $state, $lastReview, now_utc(), $cardId, $uid]
        );
        return ['status' => 200];
    });
}

/** Valida y limpia el texto de una tarjeta. Devuelve [datos, error]. */
function card_input(array $in): array
{
    $front = trim(str_replace(["\r\n", "\r"], "\n", (string) ($in['front'] ?? '')));
    $back = trim(str_replace(["\r\n", "\r"], "\n", (string) ($in['back'] ?? '')));
    $weekRaw = trim((string) ($in['week'] ?? ''));
    if ($front === '' || $back === '') {
        return [null, 'Escribe la pregunta y la respuesta.'];
    }
    if (mb_strlen($front) > FIELD_MAX || mb_strlen($back) > FIELD_MAX) {
        return [null, 'El texto es muy largo (máximo ' . FIELD_MAX . ' caracteres).'];
    }
    $week = null;
    if ($weekRaw !== '') {
        $week = csv_parse_week($weekRaw);
        if ($week === null) {
            return [null, 'La semana debe ser un número (por ejemplo 3).'];
        }
    }
    return [['front' => $front, 'back' => $back, 'week' => $week], null];
}

function user_card_count(int $userId): int
{
    return (int) qv('SELECT COUNT(*) FROM cards WHERE user_id = ?', [$userId]);
}

/** Crea una tarjeta. Devuelve [id, error]. */
function card_create(int $userId, int $deckId, array $data): array
{
    if (user_card_count($userId) >= limit('max_cards')) {
        return [null, 'Llegaste al límite de ' . number_format(limit('max_cards'), 0, ',', '.') . ' tarjetas.'];
    }
    $hash = card_front_hash($data['front']);
    if (qv('SELECT id FROM cards WHERE deck_id = ? AND front_hash = ?', [$deckId, $hash])) {
        return [null, 'Ya hay una tarjeta con esa pregunta en este mazo.'];
    }
    $pos = (int) qv('SELECT COALESCE(MAX(position), 0) + 1 FROM cards WHERE deck_id = ?', [$deckId]);
    $now = now_utc();
    q(
        'INSERT INTO cards (user_id, deck_id, week, front, back, front_hash, position, due, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$userId, $deckId, $data['week'], $data['front'], $data['back'], $hash, $pos, $now, $now, $now]
    );
    q('UPDATE decks SET updated_at = ? WHERE id = ?', [$now, $deckId]);
    return [(int) db()->lastInsertId(), null];
}

/** Cambia el texto de una tarjeta sin tocar su progreso. Devuelve error o null. */
function card_update(int $userId, array $card, array $data): ?string
{
    $hash = card_front_hash($data['front']);
    $other = qv('SELECT id FROM cards WHERE deck_id = ? AND front_hash = ? AND id <> ?', [$card['deck_id'], $hash, $card['id']]);
    if ($other) {
        return 'Ya hay otra tarjeta con esa pregunta en este mazo.';
    }
    q(
        'UPDATE cards SET front = ?, back = ?, week = ?, front_hash = ?, updated_at = ? WHERE id = ? AND user_id = ?',
        [$data['front'], $data['back'], $data['week'], $hash, now_utc(), $card['id'], $userId]
    );
    return null;
}

/**
 * Importa filas en un mazo. Si la pregunta ya existe, actualiza respuesta y semana sin borrar el progreso.
 * Devuelve ['new' => n, 'updated' => n, 'same' => n] o lanza RuntimeException si pasa el límite.
 */
function import_rows(int $userId, int $deckId, array $rows, bool $dryRun = false): array
{
    $existing = [];
    foreach (qall('SELECT id, front, back, week, front_hash FROM cards WHERE deck_id = ? AND user_id = ?', [$deckId, $userId]) as $c) {
        $existing[$c['front_hash']] = $c;
    }
    $stats = ['new' => 0, 'updated' => 0, 'same' => 0];
    $toInsert = [];
    $toUpdate = [];
    foreach ($rows as $r) {
        $old = $existing[$r['hash']] ?? null;
        if (!$old) {
            $stats['new']++;
            $toInsert[] = $r;
            continue;
        }
        $oldWeek = $old['week'] !== null ? (int) $old['week'] : null;
        if ($old['back'] !== $r['back'] || $oldWeek !== $r['week'] || $old['front'] !== $r['front']) {
            $stats['updated']++;
            $toUpdate[] = [$r, (int) $old['id']];
        } else {
            $stats['same']++;
        }
    }
    if ($dryRun) {
        return $stats;
    }
    if ($stats['new'] > 0 && user_card_count($userId) + $stats['new'] > limit('max_cards')) {
        throw new RuntimeException('Con esta importación pasarías el límite de ' . number_format(limit('max_cards'), 0, ',', '.') . ' tarjetas.');
    }
    transaction(function () use ($userId, $deckId, $toInsert, $toUpdate) {
        $now = now_utc();
        $pos = (int) qv('SELECT COALESCE(MAX(position), 0) FROM cards WHERE deck_id = ?', [$deckId]);
        $ins = db()->prepare(
            'INSERT INTO cards (user_id, deck_id, week, front, back, front_hash, position, due, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($toInsert as $r) {
            $ins->execute([$userId, $deckId, $r['week'], $r['front'], $r['back'], $r['hash'], ++$pos, $now, $now, $now]);
        }
        $upd = db()->prepare('UPDATE cards SET front = ?, back = ?, week = ?, updated_at = ? WHERE id = ? AND user_id = ?');
        foreach ($toUpdate as [$r, $id]) {
            $upd->execute([$r['front'], $r['back'], $r['week'], $now, $id, $userId]);
        }
        q('UPDATE decks SET updated_at = ? WHERE id = ?', [$now, $deckId]);
    });
    return $stats;
}

/** Tarjetas del cuaderno de errores con su conteo de fallos (últimos 30 días). */
function error_cards(int $userId, int $limit = 100): array
{
    $since = gmdate('Y-m-d H:i:s', time() - 30 * 86400);
    $limit = min(max($limit, 1), 500);
    return qall(
        "SELECT c.id, c.deck_id, c.week, c.front, c.back, c.lapses, d.name AS deck_name, COALESCE(f.fails, 0) AS fails
           FROM cards c
           JOIN decks d ON d.id = c.deck_id
           LEFT JOIN (SELECT card_id, COUNT(*) AS fails FROM review_log
                       WHERE user_id = ? AND rating = 1 AND reviewed_at >= ? GROUP BY card_id) f ON f.card_id = c.id
          WHERE c.user_id = ? AND (COALESCE(f.fails, 0) > 0 OR c.lapses > 0)
          ORDER BY fails DESC, c.lapses DESC, c.id
          LIMIT $limit",
        [$userId, $since, $userId]
    );
}

function deck_create(int $userId, string $name): array
{
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
    if ($name === '' || mb_strlen($name) > 120) {
        return [null, 'El nombre del mazo debe tener entre 1 y 120 caracteres.'];
    }
    if ((int) qv('SELECT COUNT(*) FROM decks WHERE user_id = ?', [$userId]) >= limit('max_decks')) {
        return [null, 'Llegaste al límite de ' . limit('max_decks') . ' mazos.'];
    }
    $existing = qv('SELECT id FROM decks WHERE user_id = ? AND name = ?', [$userId, $name]);
    if ($existing) {
        return [(int) $existing, null];
    }
    q('INSERT INTO decks (user_id, name, created_at, updated_at) VALUES (?, ?, ?, ?)', [$userId, $name, now_utc(), now_utc()]);
    return [(int) db()->lastInsertId(), null];
}
