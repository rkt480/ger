(() => {
  const storageKey = 'gerente-theme';
  const root = document.documentElement;

  const readTheme = () => {
    try {
      return window.localStorage.getItem(storageKey) === 'light' ? 'light' : 'dark';
    } catch (error) {
      return 'dark';
    }
  };

  const saveTheme = (theme) => {
    try {
      window.localStorage.setItem(storageKey, theme);
    } catch (error) {
      // Private browsing modes can deny storage; the current page still works.
    }
  };

  const applyTheme = (theme) => {
    const nextTheme = theme === 'light' ? 'light' : 'dark';
    root.dataset.theme = nextTheme;

    const metaThemeColor = document.querySelector('meta[name="theme-color"]');
    if (metaThemeColor) {
      metaThemeColor.setAttribute('content', nextTheme === 'light' ? '#edf2f8' : '#070a10');
    }

    const button = document.querySelector('[data-theme-toggle]');
    if (button) {
      const nextLabel = nextTheme === 'light' ? 'Usar tema escuro' : 'Usar tema claro';
      const nextText = nextTheme === 'light' ? '◐ Escuro' : '☼ Claro';
      button.setAttribute('aria-label', nextLabel);
      button.setAttribute('title', nextLabel);
      button.querySelector('[data-theme-toggle-label]')?.replaceChildren(document.createTextNode(nextText));
    }
  };

  // Set the attribute as soon as the script is read to avoid a dark/light flash.
  applyTheme(readTheme());

  const mountToggle = () => {
    if (!document.querySelector('.app-shell')) {
      return;
    }

    let button = document.querySelector('[data-theme-toggle]');

    if (!button) {
      button = document.createElement('button');
      button.type = 'button';
      button.className = 'theme-toggle';
      button.dataset.themeToggle = 'true';
      button.innerHTML = '<span data-theme-toggle-label></span>';

      document.body.append(button);
    }

    if (button.dataset.themeBound !== 'true') {
      button.addEventListener('click', () => {
        const nextTheme = root.dataset.theme === 'light' ? 'dark' : 'light';
        saveTheme(nextTheme);
        applyTheme(nextTheme);
      });
      button.dataset.themeBound = 'true';
    }

    applyTheme(root.dataset.theme || 'dark');
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mountToggle, { once: true });
  } else {
    mountToggle();
  }
})();
