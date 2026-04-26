# jprbuilds.com

Personal website for Jason Richards — Technical Training & Development Lead based in Daventry, UK.

Built with vanilla HTML, CSS, and PHP. No frameworks or build tools required.

---

## Pages

### `/` — Home
The main landing page. Links out to all sections of the site. Features a hero intro and a card grid of projects.

### `/projects/` — Hobby Projects
A showcase of side projects and things built for fun.

### `/solar/` — Solar Dashboard
A live dashboard pulling stats from a Solis solar inverter. Built with Chart.js for data visualisation. Uses a PHP proxy (`solar/proxy.php`) to fetch data from the Solis API and avoid CORS issues.

### `/twit/` — This Week in Training
A weekly L&D newsletter-style page featuring thoughts, links and ideas around Learning & Development in the automotive sector. Styled with Tailwind CSS via CDN.

### `/bookmarks/` — Bookmarks
A PHP-powered bookmarks manager. Reads from a database (via `config.php` / PDO) and supports filtering by category.

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
├── projects/
│   └── index.html
├── solar/
│   ├── index.html          # Solar dashboard UI
│   └── proxy.php           # Solis API proxy
├── twit/
│   └── index.html          # This Week in Training
└── bookmarks/
    └── index.php           # Bookmarks manager (requires DB)
```

---

## Tech

- **HTML / CSS** — hand-written, no build step
- **PHP** — used for server-side data fetching (solar proxy, bookmarks)
- **Chart.js** — solar dashboard charts
- **Tailwind CSS** (CDN) — used on the TWIT page
- **Google Fonts** — DM Sans & Inter

---

## Notes

- `bookmarks/index.php` requires a `config.php` file (not committed) that sets up a PDO database connection.
- `solar/proxy.php` requires Solis API credentials (not committed).
- `.DS_Store` files are ignored via `.gitignore`.
