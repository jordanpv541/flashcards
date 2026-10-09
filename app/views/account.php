<?php
$common = ['America/Lima', 'America/Bogota', 'America/Guayaquil', 'America/Mexico_City', 'America/Santiago', 'America/Argentina/Buenos_Aires', 'America/Caracas', 'America/La_Paz', 'Europe/Madrid'];
$current = (string) $user['timezone'];
if (!in_array($current, $common, true)) {
    $common[] = $current;
}
?>
<section class="narrow">
    <h1>Cuenta</h1>
    <p class="muted"><?= e($user['email']) ?><?= $user['google_sub'] ? ' · conectada con Google' : '' ?></p>

    <form method="post" action="/cuenta" class="form panel">
        <?= csrf_field() ?>
        <h2>Tus ajustes</h2>
        <label for="nombre">Nombre</label>
        <input id="nombre" name="nombre" type="text" maxlength="100" value="<?= e($user['name']) ?>">
        <label for="nuevas">Tarjetas nuevas por día (todos los mazos juntos)</label>
        <input id="nuevas" name="nuevas_por_dia" type="number" min="0" max="500" value="<?= (int) $user['new_per_day'] ?>">
        <p class="small muted">Si importas muchas tarjetas, el tope evita que el repaso se te junte. 20 es un buen inicio.</p>
        <label for="corta">Tamaño de la sesión corta</label>
        <input id="corta" name="sesion_corta" type="number" min="5" max="100" value="<?= (int) $user['short_session'] ?>">
        <label for="zona">Zona horaria (define cuándo empieza tu día)</label>
        <select id="zona" name="zona_horaria">
            <?php foreach ($common as $tz): ?>
                <option value="<?= e($tz) ?>"<?= $tz === $current ? ' selected' : '' ?>><?= e($tz) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-primary" type="submit">Guardar</button>
    </form>

    <div class="panel">
        <h2>Tus datos</h2>
        <ul class="summary-list">
            <li><?= plural($stats['decks'], 'mazo', 'mazos') ?> de <?= limit('max_decks') ?> como máximo</li>
            <li><?= plural($stats['cards'], 'tarjeta', 'tarjetas') ?> de <?= number_format(limit('max_cards'), 0, ',', '.') ?> como máximo</li>
            <li><?= plural($stats['reviews'], 'repaso guardado', 'repasos guardados') ?></li>
            <li><?= $stats['imports_today'] ?> de <?= limit('max_imports_per_day') ?> importaciones hoy</li>
        </ul>
        <a class="btn" href="/cuenta/exportar"><?= icon('bajar') ?> Descargar todas mis tarjetas (CSV)</a>
    </div>

    <form method="post" action="/salir" class="form">
        <?= csrf_field() ?>
        <button class="btn" type="submit">Cerrar sesión</button>
    </form>

    <details class="panel danger" id="borrar">
        <summary>Borrar mi cuenta</summary>
        <p class="small">Se borran tu cuenta, tus mazos, tus tarjetas y todo tu historial. No se puede deshacer. Si quieres, primero descarga tus tarjetas.</p>
        <form method="post" action="/cuenta/borrar" class="form" data-confirm="¿Seguro? Se borra todo y no se puede deshacer.">
            <?= csrf_field() ?>
            <label for="confirmar">Escribe BORRAR para confirmar</label>
            <input id="confirmar" name="confirmar" type="text" autocomplete="off" required>
            <button class="btn btn-danger" type="submit">Borrar mi cuenta</button>
        </form>
    </details>

    <p class="small muted">Flashcards <?= e(APP_VERSION) ?> · <a href="<?= e((string) config('source_url')) ?>" rel="noopener">código fuente</a> · Dudas: <?= e((string) config('contact_email')) ?></p>
</section>
