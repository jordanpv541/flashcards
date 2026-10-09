<section class="narrow">
    <h1>Entrar</h1>
    <p class="muted">Si es tu primera vez, se crea tu cuenta al entrar.</p>

    <?php if (google_enabled()): ?>
        <a class="btn btn-google btn-big" href="/auth/google?recordar=1"><?= icon('google') ?> Entrar con Google</a>
        <p class="divider"><span>o con tu correo</span></p>
    <?php endif; ?>

    <form method="post" action="/entrar" class="form">
        <?= csrf_field() ?>
        <label for="email">Correo</label>
        <input id="email" name="email" type="email" inputmode="email" autocomplete="email" required maxlength="254" placeholder="tu@correo.com">
        <div class="hp" aria-hidden="true">
            <label for="website">No llenes este campo</label>
            <input id="website" name="website" type="text" tabindex="-1" autocomplete="off">
        </div>
        <label class="check"><input type="checkbox" name="recordar" value="1" checked> Mantener la sesión iniciada</label>
        <?php if (turnstile_enabled()): ?>
            <div class="cf-turnstile" data-sitekey="<?= e((string) config('turnstile.site_key')) ?>" data-language="es"></div>
        <?php endif; ?>
        <button class="btn btn-primary btn-big" type="submit">Enviarme un código</button>
        <p class="muted small">Te llega un código de 6 números. Vence en 10 minutos.</p>
    </form>
</section>
