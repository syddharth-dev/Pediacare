<?php

declare(strict_types=1);

?>
<p><a href="<?= e('/children/' . $child['id'] . '?tab=immunization') ?>">Back to immunization record</a></p>
<section class="account-card clinical-panel" aria-labelledby="record-heading">
    <h2 id="record-heading">Record vaccine for <?= e($child['full_name']) ?></h2>
    <p>Record number: <?= e($child['record_no']) ?>. Required fields are marked “required”.</p>
    <?php if ($error !== null): ?><div id="immunization-error" class="notice notice-error" role="alert" tabindex="-1"><p><?= e($error) ?></p></div><?php endif; ?>
    <p class="clinical-guidance">Check the vaccination date against the configured age and interval. To record a later dose, first record its previous dose, including doses given elsewhere.</p>
    <?php if ($immunization['available'] === 0): ?><p>No active unrecorded doses are available. Check the existing records or ask an Admin to review the schedule.</p><?php else: ?>
    <form method="post" action="<?= e('/children/' . $child['id'] . '/immunizations') ?>" class="auth-form child-form" id="immunization-form">
        <?= csrf_field($csrfToken) ?>
        <fieldset><legend>Vaccine and date</legend><div class="child-fields">
            <div><label for="schedule_dose_id">Vaccine and dose (required)</label><select id="schedule_dose_id" name="schedule_dose_id" required><option value="">Choose a dose</option>
                <?php foreach ($immunization['vaccines'] as $vaccine): ?>
                    <?php foreach ($vaccine['doses'] as $dose): ?>
                        <?php if ($dose['can_record']): ?><option value="<?= e($dose['id']) ?>"<?= (string) $dose['id'] === $values['schedule_dose_id'] ? ' selected' : '' ?>><?= e($vaccine['name'] . ' — dose ' . $dose['dose_number']) ?></option><?php endif; ?>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </select></div>
            <div><label for="date_given">Date given (required)</label><input id="date_given" name="date_given" type="date" min="<?= e($child['date_of_birth']) ?>" max="<?= e($today) ?>" value="<?= e($values['date_given']) ?>" autocomplete="off" required></div>
        </div></fieldset>
        <fieldset><legend>Where it was given</legend><div class="child-fields">
            <div><label for="given_elsewhere">Given elsewhere (required)</label><select id="given_elsewhere" name="given_elsewhere" required><option value="0"<?= $values['given_elsewhere'] === '0' ? ' selected' : '' ?>>No, at this center</option><option value="1"<?= $values['given_elsewhere'] === '1' ? ' selected' : '' ?>>Yes, at another facility</option></select></div>
            <div><label for="place_given">Place given (required)</label><input id="place_given" name="place_given" maxlength="255" value="<?= e($values['place_given']) ?>" autocomplete="off" aria-describedby="place-help" required><p id="place-help">Enter this center’s name or the other facility’s name.</p></div>
        </div></fieldset>
        <fieldset><legend>Additional details</legend><div class="child-fields">
            <div><label for="lot_number">Lot or batch number (optional)</label><input id="lot_number" name="lot_number" maxlength="100" value="<?= e($values['lot_number']) ?>" autocomplete="off" spellcheck="false"></div>
            <div><label for="site">Site (optional)</label><input id="site" name="site" maxlength="150" value="<?= e($values['site']) ?>" autocomplete="off"></div>
        </div></fieldset>
        <p>Recorded by <?= e($userName ?? 'Signed-in staff') ?>. Date-only records cannot establish whether a birth dose was given within 24 hours.</p>
        <div class="form-actions"><a href="<?= e('/children/' . $child['id'] . '?tab=immunization') ?>">Cancel</a><button type="submit">Record vaccine</button></div>
    </form>
    <?php endif; ?>
</section>
<script src="/assets/js/immunizations.js" defer></script>
