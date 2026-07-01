# jprbuilds.com

Personal website for Jason Richards — Technical Training & Development Lead based in Daventry, UK.

Built with vanilla HTML, CSS, and PHP. No frameworks or build tools required (the `battleships` game has its own small Node/WebSocket backend, run separately).

---

## Pages

### `/` — Home
The main landing page. Links out to all sections of the site. Features a hero intro and a card grid of projects.

### `/portfolio/` — Portfolio & CV
Work history, achievements, and a downloadable CV.

### `/projects/` — Hobby Projects
A showcase of side projects and things built for fun.

### `/solar/` — Solar Dashboard
A live dashboard pulling stats from a Solis solar inverter. Built with Chart.js for data visualisation. Uses a PHP proxy (`solar/proxy.php`) to fetch data from the Solis Cloud API and Solcast forecast API, avoiding CORS issues and keeping API credentials off the client.

### `/strava/` — Running Log
A running log synced from the Strava API into a MariaDB database, with pace splits, HR zones, a Leaflet route map, Open-Meteo weather, and official parkrun times parsed from Gmail via IMAP.

### `/battleships/` — Battleships
A real-time two-player Battleships game with a custom Node.js WebSocket server for game state and moves. Play by hosting/joining on the same device, or across two devices via QR code or room code.

### `/twit/` — This Week in Training
A weekly L&D newsletter-style page featuring thoughts, links and ideas around Learning & Development in the automotive sector. Styled with Tailwind CSS via CDN.

---

## Structure

```
jprbuilds-site/
├── index.html              # Home page
├── css/
│   └── site.css            # Global stylesheet
├── Logos/
│   ├── jprbuilds-logo-full.svg
│   └── jprbuilds-logo-navbar.svg
├── portfolio/
│   └── index.html
├── projects/
│   └── index.html
├── solar/
│   ├── index.html          # Solar dashboard UI
│   ├── proxy.php           # Solis/Solcast API proxy
│   └── config.example.php  # Template for solar/config.php (not committed)
├── strava/
│   ├── index.html          # Running log feed
│   ├── activity.html       # Activity detail page
│   ├── proxy.php           # Strava/Open-Meteo API proxy
│   └── ...                 # DB, backfill and parkrun-sync scripts
├── battleships/
│   ├── index.html
│   ├── style.css
│   └── js/                 # Game state machine, realtime, audio, UI
└── twit/
    └── index.html          # This Week in Training
```

---

## Tech

- **HTML / CSS** — hand-written, no build step
- **PHP** — used for server-side data fetching (solar and strava proxies)
- **Node.js / WebSockets** — battleships game server
- **Chart.js** — solar dashboard charts
- **Leaflet** — strava route maps
- **Tailwind CSS** (CDN) — used on the TWIT page
- **Google Fonts** — DM Sans, DM Serif Display & Inter

---

## Notes

- `solar/proxy.php` requires a `solar/config.php` file (not committed) defining the Solis and Solcast API credentials — copy `solar/config.example.php` and fill in your own.
- `strava/proxy.php` requires a `strava/config.php` file (not committed) with Strava API credentials, and `strava/gmail_credentials.php` (copy from `strava/gmail_credentials.example.php`) for the parkrun Gmail sync.
- `.DS_Store` files are ignored via `.gitignore`.
