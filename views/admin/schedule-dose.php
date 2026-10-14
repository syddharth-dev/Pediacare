<?php

declare(strict_types=1);

?>
<p><a href="/admin/schedule">Back to vaccine schedule</a></p>
<?php if ($error !== null): ?><div class="notice notice-error" id="schedule-error" role="alert" tabindex="-1"><p><?= e($error) ?></p></div><?php endif; ?>
<section class="account-card clinical-panel" aria-labelledby="dose-heading">
    <h2 id="dose-heading">Edit <?= e($dose['vaccine_name']) ?> dose <?= e($dose['dose_number']) ?></h2>
    <p>A dose with recorded vaccinations keeps its dose number. Add a new dose to represent a different dose.</p>
    <form method="post" action="/admin/schedule/doses/<?= e($dose['id']) ?>" class="auth-form child-form">
        <?= csrf_field($csrfToken) ?>
        <?php $view->partial('admin/schedule-dose-fields', ['values' => $doseValues]); ?>
        <button type="submit">Save dose</button>
    </form>
</section>
<script src="/assets/js/schedule.js" defer></script>
