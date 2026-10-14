<?php

declare(strict_types=1);

?>
<h3 id="timeline-heading">Health timeline</h3>
<p>Measurements, vaccinations and visits, newest first. Events on the same date are grouped by type; their order does not indicate the time of day.</p>
<?php if ($timeline['total'] === 0): ?>
    <p>No health events recorded yet. Add a measurement, record a vaccine, or record a visit using the profile tabs.</p>
<?php else: ?>
    <p>Showing <?= e($timeline['from']) ?>–<?= e($timeline['to']) ?> of <?= e($timeline['total']) ?> recorded events.</p>
    <ol class="health-timeline" aria-labelledby="timeline-heading">
        <?php foreach ($timeline['events'] as $event): ?>
            <li>
                <h4><time datetime="<?= e($event['date']) ?>"><?= e($event['date']) ?></time> — <?= e($event['type']) ?></h4>
                <p class="timeline-title"><?= e($event['title']) ?></p>
                <dl class="profile-details">
                    <?php foreach ($event['details'] as $label => $value): ?>
                        <div><dt><?= e($label) ?></dt><dd><?= e($value) ?></dd></div>
                    <?php endforeach; ?>
                </dl>
                <?php if ($event['supplements'] !== []): ?>
                    <h5>Supplements and preventive services</h5>
                    <ul>
                        <?php foreach ($event['supplements'] as $supplement): ?>
                            <li><?= e($supplement['name']) ?>: <?= e($supplement['dose']) ?>, given <time datetime="<?= e($supplement['date']) ?>"><?= e($supplement['date']) ?></time></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ol>
    <nav class="child-pagination" aria-label="Health timeline pages">
        <?php if ($previousTimelineUrl !== null): ?><a href="<?= e($previousTimelineUrl) ?>" rel="prev">Previous</a><?php endif; ?>
        <span>Page <?= e($timeline['page']) ?> of <?= e($timeline['pages']) ?></span>
        <?php if ($nextTimelineUrl !== null): ?><a href="<?= e($nextTimelineUrl) ?>" rel="next">Next</a><?php endif; ?>
    </nav>
<?php endif; ?>
