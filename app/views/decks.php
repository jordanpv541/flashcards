<section>
    <h1>Mazos</h1>

    <details class="panel"<?= !$decks ? ' open' : '' ?>>
        <summary><?= icon('mas') ?> Crear un mazo</summary>
        <form method="post" action="/mazos" class="form">
            <?= csrf_field() ?>
            <label for="nombre">Nombre (por ejemplo, el curso)</label>
            <input id="nombre" name="nombre" type="text" maxlength="120" required placeholder="Matemática Discreta">
            <button class="btn btn-primary" type="submit">Crear mazo</button>
        </form>
    </details>

    <?php if (!$decks): ?>
        <p class="muted">Aún no tienes mazos. También puedes <a href="/importar">importar un CSV</a>: el mazo se crea solo.</p>
    <?php else: ?>
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
