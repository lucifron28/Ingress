document.documentElement.classList.add('js');

const appShell = document.querySelector('[data-app-shell]');
const appSidebar = document.querySelector('[data-app-sidebar]');
const mobileSidebarToggle = document.querySelector('[data-sidebar-open]');
const sidebarCollapseToggle = document.querySelector('[data-sidebar-collapse]');
const sidebarCloseButton = document.querySelector('[data-sidebar-close]');
const sidebarBackdrop = document.querySelector('[data-sidebar-backdrop]');
const appMainArea = document.querySelector('[data-app-main-area]');

if (
    appShell instanceof HTMLElement
    && appSidebar instanceof HTMLElement
    && mobileSidebarToggle instanceof HTMLButtonElement
    && sidebarCollapseToggle instanceof HTMLButtonElement
    && sidebarCloseButton instanceof HTMLButtonElement
    && sidebarBackdrop instanceof HTMLElement
) {
    const mobileViewport = window.matchMedia('(max-width: 760px)');
    const tabletViewport = window.matchMedia('(min-width: 761px) and (max-width: 1023px)');
    let returnFocusTarget = null;
    let drawerIsOpen = false;

    let hasSavedPreference = false;

    const setSidebarState = (state, persist = false) => {
        appShell.dataset.sidebarState = state;
        const isExpanded = state === 'expanded';
        sidebarCollapseToggle.setAttribute('aria-expanded', String(isExpanded));
        sidebarCollapseToggle.setAttribute('aria-label', isExpanded ? 'Collapse sidebar' : 'Expand sidebar');
        sidebarCollapseToggle.title = isExpanded ? 'Collapse sidebar' : 'Expand sidebar';

        if (persist) {
            hasSavedPreference = true;
            try {
                window.localStorage.setItem('ingress.sidebar.state', state);
            } catch {
                // Sidebar state remains usable when local storage is unavailable.
            }
        }
    };

    const setPageInert = (isInert) => {
        if (appMainArea instanceof HTMLElement) {
            appMainArea.inert = isInert;
        }
    };

    const setDrawerOpen = (isOpen, restoreFocus = false) => {
        drawerIsOpen = isOpen;
        appShell.dataset.sidebarDrawerOpen = String(isOpen);
        sidebarBackdrop.hidden = !isOpen;
        appSidebar.inert = !isOpen;
        appSidebar.setAttribute('aria-hidden', String(!isOpen));
        mobileSidebarToggle.setAttribute('aria-expanded', String(isOpen));
        mobileSidebarToggle.setAttribute('aria-label', isOpen ? 'Close navigation' : 'Open navigation');
        mobileSidebarToggle.title = isOpen ? 'Close navigation' : 'Open navigation';
        setPageInert(isOpen);
        document.body.classList.toggle('app-drawer-open', isOpen);

        if (isOpen) {
            returnFocusTarget = document.activeElement instanceof HTMLElement ? document.activeElement : mobileSidebarToggle;
            sidebarCloseButton.focus();
        } else if (restoreFocus) {
            const target = returnFocusTarget instanceof HTMLElement && returnFocusTarget.isConnected
                ? returnFocusTarget
                : mobileSidebarToggle;
            target.focus();
            returnFocusTarget = null;
        }
    };

    const syncViewport = () => {
        if (mobileViewport.matches) {
            if (drawerIsOpen) {
                setDrawerOpen(false);
            }
            appSidebar.inert = true;
            appSidebar.setAttribute('aria-hidden', 'true');
            mobileSidebarToggle.setAttribute('aria-expanded', 'false');
            mobileSidebarToggle.setAttribute('aria-label', 'Open navigation');
            mobileSidebarToggle.title = 'Open navigation';
            setPageInert(false);
        } else {
            if (drawerIsOpen) {
                setDrawerOpen(false);
            }
            appSidebar.inert = false;
            appSidebar.setAttribute('aria-hidden', 'false');
            setPageInert(false);
        }
    };

    let savedState = null;
    try {
        savedState = window.localStorage.getItem('ingress.sidebar.state');
    } catch {
        savedState = null;
    }

    hasSavedPreference = savedState === 'expanded' || savedState === 'collapsed';
    setSidebarState(savedState === 'expanded' || savedState === 'collapsed'
        ? savedState
        : (tabletViewport.matches ? 'collapsed' : 'expanded'));
    syncViewport();

    mobileSidebarToggle.addEventListener('click', () => {
        setDrawerOpen(!drawerIsOpen, drawerIsOpen);
    });

    sidebarCloseButton.addEventListener('click', () => {
        setDrawerOpen(false, true);
    });

    sidebarBackdrop.addEventListener('click', () => {
        setDrawerOpen(false, true);
    });

    sidebarCollapseToggle.addEventListener('click', () => {
        const nextState = appShell.dataset.sidebarState === 'collapsed' ? 'expanded' : 'collapsed';
        setSidebarState(nextState, true);
    });

    appSidebar.addEventListener('click', (event) => {
        if (mobileViewport.matches && event.target instanceof Element && event.target.closest('a')) {
            setDrawerOpen(false);
        }
    });

    document.addEventListener('keydown', (event) => {
        if (!mobileViewport.matches || !drawerIsOpen) {
            return;
        }

        if (event.key === 'Escape') {
            event.preventDefault();
            setDrawerOpen(false, true);
            return;
        }

        if (event.key !== 'Tab') {
            return;
        }

        const focusableElements = [...appSidebar.querySelectorAll('a[href], button:not([disabled])')]
            .filter((element) => element.getClientRects().length > 0);
        const firstFocusable = focusableElements[0];
        const lastFocusable = focusableElements[focusableElements.length - 1];

        if (!firstFocusable || !lastFocusable) {
            event.preventDefault();
            appSidebar.focus();
        } else if (event.shiftKey && document.activeElement === firstFocusable) {
            event.preventDefault();
            lastFocusable.focus();
        } else if (!event.shiftKey && document.activeElement === lastFocusable) {
            event.preventDefault();
            firstFocusable.focus();
        }
    });

    mobileViewport.addEventListener('change', syncViewport);
    tabletViewport.addEventListener('change', () => {
        if (!hasSavedPreference && !mobileViewport.matches) {
            setSidebarState(tabletViewport.matches ? 'collapsed' : 'expanded');
        }
    });
}

