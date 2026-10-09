<?php
$s = $summary;
$pending = $s['due'] + $s['new_today'];
$short = (int) $user['short_session'];
$firstName = explode(' ', trim((string) $user['name']))[0] ?? '';
?>
<section class="today">
    <h1>Hola<?= $firstName !== '' ? ', ' . e($firstName) : '' ?></h1>

    <?php if (!$decks): ?>
        <div class="panel empty">
            <p><strong>Todavía no tienes mazos.</strong></p>
            <p class="muted">Sube tu CSV de tarjetas o crea un mazo y escribe tus tarjetas a mano.</p>
            <div class="stack">
                <a class="btn btn-primary btn-big" href="/importar">Importar un CSV</a>
                <a class="btn btn-big" href="/mazos">Crear un mazo</a>
            </div>
        </div>
    <?php else: ?>
        <div class="panel today-panel">
            <p class="big-number"><?= $pending ?></p>
            <p class="today-label">
                <?= $pending === 1 ? 'tarjeta para hoy' : 'tarjetas para hoy' ?>:
                <?= plural($s['due'], 'de repaso', 'de repaso') ?> y <?= plural($s['new_today'], 'nueva', 'nuevas') ?>
            </p>
            <?php if ($pending > 0): ?>
                <div class="stack">
                    <a class="btn btn-primary btn-big" href="/repasar?limite=<?= $short ?>">Repasar <?= min($short, $pending) ?></a>
                    <?php if ($pending > $short): ?>
                        <a class="btn btn-big" href="/repasar">Repasar todo (<?= $pending ?>)</a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <p class="done-msg">¡Listo por hoy! Vuelve mañana o usa el modo examen en un mazo.</p>
            <?php endif; ?>
            <p class="muted small stats-line">
                Mañana: <?= $s['tomorrow'] ?> ·
                Hoy repasaste: <?= $s['reviewed_today'] ?> ·
                Racha: <?= plural($s['streak'], 'día', 'días') ?>
            </p>
            <?php if ($s['new_remaining'] === 0 && $s['new_available'] > 0): ?>
                <p class="muted small">Ya viste tus <?= (int) $user['new_per_day'] ?> nuevas de hoy. Puedes cambiar el tope en <a href="/cuenta">Cuenta</a>.</p>
            <?php endif; ?>
        </div>

        <h2>Tus mazos</h2>
        <ul class="deck-list">
            <?php foreach ($decks as $d): ?>
                <li>
                    <a class="deck-item" href="/mazos/<?= (int) $d['id'] ?>">
                        <span class="deck-name"><?= e($d['name']) ?></span>
                        <span class="deck-meta">
                            <span class="pill<?= $d['due_now'] > 0 ? ' pill-due' : '' ?>"><?= (int) $d['due_now'] ?> pendientes</span>
                            <span class="pill"><?= (int) $d['new_total'] ?> nuevas</span>
                            <span class="muted"><?= (int) $d['total'] ?> en total</span>
                        </span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
