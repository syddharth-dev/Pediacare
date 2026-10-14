<?php

declare(strict_types=1);

?>
<div class="child-fields">
    <div><label for="dose_number">Dose number</label><input id="dose_number" name="dose_number" type="number" min="1" max="4294967295" step="1" value="<?= e($values['dose_number']) ?>" required></div>
    <div><label for="sort_order">Display order</label><input id="sort_order" name="sort_order" type="number" min="0" max="4294967295" step="1" value="<?= e($values['sort_order']) ?>" required></div>
    <div><label for="age_value">Recommended age</label><input id="age_value" name="age_value" type="number" min="0" max="4294967295" step="1" value="<?= e($values['age_value']) ?>" required></div>
    <div><label for="age_unit">Age unit</label><select id="age_unit" name="age_unit" required><option value="days" <?= $values['age_unit'] === 'days' ? 'selected' : '' ?>>Days</option><option value="months" <?= $values['age_unit'] === 'months' ? 'selected' : '' ?>>Calendar months</option></select></div>
    <div><label for="interval_value">Minimum interval from previous dose</label><input id="interval_value" name="interval_value" type="number" min="0" max="4294967295" step="1" value="<?= e($values['interval_value']) ?>" required aria-describedby="interval-help"></div>
    <div><label for="interval_unit">Interval unit</label><select id="interval_unit" name="interval_unit" required><option value="days" <?= $values['interval_unit'] === 'days' ? 'selected' : '' ?>>Days</option><option value="months" <?= $values['interval_unit'] === 'months' ? 'selected' : '' ?>>Calendar months</option></select></div>
    <div><label for="grace_days">Grace period in days</label><input id="grace_days" name="grace_days" type="number" min="0" max="4294967295" step="1" value="<?= e($values['grace_days']) ?>" required></div>
    <div><label for="counts_toward_fic">Counts toward FIC</label><select id="counts_toward_fic" name="counts_toward_fic" required><option value="0" <?= (string) $values['counts_toward_fic'] === '0' ? 'selected' : '' ?>>No</option><option value="1" <?= (string) $values['counts_toward_fic'] === '1' ? 'selected' : '' ?>>Yes</option></select></div>
</div>
<p id="interval-help">Use a zero-day interval for a first dose. Calendar months retain their calendar meaning.</p>
<p>FIC (Fully Immunized Child) marks completion of configured doses. It does not certify the age-limited DOH reporting indicator.</p>
