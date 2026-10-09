<?php
declare(strict_types=1);

// Rutas de la aplicación. Todas las URLs son limpias (sin .php).

function dispatch(string $method, string $path): void
{
    $routes = [
        ['GET', '#^/$#', 'page_home'],
        ['GET', '#^/entrar$#', 'page_login'],
        ['POST', '#^/entrar$#', 'action_send_code'],
        ['GET', '#^/entrar/codigo$#', 'page_code'],
        ['POST', '#^/entrar/codigo$#', 'action_verify_code'],
        ['GET', '#^/auth/google$#', 'action_google_start'],
        ['GET', '#^/auth/google/callback$#', 'action_google_callback'],
        ['POST', '#^/salir$#', 'action_logout'],
        ['GET', '#^/hoy$#', 'page_today'],
        ['GET', '#^/mazos$#', 'page_decks'],
        ['POST', '#^/mazos$#', 'action_deck_create'],
        ['GET', '#^/mazos/(\d+)$#', 'page_deck'],
        ['POST', '#^/mazos/(\d+)/renombrar$#', 'action_deck_rename'],
        ['POST', '#^/mazos/(\d+)/borrar$#', 'action_deck_delete'],
        ['POST', '#^/mazos/(\d+)/tarjetas$#', 'action_card_create'],
        ['GET', '#^/mazos/(\d+)/exportar$#', 'action_deck_export'],
        ['GET', '#^/tarjetas/(\d+)/editar$#', 'page_card_edit'],
        ['POST', '#^/tarjetas/(\d+)/editar$#', 'action_card_edit'],
        ['POST', '#^/tarjetas/(\d+)/borrar$#', 'action_card_delete'],
        ['GET', '#^/importar$#', 'page_import'],
        ['POST', '#^/importar$#', 'action_import_upload'],
        ['GET', '#^/importar/revisar$#', 'page_import_review'],
        ['POST', '#^/importar/confirmar$#', 'action_import_confirm'],
        ['GET', '#^/repasar$#', 'page_review'],
        ['GET', '#^/errores$#', 'page_errors'],
        ['GET', '#^/cuenta$#', 'page_account'],
        ['POST', '#^/cuenta$#', 'action_account_save'],
        ['GET', '#^/cuenta/exportar$#', 'action_account_export'],
        ['POST', '#^/cuenta/borrar$#', 'action_account_delete'],
        ['GET', '#^/privacidad$#', 'page_privacy'],
        ['GET', '#^/terminos$#', 'page_terms'],
        ['GET', '#^/api/sesion$#', 'api_session'],
        ['POST', '#^/api/responder$#', 'api_answer'],
        ['POST', '#^/api/tarjeta$#', 'api_card_update'],
    ];

    $allowed = [];
    foreach ($routes as [$m, $pattern, $handler]) {
        if (preg_match($pattern, $path, $matches)) {
            if ($m === $method || ($m === 'GET' && $method === 'HEAD')) {
                array_shift($matches);
                $handler(...array_map('intval', $matches));
                return;
            }
            $allowed[] = $m;
        }
    }
    if ($allowed) {
        header('Allow: ' . implode(', ', array_unique($allowed)));
        if (is_api_request()) {
            json_response(['ok' => false, 'error' => 'Método no permitido.'], 405);
        }
        view('error', ['title' => 'No permitido', 'message' => 'Esta acción no se puede hacer así.'], 405);
    }
    not_found();
}

// ---------------------------------------------------------------- Inicio y login

function page_home(): void
{
    if (current_user()) {
        redirect('/hoy');
    }
    view('landing', ['title' => 'Repasa tus cursos', 'deleted' => isset($_GET['cuenta_borrada'])]);
}

function page_login(): void
{
    if (current_user()) {
        redirect('/hoy');
    }
    view('login', ['title' => 'Entrar', 'turnstile' => turnstile_enabled()]);
}

