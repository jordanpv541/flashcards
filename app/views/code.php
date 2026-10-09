<section class="narrow">
    <h1>Escribe tu código</h1>
    <p class="muted">Lo enviamos a <strong><?= e($email) ?></strong>.</p>

    <form method="post" action="/entrar/codigo" class="form">
        <?= csrf_field() ?>
        <label for="codigo">Código de 6 números</label>
        <input id="codigo" name="codigo" class="code-input" type="text" inputmode="numeric" autocomplete="one-time-code"
               pattern="[0-9 ]{6,7}" maxlength="7" required autofocus placeholder="000000">
        <button class="btn btn-primary btn-big" type="submit">Entrar</button>
    </form>

    <form method="post" action="/entrar" class="form-inline">
        <?= csrf_field() ?>
        <input type="hidden" name="email" value="<?= e($email) ?>">
        <?php if ($remember): ?><input type="hidden" name="recordar" value="1"><?php endif; ?>
        <button class="btn btn-link" type="submit">Enviarme otro código</button>
        <a class="btn btn-link" href="/entrar">Usar otro correo</a>
    </form>
</section>
