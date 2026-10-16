(function () {
    const menu = document.getElementById('site-menu');
    const openBtn = document.getElementById('hamburgerBtn');
    const closeBtn = document.getElementById('menuCloseBtn');
    
    if (!menu || !openBtn || !closeBtn) return;
    
    let lastFocus = null;
    
    function openMenu() {
        lastFocus = document.activeElement;
        menu.hidden = false;
        menu.setAttribute('data-open', 'true');
        openBtn.setAttribute('aria-expanded', 'true');
        const firstLink = menu.querySelector('a,button');
        firstLink && firstLink.focus();
        document.addEventListener('keydown', onKey);
        document.addEventListener('click', onOutsideClick, true);
    }
    
    function closeMenu() {
        menu.removeAttribute('data-open');
        openBtn.setAttribute('aria-expanded', 'false');
        setTimeout(() => { menu.hidden = true; }, 200);
        lastFocus && lastFocus.focus();
        document.removeEventListener('keydown', onKey);
        document.removeEventListener('click', onOutsideClick, true);
    }
    
    function onKey(e) { if (e.key === 'Escape') closeMenu(); }
    
    function onOutsideClick(e) {
        if (e.target === menu) { closeMenu(); }
    }
    
    openBtn.addEventListener('click', () => {
        const isOpen = menu.getAttribute('data-open') === 'true';
        isOpen ? closeMenu() : openMenu();
    });
    
    closeBtn.addEventListener('click', closeMenu);
})();