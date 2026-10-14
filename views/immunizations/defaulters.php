<?php

declare(strict_types=1);

?>
<p>Children with at least one overdue dose in the configured schedule. Archived children are excluded.</p>
<?php if ($error !== null): ?><div id="child-error" class="notice notice-error" role="alert" tabindex="-1"><p><?= e($error) ?></p></div><?php endif; ?>
<section class="account-card report-filters" aria-labelledby="filters-heading">
    <h2 id="filters-heading">Filter overdue vaccines</h2>
    <form method="get" action="/immunizations/defaulters" class="auth-form">
        <div class="child-fields">
            <div><label for="purok_id">Purok or sitio</label><select id="purok_id" name="purok_id"><option value="">All puroks and sitios</option>
                <?php foreach ($puroks as $purok): ?><option value="<?= e($purok['id']) ?>"<?= (string) $purok['id'] === $results['filters']['purok_id'] ? ' selected' : '' ?>><?= e($purok['name']) ?></option><?php endforeach; ?>
            </select></div>
            <div><label for="vaccine_id">Vaccine</label><select id="vaccine_id" name="vaccine_id"><option value="">All vaccines</option>
                <?php foreach ($results['vaccines'] as $vaccine): ?><option value="<?= e($vaccine['id']) ?>"<?= (string) $vaccine['id'] === $results['filters']['vaccine_id'] ? ' selected' : '' ?>><?= e($vaccine['name']) ?></option><?php endforeach; ?>
            </select></div>
        </div>
        <button type="submit">Apply filters</button>
        <a href="/immunizations/defaulters">Clear filters</a>
    </form>
</section>
<?php if ($error === null): ?>
    <?php $view->partial('partials/report-output', ['outputLinks' => $outputLinks, 'isPrint' => $isPrint]); ?>
    <section class="account-card" aria-labelledby="results-heading">
        <h2 id="results-heading">Overdue vaccination records</h2>
        <p>Showing <?= e($results['from']) ?>–<?= e($results['to']) ?> of <?= e($results['total']) ?> children.</p>
        <?php if ($results['total'] === 0): ?><p>No children have overdue doses matching these filters.</p><?php else: ?>
            <div class="child-table-wrap" role="region" aria-label="Children with overdue vaccines" tabindex="0">
                <table class="child-table">
                    <caption>Children with overdue vaccines matching your filters</caption>
                    <thead><tr><th scope="col">Record number</th><th scope="col">Child</th><th scope="col">Purok or sitio</th><th scope="col">Overdue doses</th></tr></thead>
                    <tbody><?php foreach ($results['children'] as $child): ?><tr>
                        <th scope="row"><a href="<?= e('/children/' . $child['id'] . '?tab=immunization') ?>"><?= e($child['record_no']) ?></a></th>
                        <td><?= e($child['full_name']) ?></td><td><?= e($child['purok_name']) ?></td>
                        <td><ul><?php foreach ($child['overdue'] as $dose): ?><li><?= e($dose) ?></li><?php endforeach; ?></ul></td>
                    </tr><?php endforeach; ?></tbody>
                </table>
            </div>
            <nav class="child-pagination" aria-label="Defaulter list pages">
                <?php if ($previousUrl !== null): ?><a href="<?= e($previousUrl) ?>" rel="prev">Previous</a><?php endif; ?>
                <span>Page <?= e($results['page']) ?> of <?= e($results['pages']) ?></span>
                <?php if ($nextUrl !== null): ?><a href="<?= e($nextUrl) ?>" rel="next">Next</a><?php endif; ?>
            </nav>
        <?php endif; ?>
    </section>
<?php endif; ?>
<script src="/assets/js/children.js" defer></script>
