<?php

declare(strict_types=1);

?>
<?php if ($error !== null): ?>
    <div class="notice notice-error" id="audit-error" role="alert" tabindex="-1"><p><?= e($error) ?></p></div>
<?php endif; ?>
<section class="audit-log" aria-labelledby="audit-heading">
    <h2 id="audit-heading">Record changes</h2>
    <form method="get" action="/admin/audit" class="auth-form audit-filters">
        <div><label for="user_id">User</label><select id="user_id" name="user_id" autocomplete="off">
            <option value="">All users</option>
            <?php foreach ($users as $account): ?>
                <option value="<?= e($account['id']) ?>"<?= $filters['user_id'] === (string) $account['id'] ? ' selected' : '' ?>><?= e($account['name']) ?></option>
            <?php endforeach; ?>
        </select></div>
        <div><label for="entity">Entity</label><select id="entity" name="entity" autocomplete="off">
            <option value="">All entities</option>
            <?php foreach ($entities as $entity): ?>
                <option value="<?= e($entity) ?>"<?= $filters['entity'] === $entity ? ' selected' : '' ?>><?= e($entity) ?></option>
            <?php endforeach; ?>
        </select></div>
        <div><label for="from">Start date</label><input type="date" id="from" name="from" autocomplete="off" min="1000-01-01" max="9999-12-31" value="<?= e($filters['from']) ?>"></div>
        <div><label for="to">End date (inclusive)</label><input type="date" id="to" name="to" autocomplete="off" min="1000-01-01" max="9999-12-31" value="<?= e($filters['to']) ?>"></div>
        <div class="audit-actions"><button type="submit">Filter audit log</button><a href="/admin/audit">Clear filters</a></div>
    </form>
    <?php if ($error === null && $entries === []): ?><p>No audit entries match these filters. Clear filters to view all entries.</p><?php endif; ?>
    <?php foreach ($entries as $entry): ?>
        <article class="account-card audit-entry">
            <h3><?= e($entry['action']) ?>: <?= e($entry['entity']) ?> #<?= e($entry['entity_id']) ?></h3>
            <p>By <?= e($entry['user_name']) ?> (user #<?= e($entry['user_id']) ?>) at <time><?= e($entry['created_at']) ?></time></p>
            <p>IP address: <?= e($entry['ip_address'] ?? 'Not recorded') ?></p>
            <details><summary>Changed fields (before and after)</summary><pre><?= e($entry['changes']) ?></pre></details>
        </article>
    <?php endforeach; ?>
</section>
<script src="/assets/js/audit.js" defer></script>
