<?php
$back = $deck ? '/mazos/' . (int) $deck['id'] : ($mode === 'errores' ? '/errores' : '/hoy');
$subtitle = $deck ? $deck['name'] : 'Todos los mazos';
if ($weeks) {
    $subtitle .= ' · ' . implode(', ', array_map(fn($w) => 'S' . $w, $weeks));
}
?>
<div id="review" class="review" data-mode="<?= e($mode) ?>" data-query="<?= e($query) ?>" data-back="<?= e($back) ?>"
     data-short="<?= (int) $user['short_session'] ?>">
    <header class="review-top">
        <a class="icon-btn" href="<?= e($back) ?>" aria-label="Salir del repaso"><?= icon('cerrar') ?></a>
        <div class="review-title">
            <strong><?= e($title) ?></strong>
            <span class="muted small"><?= e($subtitle) ?></span>
        </div>
        <button class="icon-btn" type="button" data-action="edit" aria-label="Editar esta tarjeta" hidden><?= icon('editar') ?></button>
    </header>

    <div class="review-progress" aria-hidden="true"><span class="bar"></span></div>
    <p class="review-status small muted" aria-live="polite"></p>

    <section class="review-body" aria-live="polite">
        <div class="review-loading">Cargando tarjetas…</div>
        <article class="flashcard" hidden>
            <p class="flashcard-meta small muted"></p>
            <div class="flashcard-front math"></div>
            <div class="flashcard-back math" hidden></div>
        </article>
        <div class="review-end" hidden></div>
    </section>

    <footer class="review-actions" hidden>
        <button class="btn btn-primary btn-big btn-reveal" type="button" data-action="reveal">Mostrar respuesta</button>
        <div class="grades" hidden></div>
    </footer>

    <p class="pending-note small" hidden></p>
</div>

<dialog class="edit-dialog" id="edit-dialog">
    <form method="dialog" class="form" id="edit-form">
        <h2>Editar tarjeta</h2>
        <label for="ed-front">Pregunta</label>
        <textarea id="ed-front" name="front" rows="3" required maxlength="5000"></textarea>
        <label for="ed-back">Respuesta</label>
        <textarea id="ed-back" name="back" rows="5" required maxlength="5000"></textarea>
        <label for="ed-week">Semana</label>
        <input id="ed-week" name="week" type="text" inputmode="numeric" maxlength="12">
        <p class="form-error small" hidden></p>
        <div class="actions-row">
            <button class="btn btn-primary" type="submit" value="save">Guardar</button>
            <button class="btn" type="button" data-action="cancel-edit">Cancelar</button>
        </div>
    </form>
</dialog>
