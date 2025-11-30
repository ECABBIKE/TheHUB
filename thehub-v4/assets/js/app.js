// TheHUB V4 – UI shell JS

document.addEventListener("DOMContentLoaded", () => {
  setupNavigation();
  setupTheme();
  // TODO: koppla in riktiga API-anrop:
  // loadDashboardStats();
  // loadEvents();
  // loadSeries();
  // loadDatabaseToplists();
});

function setupNavigation() {
  const links = document.querySelectorAll(".sidebar-link");
  const views = document.querySelectorAll(".view");
  const titleEl = document.getElementById("topbar-title");
  const eyebrowEl = document.getElementById("topbar-eyebrow");

  links.forEach((btn) => {
    btn.addEventListener("click", () => {
      const viewId = btn.dataset.view;

      links.forEach((b) => b.classList.remove("is-active"));
      btn.classList.add("is-active");

      views.forEach((v) => v.classList.remove("view-active"));
      const view = document.getElementById(`view-${viewId}`);
      if (view) {
        view.classList.add("view-active");
        if (titleEl && view.dataset.title) titleEl.textContent = view.dataset.title;
        if (eyebrowEl && view.dataset.eyebrow) eyebrowEl.textContent = view.dataset.eyebrow;
      }
    });
  });

  // snabblänkar på dashboard
  document.querySelectorAll("[data-goto]").forEach((el) => {
    el.addEventListener("click", () => {
      const target = el.getAttribute("data-goto");
      const sidebarBtn = document.querySelector(
        `.sidebar-link[data-view="${target}"]`
      );
      if (sidebarBtn) sidebarBtn.click();
    });
  });
}

/* ===== THEME ===== */

function setupTheme() {
  const root = document.documentElement;
  const buttons = document.querySelectorAll(".theme-btn");
  const mql = window.matchMedia("(prefers-color-scheme: dark)");

  function apply(mode, save = true) {
    let effective = mode;
    if (mode === "auto") {
      effective = mql.matches ? "dark" : "light";
    }
    root.dataset.theme = effective;
    if (save) localStorage.setItem("thehub-theme", mode);

    buttons.forEach((b) => b.classList.remove("is-active"));
    const activeBtn = document.querySelector(`.theme-btn[data-theme="${mode}"]`);
    if (activeBtn) activeBtn.classList.add("is-active");
  }

  // initial
  const stored = localStorage.getItem("thehub-theme") || "auto";
  apply(stored, false);

  // lyssna på system-ändring vid auto
  mql.addEventListener("change", () => {
    const current = localStorage.getItem("thehub-theme") || "auto";
    if (current === "auto") apply("auto", false);
  });

  buttons.forEach((btn) => {
    btn.addEventListener("click", () => {
      const mode = btn.dataset.theme || "auto";
      apply(mode, true);
    });
  });
}
