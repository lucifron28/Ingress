const navigationToggle = document.querySelector('[data-nav-toggle]');
const navigationMenu = document.querySelector('[data-nav-menu]');

if (navigationToggle instanceof HTMLButtonElement && navigationMenu instanceof HTMLElement) {
    const mobileNavigation = window.matchMedia('(max-width: 760px)');

    const setNavigationOpen = (isOpen) => {
        navigationToggle.setAttribute('aria-expanded', String(isOpen));
        navigationToggle.setAttribute('aria-label', isOpen ? 'Close navigation' : 'Open navigation');
        navigationToggle.querySelector('[data-nav-toggle-label]').textContent = isOpen ? 'Close' : 'Menu';
        navigationMenu.dataset.open = String(isOpen);
    };

    const syncNavigationToViewport = () => {
        setNavigationOpen(!mobileNavigation.matches);
    };

    document.documentElement.classList.add('js');
    syncNavigationToViewport();
    mobileNavigation.addEventListener('change', syncNavigationToViewport);

    navigationToggle.addEventListener('click', () => {
        const isExpanded = navigationToggle.getAttribute('aria-expanded') === 'true';
        setNavigationOpen(!isExpanded);
    });

    navigationMenu.addEventListener('click', (event) => {
        if (event.target instanceof Element && event.target.closest('a') && mobileNavigation.matches) {
            setNavigationOpen(false);
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && mobileNavigation.matches && navigationToggle.getAttribute('aria-expanded') === 'true') {
            setNavigationOpen(false);
            navigationToggle.focus();
        }
    });
}
