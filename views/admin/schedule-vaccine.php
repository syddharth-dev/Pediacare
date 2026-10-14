<?php

declare(strict_types=1);

?>
<p><a href="/admin/schedule">Back to vaccine schedule</a></p>
<?php if ($error !== null): ?><div class="notice notice-error" id="schedule-error" role="alert" tabindex="-1"><p><?= e($error) ?></p></div><?php endif; ?>
<div class="schedule-edit-layout">
    <section class="auth-panel" aria-labelledby="vaccine-heading">
        <h2 id="vaccine-heading">Edit <?= e($vaccine['name']) ?></h2>
        <p>Vaccine code: <?= e($vaccine['code']) ?></p>
        <form method="post" action="/admin/schedule/vaccines/<?= e($vaccine['id']) ?>" class="auth-form">
            <?= csrf_field($csrfToken) ?>
            <div><label for="name">Vaccine name</label><input id="name" name="name" maxlength="150" autocomplete="off" value="<?= e($values['name'] ?? '') ?>" required></div>
            <div><label for="active">Active</label><select id="active" name="active" required><option value="1" <?= (string) ($values['active'] ?? '') === '1' ? 'selected' : '' ?>>Yes</option><option value="0" <?= (string) ($values['active'] ?? '') === '0' ? 'selected' : '' ?>>No</option></select></div>
            <button type="submit">Save vaccine</button>
        </form>
    </section>
    <section class="account-card clinical-panel" aria-labelledby="dose-heading">
        <h2 id="dose-heading">Add dose for <?= e($vaccine['name']) ?></h2>
        <form method="post" action="/admin/schedule/vaccines/<?= e($vaccine['id']) ?>/doses" class="auth-form child-form">
            <?= csrf_field($csrfToken) ?>
            <?php $view->partial('admin/schedule-dose-fields', ['values' => $doseValues]); ?>
            <button type="submit">Add dose</button>
        </form>
    </section>
</div>
<script src="/assets/js/schedule.js" defer></script>
