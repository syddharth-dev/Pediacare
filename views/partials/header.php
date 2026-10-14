<?php

declare(strict_types=1);

?>
<header class="page-header">
    <div>
        <p class="center-label">Barangay health center</p>
        <h1><?= e($title) ?></h1>
    </div>
    <?php if ($userName !== null): ?>
        <div class="staff-account"><span class="staff-avatar" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/></svg></span><div><p class="staff-name"><?= e($userName) ?></p><a href="/login">Account and sign out</a></div></div>
    <?php endif; ?>
</header>