const eventIsland = document.querySelector('[data-event-island][data-track-sections="true"]');

if (eventIsland instanceof HTMLElement) {
    const islandLinks = [...eventIsland.querySelectorAll('[data-island-link]')];
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    const islandSections = islandLinks
        .map((link) => ({
            key: link.dataset.islandLink,
            element: document.getElementById(link.dataset.islandTarget ?? ''),
        }))
        .filter((section) => section.element instanceof HTMLElement);

    const setActiveIslandLink = (activeKey) => {
        islandLinks.forEach((link) => {
            const isActive = link.dataset.islandLink === activeKey;
            link.classList.toggle('is-active', isActive);

            if (isActive) {
                link.setAttribute('aria-current', 'location');
            } else {
                link.removeAttribute('aria-current');
            }
        });
    };

    let highlightTimeout;
    let highlightedSection = null;
    const highlightSection = (section) => {
        if (!(section instanceof HTMLElement)) {
            return;
        }

        if (highlightedSection instanceof HTMLElement && highlightedSection !== section) {
            highlightedSection.classList.remove('event-section-highlight');
        }

        section.classList.remove('event-section-highlight');
        window.clearTimeout(highlightTimeout);
        void section.offsetWidth;
        section.classList.add('event-section-highlight');
        highlightedSection = section;
        highlightTimeout = window.setTimeout(() => {
            section.classList.remove('event-section-highlight');
            if (highlightedSection === section) {
                highlightedSection = null;
            }
        }, 850);
    };

    islandLinks.forEach((link) => {
        link.addEventListener('click', (event) => {
            const section = document.getElementById(link.dataset.islandTarget ?? '');

            if (!(section instanceof HTMLElement)) {
                return;
            }

            event.preventDefault();
            window.history.pushState({}, '', link.href);
            section.scrollIntoView({
                behavior: reducedMotion.matches ? 'auto' : 'smooth',
                block: 'start',
            });
            section.focus({ preventScroll: true });
            highlightSection(section);
            setActiveIslandLink(link.dataset.islandLink ?? 'overview');
        });
    });

    let activeUpdateIsScheduled = false;
    const updateActiveIslandLink = () => {
        activeUpdateIsScheduled = false;
        const activationLine = Math.max(eventIsland.getBoundingClientRect().bottom + 12, 92);
        const currentSection = [...islandSections]
            .reverse()
            .find(({ element }) => element.getBoundingClientRect().top <= activationLine);

        setActiveIslandLink(currentSection?.key ?? 'overview');
    };

    const scheduleActiveIslandUpdate = () => {
        if (activeUpdateIsScheduled) {
            return;
        }

        activeUpdateIsScheduled = true;
        window.requestAnimationFrame(updateActiveIslandLink);
    };

    window.addEventListener('scroll', scheduleActiveIslandUpdate, { passive: true });
    window.addEventListener('resize', scheduleActiveIslandUpdate);
    scheduleActiveIslandUpdate();
}
