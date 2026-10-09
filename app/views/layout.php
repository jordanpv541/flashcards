<?php
/** @var string $content */
$user = $user ?? current_user();
$title = $title ?? 'Flashcards';
$nav = $nav ?? '';
$hideNav = $hideNav ?? false;
$flashes = take_flashes();
$navItems = [
    'hoy' => ['/hoy', 'Hoy'],
    'mazos' => ['/mazos', 'Mazos'],
    'importar' => ['/importar', 'Importar'],
    'errores' => ['/errores', 'Errores'],
    'cuenta' => ['/cuenta', 'Cuenta'],
];
?><!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?= e($title) ?> · Flashcards</title>
    <meta name="description" content="Tarjetas de estudio con repaso espaciado. Gratis, sin anuncios y de código abierto.">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#f4f5f7" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#0f1419" media="(prefers-color-scheme: dark)">
    <link rel="icon" href="/assets/img/icon.svg" type="image/svg+xml">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <?php if (!empty($math)): ?>
        <link rel="stylesheet" href="<?= e(asset('vendor/katex/katex.min.css')) ?>">
    <?php endif; ?>
</head>
<body class="<?= e(trim(($bodyClass ?? '') . ($user && !$hideNav ? ' has-nav' : ''))) ?>" data-csrf="<?= e(csrf_token()) ?>">
<?php if (empty($hideNav)): ?>
    <header class="topbar">
        <a class="brand" href="<?= $user ? '/hoy' : '/' ?>">
            <img src="/assets/img/icon.svg" alt="" width="26" height="26">
            <span>Flashcards</span>
        </a>
        <?php if ($user): ?>
            <nav class="topnav" aria-label="Principal">
                <?php foreach ($navItems as $key => [$href, $label]): ?>
                    <a href="<?= $href ?>"<?= $nav === $key ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
                <?php endforeach; ?>
            </nav>
        <?php else: ?>
            <a class="btn btn-small" href="/entrar">Entrar</a>
        <?php endif; ?>
    </header>
<?php endif; ?>

<?php if ($flashes): ?>
    <div class="flashes" role="status">
        <?php foreach ($flashes as $f): ?>
            <p class="flash flash-<?= e($f['type']) ?>"><?= e($f['message']) ?></p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<main class="<?= e($mainClass ?? 'page') ?>" id="contenido">
<?= $content ?>
</main>

<?php if (empty($hideNav)): ?>
    <footer class="footer">
        <p>Todo gratis, sin plan Pro y sin anuncios.</p>
        <p>
            <a href="<?= e((string) config('source_url')) ?>" rel="noopener">Código abierto (AGPL)</a> ·
            <a href="/privacidad">Privacidad</a> ·
            <a href="/terminos">Términos</a>
        </p>
    </footer>
<?php endif; ?>

<?php if ($user && !$hideNav): ?>
    <nav class="bottomnav" aria-label="Secciones">
        <?php foreach ($navItems as $key => [$href, $label]): ?>
            <a href="<?= $href ?>"<?= $nav === $key ? ' aria-current="page"' : '' ?>>
                <?= icon($key) ?><span><?= e($label) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>
<?php endif; ?>

<?php if (!empty($math)): ?>
    <script src="<?= e(asset('vendor/katex/katex.min.js')) ?>" defer></script>
    <script src="<?= e(asset('vendor/katex/contrib/auto-render.min.js')) ?>" defer></script>
<?php endif; ?>
<?php if (!empty($turnstile)): ?>
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
<?php endif; ?>
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
<?php foreach ($scripts ?? [] as $script): ?>
    <script src="<?= e(asset($script)) ?>" defer></script>
<?php endforeach; ?>
</body>
</html>
