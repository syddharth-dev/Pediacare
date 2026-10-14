<?php

declare(strict_types=1);

?>
<p>Children whose latest measurement has at least one saved classification outside Normal. Children of all ages are included. Same-date measurements use the most recently saved record.</p>
<p>This includes Tall and High weight-for-age as results outside Normal; these labels alone are not a malnutrition diagnosis. Missing classifications are Not available and do not qualify a child for this list.</p>
<?php if ($error !== null): ?><div id="child-error" class="notice notice-error" role="alert" tabindex="-1"><p><?= e($error) ?></p></div><?php endif; ?>
<section class="account-card report-filters" aria-labelledby="filters-heading">
    <h2 id="filters-heading">Archive filter</h2>
    <form method="get" action="/reports/malnutrition" class="auth-form">
        <label class="archive-filter" for="include_archived"><input type="checkbox" id="include_archived" name="include_archived" value="1"<?= $includeArchived ? ' checked' : '' ?>> Include archived children</label>
        <button type="submit">Show list</button>
    </form>
</section>
<?php if ($results !== null): ?>
    <?php $view->partial('partials/report-output', ['outputLinks' => $outputLinks, 'isPrint' => $isPrint]); ?>
    <section class="account-card" aria-labelledby="results-heading">
        <h2 id="results-heading">Latest measurements outside Normal</h2>
        <p>Showing <?= e($results['from']) ?>–<?= e($results['to']) ?> of <?= e($results['total']) ?> children. <?= e($results['filters']['include_archived'] === '1' ? 'Archived children included.' : 'Archived children excluded.') ?></p>
        <?php if ($results['total'] === 0): ?><p>No children have a latest measurement outside Normal matching this filter. Children without measurements are excluded.</p><?php else: ?>
            <div class="child-table-wrap" role="region" aria-label="Children with latest measurements outside Normal" tabindex="0">
                <table class="child-table">
                    <caption>Latest saved classifications by child</caption>
                    <thead><tr><th scope="col">Record number</th><th scope="col">Child</th><th scope="col">Sex</th><th scope="col">Purok or sitio</th><th scope="col">Measured on</th><th scope="col">Classifications</th></tr></thead>
                    <tbody><?php foreach ($results['children'] as $child): ?><tr>
                        <th scope="row"><a href="<?= e('/children/' . $child['id'] . '?tab=growth') ?>"><?= e($child['record_no']) ?></a><?php if ($child['archived_at'] !== null): ?> <span>Archived</span><?php endif; ?></th>
                        <td><?= e($child['full_name']) ?></td><td><?= e($child['sex'] === 'M' ? 'Male' : 'Female') ?></td><td><?= e($child['purok_name']) ?></td><td><time datetime="<?= e($child['measured_at']) ?>"><?= e($child['measured_at']) ?></time></td>
                        <td><ul><?php foreach ($indicators as $key => $indicator): ?><li><?= e($indicator['label']) ?>: <?= e($child[$key . '_status'] ?? 'Not available') ?></li><?php endforeach; ?></ul></td>
                    </tr><?php endforeach; ?></tbody>
                </table>
            </div>
            <nav class="child-pagination" aria-label="Malnutrition list pages">
                <?php if ($previousUrl !== null): ?><a href="<?= e($previousUrl) ?>" rel="prev">Previous</a><?php endif; ?>
                <span>Page <?= e($results['page']) ?> of <?= e($results['pages']) ?></span>
                <?php if ($nextUrl !== null): ?><a href="<?= e($nextUrl) ?>" rel="next">Next</a><?php endif; ?>
            </nav>
        <?php endif; ?>
    </section>
<?php endif; ?>
<script src="/assets/js/children.js" defer></script>
