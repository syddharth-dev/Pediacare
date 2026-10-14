<?php

declare(strict_types=1);

?>
<p><strong class="status-badge <?= e($immunization['fic'] ? 'status-active' : 'status-archived') ?>"><?= e($immunization['fic'] ? 'FIC: Complete' : 'FIC: Not complete') ?></strong></p>
<p>FIC means all configured completion doses are recorded. Confirm the draft schedule with the health center before live use.</p>
<?php if ($immunization['vaccines'] === []): ?><p>No vaccine schedule configured. Ask an Admin to add vaccines and doses.</p><?php endif; ?>
<?php foreach ($immunization['vaccines'] as $vaccine): ?>
    <section class="profile-section immunization-section" aria-labelledby="vaccine-<?= e($vaccine['id']) ?>">
        <h3 id="vaccine-<?= e($vaccine['id']) ?>"><?= e($vaccine['name']) ?><?= e((int) $vaccine['active'] === 1 ? '' : ' (inactive)') ?></h3>
        <?php if ($vaccine['doses'] === []): ?><p>No doses configured.</p><?php else: ?>
            <div class="child-table-wrap" role="region" aria-label="<?= e($vaccine['name'] . ' dose status') ?>" tabindex="0">
                <table class="child-table">
                    <caption><?= e($vaccine['name']) ?> dose status</caption>
                    <thead><tr><th scope="col">Dose</th><th scope="col">Status</th><th scope="col">Earliest date</th><th scope="col">Vaccination record</th></tr></thead>
                    <tbody>
                    <?php foreach ($vaccine['doses'] as $dose): ?>
                        <tr>
                            <th scope="row">Dose <?= e($dose['dose_number']) ?></th>
                            <td><span class="status-badge" data-status="<?= e($dose['status']) ?>"><?= e($dose['status']) ?></span></td>
                            <td><?= e($dose['earliest_date'] ?? 'Previous dose not recorded') ?>
                                <?php if ($child['archived_at'] === null && $canImmunize && $dose['can_record']): ?>
                                    <p><a href="<?= e('/children/' . $child['id'] . '/immunizations/new?dose=' . $dose['id']) ?>">Record dose <?= e($dose['dose_number']) ?> of <?= e($vaccine['name']) ?></a></p>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($dose['record'] === null): ?>Not recorded<?php else: ?>
                                    <p class="vaccination-date"><?= e($dose['record']['date_given']) ?></p>
                                    <details class="vaccination-details"><summary>Vaccination details</summary><dl>
                                        <dt>Date given</dt><dd><?= e($dose['record']['date_given']) ?></dd>
                                        <dt>Place given</dt><dd><?= e($dose['record']['place_given']) ?></dd>
                                        <dt>Given elsewhere</dt><dd><?= e((int) $dose['record']['given_elsewhere'] === 1 ? 'Yes' : 'No') ?></dd>
                                        <dt>Lot or batch</dt><dd><?= e($dose['record']['lot_number'] ?? 'Not recorded') ?></dd>
                                        <dt>Site</dt><dd><?= e($dose['record']['site'] ?? 'Not recorded') ?></dd>
                                        <dt>Recorded by</dt><dd><?= e($dose['record']['recorded_by']) ?></dd>
                                    </dl></details>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
<?php endforeach; ?>
