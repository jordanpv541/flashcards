<?php
$rows = $import['rows'];
$sample = array_slice($rows, 0, 8);
$fmt = $import['format'];
?>
<section class="narrow">
    <p class="crumbs"><a href="/importar">Importar</a></p>
    <h1>Revisa antes de guardar</h1>

    <div class="panel">
        <p><strong><?= e($import['filename']) ?></strong> → <?= $isNewDeck ? 'mazo nuevo' : 'mazo' ?> <strong><?= e($deckName) ?></strong></p>
        <ul class="summary-list">
            <li><strong><?= $stats['new'] ?></strong> <?= $stats['new'] === 1 ? 'tarjeta nueva' : 'tarjetas nuevas' ?></li>
            <?php if (!$isNewDeck): ?>
                <li><strong><?= $stats['updated'] ?></strong> se actualizan (tu avance se mantiene)</li>
                <li><strong><?= $stats['same'] ?></strong> ya están iguales</li>
            <?php endif; ?>
        </ul>
        <p class="small muted">
            Formato detectado: separador <?= e($fmt['delimiter']) ?>,
            <?= $fmt['header'] ? 'con encabezado' : 'sin encabezado' ?>,
            columnas <?= e($fmt['columns']) ?>.
            <?php if ($weeks): ?>Semanas: <?= e(implode(', ', array_map(fn($w) => 'S' . $w, $weeks))) ?>.<?php endif; ?>
        </p>
    </div>

    <?php if ($import['errors']): ?>
        <div class="panel warn">
            <p><strong><?= plural(count($import['errors']), 'fila no se importa', 'filas no se importan') ?>:</strong></p>
            <ul class="small"><?php foreach (array_slice($import['errors'], 0, 10) as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>
    <?php if ($import['warnings']): ?>
        <div class="panel info">
            <ul class="small"><?php foreach (array_slice($import['warnings'], 0, 10) as $w): ?><li><?= e($w) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <h2>Primeras tarjetas</h2>
    <ul class="card-list math">
        <?php foreach ($sample as $r): ?>
            <li class="card-row">
                <p class="card-front"><?= e(short_text($r['front'], 160)) ?></p>
                <p class="card-back"><?= e(short_text($r['back'], 160)) ?></p>
                <?php if ($r['week'] !== null): ?><p class="card-info"><span class="pill">S<?= (int) $r['week'] ?></span></p><?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php if (count($rows) > count($sample)): ?>
        <p class="muted small">…y <?= count($rows) - count($sample) ?> más.</p>
    <?php endif; ?>

    <form method="post" action="/importar/confirmar" class="form sticky-actions">
        <?= csrf_field() ?>
        <input type="hidden" name="t" value="<?= e($token) ?>">
        <button class="btn btn-primary btn-big" type="submit"<?= ($stats['new'] + $stats['updated']) === 0 ? ' disabled' : '' ?>>
            <?= ($stats['new'] + $stats['updated']) === 0 ? 'No hay cambios que guardar' : 'Guardar ' . ($stats['new'] + $stats['updated']) . ' tarjetas' ?>
        </button>
        <a class="btn btn-link" href="/importar">Cancelar</a>
    </form>
</section>