function action_send_code(): void
{
    require_csrf();
    $email = normalize_email((string) ($_POST['email'] ?? ''));
    $remember = !empty($_POST['recordar']);

    // Campo trampa: los humanos no lo ven. Si viene lleno, se finge éxito sin enviar nada.
    if (trim((string) ($_POST['website'] ?? '')) !== '') {
        $_SESSION['login_email'] = $email;
        redirect('/entrar/codigo');
    }
    if (!valid_email($email)) {
        flash('error', 'Escribe un correo válido.');
        redirect('/entrar');
    }
    // Turnstile se pide una vez; durante 15 minutos se puede reenviar el código sin repetirlo.
    if (turnstile_enabled() && (int) ($_SESSION['human_ok_until'] ?? 0) < time()) {
        if (!turnstile_verify((string) ($_POST['cf-turnstile-response'] ?? ''))) {
            flash('error', 'No pudimos comprobar que eres una persona. Inténtalo otra vez.');
            redirect('/entrar');
        }
        $_SESSION['human_ok_until'] = time() + 900;
    }

    $ip = client_ip();
    $mailBucket = 'code-mail:' . $email;
    if (!rate_allow($mailBucket, 1, 60)) {
        $_SESSION['login_email'] = $email;
        $_SESSION['login_remember'] = $remember;
        flash('info', 'Ya te enviamos un código hace poco. Espera ' . max(1, rate_wait_seconds($mailBucket, 60)) . ' segundos para pedir otro.');
        redirect('/entrar/codigo');
    }
    if (!rate_allow($mailBucket, 5, 3600) || !rate_allow('code-ip:' . $ip, 10, 3600) || !rate_allow('code-global', 1000, 3600)) {
        flash('error', 'Se pidieron demasiados códigos. Espera un rato o entra con Google.');
        redirect('/entrar');
    }

    rate_hit($mailBucket);
    rate_hit('code-ip:' . $ip);
    rate_hit('code-global');
    code_cleanup();

    $code = code_create($email);
    $text = "Tu código para entrar a Flashcards es:\n\n    $code\n\nVence en 10 minutos y sirve una sola vez.\n"
        . "Si no lo pediste, ignora este correo: nadie puede entrar sin el código.\n\n" . url('/') . "\n";
    if (!send_mail($email, "Tu código de Flashcards: $code", $text)) {
        flash('error', 'No pudimos enviar el correo. Inténtalo más tarde o entra con Google.');
        redirect('/entrar');
    }
    $_SESSION['login_email'] = $email;
    $_SESSION['login_remember'] = $remember;
    flash('success', 'Te enviamos un código a ' . $email . '. Revisa también la carpeta de spam.');
    redirect('/entrar/codigo');
}

function page_code(): void
{
    if (current_user()) {
        redirect('/hoy');
    }
    $email = $_SESSION['login_email'] ?? null;
    if (!$email) {
        redirect('/entrar');
    }
    view('code', ['title' => 'Escribe tu código', 'email' => $email, 'remember' => !empty($_SESSION['login_remember'])]);
}

function action_verify_code(): void
{
    require_csrf();
    $email = $_SESSION['login_email'] ?? null;
    if (!$email) {
        redirect('/entrar');
    }
    $ipBucket = 'verify-ip:' . client_ip();
    if (!rate_allow($ipBucket, 30, 3600)) {
        flash('error', 'Demasiados intentos. Espera un rato e inténtalo otra vez.');
        redirect('/entrar/codigo');
    }
    rate_hit($ipBucket);

    $code = preg_replace('/\D/', '', (string) ($_POST['codigo'] ?? '')) ?? '';
    $result = code_verify($email, $code);
    if ($result === 'ok') {
        $user = user_find_or_create_by_email($email);
        login_user((int) $user['id'], !empty($_SESSION['login_remember']));
        unset($_SESSION['login_remember']);
        redirect(after_login_path());
    }
    $messages = [
        'wrong' => 'Ese código no es correcto. Revisa el último correo que te llegó.',
        'expired' => 'El código venció o se intentó demasiadas veces. Pide uno nuevo.',
        'none' => 'Primero pide un código.',
    ];
    flash('error', $messages[$result] ?? 'No se pudo entrar.');
    redirect($result === 'none' ? '/entrar' : '/entrar/codigo');
}

function action_google_start(): void
{
    if (!google_enabled()) {
        not_found();
    }
    redirect(google_auth_url(($_GET['recordar'] ?? '1') === '1'));
}

