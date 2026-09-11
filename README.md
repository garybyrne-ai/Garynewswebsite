<p align="center">
  <img src="public/assets/img/og.svg" alt="ME News Ireland" width="720">
</p>

# ME News Ireland

**Your Community. Your News. Live.**

ME News Ireland is a community-first Irish news platform built on plain **PHP 8.2+** and **SQLite** — no framework, no build step, no Composer dependencies. It combines a live **wire of real headlines** from established Irish publishers with **community reporting** that is screened by the ME Trust Engine and labelled by human editors.

The design is a premium, dark-first "Aurora / 2090" system: luminous gradients, HUD detailing, self-hosted variable fonts (Sora, Manrope, JetBrains Mono), a live map, a newsroom pulse sparkline and a light theme toggle.

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
- pins every story on the live map using town/county coordinates (`config/geo.json`, built from OpenStreetMap by `scripts/geocode-locations.php`);
- **updates itself automatically**: once the wire is older than `WIRE_REFRESH_MINUTES` (default 20) the next page view triggers a refresh in the background (`fastcgi_finish_request` under PHP-FPM, so visitors never wait; a browser ping is the fallback). A lock prevents overlapping runs.
- for guaranteed updates even without visitors, call the private endpoint from cron or an uptime monitor (the key is generated at install and shown in Newsroom → News wire):

```
*/15 * * * *  curl -s "https://yourdomain.ie/cron/wire?key=YOUR_CRON_KEY"
# or, on the server itself
*/15 * * * *  php /var/www/menews/scripts/fetch-news.php --if-stale >> /var/www/menews/storage/logs/wire.log 2>&1
```

`database/seed/wire-snapshot.json` is a captured snapshot so a fresh install shows real news even offline (`php scripts/seed.php --offline`). Regenerate it with `php scripts/fetch-news.php --snapshot`.

## Fresh every day

Every calendar day (Irish time) the site changes: an **edition number** and masthead date, **live weather** for eight Irish cities with sunrise and sunset (Open-Meteo, cached 30 min), the **Irish word of the day** (*Focal an lae*), a subtle daily hue shift in the background, and a brand-new set of puzzles.

## Near me (visitor location)

On first visit a slim banner offers **Use my location**. With permission (phones and computers, over HTTPS) the browser sends coordinates to `/api/near`, which resolves the nearest of 227 towns and its county from `config/geo.json`, gathers every published story within 40 km (widening to 80 km when quiet) ordered by a freshness-weighted distance, and drops a **Near you · Town, Co. County** section into the home page without a reload. The choice is kept in two small cookies (`me_loc`, rounded to ~100 m, and `me_county`) for 30 days and is never attached to an account; visitors can pick a county manually instead, or tap *Forget*. `/near` is the full page with a map centred on the visitor.

## The Signal (reader voting)

ME's own ranking, at `/signal` and as a slider on the home page. Readers vote on *why* a story matters — ⚡ Matters, 🔥 Talking point, 💚 Good news, 🔎 Needs digging — one vote per reader per story (user id when signed in, an anonymous `me_voter` cookie otherwise; tap the same signal again to withdraw). Each vote is weighted: signal (1.4 / 1.0 / 1.1 / 1.2) × voter (signed-in 1.25) × local boost (1.5 when the reader's Near-me county matches the story). A story's score is `(Σ weights + 2×confirmations + 0.5×comments + 2×votes in the last 3 h) ÷ (hours old + 4)^1.2`, so fresh, locally backed, fast-moving stories rise and everything decays. Windows: Today, This week, Rising now; filter by county. The formula is published on the page. Votes are rate-limited (60/hour/IP); the leaderboard is cached for 30 s and invalidated on every vote. Vote widgets sit on every story page and on the slider/leaderboard cards, with live count bumps and a burst animation.

## ME Ads (advertising platform)

Self-serve local advertising at `/advertise`:

- **Designer** — advertisers (and the newsroom, for house ads) build an ad in the browser: six templates (Aurora, Bold, Clean, Night, Paper, Photo), custom colours, headline, supporting line, button text, badge, logo and photo uploads, decoration and alignment, with a live server-rendered preview of both formats. Ads are stored as a structured spec and rendered by `Services/Ads.php`, so no advertiser HTML ever reaches a page.
- **Placements** — sidebar card (home, sections, counties, articles) and banner (under the home masthead, on section pages, inline in every article). County or town targeting; local ads outrank national ones; weighted rotation; the kids' section is always ad-free. Every ad is labelled Sponsored and opens in a new tab through `/ads/{id}/go`, which records clicks.
- **Lifecycle** — draft → review → approved. Approval starts the free trial (default 7 days, editable); the advertiser then subscribes monthly (default €25, editable) by **Stripe Checkout** (inline price, trial aligned with the remaining days) or **PayPal Subscriptions** (plan created on demand, billing starts when the trial ends). Webhooks (`/api/stripe/webhook`, `/api/paypal/webhook`, signature-verified) keep `plan_status` and `current_period_end` in sync; ads stop showing automatically when unpaid. Admins can also mark bank-transfer or complimentary periods manually.
- **Newsroom** — review queue, approve/reject with a note, pause, weight, make house ad, mark paid, delete, per-ad daily impressions/clicks and payment history, and the price/trial/currency settings. Gateway status shows which `.env` keys are missing.
- **House ads** — `database/seed/house-ads.json` ships four ads for Gary's Tech Hub, the Tech Hub Shop, KnowIT and VanQuotes.ie, seeded at install.

