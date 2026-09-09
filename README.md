<p align="center">
  <img src="public/assets/img/og.svg" alt="ME News Ireland" width="720">
</p>

# ME News Ireland

**Your Community. Your News. Live.**

ME News Ireland is a community-first Irish news platform built on plain **PHP 8.2+** and **SQLite** — no framework, no build step, no Composer dependencies. It combines a live **wire of real headlines** from established Irish publishers with **community reporting** that is screened by the ME Trust Engine and labelled by human editors.

The design is a premium, dark-first "Aurora / 2090" system: luminous gradients, HUD detailing, self-hosted variable fonts (Syne, Manrope, JetBrains Mono), a live map, a newsroom pulse sparkline and a light theme toggle.

---

## Quick start

Requirements: PHP 8.2+ with `pdo_sqlite`, `curl`, `mbstring`, `fileinfo` and `simplexml`. FFmpeg is optional (video screening).

```bash
cp .env.example .env            # edit ADMIN_EMAIL / ADMIN_PASSWORD if you like
php scripts/setup.php --seed    # creates storage/, the database, the admin, 5 contributors and today's news
php -S 127.0.0.1:8000 -t public public/router.php
```

Or simply `scripts/serve.sh` (macOS/Linux) / `scripts\start.bat` (Windows) — they do all of the above.

Open <http://127.0.0.1:8000>.

| Account | Email | Password (development default) |
|---|---|---|
| Administrator / newsroom | `admin@menews.ie` | `ChangeMe123!` |
| Aoife Ní Bhriain — National desk | `aoife@menews.ie` | `Contributor-2090` |
| Cian Ó Súilleabháin — Sport desk | `cian@menews.ie` | `Contributor-2090` |
| Saoirse Mac Cárthaigh — Culture desk | `saoirse@menews.ie` | `Contributor-2090` |
| Niamh Brennan — Business desk | `niamh@menews.ie` | `Contributor-2090` |
| Tadhg Ó Ceallaigh — Regional desk | `tadhg@menews.ie` | `Contributor-2090` |

Change every password before any public deployment (`ADMIN_PASSWORD`, `CONTRIBUTOR_PASSWORD` in `.env`; setup refuses weak admin passwords in production mode).

## Real news: the wire

`config/sources.json` lists 15 RSS feeds from RTÉ News/Sport/Culture, The Irish Times, TheJournal.ie, BreakingNews.ie, the Irish Independent, Dublin Live and Cork Beo. The wire:

- stores only the headline, standfirst, image reference, byline and publication time — every story links back to its publisher;
- infers the section (National, Local, Business, Sport, Culture, Traffic, Council, What's On, World) and the county/town from the text;
- files each story to the ME contributor who runs that desk, so every story has a named editor;
- de-duplicates across feeds, prunes stale items and logs each run in `wire_runs`;
- refreshes itself when a visitor loads a page and the wire is older than `WIRE_REFRESH_MINUTES` (a lock prevents overlapping runs), or on demand from the newsroom, or from cron:

```
*/15 * * * *  php /var/www/menews/scripts/fetch-news.php --if-stale >> /var/www/menews/storage/logs/wire.log 2>&1
```

`database/seed/wire-snapshot.json` is a captured snapshot so a fresh install shows real news even offline (`php scripts/seed.php --offline`). Regenerate it with `php scripts/fetch-news.php --snapshot`.

## Project layout

```
public/                 web root — index.php front controller, .htaccess, assets (css/js/fonts/img)
src/                    application code (PSR-4, namespace MeNews)
  bootstrap.php         autoloader, .env, error handling
  routes.php            all routes
  Http/                 Request, Response, Router, HttpException
  Controllers/          PageController (SSR pages), ApiController, AccountController, AdminController
  Services/             NewsWire, TrustEngine, Moderation, Media, Stripe, Notifier, Audit, RateLimiter, Remote
  Support/              Categories, Locations
  Auth.php  Database.php  Stories.php  Ui.php  View.php  Config.php  helpers.php
templates/              PHP templates (layout, home, story, listing, contributors, about, plus, dashboard, newsroom)
database/schema.sql     SQLite schema (idempotent)
database/seed/          contributors.json, wire-snapshot.json
config/                 sources.json (RSS feeds), locations.json (provinces, counties, towns)
scripts/                setup.php, seed.php, fetch-news.php, backup.php, import-locations.php, serve.sh, start.bat
storage/                runtime data (git-ignored): data/menews.sqlite, uploads/, logs/, cache/
deploy/                 apache.conf, nginx.conf, php.ini
tests/smoke.php         end-to-end smoke test against a running server
```

## Features

**Public site (server-rendered, SEO-ready)** — home with featured story, live wire ticker, per-section blocks, community desk, contributor cards, newsroom pulse sparkline, county signal, live Leaflet map; section, county, search, story, contributor, about and ME+ pages; JSON-LD, Open Graph, sitemap.xml, feed.xml, manifest; dark/light theme; reduced-motion support.

**Accounts** — email/password registration, hashed passwords, random sessions stored as SHA-256 hashes, HttpOnly cookie + bearer token, CSRF guard on cookie sessions, persistent rate limits, roles (member, contributor, editor, admin), dashboard with reports, profile, followed areas (1 free / 10 on ME+), notifications, advertising and password change.

**Community reporting & Trust Engine** — text/photo/video submissions into private quarantine, FFprobe/FFmpeg frame sampling, audio transcription, OpenAI moderation (production requires a clean OpenAI result before publication), separate safety and confidence scores, editor-only labels (Community Report, Developing, Verified, Official), comments with moderation, independent confirmations, local alerts.

**Newsroom** — review queue with media preview, publish/hold/reject, re-run safety, edit any story (section, county, label, featured), flagged comments, people & roles, advertising approvals, wire control panel with run log, official CSO / Tailte Éireann town import, full audit log.

**Billing** — Stripe Checkout for ME+ with signed webhook handling.

## Configuration

See `.env.example`. Key settings: `APP_ENV`, `PUBLIC_BASE_URL`, `OPENAI_API_KEY`, `AUTO_PUBLISH_SAFE`, `WIRE_*`, `STRIPE_*`, `STORAGE_PATH`.

## Deployment

- Point the document root at `public/` (see `deploy/apache.conf` or `deploy/nginx.conf`). `.env`, `src/` and `storage/` must stay outside the web root.
- Merge `deploy/php.ini` for upload limits.
- Run `php scripts/setup.php --seed` once, then schedule `scripts/fetch-news.php`.
- Back up with `php scripts/backup.php /path/outside/public/menews.sqlite` plus `storage/uploads`.

## Testing

```bash
php -S 127.0.0.1:8000 -t public public/router.php &
php tests/smoke.php
```

The smoke test covers every public page, the API, registration, cookie and bearer sessions, the CSRF guard, plan limits, community reporting, comments, confirmations, adverts and the full newsroom decision flow.

## Editorial principle

**Safety is not truth.** The safety score asks whether content presents a harmful-content or privacy risk. The confidence score is an evidence signal only. Neither is permission to call a claim true — only the newsroom sets the public label.