function action_google_callback(): void
{
    if (!google_enabled()) {
        not_found();
    }
    try {
        $info = google_handle_callback($_GET);
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
        redirect('/entrar');
    }
    $user = google_login_user($info);
    login_user((int) $user['id'], $info['remember']);
    redirect(after_login_path());
}

function action_logout(): void
{
    require_csrf();
    logout_user();
    redirect('/');
}

// ---------------------------------------------------------------- Hoy

function page_today(): void
{
    $user = require_user();
    view('today', [
        'title' => 'Hoy',
        'user' => $user,
        'summary' => today_summary($user),
        'decks' => decks_with_counts((int) $user['id']),
        'nav' => 'hoy',
    ]);
}

// ---------------------------------------------------------------- Mazos

function page_decks(): void
{
    $user = require_user();
    view('decks', ['title' => 'Mazos', 'user' => $user, 'decks' => decks_with_counts((int) $user['id']), 'nav' => 'mazos']);
}

function action_deck_create(): void
{
    $user = require_user();
    require_csrf();
    [$id, $error] = deck_create((int) $user['id'], (string) ($_POST['nombre'] ?? ''));
    if ($error) {
        flash('error', $error);
        redirect('/mazos');
    }
    redirect('/mazos/' . $id);
}

function require_deck(array $user, int $deckId): array
{
    $deck = deck_for_user((int) $user['id'], $deckId);
    if (!$deck) {
        not_found();
    }
    return $deck;
}

function page_deck(int $deckId): void
{
    $user = require_user();
    $deck = require_deck($user, $deckId);
    $uid = (int) $user['id'];
    $week = isset($_GET['semana']) && $_GET['semana'] !== '' ? (int) $_GET['semana'] : null;
    $search = trim((string) ($_GET['q'] ?? ''));
    $page = max(1, (int) ($_GET['pagina'] ?? 1));
    $perPage = 50;

    $where = 'user_id = ? AND deck_id = ?';
    $params = [$uid, $deckId];
    if ($week !== null) {
        $where .= ' AND week = ?';
        $params[] = $week;
    }
    if ($search !== '') {
        $like = '%' . addcslashes(mb_substr($search, 0, 100), '%_\\') . '%';
        $where .= ' AND (front LIKE ? OR back LIKE ?)';
        array_push($params, $like, $like);
    }
    $total = (int) qv("SELECT COUNT(*) FROM cards WHERE $where", $params);
    $offset = ($page - 1) * $perPage;
    $cards = qall("SELECT id, week, front, back, state, due, reps, lapses FROM cards WHERE $where ORDER BY (week IS NULL), week, position, id LIMIT $perPage OFFSET $offset", $params);

    $counts = null;
    foreach (decks_with_counts($uid) as $d) {
        if ((int) $d['id'] === $deckId) {
            $counts = $d;
        }
    }
    view('deck', [
        'title' => $deck['name'],
        'user' => $user,
        'deck' => $deck,
        'counts' => $counts,
        'weeks' => deck_weeks($uid, $deckId),
        'week' => $week,
        'search' => $search,
        'cards' => $cards,
        'total' => $total,
        'page' => $page,
        'pages' => max(1, (int) ceil($total / $perPage)),
        'nav' => 'mazos',
        'math' => true,
    ]);
}

function action_deck_rename(int $deckId): void
{
    $user = require_user();
    require_csrf();
    $deck = require_deck($user, $deckId);
    $name = trim(preg_replace('/\s+/u', ' ', (string) ($_POST['nombre'] ?? '')) ?? '');
    if ($name === '' || mb_strlen($name) > 120) {
        flash('error', 'El nombre debe tener entre 1 y 120 caracteres.');
    } elseif (qv('SELECT id FROM decks WHERE user_id = ? AND name = ? AND id <> ?', [$user['id'], $name, $deckId])) {
        flash('error', 'Ya tienes otro mazo con ese nombre.');
    } else {
        q('UPDATE decks SET name = ?, updated_at = ? WHERE id = ? AND user_id = ?', [$name, now_utc(), $deckId, $user['id']]);
        flash('success', 'Mazo renombrado.');
    }
    redirect('/mazos/' . $deck['id']);
}