Set `STRIPE_SECRET_KEY` + `STRIPE_WEBHOOK_SECRET` and/or `PAYPAL_CLIENT_ID` + `PAYPAL_CLIENT_SECRET` + `PAYPAL_WEBHOOK_ID` (`PAYPAL_MODE=sandbox|live`) in `.env`, then point the providers' webhooks at the two endpoints above.

## ME Óg · The Junior Post (kids section)

`/kids` is an old-school newspaper inside the futuristic site — cream newsprint, a serif masthead and a puzzle corner that resets at midnight:

- **Daily crossword** — generated deterministically from the date by `Services/Puzzles.php` using a 370-word Irish-flavoured bank (`config/kids/crossword-words.json`). Junior (11×11, 10 clues) and Grown-up (15×15, 18 clues) grids, on-screen solving with check/reveal, saved progress, printing and a 14-day archive.
- **Daily word search** — ten words on a rotating theme (counties, animals in Irish, food, GAA, legends…), drag to find.
- **Know Your Ireland quiz** — five questions a day from a 60-question bank, each with a fact.
- **Find the County** — a Leaflet map game using the geocoded county centroids.
- **Focal an lae**, a curated list of genuinely free things to do across Ireland, "bright side" wire stories filtered for young readers, and a junior reporter prompt.

Puzzles are cached in `storage/cache/` so generation costs nothing after the first visitor of the day.

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
config/                 sources.json (RSS feeds), locations.json (places), geo.json (coordinates), kids/ (puzzle banks)
scripts/                setup.php, seed.php, fetch-news.php, backup.php, import-locations.php, serve.sh, start.bat
storage/                runtime data (git-ignored): data/menews.sqlite, uploads/, logs/, cache/
deploy/                 apache.conf, nginx.conf, php.ini
tests/smoke.php         end-to-end smoke test against a running server
```

## Features

**Public site (server-rendered, SEO-ready)** — home with featured story, live wire ticker, per-section blocks, community desk, contributor cards, newsroom pulse sparkline, county signal, live Leaflet map with category-coloured pins, county activity circles, filters and a full-screen `/map` page; section, county, search, story, contributor, about and ME+ pages; JSON-LD, Open Graph, sitemap.xml, feed.xml, manifest; dark/light theme; reduced-motion support.

**Accounts** — email/password registration, hashed passwords, random sessions stored as SHA-256 hashes, HttpOnly cookie + bearer token, CSRF guard on cookie sessions, persistent rate limits, roles (member, contributor, editor, admin), dashboard with reports, profile, followed areas (1 free / 10 on ME+), notifications, advertising and password change.

**Community reporting & Trust Engine** — text/photo/video submissions into private quarantine, FFprobe/FFmpeg frame sampling, audio transcription, OpenAI moderation (production requires a clean OpenAI result before publication), separate safety and confidence scores, editor-only labels (Community Report, Developing, Verified, Official), comments with moderation, independent confirmations, local alerts.

**Newsroom** — review queue with media preview, publish/hold/reject, re-run safety, edit any story (section, county, label, featured), flagged comments, people & roles, advertising approvals, wire control panel with run log, official CSO / Tailte Éireann town import, full audit log.

**Billing** — Stripe Checkout for ME+ and ME Ads, PayPal Subscriptions for ME Ads, signed webhook handling for both.

## Configuration

See `.env.example`. Key settings: `APP_ENV`, `PUBLIC_BASE_URL`, `OPENAI_API_KEY`, `AUTO_PUBLISH_SAFE`, `WIRE_*`, `STRIPE_*`, `STORAGE_PATH`.

## Deployment

- Point the document root at `public/` (see `deploy/apache.conf` or `deploy/nginx.conf`). `.env`, `src/` and `storage/` must stay outside the web root.
- **Cloudways / shared hosting** where the web root is the project folder itself: the root-level `index.php` and `.htaccess` forward everything into `public/` and block private paths. See `deploy/cloudways.md` for the step-by-step guide (set Webroot to `public_html/public`, PHP 8.2+, run setup over SSH, add the wire cron).
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
