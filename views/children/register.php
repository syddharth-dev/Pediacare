<?php

declare(strict_types=1);

?>
<section class="account-card registration-panel">
    <p><?= e($intro) ?></p>
    <?php if ($puroks === []): ?><p class="notice notice-warning">Ask an Admin to add a purok or sitio before registering a child.</p><?php endif; ?>
    <?php if ($error !== null): ?><div id="child-error" class="notice notice-error" role="alert" tabindex="-1"><p><?= e($error) ?></p></div><?php endif; ?>
    <nav class="form-section-nav" aria-label="Registration sections">
        <?php foreach ($sections as $heading => $names): ?><a href="<?= e('#registration-' . bin2hex($heading)) ?>"><?= e($heading) ?></a><?php endforeach; ?>
        <a href="#birth-measurements">Birth measurements</a>
    </nav>
    <form method="post" action="<?= e($formAction) ?>" class="auth-form child-form">
        <?= csrf_field($csrfToken) ?>
        <?php foreach ($sections as $heading => $names): ?>
            <fieldset id="<?= e('registration-' . bin2hex($heading)) ?>"><legend><?= e($heading) ?></legend><div class="child-fields">
                <?php foreach ($names as $field): ?>
                    <div class="<?= e(in_array($field, ['address', 'notes'], true) ? 'field-wide' : 'field-standard') ?>">
                        <label for="<?= e($field) ?>"><?= e($fields[$field][0]) ?> (<?= e($fields[$field][2] ? 'required' : 'optional') ?>)</label>
                        <?php if (in_array($field, ['address', 'notes'], true)): ?>
                            <textarea id="<?= e($field) ?>" name="<?= e($field) ?>" autocomplete="off" rows="3" maxlength="<?= e($fields[$field][1]) ?>"<?= $fields[$field][2] ? ' required' : '' ?>><?= e($values[$field]) ?></textarea>
                        <?php else: ?>
                            <input id="<?= e($field) ?>" name="<?= e($field) ?>" type="<?= e($field === 'contact_no' ? 'tel' : 'text') ?>" autocomplete="off" maxlength="<?= e($fields[$field][1]) ?>" value="<?= e($values[$field]) ?>"<?= $fields[$field][2] ? ' required' : '' ?>>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <?php if ($heading === 'Child details'): ?>
                    <div><label for="sex">Sex (required)</label><select id="sex" name="sex" autocomplete="off" required>
                        <option value="">Select sex</option><option value="M"<?= $values['sex'] === 'M' ? ' selected' : '' ?>>Male</option><option value="F"<?= $values['sex'] === 'F' ? ' selected' : '' ?>>Female</option>
                    </select></div>
                    <div><label for="date_of_birth">Date of birth (required)</label><input type="date" id="date_of_birth" name="date_of_birth" autocomplete="off" max="<?= e($today) ?>" value="<?= e($values['date_of_birth']) ?>" required></div>
                <?php endif; ?>
                <?php if ($heading === 'Home and family'): ?>
                    <div><label for="purok_id">Purok or sitio (required)</label><select id="purok_id" name="purok_id" autocomplete="off" required>
                        <option value="">Select a purok or sitio</option>
                        <?php foreach ($puroks as $purok): ?><option value="<?= e($purok['id']) ?>"<?= $values['purok_id'] === (string) $purok['id'] ? ' selected' : '' ?>><?= e($purok['name']) ?></option><?php endforeach; ?>
                    </select></div>
                <?php endif; ?>
            </div></fieldset>
        <?php endforeach; ?>
        <fieldset id="birth-measurements"><legend>Birth measurements (optional)</legend><div class="child-fields">
            <div><label for="birth_weight_kg">Birth weight (kg)</label><input type="number" id="birth_weight_kg" name="birth_weight_kg" inputmode="decimal" autocomplete="off" step="0.01" min="0.01" max="99.99" value="<?= e($values['birth_weight_kg']) ?>"></div>
            <div><label for="birth_length_cm">Birth length (cm)</label><input type="number" id="birth_length_cm" name="birth_length_cm" inputmode="decimal" autocomplete="off" step="0.1" min="0.1" max="999.9" value="<?= e($values['birth_length_cm']) ?>"></div>
        </div></fieldset>
        <?php if ($duplicates !== []): ?>
            <div id="duplicate-warning" class="notice notice-warning" role="alert" tabindex="-1">
                <p>Possible duplicate: this full name and date of birth already exist. Check the existing record numbers before saving.</p>
                <ul><?php foreach ($duplicates as $duplicate): ?><li><a href="<?= e('/children/' . $duplicate['id']) ?>"><?= e($duplicate['record_no']) ?></a><?= e($duplicate['archived_at'] !== null ? ' (archived)' : '') ?></li><?php endforeach; ?></ul>
                <input type="hidden" name="duplicate_token" value="<?= e($duplicateToken) ?>">
                <label class="duplicate-check"><input type="checkbox" name="confirm_duplicate" value="1" required> I checked these records and need to register a different child.</label>
            </div>
        <?php endif; ?>
        <div class="form-actions">
            <?php if ($cancelUrl !== null): ?><a href="<?= e($cancelUrl) ?>">Cancel changes</a><?php endif; ?>
            <button type="submit"<?= $puroks === [] ? ' disabled' : '' ?>><?= e($submitLabel) ?></button>
        </div>
    </form>
</section>
<script src="/assets/js/children.js" defer></script>
