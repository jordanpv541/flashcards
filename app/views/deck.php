<?php
$deckId = (int) $deck['id'];
$draft = $_SESSION['card_draft'] ?? null;
unset($_SESSION['card_draft']);
$lastWeek = $_SESSION['last_week'] ?? ($week ?? null);
$stateNames = ['Nueva', 'Aprendiendo', 'Repaso', 'Reaprendiendo'];
$baseQuery = fn(array $extra) => '?' . http_build_query(array_filter(['semana' => $week, 'q' => $search] + $extra, fn($v) => $v !== null && $v !== ''));
?>
<section>
    <p class="crumbs"><a href="/mazos">Mazos</a></p>
    <h1><?= e($deck['name']) ?></h1>
    <?php if ($counts): ?>
        <p class="deck-meta">
            <span class="pill<?= $counts['due_now'] > 0 ? ' pill-due' : '' ?>"><?= (int) $counts['due_now'] ?> pendientes</span>
            <span class="pill"><?= (int) $counts['new_total'] ?> nuevas</span>
            <span class="muted"><?= (int) $counts['total'] ?> en total</span>
        </p>
    <?php endif; ?>

    <a class="btn btn-primary btn-big" href="/repasar?mazo=<?= $deckId ?>">Repasar este mazo</a>

    <details class="panel" id="examen">
        <summary><?= icon('examen') ?> Modo examen</summary>
        <p class="muted small">Repasa todas las tarjetas de las semanas que elijas, en desorden. No cambia tu horario de repaso.</p>
        <form method="get" action="/repasar" class="form">
            <input type="hidden" name="modo" value="examen">
            <input type="hidden" name="mazo" value="<?= $deckId ?>">
            <?php if ($weeks): ?>
                <fieldset class="chips">
                    <legend>Semanas (si no marcas ninguna, entran todas)</legend>
                    <?php foreach ($weeks as $w): ?>
                        <label class="chip"><input type="checkbox" name="semanas[]" value="<?= $w ?>"> S<?= $w ?></label>
                    <?php endforeach; ?>
                </fieldset>
            <?php endif; ?>
            <button class="btn btn-primary" type="submit">Empezar modo examen</button>
        </form>
    </details>

    <details class="panel" id="nueva"<?= $draft ? ' open' : '' ?>>
        <summary><?= icon('mas') ?> Agregar tarjeta</summary>
        <form method="post" action="/mazos/<?= $deckId ?>/tarjetas" class="form">
            <?= csrf_field() ?>
            <label for="pregunta">Pregunta</label>
            <textarea id="pregunta" name="pregunta" rows="3" required maxlength="5000"><?= e($draft['pregunta'] ?? '') ?></textarea>
            <label for="respuesta">Respuesta</label>
            <textarea id="respuesta" name="respuesta" rows="4" required maxlength="5000"><?= e($draft['respuesta'] ?? '') ?></textarea>
            <label for="semana">Semana (opcional)</label>
            <input id="semana" name="semana" type="text" inputmode="numeric" maxlength="12" value="<?= e((string) ($draft['semana'] ?? ($lastWeek ?? ''))) ?>" placeholder="3">
            <p class="muted small">Fórmulas: escríbelas entre \( y \), por ejemplo \( x^2 + y^2 = r^2 \).</p>
            <button class="btn btn-primary" type="submit">Guardar tarjeta</button>
        </form>
    </details>

    <h2>Tarjetas</h2>
    <?php if ($weeks): ?>
        <nav class="chips" aria-label="Filtrar por semana">
            <a class="chip<?= $week === null ? ' is-active' : '' ?>" href="<?= e('?' . http_build_query(array_filter(['q' => $search]))) ?>">Todas</a>
            <?php foreach ($weeks as $w): ?>
                <a class="chip<?= $week === $w ? ' is-active' : '' ?>" href="<?= e('?' . http_build_query(array_filter(['semana' => $w, 'q' => $search], fn($v) => $v !== null && $v !== ''))) ?>">S<?= $w ?></a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>

    <form method="get" class="search">
        <?php if ($week !== null): ?><input type="hidden" name="semana" value="<?= (int) $week ?>"><?php endif; ?>
        <input type="search" name="q" value="<?= e($search) ?>" placeholder="Buscar en este mazo" aria-label="Buscar">
    </form>

    <?php if (!$cards): ?>
        <p class="muted">No hay tarjetas<?= $search !== '' || $week !== null ? ' con ese filtro' : '' ?>.
            <?php if ($search === '' && $week === null): ?><a href="/importar?mazo=<?= $deckId ?>">Importa un CSV</a> o agrega una arriba.<?php endif; ?>
        </p>
    <?php else: ?>
        <p class="muted small"><?= plural($total, 'tarjeta', 'tarjetas') ?></p>
        <ul class="card-list math">
            <?php foreach ($cards as $c): ?>
                <li class="card-row">
                    <a class="card-edit" href="/tarjetas/<?= (int) $c['id'] ?>/editar?volver=<?= e(rawurlencode('/mazos/' . $deckId . $baseQuery(['pagina' => $page > 1 ? $page : null]))) ?>" aria-label="Editar tarjeta"><?= icon('editar') ?></a>
                    <p class="card-front"><?= e(short_text($c['front'], 160)) ?></p>
                    <p class="card-back"><?= e(short_text($c['back'], 160)) ?></p>
                    <p class="card-info">
                        <?php if ($c['week'] !== null): ?><span class="pill">S<?= (int) $c['week'] ?></span><?php endif; ?>
                        <span><?= e($stateNames[(int) $c['state']] ?? '') ?></span>
                        <?php if ((int) $c['state'] > 0): ?>
                            <span>· vuelve el <?= e(format_local($c['due'], user_tz($user), 'd/m')) ?></span>
                        <?php endif; ?>
                        <?php if ((int) $c['lapses'] > 0): ?><span>· olvidada <?= plural((int) $c['lapses'], 'vez', 'veces') ?></span><?php endif; ?>
                    </p>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php if ($pages > 1): ?>
            <nav class="pager">
                <?php if ($page > 1): ?><a class="btn" href="<?= e($baseQuery(['pagina' => $page - 1])) ?>">Anterior</a><?php endif; ?>
                <span class="muted">Página <?= $page ?> de <?= $pages ?></span>
                <?php if ($page < $pages): ?><a class="btn" href="<?= e($baseQuery(['pagina' => $page + 1])) ?>">Siguiente</a><?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>

    <h2>Opciones del mazo</h2>
    <div class="stack">
        <a class="btn" href="/importar?mazo=<?= $deckId ?>"><?= icon('importar') ?> Importar CSV a este mazo</a>
        <a class="btn" href="/mazos/<?= $deckId ?>/exportar"><?= icon('bajar') ?> Exportar CSV</a>
    </div>
    <details class="panel">
        <summary>Renombrar</summary>
        <form method="post" action="/mazos/<?= $deckId ?>/renombrar" class="form">
            <?= csrf_field() ?>
            <label for="nuevo-nombre">Nuevo nombre</label>
            <input id="nuevo-nombre" name="nombre" type="text" maxlength="120" required value="<?= e($deck['name']) ?>">
            <button class="btn" type="submit">Guardar nombre</button>
        </form>
    </details>
    <details class="panel danger">
        <summary>Borrar mazo</summary>
        <p class="small">Se borran el mazo, todas sus tarjetas y su progreso. No se puede deshacer.</p>
        <form method="post" action="/mazos/<?= $deckId ?>/borrar" data-confirm="¿Borrar el mazo &quot;<?= e($deck['name']) ?>&quot; y todas sus tarjetas?">
            <?= csrf_field() ?>
            <button class="btn btn-danger" type="submit">Borrar mazo</button>
        </form>
    </details>
</section>
<?php unset($_SESSION['last_week']); ?>
