# Deploying ME News Ireland on Cloudways

Cloudways serves `public_html/` for a PHP stack application. The ME News web root is the
`public/` folder inside the project, so there are two ways to run it.

## Option A (recommended): point the web root at public/

1. **Application Settings → General → Webroot**: set to `public_html/public` and save.
2. **Application Settings → PHP**: PHP 8.2 or newer (8.3 recommended).
3. SSH into the application and install:

```bash
cd ~/applications/<app-folder>/public_html
cp .env.example .env
nano .env
#   APP_ENV=production
#   PUBLIC_BASE_URL=https://your-app.cloudwaysapps.com   (or your domain)
#   ADMIN_EMAIL=you@yourdomain.ie
#   ADMIN_PASSWORD=<a unique password of 12+ characters>
#   CONTRIBUTOR_PASSWORD=<change from the default>
php scripts/setup.php --seed
```

4. News updates are automatic: the wire refreshes in the background whenever it is older than
   20 minutes and a page is visited. To guarantee updates even with no visitors, add a cron job
   (Application → Cron Job Management → Advanced) using the private URL shown after install
   (also in Newsroom → News wire):

```
*/15 * * * *  curl -s "https://your-app.cloudwaysapps.com/cron/wire?key=YOUR_CRON_KEY"
*/15 * * * *  curl -s "https://your-app.cloudwaysapps.com/cron/daily?key=YOUR_CRON_KEY"
```

   or the CLI equivalent: `*/15 * * * * php /home/master/applications/<app-folder>/public_html/scripts/fetch-news.php --if-stale`

## Option B: keep the default web root

If you cannot change the web root, the root-level `index.php` and `.htaccess` in this
repository forward every request into `public/` and deny access to `.env`, `storage/`,
`src/` and the other private folders. Complete steps 2–4 above and the site will work
from `public_html/` directly.

## Notes

- In production mode the newsroom refuses to publish community reports until a clean
  OpenAI moderation result exists (`OPENAI_API_KEY`). Wire stories are unaffected.
- `storage/` must be writable by the application user (it is, by default, on Cloudways).
- Back up with `php scripts/backup.php ~/menews-backup-$(date +%F).sqlite` (outside `public_html`).
- The error `directory index of ".../public_html/" is forbidden` means neither `index.php`
  nor the `public/` web root was in place — pull this branch and repeat Option A or B.

## Email (7am digest, notice alerts, report confirmations)

Set `MAIL_TRANSPORT=mail` in `.env` to send through PHP `mail()` (works out of the box on Cloudways, but
add SPF/DKIM for your domain or use `MAIL_TRANSPORT=smtp` with your SMTP provider's details). Set
`MAIL_FROM` and `MAIL_REPLY_TO` to addresses on your domain. In `log` mode nothing is sent; messages are
written to `storage/logs/mail.log`.

## After pulling an update

Nothing to run: the database upgrades itself on the first request (tables, columns, the Wire label,
clustering of existing stories). Check `storage/logs/app.log` if a page ever shows a 500.
