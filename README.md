# TÖP Bülten feed

GitHub Actions builds the Bülten feed with RSS-Bridge and publishes it to
Cloudflare Pages: https://top-bulten-feed.pages.dev/feed.xml

- Hourly full update, plus per-account updates requested from the app via
  the Bülten Worker (`workflow_dispatch` input `accounts`).
- Nothing is committed per run: each run starts from the live site.
- Accounts: `sources.json` (edited from the admin dashboard).
- Secrets: `CLOUDFLARE_API_TOKEN` (Cloudflare Pages: Edit), `CLOUDFLARE_ACCOUNT_ID`.
