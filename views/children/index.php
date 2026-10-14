<?php

declare(strict_types=1);

?>
<section aria-label="Find children">
    <div class="section-intro"><div><h2>Find a child record</h2><p>Search by name, record number, or guardian. Ages use completed months as of today.</p></div><a class="button-primary" href="/children/new">Register child</a></div>
    <?php if ($error !== null): ?>
        <div id="child-error" class="notice notice-error" role="alert" tabindex="-1"><p><?= e($error) ?></p></div>
    <?php endif; ?>
    <form method="get" action="/children" class="auth-form audit-filters child-filters">
        <div class="child-search">
            <label for="q">Name, record number, or guardian</label>
            <input type="search" id="q" name="q" value="<?= e($filters['q']) ?>" placeholder="Enter a name or record number&#8230;" maxlength="255" autocomplete="off">
        </div>
        <div>
            <label for="purok_id">Purok or sitio</label>
            <select id="purok_id" name="purok_id" autocomplete="off">
                <option value="">All puroks and sitios</option>
                <?php foreach ($puroks as $purok): ?>
                    <option value="<?= e($purok['id']) ?>"<?= $filters['purok_id'] === (string) $purok['id'] ? ' selected' : '' ?>><?= e($purok['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="sex">Sex</label>
            <select id="sex" name="sex" autocomplete="off">
                <option value="">All sexes</option>
                <option value="M"<?= $filters['sex'] === 'M' ? ' selected' : '' ?>>Male</option>
                <option value="F"<?= $filters['sex'] === 'F' ? ' selected' : '' ?>>Female</option>
            </select>
        </div>
        <div>
            <label for="age_group">Age group</label>
            <select id="age_group" name="age_group" autocomplete="off">
                <option value="">All ages</option>
                <?php foreach ($ageGroups as $key => $label): ?>
                    <option value="<?= e($key) ?>"<?= $filters['age_group'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="audit-actions child-list-actions">
            <label class="archive-filter" for="include_archived">
                <input type="checkbox" id="include_archived" name="include_archived" value="1"<?= $filters['include_archived'] ? ' checked' : '' ?>>
                Include archived
            </label>
            <button type="submit">Find children</button>
            <a href="/children">Clear filters</a>
        </div>
    </form>
</section>
<?php if ($error === null): ?>
    <?php if ($results['children'] === []): ?>
        <div class="account-card empty-state">
            <span class="empty-state-icon" aria-hidden="true"><?php $view->partial('partials/navigation-icon', ['href' => '/children']); ?></span>
            <h3>No matching records</h3>
            <p>No children match these filters. Clear the filters or register a child.</p>
            <div><a class="button-secondary" href="/children">Clear filters</a><a class="button-primary" href="/children/new">Register child</a></div>
        </div>
    <?php else: ?>
        <p class="child-result-count">Showing <?= e($results['from']) ?>–<?= e($results['to']) ?> of <?= e($results['total']) ?> children.</p>
        <div class="child-table-wrap" role="region" aria-label="Child search results" tabindex="0">
            <table class="child-table">
                <caption>Children matching your filters</caption>
                <thead><tr>
                    <th scope="col">Record number</th><th scope="col">Name</th><th scope="col">Sex</th>
                    <th scope="col">Birth date</th><th scope="col">Age (months)</th><th scope="col">Purok or sitio</th>
                    <th scope="col">Guardian</th><th scope="col">Status</th>
                </tr></thead>
                <tbody>
                    <?php foreach ($results['children'] as $child): ?>
                        <tr>
                            <th scope="row"><a href="<?= e('/children/' . $child['id']) ?>"><?= e($child['record_no']) ?></a></th>
                            <td><a class="child-name-link" href="<?= e('/children/' . $child['id']) ?>"><?= e($child['full_name']) ?></a></td><td><?= e($child['sex'] === 'M' ? 'Male' : 'Female') ?></td>
                            <td><time datetime="<?= e($child['date_of_birth']) ?>"><?= e($child['date_of_birth']) ?></time></td>
                            <td class="child-age"><?= e($child['age_months']) ?></td><td><?= e($child['purok_name']) ?></td>
                            <td><?= e($child['guardian_name']) ?></td><td><span class="status-badge <?= e($child['archived_at'] === null ? 'status-active' : 'status-archived') ?>"><?= e($child['archived_at'] === null ? 'Active' : 'Archived') ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <nav class="child-pagination" aria-label="Children pages">
            <?php if ($previousUrl !== null): ?><a href="<?= e($previousUrl) ?>" rel="prev">Previous</a><?php endif; ?>
            <span>Page <?= e($results['page']) ?> of <?= e($results['pages']) ?></span>
            <?php if ($nextUrl !== null): ?><a href="<?= e($nextUrl) ?>" rel="next">Next</a><?php endif; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>
<script src="/assets/js/children.js" defer></script>
