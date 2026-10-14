'use strict';

const navigationMenu = document.querySelector('.navigation-menu');
const compactNavigation = window.matchMedia('(max-width: 700px)');

function updateNavigationLayout() {
    if (navigationMenu) {
        navigationMenu.open = !compactNavigation.matches;
    }
}

updateNavigationLayout();
compactNavigation.addEventListener('change', updateNavigationLayout);
