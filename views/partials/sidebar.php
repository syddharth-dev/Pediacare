<?php

declare(strict_types=1);

$navigationGroups = ['Care records' => [], 'Reports' => [], 'Administration' => []];
foreach ($navigation as $item) {
    $group = str_starts_with($item['href'], '/admin/') ? 'Administration'
        : (str_starts_with($item['href'], '/reports/') || $item['href'] === '/immunizations/defaulters' ? 'Reports' : 'Care records');
    $navigationGroups[$group][] = $item;
}

?>
<aside class="sidebar" aria-label="Application sidebar">
    <a class="brand" href="/" translate="no"><span class="brand-mark" aria-hidden="true"><svg viewBox="0 0 32 32"><path d="M6 17h6l3-8 4 15 3-7h4"/><path d="M25 5H7a2 2 0 0 0-2 2v18a2 2 0 0 0 2 2h18a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2Z"/></svg></span>Pediacare</a>
    <p class="brand-description">Child health records</p>
    <?php if ($navigation !== []): ?>
        <details class="navigation-menu" open>
        <summary>Browse workspace <span aria-hidden="true">&#8964;</span></summary>
        <nav aria-label="Main navigation">
            <?php foreach ($navigationGroups as $group => $items): ?>
                <?php if ($items === []): continue; endif; ?>
            <p class="navigation-heading"><?= e($group) ?></p>
            <ul class="navigation">
                <?php foreach ($items as $item): ?>
                    <li>
                        <a href="<?= e($item['href']) ?>"<?php if (!empty($item['current'])): ?> aria-current="page"<?php endif; ?>><?php $view->partial('partials/navigation-icon', ['href' => $item['href']]); ?><span><?= e($item['label']) ?></span></a>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php endforeach; ?>
        </nav>
        </details>
    <?php endif; ?>
    <div class="sidebar-footer"><strong>Health center workspace</strong><a href="/login">Account and workspace</a></div>
</aside>
