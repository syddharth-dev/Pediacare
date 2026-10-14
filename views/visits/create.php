<?php

declare(strict_types=1);

?>
<p><a href="<?= e('/children/' . $child['id'] . '?tab=visits') ?>">Back to child visits</a></p>
<section class="account-card clinical-panel visit-panel" aria-labelledby="record-heading">
    <h2 id="record-heading">Record visit for <?= e($child['full_name']) ?></h2>
    <p>Record number: <?= e($child['record_no']) ?>. Required fields are marked “required”.</p>
    <?php if ($error !== null): ?><div id="child-error" class="notice notice-error" role="alert" tabindex="-1"><p><?= e($error) ?></p></div><?php endif; ?>
    <form method="post" action="<?= e('/children/' . $child['id'] . '/visits') ?>" class="auth-form child-form">
        <?= csrf_field($csrfToken) ?>
        <fieldset><legend>Visit details</legend><div class="child-fields">
            <div><label for="visit_date">Visit date (required)</label><input id="visit_date" name="visit_date" type="date" min="<?= e($child['date_of_birth']) ?>" max="<?= e($today) ?>" value="<?= e($values['visit_date']) ?>" autocomplete="off" required></div>
            <div><label for="reason">Reason or chief complaint (required)</label><input id="reason" name="reason" maxlength="255" value="<?= e($values['reason']) ?>" autocomplete="off" required></div>
            <div><label for="findings">Findings (optional)</label><textarea id="findings" name="findings" rows="4" maxlength="65535" autocomplete="off"><?= e($values['findings']) ?></textarea></div>
            <div><label for="actions">Actions or treatment (optional)</label><textarea id="actions" name="actions" rows="4" maxlength="65535" autocomplete="off"><?= e($values['actions']) ?></textarea></div>
        </div></fieldset>
        <fieldset><legend>Referral</legend><div class="child-fields">
            <div><label for="referred">Referred (required)</label><select id="referred" name="referred" required><option value="0"<?= $values['referred'] === '0' ? ' selected' : '' ?>>No</option><option value="1"<?= $values['referred'] === '1' ? ' selected' : '' ?>>Yes</option></select></div>
            <div><label for="referred_to">Referral destination (required if referred)</label><input id="referred_to" name="referred_to" maxlength="255" value="<?= e($values['referred_to']) ?>" autocomplete="off" aria-describedby="referral-help"><p id="referral-help">Enter where the child was referred. Leave blank when Referred is No.</p></div>
        </div></fieldset>
        <div class="supplement-section"><h3>Supplements and preventive services (optional)</h3>
        <p id="supplement-help">For each service given, enter both the dose, including its unit, and the date given. Leave both fields blank for services not given.</p>
        <div class="supplement-grid"><?php foreach ($supplementTypes as $type => $label): ?>
            <fieldset aria-describedby="supplement-help">
                <legend><?= e($label) ?></legend>
                <div class="child-fields">
                    <div><label for="<?= e($type . '-dose') ?>">Dose (optional)</label><input id="<?= e($type . '-dose') ?>" name="<?= e('supplements[' . $type . '][dose]') ?>" maxlength="100" value="<?= e($values['supplements'][$type]['dose']) ?>" autocomplete="off"></div>
                    <div><label for="<?= e($type . '-date') ?>">Date given (optional)</label><input id="<?= e($type . '-date') ?>" name="<?= e('supplements[' . $type . '][date_given]') ?>" type="date" min="<?= e($child['date_of_birth']) ?>" max="<?= e($today) ?>" value="<?= e($values['supplements'][$type]['date_given']) ?>" autocomplete="off"></div>
                </div>
            </fieldset>
        <?php endforeach; ?></div></div>
        <div><label for="notes">Notes (optional)</label><textarea id="notes" name="notes" rows="4" maxlength="65535" autocomplete="off"><?= e($values['notes']) ?></textarea></div>
        <p>Attended by <?= e($userName ?? 'Signed-in staff') ?>.</p>
        <div class="form-actions"><a href="<?= e('/children/' . $child['id'] . '?tab=visits') ?>">Cancel</a><button type="submit">Record visit</button></div>
    </form>
</section>
<script src="/assets/js/children.js" defer></script>
