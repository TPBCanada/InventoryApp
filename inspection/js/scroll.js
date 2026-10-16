/*
 * This file is assumed to be /js/scroll.js or similar
 */

// --- 1. Hide/Show Navbar on Scroll Logic (No changes needed) ---
/* Hide navbar on scroll down past 100px, show when scrolling up 50px */
(function () {
  const nav = document.querySelector('.navbar');
  if (!nav) return;

  let lastY = window.scrollY;
  let hidden = false;
  let lastShownY = 0;

  window.addEventListener('scroll', () => {
    const y = window.scrollY;
    const dy = y - lastY;

    // Scroll Down Logic: Hide navbar if scrolled past 100px
    if (dy > 0 && y > 100 && !hidden) {
      nav.classList.add('is-hidden');
      hidden = true;
      lastShownY = y;
      // Scroll Up Logic: Show navbar if scrolled up more than 50px since hiding
    } else if (dy < 0 && hidden && (lastShownY - y) > 50) {
      nav.classList.remove('is-hidden');
      hidden = false;
    }

    lastY = y;
  }, { passive: true });
})();

// ------------------------------------------------------------------

// --- 2. Smooth Scroll to Top Logic & Visibility (Minor Fix) ---
(function () {
  // CONFIGURATION: Adjust this value to set the scroll distance before the button appears
  const SCROLL_THRESHOLD = 200;

  // Select the specific "scroll to top" link
  const scrollToTopLink = document.querySelector('a[alt="scroll to top"]');

  // Applying fixed position via JS is generally discouraged; use CSS instead. 
  // However, leaving it here to maintain the existing behavior:
  if (scrollToTopLink) {
    scrollToTopLink.style.position = 'fixed';
    scrollToTopLink.style.bottom = '20px';
    scrollToTopLink.style.right = '20px';

    // 2a. Smooth Scroll Handler
    scrollToTopLink.addEventListener('click', function (e) {
      e.preventDefault();
      window.scrollTo({
        top: 0,
        behavior: 'smooth'
      });
    });

    // 2b. Visibility Handler
    const toggleVisibility = () => {
      // Check current vertical scroll position
      if (window.scrollY > SCROLL_THRESHOLD) {
        // Show the button (e.g., by adding an 'is-visible' class)
        scrollToTopLink.classList.add('is-visible');
      } else {
        // Hide the button
        scrollToTopLink.classList.remove('is-visible');
      }
    };

    // Initial check on load
    toggleVisibility();

    // Check visibility on every scroll event
    window.addEventListener('scroll', toggleVisibility, { passive: true });
  }
})();


// ------------------------------------------------------------------

// --- Example using setAttribute ---
(function () {
  const backToMain = document.querySelector('a[alt="back to main"]');

  if (backToMain) {

    backToMain.style.position = 'fixed';
    backToMain.style.bottom = '20px';
    backToMain.style.right = '20px'; 

    backToMain.addEventListener('click', function (e) {
      e.preventDefault();

      const newDestination = "/dev/dashboard.php";

      this.setAttribute('href', newDestination);

      window.location.href = this.href;
    });
  }
})();