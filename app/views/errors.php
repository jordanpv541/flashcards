<section>
    <h1>Cuaderno de errores</h1>
    <p class="muted">Las tarjetas que más fallaste en los últimos 30 días y las que olvidaste alguna vez. Si una tarjeta falla siempre, quizá está mal escrita: edítala.</p>

    <?php if (!$cards): ?>
        <div class="panel empty">
            <p><strong>No hay errores todavía.</strong></p>
            <p class="muted">Cuando marques "Otra vez" o "No lo sabía", la tarjeta aparece aquí.</p>
        </div>
    <?php else: ?>
        <a class="btn btn-primary btn-big" href="/repasar?modo=errores">Repasar estas <?= count($cards) ?></a>
        <ul class="card-list math">
            <?php foreach ($cards as $c): ?>
                <li class="card-row">
                    <a class="card-edit" href="/tarjetas/<?= (int) $c['id'] ?>/editar?volver=%2Ferrores" aria-label="Editar tarjeta"><?= icon('editar') ?></a>
                    <p class="card-front"><?= e(short_text($c['front'], 160)) ?></p>
                    <p class="card-back"><?= e(short_text($c['back'], 160)) ?></p>
                    <p class="card-info">
                        <span><?= e($c['deck_name']) ?></span>
                        <?php if ($c['week'] !== null): ?><span class="pill">S<?= (int) $c['week'] ?></span><?php endif; ?>
                        <?php if ((int) $c['fails'] > 0): ?><span class="pill pill-due"><?= plural((int) $c['fails'], 'fallo', 'fallos') ?></span><?php endif; ?>
                        <?php if ((int) $c['lapses'] > 0): ?><span>· olvidada <?= plural((int) $c['lapses'], 'vez', 'veces') ?></span><?php endif; ?>
                    </p>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
