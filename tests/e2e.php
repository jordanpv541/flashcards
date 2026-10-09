<?php
// Prueba de punta a punta contra el servidor local (Apache + MariaDB).
// Uso: php tests/e2e.php [http://localhost:8080]
declare(strict_types=1);

$BASE = $argv[1] ?? 'http://localhost:8080';
$ROOT = dirname(__DIR__);
$MAIL_LOG = $ROOT . '/storage/logs/mail.log';
$failures = 0;
$checks = 0;

function check(bool $ok, string $msg): void
{
    global $failures, $checks;
    $checks++;
    echo ($ok ? "  ✓ " : "  ✗ ") . $msg . "\n";
    if (!$ok) {
        $failures++;
    }
}

final class Client
{
    public string $jar;
    public string $csrf = '';
    public function __construct(public string $base, string $name)
    {
        $this->jar = sys_get_temp_dir() . '/fc-jar-' . $name . '-' . getmypid();
        @unlink($this->jar);
    }
    public function req(string $method, string $path, ?array $form = null, array $headers = [], ?string $raw = null): array
    {
        $ch = curl_init($this->base . $path);
        $opts = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_COOKIEJAR => $this->jar,
            CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => $headers,
        ];
        if ($form !== null) {
            $opts[CURLOPT_POSTFIELDS] = $form;
        } elseif ($raw !== null) {
            $opts[CURLOPT_POSTFIELDS] = $raw;
        }
        curl_setopt_array($ch, $opts);
        $resp = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $head = substr($resp, 0, $hsize);
        $body = substr($resp, $hsize);
        preg_match('/^Location:\s*(\S+)/mi', $head, $m);
        if (preg_match('/data-csrf="([a-f0-9]{64})"/', $body, $c)) {
            $this->csrf = $c[1];
        }
        return ['status' => $status, 'head' => $head, 'body' => $body, 'location' => $m[1] ?? null];
    }
    public function get(string $path): array
    {
        return $this->req('GET', $path);
    }
    public function post(string $path, array $form = []): array
    {
        if ($this->csrf === '') {
            $this->get('/entrar');
        }
        return $this->req('POST', $path, $form + ['_csrf' => $this->csrf]);
    }
    public function api(string $method, string $path, ?array $json = null, bool $withCsrf = true): array
    {
        $headers = ['Accept: application/json', 'Content-Type: application/json'];
        if ($withCsrf) {
            $headers[] = 'X-CSRF-Token: ' . $this->csrf;
        }
        $r = $this->req($method, $path, null, $headers, $json !== null ? json_encode($json) : null);
        $r['json'] = json_decode($r['body'], true);
        return $r;
    }
}

function last_code(string $email): ?string
{
    global $MAIL_LOG;
    $log = (string) @file_get_contents($MAIL_LOG);
    $blocks = explode('=== ', $log);
    for ($i = count($blocks) - 1; $i >= 0; $i--) {
        if (str_contains($blocks[$i], 'Para: ' . $email) && preg_match('/^\s{4}(\d{6})$/m', $blocks[$i], $m)) {
            return $m[1];
        }
    }
    return null;
}