function action_deck_delete(int $deckId): void
{
    $user = require_user();
    require_csrf();
    $deck = require_deck($user, $deckId);
    q('DELETE FROM decks WHERE id = ? AND user_id = ?', [$deckId, $user['id']]);
    flash('success', 'Borraste el mazo "' . $deck['name'] . '" y sus tarjetas.');
    redirect('/mazos');
}

function action_card_create(int $deckId): void
{
    $user = require_user();
    require_csrf();
    require_deck($user, $deckId);
    [$data, $error] = card_input([
        'front' => $_POST['pregunta'] ?? '',
        'back' => $_POST['respuesta'] ?? '',
        'week' => $_POST['semana'] ?? '',
    ]);
    if (!$error) {
        [, $error] = card_create((int) $user['id'], $deckId, $data);
    }
    if ($error) {
        flash('error', $error);
        $_SESSION['card_draft'] = ['pregunta' => $_POST['pregunta'] ?? '', 'respuesta' => $_POST['respuesta'] ?? '', 'semana' => $_POST['semana'] ?? ''];
    } else {
        flash('success', 'Tarjeta agregada.');
        $_SESSION['last_week'] = $data['week'];
    }
    redirect('/mazos/' . $deckId . '#nueva');
}

function action_deck_export(int $deckId): void
{
    $user = require_user();
    $deck = require_deck($user, $deckId);
    $cards = qall('SELECT week, front, back FROM cards WHERE deck_id = ? AND user_id = ? ORDER BY (week IS NULL), week, position, id', [$deckId, $user['id']]);
    send_csv_download(csv_export($cards), $deck['name']);
}

function send_csv_download(string $csv, string $name): never
{
    $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: 'mazo')) ?? 'mazo', '-');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . ($slug !== '' ? $slug : 'mazo') . '.csv"');
    header('Cache-Control: no-store');
    echo $csv;
    exit;
}

// ---------------------------------------------------------------- Tarjetas

function require_card(array $user, int $cardId): array
{
    $card = card_for_user((int) $user['id'], $cardId);
    if (!$card) {
        not_found();
    }
    return $card;
}

function page_card_edit(int $cardId): void
{
    $user = require_user();
    $card = require_card($user, $cardId);
    $back = safe_local_path($_GET['volver'] ?? '', '/mazos/' . $card['deck_id']);
    view('card_edit', [
        'title' => 'Editar tarjeta',
        'user' => $user,
        'card' => $card,
        'back' => $back,
        'nav' => 'mazos',
        'math' => true,
    ]);
}

function action_card_edit(int $cardId): void
{
    $user = require_user();
    require_csrf();
    $card = require_card($user, $cardId);
    [$data, $error] = card_input(['front' => $_POST['pregunta'] ?? '', 'back' => $_POST['respuesta'] ?? '', 'week' => $_POST['semana'] ?? '']);
    if (!$error) {
        $error = card_update((int) $user['id'], $card, $data);
    }
    $back = safe_local_path($_POST['volver'] ?? '', '/mazos/' . $card['deck_id']);
    if ($error) {
        flash('error', $error);
        redirect('/tarjetas/' . $cardId . '/editar?volver=' . rawurlencode($back));
    }
    flash('success', 'Tarjeta guardada.');
    redirect($back);
}

function action_card_delete(int $cardId): void
{
    $user = require_user();
    require_csrf();
    $card = require_card($user, $cardId);
    q('DELETE FROM cards WHERE id = ? AND user_id = ?', [$cardId, $user['id']]);
    flash('success', 'Tarjeta borrada.');
    redirect('/mazos/' . $card['deck_id']);
}

// ---------------------------------------------------------------- Importar

function import_file_path(int $userId, string $token): ?string
{
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        return null;
    }
    return APP_ROOT . '/storage/imports/' . $userId . '-' . $token . '.json';
}

function import_load(int $userId, string $token): ?array
{
    $file = import_file_path($userId, $token);
    if (!$file || !is_file($file) || filemtime($file) < time() - 3600) {
        return null;
    }
    $data = json_decode((string) file_get_contents($file), true);
    return is_array($data) ? $data : null;
}

