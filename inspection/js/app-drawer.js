/**
 * /js/app-drawer.js
 *
 * Implements the logic for the App Menu Slide-Out Drawer (left-side).
 * This logic handles opening/closing, focus management, and keyboard/backdrop events.
 */
(function () {
    const appDrawer = document.getElementById('appDrawer');
    const appLaunchBtn = document.getElementById('appLaunchBtn');
    const appBackdrop = document.getElementById('appBackdrop'); // Add backdrop element
    
    // The close button is commented out in your HTML, so we will use the backdrop and Esc key.
    // If you uncomment it later, you can re-add the appCloseBtn variable.
    // const appCloseBtn = document.getElementById('appCloseBtn'); 

    // Guard clause: Exit if the core elements are missing (e.g., on a non-app page)
    if (!appDrawer || !appLaunchBtn || !appBackdrop) {
        return;
    }

    // --- Core Functions ---

    function closeAppDrawer() {
        appDrawer.setAttribute('data-open', 'false');
        appDrawer.setAttribute('aria-hidden', 'true');
        appLaunchBtn.setAttribute('aria-expanded', 'false');

        // Remove the keyboard listener when closed
        document.removeEventListener('keydown', handleKeydown);
        
        // Return focus to the launch button for smooth UX/Accessibility
        appLaunchBtn.focus();
    }

    function openAppDrawer() {
        appDrawer.setAttribute('data-open', 'true');
        appDrawer.setAttribute('aria-hidden', 'false');
        appLaunchBtn.setAttribute('aria-expanded', 'true');

        // Add the keyboard listener when open
        document.addEventListener('keydown', handleKeydown);

        // Best Practice: Shift focus into the drawer for accessibility
        // Focus the first actionable element, e.g., the first link
        const firstLink = appDrawer.querySelector('a[href]');
        if (firstLink) {
            firstLink.focus();
        }
    }

    // --- Event Handlers ---
    
    // 1. Launch Button Toggles Drawer
    appLaunchBtn.addEventListener('click', () => {
        const isOpen = appDrawer.getAttribute('data-open') === 'true';
        if (isOpen) {
            closeAppDrawer();
        } else {
            openAppDrawer();
        }
    });

    // 2. Backdrop Closes Drawer
    appBackdrop.addEventListener('click', closeAppDrawer);

    // 3. Escape Key Closes Drawer (Accessibility)
    function handleKeydown(event) {
        // If Escape key is pressed, close the drawer
        if (event.key === 'Escape') {
            closeAppDrawer();
        }
    }

    // Note on `appCloseBtn`: If you uncomment the close button in the HTML,
    // you would uncomment the following lines:
    /*
    if (appCloseBtn) {
        appCloseBtn.addEventListener('click', closeAppDrawer);
    }
    */

})();