function db(): PDO
{
    static $pdo = null;
    if (!$pdo) {
        $cfg = require dirname(__DIR__) . '/config/config.php';
        $c = $cfg['db'];
        $pdo = new PDO("mysql:host={$c['host']};dbname={$c['name']};charset=utf8mb4", $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    }
    return $pdo;
}

function login(Client $c, string $email): void
{
    $c->get('/entrar');
    $r = $c->post('/entrar', ['email' => $email, 'recordar' => '1']);
    check($r['status'] === 303 && str_ends_with((string) $r['location'], '/entrar/codigo'), "pedir código para $email redirige a /entrar/codigo");
    $code = last_code($email);
    check($code !== null, 'el código llegó al correo (registro local)');
    $c->get('/entrar/codigo');
    $r = $c->post('/entrar/codigo', ['codigo' => $code]);
    check($r['status'] === 303 && str_ends_with((string) $r['location'], '/hoy'), 'el código correcto inicia sesión');
    $c->get('/hoy');
}

function fsrs_next(array $card, int $rating, string $nowIso): array
{
    $cmd = 'node ' . escapeshellarg(__DIR__ . '/fsrs_next.cjs') . ' ' . escapeshellarg(json_encode($card)) . ' ' . $rating . ' ' . escapeshellarg($nowIso);
    return json_decode((string) shell_exec($cmd), true);
}

function uuid(): string
{
    $b = random_bytes(16);
    $b[6] = chr(ord($b[6]) & 0x0f | 0x40);
    $b[8] = chr(ord($b[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

// ------------------------------------------------------------------ inicio
db()->exec('DELETE FROM users');
db()->exec('DELETE FROM login_codes');
db()->exec('DELETE FROM rate_events');
@unlink($MAIL_LOG);

echo "1) Login con correo y código\n";
$a = new Client($BASE, 'a');
$emailA = 'jordan.prueba@example.com';
$a->get('/entrar');
$r = $a->req('POST', '/entrar', ['email' => $emailA]);
check($r['status'] === 403, 'sin token CSRF el formulario se rechaza (403)');
$r = $a->post('/entrar', ['email' => 'no-es-correo']);
check($r['status'] === 303 && str_ends_with((string) $r['location'], '/entrar'), 'correo inválido vuelve a /entrar');
$r = $a->post('/entrar', ['email' => $emailA, 'recordar' => '1']);
$code = last_code($emailA);
check($code !== null && strlen($code) === 6, 'se envió un código de 6 dígitos');
$r = $a->post('/entrar', ['email' => $emailA, 'recordar' => '1']);
check(str_contains($a->get('/entrar/codigo')['body'], 'Espera'), 'pedir otro código antes de 60 s muestra "Espera"');
$wrong = $code === '000000' ? '111111' : '000000';
$r = $a->post('/entrar/codigo', ['codigo' => $wrong]);
check(str_ends_with((string) $r['location'], '/entrar/codigo'), 'código incorrecto no deja entrar');
$r = $a->post('/entrar/codigo', ['codigo' => substr($code, 0, 3) . ' ' . substr($code, 3)]);
check($r['status'] === 303 && str_ends_with((string) $r['location'], '/hoy'), 'código correcto (con espacio) inicia sesión');
check(str_contains($r['head'], 'fc_recordar='), 'se crea la cookie de "mantener sesión"');
$hoy = $a->get('/hoy');
check($hoy['status'] === 200 && str_contains($hoy['body'], 'Todavía no tienes mazos'), '/hoy muestra el estado vacío');
$r = $a->post('/entrar/codigo', ['codigo' => $code]);
$userA = db()->query("SELECT * FROM users WHERE email = '$emailA'")->fetch();
check((bool) $userA && $userA['name'] === 'Jordan Prueba', 'se creó el usuario con nombre a partir del correo');

echo "2) Importar los 8 CSV reales\n";
$expected = [];
foreach (glob(__DIR__ . '/fixtures/*.csv') as $file) {
    $name = ucwords(str_replace('-', ' ', basename($file, '.csv')));
    $a->get('/importar');
    $r = $a->post('/importar', ['archivo' => new CURLFile($file, 'text/csv', 'TARJETAS_CURSO.csv'), 'mazo' => '0', 'nuevo_mazo' => $name]);
    $ok = $r['status'] === 303 && str_contains((string) $r['location'], '/importar/revisar?t=');
    check($ok, "vista previa de $name");
    if (!$ok) {
        continue;
    }
    $path = parse_url((string) $r['location'], PHP_URL_PATH) . '?' . parse_url((string) $r['location'], PHP_URL_QUERY);
    $rev = $a->get($path);
    preg_match('/<strong>(\d+)<\/strong> tarjetas? nuevas?/', $rev['body'], $m);
    $expected[$name] = (int) ($m[1] ?? 0);
    parse_str((string) parse_url((string) $r['location'], PHP_URL_QUERY), $qs);
    $r = $a->post('/importar/confirmar', ['t' => $qs['t']]);
    check($r['status'] === 303 && preg_match('#/mazos/\d+$#', (string) $r['location']) === 1, "guardar $name ({$expected[$name]} tarjetas)");
}
$counts = db()->query("SELECT d.name, COUNT(c.id) n FROM decks d LEFT JOIN cards c ON c.deck_id = d.id WHERE d.user_id = {$userA['id']} GROUP BY d.id")->fetchAll(PDO::FETCH_KEY_PAIR);
check(count($counts) === 8, '8 mazos creados');
$allMatch = true;
foreach ($expected as $n => $exp) {
    $allMatch = $allMatch && (int) ($counts[$n] ?? -1) === $exp;
}
check($allMatch, 'cada mazo tiene las tarjetas que mostró la vista previa (total ' . array_sum($counts) . ')');

echo "3) Reimportar no duplica y conserva el progreso\n";
$tic = __DIR__ . '/fixtures/introduccion-a-las-tic.csv';
$deckTic = (int) db()->query("SELECT id FROM decks WHERE user_id = {$userA['id']} AND name = 'Introduccion A Las Tic'")->fetchColumn();
$firstCard = db()->query("SELECT * FROM cards WHERE deck_id = $deckTic ORDER BY position LIMIT 1")->fetch();
db()->exec("UPDATE cards SET state = 2, reps = 5, stability = 12.5, due = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 7 DAY) WHERE id = {$firstCard['id']}");
$modified = tempnam(sys_get_temp_dir(), 'fc') . '.csv';
$raw = file_get_contents($tic);
$raw = str_replace($firstCard['back'], $firstCard['back'] . ' (editado)', $raw);
file_put_contents($modified, $raw);
$a->get('/importar');
$r = $a->post('/importar', ['archivo' => new CURLFile($modified, 'text/csv', 'TARJETAS_CURSO.csv'), 'mazo' => (string) $deckTic]);
$rev = $a->get(parse_url((string) $r['location'], PHP_URL_PATH) . '?' . parse_url((string) $r['location'], PHP_URL_QUERY));
check(str_contains($rev['body'], '<strong>0</strong> tarjetas nuevas') && str_contains($rev['body'], '<strong>1</strong> se actualizan'), 'la vista previa dice 0 nuevas y 1 actualizada');
parse_str((string) parse_url((string) $r['location'], PHP_URL_QUERY), $qs);
$a->post('/importar/confirmar', ['t' => $qs['t']]);
$after = db()->query("SELECT * FROM cards WHERE id = {$firstCard['id']}")->fetch();
check(str_ends_with($after['back'], '(editado)'), 'la respuesta se actualizó');
check((int) $after['reps'] === 5 && (float) $after['stability'] === 12.5 && (int) $after['state'] === 2, 'el progreso FSRS de esa tarjeta se mantuvo');
check((int) db()->query("SELECT COUNT(*) FROM cards WHERE deck_id = $deckTic")->fetchColumn() === $expected['Introduccion A Las Tic'], 'no se duplicaron tarjetas');

echo "4) Sesión de hoy con tope de nuevas y mezcla de cursos\n";
$s = $a->api('GET', '/api/sesion');
$cards = $s['json']['cards'] ?? [];
check($s['status'] === 200 && count($cards) === 20, 'la sesión trae 20 nuevas (tope por defecto)');
$decksInSession = count(array_unique(array_column($cards, 'deck_id')));
check($decksInSession === 8, "las nuevas vienen de los 8 cursos (vienen de $decksInSession)");
$weekOk = true;
foreach ($cards as $c) {
    $weekOk = $weekOk && ($c['week'] === null || $c['week'] <= 2);
}
check($weekOk, 'las primeras nuevas son de las primeras semanas');
$s10 = $a->api('GET', '/api/sesion?limite=10');
check(count($s10['json']['cards']) === 10, 'la sesión corta trae 10');

echo "5) Responder con FSRS (igual que el navegador)\n";
$c0 = $cards[0];
$nowIso = gmdate('Y-m-d\TH:i:s\Z');
$next = fsrs_next($c0['card'], 3, $nowIso);
$payload = ['uuid' => uuid(), 'card_id' => $c0['id'], 'rating' => 3, 'mode' => 'hoy', 'reps_before' => 0, 'now' => $nowIso, 'duration_ms' => 4200, 'card' => $next];
$r = $a->api('POST', '/api/responder', $payload, false);
check($r['status'] === 403, 'sin token CSRF la API rechaza (403)');
$r = $a->api('POST', '/api/responder', $payload);
check($r['status'] === 200 && ($r['json']['ok'] ?? false), 'respuesta "Bien" guardada');
$row = db()->query("SELECT * FROM cards WHERE id = {$c0['id']}")->fetch();
check((int) $row['state'] === 1 && (int) $row['reps'] === 1, 'la tarjeta pasó a "aprendiendo" con 1 repaso');
$r = $a->api('POST', '/api/responder', $payload);
check(($r['json']['duplicate'] ?? false) === true, 'reenviar la misma respuesta no la cuenta dos veces');
$payload2 = $payload;
$payload2['uuid'] = uuid();
$r = $a->api('POST', '/api/responder', $payload2);
check($r['status'] === 409, 'una respuesta vieja (reps distintos) da conflicto 409');
$bad = $payload;
$bad['uuid'] = uuid();
$bad['reps_before'] = 1;
$bad['card'] = ['due' => '2099-01-01T00:00:00Z', 'stability' => -5] + $next;
$r = $a->api('POST', '/api/responder', $bad);
check($r['status'] === 422, 'datos FSRS falsos se rechazan (422)');
$badClock = $payload;
$badClock['uuid'] = uuid();
$badClock['reps_before'] = 1;
$badClock['now'] = '2026-01-01T00:00:00Z';
$r = $a->api('POST', '/api/responder', $badClock);
check($r['status'] === 422 && str_contains((string) ($r['json']['error'] ?? ''), 'hora'), 'reloj del celular muy desfasado se rechaza');

// Otra vez -> debe quedar en el cuaderno de errores
$c1 = $cards[1];
$next1 = fsrs_next($c1['card'], 1, $nowIso);
$r = $a->api('POST', '/api/responder', ['uuid' => uuid(), 'card_id' => $c1['id'], 'rating' => 1, 'mode' => 'hoy', 'reps_before' => 0, 'now' => $nowIso, 'duration_ms' => 3000, 'card' => $next1]);
check($r['status'] === 200, 'respuesta "Otra vez" guardada');
$s = $a->api('GET', '/api/sesion');
$ids = array_column($s['json']['cards'], 'id');
check(in_array($c1['id'], $ids, true), 'la tarjeta fallada vuelve hoy (aprendizaje en menos de 20 min)');
check(count(array_filter($s['json']['cards'], fn($c) => $c['card']['state'] === 0)) === 18, 'el tope cuenta las 2 nuevas ya vistas (quedan 18 nuevas)');

echo "6) Modo examen y cuaderno de errores\n";
$deckM2 = (int) db()->query("SELECT id FROM decks WHERE user_id = {$userA['id']} AND name = 'Matematica Ii'")->fetchColumn();
$e = $a->api('GET', "/api/sesion?modo=examen&mazo=$deckM2&semanas=1,2");
$weeksE = array_unique(array_column($e['json']['cards'], 'week'));
$expectedE = (int) db()->query("SELECT COUNT(*) FROM cards WHERE deck_id = $deckM2 AND week IN (1,2)")->fetchColumn();
check(count($e['json']['cards']) === $expectedE && !array_diff($weeksE, [1, 2]), "modo examen trae todas las de S1 y S2 ($expectedE)");
$ce = $e['json']['cards'][0];
$before = db()->query("SELECT due, reps, state FROM cards WHERE id = {$ce['id']}")->fetch();
$r = $a->api('POST', '/api/responder', ['uuid' => uuid(), 'card_id' => $ce['id'], 'rating' => 1, 'mode' => 'examen', 'now' => $nowIso]);
$afterE = db()->query("SELECT due, reps, state FROM cards WHERE id = {$ce['id']}")->fetch();
check($r['status'] === 200 && $before === $afterE, 'responder en modo examen no cambia el horario FSRS');
$err = $a->get('/errores');
check(str_contains($err['body'], 'Repasar estas 2'), 'el cuaderno de errores tiene las 2 falladas');
$er = $a->api('GET', '/api/sesion?modo=errores');
check(count($er['json']['cards']) === 2, 'la sesión de errores trae 2 tarjetas');

echo "7) Editar tarjeta desde el repaso y exportar\n";
$r = $a->api('POST', '/api/tarjeta', ['id' => $c0['id'], 'front' => 'Pregunta editada \\(x^2\\)', 'back' => 'Respuesta editada', 'week' => 'S3']);
check($r['status'] === 200 && ($r['json']['card']['week'] ?? null) === 3, 'editar desde el repaso guarda texto y semana');
$keep = db()->query("SELECT reps FROM cards WHERE id = {$c0['id']}")->fetchColumn();
check((int) $keep === 1, 'editar no borra el progreso');
$exp = $a->get("/mazos/$deckTic/exportar");
check($exp['status'] === 200 && str_contains($exp['head'], 'text/csv') && str_starts_with($exp['body'], "\xEF\xBB\xBFsemana;pregunta;respuesta"), 'exportar CSV del mazo');
$GLOBALS['FC_CONFIG'] = [];
define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/app/lib/helpers.php';
require APP_ROOT . '/app/lib/csv.php';
require APP_ROOT . '/app/lib/cards.php';
$re = csv_parse_cards($exp['body']);
check(count($re['rows']) === $expected['Introduccion A Las Tic'] && !$re['errors'], 'el CSV exportado se vuelve a leer completo, sin errores');
$all = $a->get('/cuenta/exportar');
check($all['status'] === 200 && str_contains($all['body'], 'mazo;semana;pregunta;respuesta'), 'exportar todas las tarjetas');

echo "8) Páginas principales cargan\n";
foreach (['/hoy', '/mazos', "/mazos/$deckTic", "/mazos/$deckTic?semana=2", "/mazos/$deckTic?q=byte", '/importar', '/errores', '/cuenta', '/repasar', '/repasar?modo=examen&mazo=' . $deckM2, "/tarjetas/{$c0['id']}/editar"] as $p) {
    $r = $a->get($p);
    check($r['status'] === 200, "GET $p → 200");
}

echo "9) Privacidad: otro usuario no ve nada de A\n";
$b = new Client($BASE, 'b');
login($b, 'companero@example.com');
foreach (["/mazos/$deckTic", "/mazos/$deckTic/exportar", "/tarjetas/{$c0['id']}/editar", "/repasar?mazo=$deckTic"] as $p) {
    check($b->get($p)['status'] === 404, "B no puede abrir $p (404)");
}
check($b->api('GET', "/api/sesion?mazo=$deckTic")['status'] === 404, 'B no puede pedir la sesión del mazo de A');
check($b->api('GET', '/api/sesion')['json']['cards'] === [], 'la sesión de B está vacía');
$r = $b->api('POST', '/api/responder', ['uuid' => uuid(), 'card_id' => $c0['id'], 'rating' => 3, 'mode' => 'examen', 'now' => $nowIso]);
check($r['status'] === 404, 'B no puede responder tarjetas de A');
$r = $b->api('POST', '/api/tarjeta', ['id' => $c0['id'], 'front' => 'hack', 'back' => 'hack']);
check($r['status'] === 404, 'B no puede editar tarjetas de A');
$r = $b->post("/mazos/$deckTic/borrar");
check($r['status'] === 404 && (int) db()->query("SELECT COUNT(*) FROM decks WHERE id = $deckTic")->fetchColumn() === 1, 'B no puede borrar mazos de A');

echo "10) Google: el botón arma bien la solicitud\n";
$g = $a->get('/auth/google?recordar=1');
$loc = (string) $g['location'];
parse_str((string) parse_url($loc, PHP_URL_QUERY), $gq);
check(str_starts_with($loc, 'https://accounts.google.com/o/oauth2/v2/auth?'), 'redirige a Google');
check(($gq['code_challenge_method'] ?? '') === 'S256' && strlen($gq['state'] ?? '') === 48 && strlen($gq['nonce'] ?? '') === 48, 'manda state, nonce y PKCE S256');
check(($gq['redirect_uri'] ?? '') === $BASE . '/auth/google/callback' && ($gq['scope'] ?? '') === 'openid email profile', 'redirect_uri y scope correctos');
$cb = $a->get('/auth/google/callback?state=falso&code=abc');
check($cb['status'] === 303 && str_ends_with((string) $cb['location'], '/entrar'), 'un state falso se rechaza');

echo "11) Cerrar sesión, recordar sesión y borrar cuenta\n";
$cookieLine = '';
foreach (file($a->jar) as $line) {
    if (str_contains($line, 'fc_recordar')) {
        $cookieLine = $line;
    }
}
$remember = trim((string) (explode("\t", trim($cookieLine))[6] ?? ''));
$c2 = new Client($BASE, 'a2');
$r = $c2->req('GET', '/hoy', null, ['Cookie: fc_recordar=' . $remember]);
check($r['status'] === 200, 'la cookie de "mantener sesión" entra sin código');
$c3 = new Client($BASE, 'a3');
$r = $c3->req('GET', '/hoy', null, ['Cookie: fc_recordar=' . $remember]);
check($r['status'] === 303, 'esa cookie solo sirve una vez (se renueva)');
$b->get('/cuenta');
$r = $b->post('/cuenta/borrar', ['confirmar' => 'borrar']);
check(str_ends_with((string) $r['location'], '/cuenta#borrar'), 'borrar cuenta pide escribir BORRAR');
$r = $b->post('/cuenta/borrar', ['confirmar' => 'BORRAR']);
check(str_contains((string) $r['location'], 'cuenta_borrada') && !db()->query("SELECT id FROM users WHERE email = 'companero@example.com'")->fetch(), 'la cuenta de B se borró');
$a->get('/cuenta');
$r = $a->post('/salir');
check($r['status'] === 303 && $a->get('/hoy')['status'] === 303, 'cerrar sesión funciona');

echo "\nResultado: " . ($checks - $failures) . " de $checks pruebas pasaron.\n";
exit($failures ? 1 : 0);
