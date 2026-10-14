<?php

declare(strict_types=1);

?>
<?php if ($error !== null): ?>
    <div class="notice notice-error" id="user-error" role="alert" tabindex="-1"><p><?= e($error) ?></p></div>
<?php endif; ?>
<div class="user-management">
    <section class="auth-panel" aria-labelledby="create-heading">
        <h2 id="create-heading">Create account</h2>
        <form method="post" action="/admin/users" class="auth-form">
            <?= csrf_field($csrfToken) ?>
            <div><label for="name">Name</label><input id="name" name="name" autocomplete="name" maxlength="255" value="<?= e($values['name']) ?>" required></div>
            <div><label for="email">Email</label><input id="email" name="email" type="email" autocomplete="off" spellcheck="false" autocapitalize="none" maxlength="254" value="<?= e($values['email']) ?>" required></div>
            <div><label for="password">Initial password</label><input id="password" name="password" type="password" autocomplete="new-password" required></div>
            <div>
                <label for="role">Role</label>
                <select id="role" name="role" autocomplete="off" required>
                    <option value="">Select a role</option>
                    <?php foreach ($roles as $role): ?>
                        <option value="<?= e($role) ?>"<?= $values['role'] === $role ? ' selected' : '' ?>><?= e($roleLabels[$role]) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit">Create account</button>
        </form>
    </section>
    <section class="account-list" aria-labelledby="accounts-heading">
        <h2 id="accounts-heading">Accounts</h2>
        <?php if ($users === []): ?><p>No accounts to display.</p><?php endif; ?>
        <?php foreach ($users as $account): ?>
            <article class="account-card">
                <h3><?= e($account['name']) ?></h3>
                <p><?= e($account['email']) ?></p>
                <p>Status: <?= e((int) $account['active'] === 1 ? 'Active' : 'Inactive') ?></p>
                <form method="post" action="/admin/users/<?= e($account['id']) ?>/role" class="role-form">
                    <?= csrf_field($csrfToken) ?>
                    <label for="role-<?= e($account['id']) ?>">Role for <?= e($account['name']) ?></label>
                    <select id="role-<?= e($account['id']) ?>" name="role" autocomplete="off" required>
                        <?php foreach ($roles as $role): ?>
                            <option value="<?= e($role) ?>"<?= $account['role'] === $role ? ' selected' : '' ?>><?= e($roleLabels[$role]) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit">Save role</button>
                </form>
                <?php if ((int) $account['active'] === 1): ?>
                    <details class="deactivate-confirm">
                        <summary>Deactivate account</summary>
                        <p>This account will lose access on its next request. Its records will be kept.</p>
                        <form method="post" action="/admin/users/<?= e($account['id']) ?>/deactivate">
                            <?= csrf_field($csrfToken) ?>
                            <button type="submit">Confirm deactivation</button>
                        </form>
                    </details>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </section>
</div>
<script src="/assets/js/users.js" defer></script>