function import_cleanup(): void
{
    foreach (glob(APP_ROOT . '/storage/imports/*.json') ?: [] as $f) {
        if (filemtime($f) < time() - 3600) {
            @unlink($f);
        }
    }
}

function page_import(): void
{
    $user = require_user();
    view('import', [
        'title' => 'Importar CSV',
        'user' => $user,
        'decks' => qall('SELECT id, name FROM decks WHERE user_id = ? ORDER BY name', [$user['id']]),
        'selected' => (int) ($_GET['mazo'] ?? 0),
        'nav' => 'importar',
    ]);
}

function action_import_upload(): void
{
    $user = require_user();
    require_csrf();
    $uid = (int) $user['id'];
    if (!rate_allow('import-preview:' . $uid, 100, 86400)) {
        flash('error', 'Hiciste demasiadas vistas previas hoy. Vuelve mañana.');
        redirect('/importar');
    }
    rate_hit('import-preview:' . $uid);

    $file = $_FILES['archivo'] ?? null;
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $tooBig = in_array($file['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
        flash('error', $tooBig ? 'El archivo es muy grande.' : 'Elige un archivo CSV.');
        redirect('/importar');
    }
    if ((int) $file['size'] > limit('max_csv_bytes')) {
        flash('error', 'El archivo pasa de ' . round(limit('max_csv_bytes') / 1048576, 1) . ' MB.');
        redirect('/importar');
    }
    $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['csv', 'txt', 'tsv'], true)) {
        flash('error', 'El archivo debe ser .csv, .tsv o .txt.');
        redirect('/importar');
    }
    $raw = (string) file_get_contents((string) $file['tmp_name']);
    $parsed = csv_parse_cards($raw);
    if (!$parsed['rows']) {
        flash('error', 'No encontramos tarjetas en el archivo. ' . implode(' ', array_slice($parsed['errors'], 0, 3)));
        redirect('/importar');
    }
    if (count($parsed['rows']) > limit('max_import_rows')) {
        flash('error', 'El archivo tiene ' . count($parsed['rows']) . ' tarjetas; el máximo por importación es ' . limit('max_import_rows') . '.');
        redirect('/importar');
    }

    $deckId = (int) ($_POST['mazo'] ?? 0);
    $newName = trim((string) ($_POST['nuevo_mazo'] ?? ''));
    if ($deckId > 0) {
        if (!deck_for_user($uid, $deckId)) {
            not_found();
        }
        $newName = '';
    } elseif ($newName === '' || mb_strlen($newName) > 120) {
        flash('error', 'Elige un mazo o escribe el nombre del mazo nuevo (por ejemplo, el nombre del curso).');
        redirect('/importar');
    }

    import_cleanup();
    $token = bin2hex(random_bytes(16));
    file_put_contents((string) import_file_path($uid, $token), json_encode([
        'deck_id' => $deckId,
        'new_name' => $newName,
        'filename' => mb_substr((string) $file['name'], 0, 120),
        'rows' => $parsed['rows'],
        'errors' => $parsed['errors'],
        'warnings' => $parsed['warnings'],
        'format' => $parsed['format'],
    ], JSON_UNESCAPED_UNICODE));
    redirect('/importar/revisar?t=' . $token);
}

function page_import_review(): void
{
    $user = require_user();
    $token = (string) ($_GET['t'] ?? '');
    $data = import_load((int) $user['id'], $token);
    if (!$data) {
        flash('error', 'La vista previa venció. Sube el archivo otra vez.');
        redirect('/importar');
    }
    $deck = $data['deck_id'] ? deck_for_user((int) $user['id'], (int) $data['deck_id']) : null;
    $stats = $deck
        ? import_rows((int) $user['id'], (int) $deck['id'], $data['rows'], true)
        : ['new' => count($data['rows']), 'updated' => 0, 'same' => 0];
    $weeks = array_values(array_unique(array_filter(array_column($data['rows'], 'week'), fn($w) => $w !== null)));
    sort($weeks);
    view('import_review', [
        'title' => 'Revisar importación',
        'user' => $user,
        'token' => $token,
        'import' => $data,
        'deckName' => $deck['name'] ?? $data['new_name'],
        'isNewDeck' => !$deck,
        'stats' => $stats,
        'weeks' => $weeks,
        'nav' => 'importar',
        'math' => true,
    ]);
}

