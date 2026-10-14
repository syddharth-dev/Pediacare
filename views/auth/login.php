<?php

declare(strict_types=1);

$shortcutDescriptions = [
    '/children' => 'Find a child and review their health history.',
    '/children/new' => 'Create a record for a child under five.',
    '/immunizations/defaulters' => 'Find children with overdue vaccine doses.',
    '/reports/malnutrition' => 'Review children flagged by their latest measurement.',
    '/admin/users' => 'Manage staff accounts and their access.',
    '/admin/puroks' => 'Manage the areas served by your center.',
    '/admin/schedule' => 'Review vaccines, dose ages, and intervals.',
    '/admin/audit' => 'Review changes made to health records.',
    '/reports/nutrition' => 'View nutritional status counts by period.',
    '/reports/immunization' => 'View monthly doses and configured FIC counts.',
];

?>
<?php if ($signedIn): ?>
    <section class="welcome-panel" aria-label="Staff access">
        <div class="workspace-intro"><div><h2>Your care workspace</h2><p>Find records, record care, and prepare your center's reports.</p></div><span class="status-badge status-active">You are signed in.</span></div>
        <h3 class="workspace-section-title">Quick access</h3>
        <div class="workspace-shortcuts">
            <?php foreach ($navigation as $item): ?>
                <a class="shortcut-card" href="<?= e($item['href']) ?>"><span class="shortcut-icon"><?php $view->partial('partials/navigation-icon', ['href' => $item['href']]); ?></span><span class="shortcut-title"><?= e($item['label']) ?></span><span class="shortcut-description"><?= e($shortcutDescriptions[$item['href']] ?? 'Open this area of your workspace.') ?></span><span class="shortcut-link">Open <span aria-hidden="true">&#8599;</span></span></a>
            <?php endforeach; ?>
        </div>
        <form method="post" action="/logout">
            <?= csrf_field($csrfToken) ?>
            <button type="submit" class="button-secondary">Sign out</button>
        </form>
    </section>
<?php else: ?>
    <div class="login-screen">
        <section class="login-story" aria-labelledby="care-heading">
            <a class="login-brand" href="/login" translate="no"><span class="brand-mark" aria-hidden="true"><svg viewBox="0 0 32 32"><path d="M6 17h6l3-8 4 15 3-7h4"/><path d="M25 5H7a2 2 0 0 0-2 2v18a2 2 0 0 0 2 2h18a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2Z"/></svg></span>Pediacare</a>
            <h1 id="care-heading">Every child.<br>Every milestone.</h1>
            <p>Growth, vaccinations, and health visits.<br>A complete record of the care you give.</p>
            <img class="care-illustration" src="/assets/images/care-record.svg" width="520" height="320" alt="">
            <div class="care-topics"><span>Growth monitoring</span><span>Immunization</span><span>Health visits</span></div>
            <p class="login-story-footer">Built for your barangay health center.</p>
        </section>
        <section class="auth-panel login-panel" aria-labelledby="login-heading">
        <div class="login-heading-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/><path d="M12 14v3"/></svg></div>
        <h2 id="login-heading">Welcome back</h2>
        <p>Sign in with your health center account.</p>
        <?php if ($error !== null): ?>
            <div class="notice notice-error" id="login-error" role="alert" tabindex="-1"><p><?= e($error) ?></p></div>
        <?php endif; ?>
        <form method="post" action="/login" class="auth-form">
            <?= csrf_field($csrfToken) ?>
            <div>
                <label for="email">Email</label>
                <input id="email" name="email" type="email" autocomplete="username" spellcheck="false" autocapitalize="none" maxlength="254" required
                    <?php if ($error !== null): ?>aria-describedby="login-error"<?php endif; ?>>
            </div>
            <div>
                <label for="password">Password</label>
                <input id="password" name="password" type="password" autocomplete="current-password" required
                    <?php if ($error !== null): ?>aria-describedby="login-error"<?php endif; ?>>
            </div>
            <button type="submit">Sign in</button>
        </form>
        <p class="auth-help">If you need an account, contact the health center Admin.</p>
        <p class="login-access-note">Staff access only. Keep your account details private.</p>
        </section>
    </div>
<?php endif; ?>
<script src="/assets/js/auth.js" defer></script>
