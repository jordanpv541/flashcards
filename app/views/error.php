<section class="narrow">
    <h1><?= e($title ?? 'Algo salió mal') ?></h1>
    <p><?= e($message ?? '') ?></p>
    <?php if (!empty($detail)): ?><pre class="small"><?= e($detail) ?></pre><?php endif; ?>
    <p><a class="btn" href="/">Ir al inicio</a></p>
</section>