function action_import_confirm(): void
{
    $user = require_user();
    require_csrf();
    $uid = (int) $user['id'];
    $token = (string) ($_POST['t'] ?? '');
    $data = import_load($uid, $token);
    if (!$data) {
        flash('error', 'La vista previa venció. Sube el archivo otra vez.');
        redirect('/importar');
    }
    if (!rate_allow('import:' . $uid, limit('max_imports_per_day'), 86400)) {
        flash('error', 'Llegaste al límite de ' . limit('max_imports_per_day') . ' importaciones por día.');
        redirect('/importar');
    }
    if ($data['deck_id']) {
        $deck = deck_for_user($uid, (int) $data['deck_id']);
        if (!$deck) {
            not_found();
        }
        $deckId = (int) $deck['id'];
    } else {
        [$deckId, $error] = deck_create($uid, (string) $data['new_name']);
        if ($error) {
            flash('error', $error);
            redirect('/importar');
        }
    }
    try {
        $stats = import_rows($uid, (int) $deckId, $data['rows']);
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
        redirect('/importar');
    }
    rate_hit('import:' . $uid);
    @unlink((string) import_file_path($uid, $token));
    flash('success', sprintf(
        'Importación lista: %s, %s y %s.',
        plural($stats['new'], 'tarjeta nueva', 'tarjetas nuevas'),
        plural($stats['updated'], 'actualizada', 'actualizadas'),
        plural($stats['same'], 'sin cambios', 'sin cambios')
    ));
    redirect('/mazos/' . $deckId);
}

// ---------------------------------------------------------------- Repaso

function review_params(): array
{
    $mode = (string) ($_GET['modo'] ?? 'hoy');
    if (!in_array($mode, ['hoy', 'examen', 'errores'], true)) {
        $mode = 'hoy';
    }
    $deckId = (int) ($_GET['mazo'] ?? 0) ?: null;
    $weeksRaw = $_GET['semanas'] ?? [];
    if (is_string($weeksRaw)) {
        $weeksRaw = explode(',', $weeksRaw);
    }
    $weeks = is_array($weeksRaw) ? array_values(array_filter(array_map('intval', $weeksRaw), fn($w) => $w >= 0)) : [];
    $limit = isset($_GET['limite']) && $_GET['limite'] !== '' ? max(1, min(500, (int) $_GET['limite'])) : null;
    return [$mode, $deckId, $weeks, $limit];
}

function page_review(): void
{
    $user = require_user();
    [$mode, $deckId, $weeks, $limit] = review_params();
    $deck = $deckId ? require_deck($user, $deckId) : null;
    $query = array_filter([
        'modo' => $mode,
        'mazo' => $deckId,
        'semanas' => $weeks ? implode(',', $weeks) : null,
        'limite' => $limit,
    ], fn($v) => $v !== null && $v !== '');
    $labels = ['hoy' => 'Repaso de hoy', 'examen' => 'Modo examen', 'errores' => 'Cuaderno de errores'];
    view('review', [
        'title' => $labels[$mode],
        'user' => $user,
        'mode' => $mode,
        'deck' => $deck,
        'weeks' => $weeks,
        'query' => http_build_query($query),
        'math' => true,
        'scripts' => ['vendor/ts-fsrs/ts-fsrs.umd.js', 'js/review.js'],
        'bodyClass' => 'is-review',
        'hideNav' => true,
    ]);
}

function page_errors(): void
{
    $user = require_user();
    view('errors', [
        'title' => 'Cuaderno de errores',
        'user' => $user,
        'cards' => error_cards((int) $user['id']),
        'nav' => 'errores',
        'math' => true,
    ]);
}

// ---------------------------------------------------------------- Cuenta

