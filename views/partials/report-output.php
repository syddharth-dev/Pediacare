<?php

declare(strict_types=1);

?>
<div class="report-actions">
    <a href="<?= e($outputLinks['csvUrl']) ?>">Download CSV (all results)</a>
    <?php if ($isPrint): ?><button type="button" data-print-report>Print report</button><?php else: ?><a href="<?= e($outputLinks['printUrl']) ?>">Print view (all results)</a><?php endif; ?>
</div>
<?php if ($isPrint): ?><p class="print-help">All matching results are shown. Use Print report or your browser’s print command.</p><?php endif; ?>
<script src="/assets/js/reports.js" defer></script>
