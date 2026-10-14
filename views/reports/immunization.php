<?php

declare(strict_types=1);

?>
<p>Doses are counted by date given, including doses given elsewhere and historical records for inactive vaccines.</p>
<p>FIC counts children whose last required dose was given during this month, using the current configured schedule. Schedule edits can change historical counts. This is configured completion, not the DOH age-limited reporting definition.</p>
<?php if ($error !== null): ?><div id="child-error" class="notice notice-error" role="alert" tabindex="-1"><p><?= e($error) ?></p></div><?php endif; ?>
<section class="account-card report-filters" aria-labelledby="month-heading">
    <h2 id="month-heading">Reporting month</h2>
    <form method="get" action="/reports/immunization" class="auth-form">
        <label for="month">Month (required)</label>
        <input type="month" id="month" name="month" min="1000-01" value="<?= e($values['month']) ?>" autocomplete="off" required>
        <label class="archive-filter" for="include_archived"><input type="checkbox" id="include_archived" name="include_archived" value="1"<?= $values['include_archived'] === '1' ? ' checked' : '' ?>> Include archived children</label>
        <button type="submit">Show summary</button>
    </form>
</section>
<?php if ($results !== null): ?>
    <?php $view->partial('partials/report-output', ['outputLinks' => $outputLinks, 'isPrint' => $isPrint]); ?>
    <p>Period: <?= e($results['filters']['from']) ?> to <?= e($results['filters']['to']) ?>, including both dates. <?= e($results['filters']['include_archived'] === '1' ? 'Archived children included.' : 'Archived children excluded.') ?></p>
    <section class="account-card nutrition-group" aria-labelledby="totals-heading">
        <h2 id="totals-heading">Barangay total</h2>
        <?php if ($results['totals']['total'] === 0): ?><p>No vaccine doses are recorded in this month. Choose another month or ask staff to record vaccinations.</p><?php endif; ?>
        <div class="report-metrics" aria-label="Monthly immunization totals">
            <div class="report-metric"><span class="metric-label">Doses recorded</span><strong><?= e($results['totals']['total']) ?></strong> <span class="metric-detail">During the selected month</span></div>
            <div class="report-metric"><span class="metric-label">Children completing FIC</span><strong><?= e($results['fic']['total']) ?></strong> <span class="metric-detail">All configured completion doses</span></div>
            <div class="report-metric"><span class="metric-label">Male completing FIC</span><strong><?= e($results['fic']['M']) ?></strong> <span class="metric-detail">During the selected month</span></div>
            <div class="report-metric"><span class="metric-label">Female completing FIC</span><strong><?= e($results['fic']['F']) ?></strong> <span class="metric-detail">During the selected month</span></div>
        </div>
        <div class="child-table-wrap" role="region" aria-label="Doses given per vaccine" tabindex="0">
            <table class="child-table">
                <caption>Doses given per vaccine</caption>
                <thead><tr><th scope="col">Vaccine</th><th scope="col">Male</th><th scope="col">Female</th><th scope="col">Total</th></tr></thead>
                <tbody><?php foreach ($results['vaccines'] as $vaccine): ?><tr><th scope="row"><?= e($vaccine['name']) ?></th><td><?= e($vaccine['counts']['M']) ?></td><td><?= e($vaccine['counts']['F']) ?></td><td><?= e($vaccine['counts']['total']) ?></td></tr><?php endforeach; ?></tbody>
                <tfoot><tr><th scope="row">Total doses</th><td><?= e($results['totals']['M']) ?></td><td><?= e($results['totals']['F']) ?></td><td><?= e($results['totals']['total']) ?></td></tr></tfoot>
            </table>
        </div>
    </section>
<?php endif; ?>
<script src="/assets/js/children.js" defer></script>
