(() => {
  const root = document.documentElement;

  // Set this before stylesheets paint so a previously selected dark theme
  // cannot flash while the clean light interface loads.
  root.dataset.theme = 'light';

  const metaThemeColor = document.querySelector('meta[name="theme-color"]');
  if (metaThemeColor) {
    metaThemeColor.setAttribute('content', '#f4f4f2');
  }
})();
