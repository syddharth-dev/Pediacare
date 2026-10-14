<?php

declare(strict_types=1);

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f5f6f8">
    <title><?= e($title ?? 'Pediacare') ?> — Pediacare</title>
    <link rel="stylesheet" href="/assets/css/app.css?v=6">
    <script src="/assets/js/layout.js" defer></script>
</head>
<body class="<?= e((isset($signedIn) && !$signedIn ? 'auth-page ' : '') . (($isReport ?? false) ? 'report-page' . (($isPrint ?? false) ? ' report-print' : '') : '')) ?>">
    <a class="skip-link" href="#main-content">Skip to content</a>
    <div class="app-shell">
        <?php $view->partial('partials/sidebar', ['navigation' => $navigation ?? []]); ?>
        <main class="workspace" id="main-content" tabindex="-1">
            <?php $view->partial('partials/header', ['title' => $title ?? 'Pediacare', 'userName' => $userName ?? null]); ?>
            <?php $view->partial('partials/flash', ['messages' => $messages ?? []]); ?>
            <div class="page-content">
                <?php $content(); ?>
            </div>
        </main>
    </div>
</body>
</html>
