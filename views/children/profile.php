<?php

declare(strict_types=1);

?>
<a class="profile-back" href="<?= e($child['archived_at'] === null ? '/children' : '/children?include_archived=1') ?>">Back to children</a>
<?php if ($error !== null): ?>
    <div id="child-error" class="notice notice-error" role="alert" tabindex="-1"><p><?= e($error) ?></p></div>
<?php endif; ?>
<article class="account-card child-profile" aria-labelledby="child-name">
    <header class="child-profile-heading">
        <span class="child-identity-icon" aria-hidden="true"><?php $view->partial('partials/navigation-icon', ['href' => '/children']); ?></span>
        <div class="child-identity">
        <h2 id="child-name"><?= e($child['full_name']) ?></h2>
        <p>Record number: <?= e($child['record_no']) ?></p>
        <div class="child-identity-meta"><span><?= e($child['sex'] === 'M' ? 'Male' : 'Female') ?></span><span>Born <?= e($child['date_of_birth']) ?></span><span class="status-badge <?= e($child['archived_at'] === null ? 'status-active' : 'status-archived') ?>"><?= e($child['archived_at'] === null ? 'Active record' : 'Archived record') ?></span></div>
        </div>
        <?php if ($canEdit): ?><a class="profile-edit" href="<?= e('/children/' . $child['id'] . '/edit') ?>">Edit child details</a><?php endif; ?>
    </header>
    <?php if ($child['archived_at'] !== null): ?>
        <div class="notice notice-warning">
            <p>This child record is archived. Archived on <?= e($child['archived_at']) ?>.</p>
            <p>Reason: <?= e($child['archive_reason'] ?? 'Not recorded') ?></p>
        </div>
    <?php endif; ?>
    <details class="demographic-details"><summary>Child and family details <span>View demographics, contact details, and notes</span></summary>
    <div class="demographic-grid"><?php foreach ($sections as $heading => $fields): ?>
        <section class="profile-section">
            <h3><?= e($heading) ?></h3>
            <dl class="profile-details">
                <?php foreach ($fields as $field => $label): ?>
                    <div>
                        <dt><?= e($label) ?></dt>
                        <dd><?= e($field === 'sex' ? ($child[$field] === 'M' ? 'Male' : 'Female') : ($child[$field] ?? 'Not recorded')) ?></dd>
                    </div>
                <?php endforeach; ?>
            </dl>
        </section>
    <?php endforeach; ?></div></details>
</article>
<?php if (($child['archived_at'] === null && $canArchive) || ($child['archived_at'] !== null && $canRestore)): ?>
    <section class="account-card profile-records record-status" aria-label="Record status">
        <details class="deactivate-confirm"<?= $error !== null ? ' open' : '' ?>>
            <summary><?= e($child['archived_at'] === null ? 'Archive child record' : 'Restore child record') ?></summary>
            <p><?= e($child['archived_at'] === null
                ? 'Archiving hides this child from default lists and reports. The record is kept and can be restored.'
                : 'Restoring returns this child to default lists and reports. The archive history remains in the audit log.') ?></p>
            <form method="post" action="<?= e('/children/' . $child['id'] . ($child['archived_at'] === null ? '/archive' : '/restore')) ?>" class="auth-form child-form">
                <?= csrf_field($csrfToken) ?>
                <div>
                    <label for="status-reason">Reason (required)</label>
                    <textarea id="status-reason" name="reason" autocomplete="off" rows="3" maxlength="65535" required><?= e($reason) ?></textarea>
                </div>
                <label class="duplicate-check" for="status-confirm"><input type="checkbox" id="status-confirm" name="confirm" value="1" required>
                    <?= e($child['archived_at'] === null ? 'Confirm archive of this child record.' : 'Confirm restore of this child record.') ?>
                </label>
                <button type="submit"><?= e($child['archived_at'] === null ? 'Archive child record' : 'Restore child record') ?></button>
            </form>
        </details>
    </section>
<?php endif; ?>
<nav class="profile-tabs" aria-label="Child health records">
    <?php foreach ($tabs as $key => $label): ?>
        <a href="<?= e('/children/' . $child['id'] . '?tab=' . $key) ?>"<?= $tab === $key ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>
<section class="account-card profile-records" aria-labelledby="health-records-heading">
    <header class="care-section-header"><h2 id="health-records-heading"><?= e($tabs[$tab]) ?></h2>
    <?php if ($tab === 'growth' && $child['archived_at'] === null && $canMeasure): ?>
        <a class="profile-edit" href="<?= e('/children/' . $child['id'] . '/measurements/new') ?>">Add measurement</a>
    <?php endif; ?>
    <?php if ($tab === 'visits' && $child['archived_at'] === null && $canVisit): ?>
        <a class="profile-edit" href="<?= e('/children/' . $child['id'] . '/visits/new') ?>">Record visit</a>
    <?php endif; ?>
    </header><p class="care-section-description"><?= e($tabMessages[$tab]) ?></p>
    <?php if ($tab === 'growth'): ?>
        <?php require __DIR__ . '/growth.php'; ?>
    <?php endif; ?>
    <?php if ($tab === 'immunization'): ?>
        <?php require __DIR__ . '/immunization.php'; ?>
    <?php endif; ?>
    <?php if ($tab === 'visits'): ?>
        <?php require __DIR__ . '/timeline.php'; ?>
    <?php endif; ?>
</section>
<script src="/assets/js/children.js" defer></script>
