<?php

declare(strict_types=1);

?>
<a class="profile-back" href="<?= e('/children/' . $child['id'] . '?tab=growth') ?>">Back to child profile</a>
<section class="account-card registration-panel">
    <h2><?= e($child['full_name']) ?></h2>
    <p>Record number: <?= e($child['record_no']) ?>. Required fields are marked “required”.</p>
    <?php if ($error !== null): ?>
        <div id="measurement-error" class="notice notice-error" role="alert" tabindex="-1"><p><?= e($error) ?></p></div>
    <?php endif; ?>
    <form method="post" action="<?= e('/children/' . $child['id'] . '/measurements') ?>" class="auth-form child-form" id="measurement-form">
        <?= csrf_field($csrfToken) ?>
        <fieldset>
            <legend>Measurement details</legend>
            <div class="child-fields">
                <div>
                    <label for="measured-at">Measurement date (required)</label>
                    <input type="date" id="measured-at" name="measured_at" autocomplete="off" min="<?= e($child['date_of_birth']) ?>" max="<?= e($today) ?>" value="<?= e($values['measured_at']) ?>" required>
                </div>
                <?php foreach ($fields as $field => [$label, $scale, $maximum, $required]): ?>
                    <div>
                        <label for="<?= e($field) ?>"><?= e($label . ($required ? ' (required)' : ' (optional)')) ?></label>
                        <input type="number" inputmode="decimal" autocomplete="off" id="<?= e($field) ?>" name="<?= e($field) ?>" min="<?= e($scale === 2 ? '0.01' : '0.1') ?>" max="<?= e($maximum) ?>" step="<?= e($scale === 2 ? '0.01' : '0.1') ?>" value="<?= e($values[$field]) ?>"<?= $required ? ' required' : '' ?>>
                    </div>
                <?php endforeach; ?>
                <div>
                    <label for="position">Measured lying or standing (required)</label>
                    <select id="position" name="position" autocomplete="off" required>
                        <option value="">Select position</option>
                        <option value="LYING"<?= $values['position'] === 'LYING' ? ' selected' : '' ?>>Lying</option>
                        <option value="STANDING"<?= $values['position'] === 'STANDING' ? ' selected' : '' ?>>Standing</option>
                    </select>
                </div>
            </div>
            <p>Enter the length or height as measured. Position adjustments are calculated automatically.</p>
        </fieldset>
        <?php if ($result !== null): ?>
            <section class="notice notice-warning" id="measurement-warning" tabindex="-1" aria-labelledby="measurement-warning-title">
                <h3 id="measurement-warning-title">Check these measurement values</h3>
                <p>These results are outside the expected range. Check the date, weight, length or height, and position. Correct any entry errors before saving.</p>
                <ul>
                    <?php foreach ($result->flags as $indicator): ?>
                        <li><?= e($indicatorLabels[$indicator]) ?> z-score: <?= e($result->data[$indicator]) ?></li>
                    <?php endforeach; ?>
                </ul>
                <input type="hidden" name="confirmation_token" value="<?= e($result->confirmationToken) ?>">
                <label class="duplicate-check" for="confirm-implausible">
                    <input type="checkbox" id="confirm-implausible" name="confirm_implausible" value="1">
                    I checked the measurement values and confirm they are correct.
                </label>
                <p>You can correct the values and save again, or confirm the values to save this measurement.</p>
            </section>
        <?php endif; ?>
        <div class="form-actions"><a href="<?= e('/children/' . $child['id'] . '?tab=growth') ?>">Cancel</a><button type="submit">Save measurement</button></div>
    </form>
</section>
<script src="/assets/js/measurements.js" defer></script>
