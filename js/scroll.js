/* Hide navbar on scroll down past 100px, show when scrolling up 50px */
(function(){
  const nav = document.querySelector('.navbar');
  if (!nav) return;

  let lastY = window.scrollY;
  let hidden = false;
  let lastShownY = 0;

  window.addEventListener('scroll', () => {
    const y = window.scrollY;
    const dy = y - lastY;

    if (dy > 0 && y > 100 && !hidden) {
      nav.classList.add('is-hidden');
      hidden = true;
      lastShownY = y;
    } else if (dy < 0 && hidden && (lastShownY - y) > 50) {
      nav.classList.remove('is-hidden');
      hidden = false;
    }

    lastY = y;
  }, { passive: true });
})();
