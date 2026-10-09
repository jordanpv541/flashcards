<section class="narrow">
    <p class="crumbs"><a href="<?= e($back) ?>">Volver</a> · <?= e($card['deck_name']) ?></p>
    <h1>Editar tarjeta</h1>
    <form method="post" action="/tarjetas/<?= (int) $card['id'] ?>/editar" class="form">
        <?= csrf_field() ?>
        <input type="hidden" name="volver" value="<?= e($back) ?>">
        <label for="pregunta">Pregunta</label>
        <textarea id="pregunta" name="pregunta" rows="4" required maxlength="5000"><?= e($card['front']) ?></textarea>
        <label for="respuesta">Respuesta</label>
        <textarea id="respuesta" name="respuesta" rows="6" required maxlength="5000"><?= e($card['back']) ?></textarea>
        <label for="semana">Semana (opcional)</label>
        <input id="semana" name="semana" type="text" inputmode="numeric" maxlength="12" value="<?= e($card['week'] !== null ? (string) $card['week'] : '') ?>">
        <p class="muted small">Cambiar el texto no borra tu progreso con esta tarjeta.</p>
        <button class="btn btn-primary btn-big" type="submit">Guardar</button>
    </form>

    <h2>Vista previa</h2>
    <div class="panel preview math">
        <p class="preview-front"><?= e($card['front']) ?></p>
        <hr>
        <p class="preview-back"><?= e($card['back']) ?></p>
    </div>

    <details class="panel danger">
        <summary>Borrar tarjeta</summary>
        <form method="post" action="/tarjetas/<?= (int) $card['id'] ?>/borrar" data-confirm="¿Borrar esta tarjeta?">
            <?= csrf_field() ?>
            <button class="btn btn-danger" type="submit">Borrar tarjeta</button>
        </form>
    </details>
</section>
