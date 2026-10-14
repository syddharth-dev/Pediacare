<?php

declare(strict_types=1);

?>
<p>Each child is counted once using their latest measurement within the chosen dates, if aged 0–59 completed calendar months on that measurement date. Saved classifications are counted separately for each indicator.</p>
<p>Children without a measurement in the period are excluded. Sex, purok and date of birth use the current child record. Missing classifications appear as Not available.</p>
<?php if ($error !== null): ?><div id="child-error" class="notice notice-error" role="alert" tabindex="-1"><p><?= e($error) ?></p></div><?php endif; ?>
<section class="account-card report-filters" aria-labelledby="period-heading">
    <h2 id="period-heading">Reporting period</h2>
    <form method="get" action="/reports/nutrition" class="auth-form">
        <div class="child-fields">
            <div><label for="from">Start date (required)</label><input type="date" id="from" name="from" min="1000-01-01" value="<?= e($values['from']) ?>" autocomplete="off" required></div>
            <div><label for="to">End date (required)</label><input type="date" id="to" name="to" min="1000-01-01" value="<?= e($values['to']) ?>" autocomplete="off" required></div>
        </div>
        <label class="archive-filter" for="include_archived"><input type="checkbox" id="include_archived" name="include_archived" value="1"<?= $values['include_archived'] === '1' ? ' checked' : '' ?>> Include archived children</label>
        <button type="submit">Show summary</button>
    </form>
</section>
<?php if ($results !== null): ?>
    <?php $view->partial('partials/report-output', ['outputLinks' => $outputLinks, 'isPrint' => $isPrint]); ?>
    <p>Period: <?= e($results['filters']['from']) ?> to <?= e($results['filters']['to']) ?>, including both dates. <?= e($results['filters']['include_archived'] === '1' ? 'Archived children included.' : 'Archived children excluded.') ?></p>
    <?php if (!$results['byPurok']): ?><p>Viewer access shows barangay-wide totals by sex.</p><?php endif; ?>
    <?php if ($results['groups'][0]['totals']['total'] === 0): ?><p>No children have an eligible measurement in this period. Choose another period or ask staff to record measurements.</p><?php endif; ?>
    <?php foreach ($results['groups'] as $group): ?>
        <section class="account-card nutrition-group">
            <h2><?= e($group['name']) ?></h2>
            <div class="report-metrics" aria-label="<?= e($group['name'] . ' child counts') ?>">
                <div class="report-metric"><span class="metric-label">Children counted</span><strong><?= e($group['totals']['total']) ?></strong> <span class="metric-detail">With an eligible measurement</span></div>
                <div class="report-metric"><span class="metric-label">Male</span><strong><?= e($group['totals']['M']) ?></strong> <span class="metric-detail">During the selected period</span></div>
                <div class="report-metric"><span class="metric-label">Female</span><strong><?= e($group['totals']['F']) ?></strong> <span class="metric-detail">During the selected period</span></div>
            </div>
            <?php foreach ($indicators as $key => $indicator): ?>
                <div class="child-table-wrap" role="region" aria-label="<?= e($group['name'] . ': ' . $indicator['label']) ?>" tabindex="0">
                    <table class="child-table">
                        <caption><?= e($indicator['label']) ?></caption>
                        <thead><tr><th scope="col">Status</th><th scope="col">Male</th><th scope="col">Female</th><th scope="col">Total</th></tr></thead>
                        <tbody><?php foreach ($group['indicators'][$key] as $status => $counts): ?><tr><th scope="row"><?= e($status) ?></th><td><?= e($counts['M']) ?></td><td><?= e($counts['F']) ?></td><td><?= e($counts['total']) ?></td></tr><?php endforeach; ?></tbody>
                        <tfoot><tr><th scope="row">Total</th><td><?= e($group['totals']['M']) ?></td><td><?= e($group['totals']['F']) ?></td><td><?= e($group['totals']['total']) ?></td></tr></tfoot>
                    </table>
                </div>
            <?php endforeach; ?>
        </section>
    <?php endforeach; ?>
<?php endif; ?>
<script src="/assets/js/children.js" defer></script>
