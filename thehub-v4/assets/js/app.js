// TheHUB v4 - Minimal SPA-shell
// Hanterar navigation + dataladdning mot backend/public/api/*.php

const API_BASE = "backend/public/api";

const routes = {
    dashboard: renderDashboardView,
    events: renderEventsView,
    riders: renderRidersView,
    ranking: renderRankingView,
};

document.addEventListener("DOMContentLoaded", () => {
    const navButtons = document.querySelectorAll(".hub-nav-item");
    navButtons.forEach((btn) => {
        btn.addEventListener("click", () => {
            const route = btn.dataset.route;
            window.location.hash = route;
        });
    });

    window.addEventListener("hashchange", handleRouteChange);
    handleRouteChange();
});

function handleRouteChange() {
    const route = (window.location.hash.replace("#", "") || "dashboard").toLowerCase();
    const viewRoot = document.getElementById("hub-view-root");
    const title = document.getElementById("hub-page-title");
    const subtitle = document.getElementById("hub-page-subtitle");

    document
        .querySelectorAll(".hub-nav-item")
        .forEach((btn) => btn.classList.toggle("is-active", btn.dataset.route === route));

    switch (route) {
        case "events":
            title.textContent = "Events";
            subtitle.textContent = "Översikt av planerade och genomförda tävlingar.";
            renderEventsView(viewRoot);
            break;
        case "riders":
            title.textContent = "Riders";
            subtitle.textContent = "Register över åkare kopplade till GravitySeries.";
            renderRidersView(viewRoot);
            break;
        case "ranking":
            title.textContent = "Ranking";
            subtitle.textContent = "Exempel på poäng- och rankingdata.";
            renderRankingView(viewRoot);
            break;
        case "dashboard":
        default:
            title.textContent = "Dashboard";
            subtitle.textContent = "Överblick över events, åkare och ranking.";
            renderDashboardView(viewRoot);
            break;
    }
}

function setLoading(root) {
    root.innerHTML = `
        <div class="hub-loading">
            <div class="hub-spinner"></div>
            <p>Laddar data...</p>
        </div>
    `;
}

async function fetchJson(endpoint) {
    const res = await fetch(`${API_BASE}/${endpoint}`);
    if (!res.ok) {
        throw new Error(`API-fel: ${res.status}`);
    }
    return res.json();
}

/* Views */
async function renderDashboardView(root) {
    setLoading(root);
    try {
        const stats = await fetchJson("stats.php");
        root.innerHTML = `
            <section class="hub-grid">
                <article class="hub-card">
                    <header class="hub-card-header">
                        <div class="hub-card-title">Events</div>
                        <div class="hub-card-meta">Totalt</div>
                    </header>
                    <div class="hub-metric">${stats.total_events ?? "-"}</div>
                </article>
                <article class="hub-card">
                    <header class="hub-card-header">
                        <div class="hub-card-title">Riders</div>
                        <div class="hub-card-meta">Registrerade</div>
                    </header>
                    <div class="hub-metric">${stats.total_riders ?? "-"}</div>
                </article>
                <article class="hub-card">
                    <header class="hub-card-header">
                        <div class="hub-card-title">Klubbar</div>
                        <div class="hub-card-meta">Aktiva</div>
                    </header>
                    <div class="hub-metric">${stats.total_clubs ?? "-"}</div>
                </article>
                <article class="hub-card">
                    <header class="hub-card-header">
                        <div class="hub-card-title">Senaste uppdatering</div>
                        <div class="hub-card-meta">Backend</div>
                    </header>
                    <div class="hub-metric" style="font-size:1rem;">${stats.last_updated ?? "-"}</div>
                </article>
            </section>
        `;
    } catch (err) {
        root.innerHTML = `<p>Fel vid hämtning av dashboard-data: ${err.message}</p>`;
    }
}

async function renderEventsView(root) {
    setLoading(root);
    try {
        const events = await fetchJson("events.php");
        const rows = events
            .map(
                (e) => `
            <tr>
                <td>${e.id}</td>
                <td>${e.name}</td>
                <td>${e.location}</td>
                <td>${e.date}</td>
                <td>${e.series}</td>
                <td>${e.status}</td>
            </tr>`
            )
            .join("");

        root.innerHTML = `
            <section class="hub-table-wrapper">
                <table class="hub-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Event</th>
                            <th>Plats</th>
                            <th>Datum</th>
                            <th>Serie</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${rows}
                    </tbody>
                </table>
            </section>
        `;
    } catch (err) {
        root.innerHTML = `<p>Fel vid hämtning av events: ${err.message}</p>`;
    }
}

async function renderRidersView(root) {
    setLoading(root);
    try {
        const riders = await fetchJson("riders.php");
        const rows = riders
            .map(
                (r) => `
            <tr>
                <td>${r.id}</td>
                <td>${r.name}</td>
                <td>${r.club}</td>
                <td>${r.nation}</td>
                <td>${r.category}</td>
            </tr>`
            )
            .join("");

        root.innerHTML = `
            <section class="hub-table-wrapper">
                <table class="hub-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Åkare</th>
                            <th>Klubb</th>
                            <th>Nation</th>
                            <th>Klass</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${rows}
                    </tbody>
                </table>
            </section>
        `;
    } catch (err) {
        root.innerHTML = `<p>Fel vid hämtning av riders: ${err.message}</p>`;
    }
}

async function renderRankingView(root) {
    setLoading(root);
    try {
        const ranking = await fetchJson("ranking.php");
        const rows = ranking
            .map(
                (r, idx) => `
            <tr>
                <td>${idx + 1}</td>
                <td>${r.name}</td>
                <td>${r.club}</td>
                <td>${r.series}</td>
                <td>${r.points}</td>
            </tr>`
            )
            .join("");

        root.innerHTML = `
            <section class="hub-table-wrapper">
                <table class="hub-table">
                    <thead>
                        <tr>
                            <th>Plats</th>
                            <th>Åkare</th>
                            <th>Klubb</th>
                            <th>Serie</th>
                            <th>Poäng</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${rows}
                    </tbody>
                </table>
            </section>
        `;
    } catch (err) {
        root.innerHTML = `<p>Fel vid hämtning av ranking: ${err.message}</p>`;
    }
}
