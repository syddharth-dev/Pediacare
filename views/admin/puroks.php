<?php

declare(strict_types=1);

?>
<?php if ($error !== null): ?>
    <div class="notice notice-error" id="purok-error" role="alert" tabindex="-1"><p><?= e($error) ?></p></div>
<?php endif; ?>
<div class="user-management">
    <section class="auth-panel" aria-labelledby="add-heading">
        <h2 id="add-heading">Add purok or sitio</h2>
        <form method="post" action="/admin/puroks" class="auth-form">
            <?= csrf_field($csrfToken) ?>
            <div><label for="name">Name</label><input id="name" name="name" autocomplete="off" maxlength="255" value="<?= e($editId === null ? $name : '') ?>" required></div>
            <button type="submit">Add purok or sitio</button>
        </form>
    </section>
    <section aria-labelledby="puroks-heading">
        <h2 id="puroks-heading">Puroks and sitios</h2>
        <?php if ($puroks === []): ?><p>No puroks or sitios yet. Add one to make it available for child registration.</p><?php endif; ?>
        <?php foreach ($puroks as $purok): ?>
            <article class="account-card purok-row">
                <h3><?= e($purok['name']) ?></h3>
                <form method="post" action="/admin/puroks/<?= e($purok['id']) ?>/rename" class="auth-form">
                    <?= csrf_field($csrfToken) ?>
                    <div><label for="name-<?= e($purok['id']) ?>">Name for <?= e($purok['name']) ?></label><input id="name-<?= e($purok['id']) ?>" name="name" autocomplete="off" maxlength="255" value="<?= e($editId === (string) $purok['id'] ? $name : $purok['name']) ?>" required></div>
                    <button type="submit">Save name</button>
                </form>
            </article>
        <?php endforeach; ?>
    </section>
</div>
<script src="/assets/js/puroks.js" defer></script>
