<?php

declare(strict_types=1);

?>
<div class="notice notice-info schedule-guidance"><p>The bundled schedule is a draft. Confirm it with the health center before live use. Changes apply to the configured schedule; recorded vaccinations keep their dose identities.</p></div>
<?php if ($error !== null): ?><div class="notice notice-error" id="schedule-error" role="alert" tabindex="-1"><p><?= e($error) ?></p></div><?php endif; ?>
<div class="schedule-layout">
    <section class="auth-panel schedule-add" aria-labelledby="add-heading">
        <h2 id="add-heading">Add vaccine</h2>
        <p class="panel-description">Create the vaccine first, then configure its doses.</p>
        <form method="post" action="/admin/schedule/vaccines" class="auth-form">
            <?= csrf_field($csrfToken) ?>
            <div><label for="code">Vaccine code</label><input id="code" name="code" maxlength="32" pattern="[A-Z0-9_]+" autocomplete="off" value="<?= e($values['code'] ?? '') ?>" aria-describedby="code-help" required><p id="code-help">Uppercase letters, digits and underscores. The code stays fixed after creation.</p></div>
            <div><label for="name">Vaccine name</label><input id="name" name="name" maxlength="150" autocomplete="off" value="<?= e($values['name'] ?? '') ?>" required></div>
            <div><label for="active">Active</label><select id="active" name="active" required><option value="1" <?= (string) ($values['active'] ?? '') === '1' ? 'selected' : '' ?>>Yes</option><option value="0" <?= (string) ($values['active'] ?? '') === '0' ? 'selected' : '' ?>>No</option></select></div>
            <button type="submit">Add vaccine</button>
        </form>
    </section>
    <section class="schedule-list" aria-labelledby="schedule-heading">
        <h2 id="schedule-heading">Vaccines and doses</h2>
        <?php if ($vaccines === []): ?><p>No vaccines yet. Add a vaccine, then define its doses.</p><?php endif; ?>
        <?php foreach ($vaccines as $vaccine): ?>
            <article class="account-card vaccine-card">
                <header class="vaccine-card-header"><div>
                <h3><?= e($vaccine['name']) ?></h3>
                <p class="vaccine-code"><?= e($vaccine['code']) ?> <span class="status-badge <?= e((int) $vaccine['active'] === 1 ? 'status-active' : 'status-archived') ?>"><?= e((int) $vaccine['active'] === 1 ? 'Active' : 'Inactive') ?></span></p>
                </div><a class="button-secondary" href="/admin/schedule/vaccines/<?= e($vaccine['id']) ?>" aria-label="<?= e('Edit vaccine or add a dose for ' . $vaccine['name']) ?>">Manage vaccine</a></header>
                <?php if ($vaccine['doses'] === []): ?><p>No doses configured.</p><?php endif; ?>
                <?php if ($vaccine['doses'] !== []): ?>
                <div class="child-table-wrap dose-table-wrap" role="region" aria-label="<?= e($vaccine['name'] . ' configured doses') ?>" tabindex="0">
                    <table class="child-table dose-table">
                        <caption class="visually-hidden"><?= e($vaccine['name']) ?> configured doses</caption>
                        <thead><tr><th scope="col">Dose</th><th scope="col">Recommended age</th><th scope="col">Minimum interval</th><th scope="col">Grace period</th><th scope="col">FIC</th><th scope="col">Order</th><th scope="col">Action</th></tr></thead>
                        <tbody>
                <?php foreach ($vaccine['doses'] as $dose): ?>
                        <tr>
                            <th scope="row">Dose <?= e($dose['dose_number']) ?></th>
                            <td><?= e($dose['recommended_age_days'] ?? $dose['recommended_age_months']) ?> <?= e($dose['recommended_age_days'] === null ? 'calendar months' : 'days') ?></td>
                            <td><?= e($dose['min_interval_days'] ?? $dose['min_interval_months']) ?> <?= e($dose['min_interval_days'] === null ? 'calendar months' : 'days') ?></td>
                            <td><?= e($dose['grace_days']) ?> days</td>
                            <td><?= e((int) $dose['counts_toward_fic'] === 1 ? 'Yes' : 'No') ?></td>
                            <td><?= e($dose['sort_order']) ?></td>
                            <td><a href="/admin/schedule/doses/<?= e($dose['id']) ?>" aria-label="<?= e('Edit dose ' . $dose['dose_number'] . ' of ' . $vaccine['name']) ?>">Edit dose</a></td>
                        </tr>
                <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </section>
</div>
<script src="/assets/js/schedule.js" defer></script>
