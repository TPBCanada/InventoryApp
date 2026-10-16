(() => {
  if (window.__invHttpInterceptorInstalled) {
    return;
  }
  window.__invHttpInterceptorInstalled = true;

  function getLoginUrl() {
    const path = window.location.pathname || "";
    const isModulePage = /\/(inventory|inspection|supplies)(\/|$)/.test(path);
    return isModulePage ? "../login.php?timeout=1" : "login.php?timeout=1";
  }

  function handleUnauthorized() {
    if (window.__invAuthRedirecting) {
      return;
    }
    window.__invAuthRedirecting = true;
    window.location.assign(getLoginUrl());
  }

  if (typeof window.fetch === "function") {
    const originalFetch = window.fetch.bind(window);
    window.fetch = async (...args) => {
      const response = await originalFetch(...args);
      if (response && response.status === 401) {
        handleUnauthorized();
      }
      return response;
    };
  }

  if (typeof window.XMLHttpRequest === "function") {
    const originalOpen = XMLHttpRequest.prototype.open;
    XMLHttpRequest.prototype.open = function (...args) {
      this.addEventListener("load", function () {
        if (this.status === 401) {
          handleUnauthorized();
        }
      });
      return originalOpen.apply(this, args);
    };
  }
})();
