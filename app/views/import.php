<section class="narrow">
    <h1>Importar CSV</h1>
    <p class="muted">Sirven tus archivos TARJETAS_CURSO.csv tal como están. Antes de guardar verás una vista previa.</p>

    <form method="post" action="/importar" enctype="multipart/form-data" class="form">
        <?= csrf_field() ?>
        <label for="archivo">Archivo (.csv, .tsv o .txt)</label>
        <input id="archivo" name="archivo" type="file" accept=".csv,.tsv,.txt,text/csv,text/plain" required>

        <?php if ($decks): ?>
            <label for="mazo">¿A qué mazo va?</label>
            <select id="mazo" name="mazo" data-toggle-new="nuevo-mazo-campo">
                <option value="0">Mazo nuevo…</option>
                <?php foreach ($decks as $d): ?>
                    <option value="<?= (int) $d['id'] ?>"<?= $selected === (int) $d['id'] ? ' selected' : '' ?>><?= e($d['name']) ?></option>
                <?php endforeach; ?>
            </select>
        <?php else: ?>
            <input type="hidden" name="mazo" value="0">
        <?php endif; ?>

        <div id="nuevo-mazo-campo"<?= $selected ? ' hidden' : '' ?>>
            <label for="nuevo_mazo">Nombre del mazo nuevo</label>
            <input id="nuevo_mazo" name="nuevo_mazo" type="text" maxlength="120" placeholder="Por ejemplo: Matemática II">
        </div>

        <button class="btn btn-primary btn-big" type="submit">Ver vista previa</button>
    </form>

    <div class="panel help">
        <h2>Formatos que acepta</h2>
        <ul>
            <li><code>1;pregunta;respuesta</code> o <code>"1";"pregunta";"respuesta"</code></li>
            <li><code>Semana 1;…</code> o <code>S1;…</code></li>
            <li><code>semana,pregunta,respuesta</code> con encabezado y comas</li>
            <li>Solo <code>pregunta;respuesta</code>, sin semana</li>
        </ul>
        <p class="small muted">Si vuelves a subir el mismo archivo con cambios, las tarjetas se actualizan y no pierdes tu avance. Las preguntas iguales no se duplican.</p>
        <p class="small muted">Límites: <?= limit('max_import_rows') ?> tarjetas por archivo, <?= round(limit('max_csv_bytes') / 1048576, 1) ?> MB y <?= limit('max_imports_per_day') ?> importaciones por día.</p>
    </div>
</section>
