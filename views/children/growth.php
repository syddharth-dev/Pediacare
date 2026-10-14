<?php

declare(strict_types=1);

?>
<?php if ($growth['history'] === []): ?>
    <p>No measurements recorded yet.</p>
<?php else: ?>
    <h3 id="growth-chart-heading">BMI-for-age chart</h3>
    <p id="growth-chart-description">WHO reference curves for <?= e($child['sex'] === 'M' ? 'boys' : 'girls') ?>: median, −3, −2, +1, +2 and +3 standard deviations. Saved BMI values appear as dots. Use the status labels in the history table to assess each measurement.</p>
    <p>Chart ages and reference curves use the current date of birth and sex. Saved ages, scores and status labels remain as recorded. Breaks in reference curves mark the change from length to height and from WHO 2006 to WHO 2007.</p>
    <?php if ($growth['omitted'] > 0): ?>
        <p class="notice notice-warning"><?= e($growth['omitted']) ?> measurement(s) fall outside the WHO chart age range using the current date of birth. They remain in the history below.</p>
    <?php endif; ?>
    <?php if ($growth['chartJson'] !== null): ?>
        <div id="growth-chart-data" data-chart="<?= e($growth['chartJson']) ?>" hidden></div>
        <div class="growth-chart-wrap" id="growth-chart-wrap" hidden>
            <canvas id="growth-chart" role="img" aria-labelledby="growth-chart-heading" aria-describedby="growth-chart-description">Saved BMI measurements are available in the measurement history table below.</canvas>
        </div>
        <p id="growth-chart-fallback">The chart needs JavaScript. All saved measurements are available in the history below.</p>
        <script src="/assets/vendor/chart.umd.min.js" defer></script>
        <script src="/assets/js/growth.js" defer></script>
    <?php endif; ?>
    <div class="child-table-wrap growth-history" role="region" aria-label="Measurement history" tabindex="0">
        <table class="child-table">
            <caption>Measurement history — newest first. Z-scores are standard deviations from the WHO median.</caption>
            <thead><tr>
                <th scope="col">Date and recorded age</th><th scope="col">Measurements</th>
                <?php foreach ($growthIndicators as $label): ?><th scope="col"><?= e($label) ?></th><?php endforeach; ?>
                <th scope="col">Measured by</th>
            </tr></thead>
            <tbody>
            <?php foreach ($growth['history'] as $measurement): ?>
                <tr>
                    <th scope="row"><time datetime="<?= e($measurement['measured_at']) ?>"><?= e($measurement['measured_at']) ?></time><br><?= e($measurement['age_months']) ?> completed months</th>
                    <td>Weight: <?= e($measurement['weight_kg']) ?> kg<br>Length/height: <?= e($measurement['height_cm']) ?> cm (<?= e($measurement['position'] === 'LYING' ? 'lying' : 'standing') ?>)<br>MUAC: <?= e($measurement['muac_cm'] === null ? 'Not recorded' : $measurement['muac_cm'] . ' cm') ?><br>BMI: <?= e($measurement['bmi']) ?> kg/m²
                        <?php if ($measurement['implausible_confirmed']): ?><br><strong>Implausible values confirmed</strong><?php endif; ?>
                    </td>
                    <?php foreach ($growthIndicators as $indicator => $label): ?>
                        <td><?= e($measurement[$indicator . '_status'] ?? 'Not available') ?><br>Z-score: <?= e($measurement[$indicator . '_z'] ?? 'Not available') ?></td>
                    <?php endforeach; ?>
                    <td><?= e($measurement['measured_by_name']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
