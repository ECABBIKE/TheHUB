(function () {
  const toggle = document.getElementById('themeToggle');
  if (!toggle) return;

  const root = document.body;

  function setTheme(theme) {
    if (theme === 'light') {
      root.classList.add('theme-light');
      root.classList.remove('theme-dark');
    } else {
      root.classList.add('theme-dark');
      root.classList.remove('theme-light');
    }
    try {
      localStorage.setItem('gs-webapp-theme', theme);
    } catch (e) {}
  }

  const saved = (() => {
    try {
      return localStorage.getItem('gs-webapp-theme');
    } catch (e) {
      return null;
    }
  })();

  if (saved === 'light' || saved === 'dark') {
    setTheme(saved);
  } else {
    setTheme('dark');
  }

  toggle.addEventListener('click', () => {
    const isDark = root.classList.contains('theme-dark');
    setTheme(isDark ? 'light' : 'dark');
  });
})();