function page_account(): void
{
    $user = require_user();
    $uid = (int) $user['id'];
    view('account', [
        'title' => 'Cuenta',
        'user' => $user,
        'stats' => [
            'decks' => (int) qv('SELECT COUNT(*) FROM decks WHERE user_id = ?', [$uid]),
            'cards' => user_card_count($uid),
            'reviews' => (int) qv('SELECT COUNT(*) FROM review_log WHERE user_id = ?', [$uid]),
            'imports_today' => rate_count('import:' . $uid, 86400),
        ],
        'nav' => 'cuenta',
    ]);
}

function action_account_save(): void
{
    $user = require_user();
    require_csrf();
    $name = trim(mb_substr((string) ($_POST['nombre'] ?? ''), 0, 100));
    $newPerDay = (int) ($_POST['nuevas_por_dia'] ?? 20);
    $short = (int) ($_POST['sesion_corta'] ?? 10);
    $tz = (string) ($_POST['zona_horaria'] ?? 'America/Lima');
    if ($newPerDay < 0 || $newPerDay > 500 || $short < 5 || $short > 100) {
        flash('error', 'Las nuevas por día van de 0 a 500 y la sesión corta de 5 a 100.');
        redirect('/cuenta');
    }
    if (!in_array($tz, DateTimeZone::listIdentifiers(), true)) {
        flash('error', 'Zona horaria no válida.');
        redirect('/cuenta');
    }
    q(
        'UPDATE users SET name = ?, new_per_day = ?, short_session = ?, timezone = ? WHERE id = ?',
        [$name !== '' ? $name : $user['name'], $newPerDay, $short, $tz, $user['id']]
    );
    flash('success', 'Guardado.');
    redirect('/cuenta');
}

function action_account_export(): void
{
    $user = require_user();
    $cards = qall(
        'SELECT d.name AS deck_name, c.week, c.front, c.back FROM cards c JOIN decks d ON d.id = c.deck_id
          WHERE c.user_id = ? ORDER BY d.name, (c.week IS NULL), c.week, c.position, c.id',
        [$user['id']]
    );
    send_csv_download(csv_export($cards, true), 'flashcards-todo');
}

function action_account_delete(): void
{
    $user = require_user();
    require_csrf();
    if (trim((string) ($_POST['confirmar'] ?? '')) !== 'BORRAR') {
        flash('error', 'Para borrar tu cuenta escribe BORRAR en mayúsculas.');
        redirect('/cuenta#borrar');
    }
    q('DELETE FROM users WHERE id = ?', [$user['id']]);
    q('DELETE FROM login_codes WHERE email = ?', [$user['email']]);
    logout_user();
    redirect('/?cuenta_borrada=1');
}

// ---------------------------------------------------------------- Páginas legales

function page_privacy(): void
{
    view('privacy', ['title' => 'Privacidad', 'user' => current_user()]);
}

function page_terms(): void
{
    view('terms', ['title' => 'Términos', 'user' => current_user()]);
}

// ---------------------------------------------------------------- API (JSON)

function api_session(): void
{
    $user = require_user();
    [$mode, $deckId, $weeks, $limit] = review_params();
    if ($deckId && !deck_for_user((int) $user['id'], $deckId)) {
        not_found();
    }
    $cards = build_session($user, $mode, $deckId, $weeks, $limit);
    json_response([
        'ok' => true,
        'mode' => $mode,
        'cards' => $cards,
        'new_remaining' => new_remaining_today($user),
        'server_now' => gmdate('Y-m-d\TH:i:s\Z'),
    ]);
}

function api_answer(): void
{
    $user = require_user();
    require_csrf();
    $result = apply_answer($user, json_body());
    $status = $result['status'];
    unset($result['status']);
    json_response(['ok' => $status === 200] + $result, $status);
}

function api_card_update(): void
{
    $user = require_user();
    require_csrf();
    $in = json_body();
    $card = card_for_user((int) $user['id'], (int) ($in['id'] ?? 0));
    if (!$card) {
        not_found();
    }
    [$data, $error] = card_input($in);
    if (!$error) {
        $error = card_update((int) $user['id'], $card, $data);
    }
    if ($error) {
        json_response(['ok' => false, 'error' => $error], 422);
    }
    $fresh = card_for_user((int) $user['id'], (int) $card['id']);
    json_response(['ok' => true, 'card' => card_to_client($fresh)]);
}
