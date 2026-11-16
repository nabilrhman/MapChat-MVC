# Deploy for free

Running your own MapChat at no cost. Back to the [README](../README.md).

The app runs as a Docker container on [Render](https://render.com)'s free plan,
with the database on [TiDB Cloud Starter](https://tidbcloud.com), a free
MySQL-compatible service.

1. Create a TiDB Cloud Starter cluster. In its SQL editor, run
   `CREATE DATABASE mapchat; USE mapchat;`, then the contents of `database/init.sql`,
   and, if you want the sample conversation, `database/demo.sql`.
2. Fork this repository and make it point at your site, not mine:
   - In `render.yaml`, `APP_URL` and `domains` are set to
     `mapchat.swiftsolve.net`. Put your own domain in both. Without a domain,
     delete the two `domains` lines, and once Render shows the service's
     `onrender.com` address, set `APP_URL` to it and push.
   - In `.github/workflows/keep-awake.yml`, change the address it visits to
     yours (see below).
3. In Render, choose **New > Blueprint** and pick your fork. Render reads
   `render.yaml` and asks for the settings it leaves blank:
   - `DB_HOST`, `DB_USER` and `DB_PASSWORD`, from TiDB's **Connect** dialog.
   - `MAILER_DSN` for activation and password-reset emails. Render's free plan
     blocks SMTP, so use `brevo+api://API_KEY@default`. Left empty, email is
     off: new accounts are active at once, and a password reset says it
     couldn't send the email.
   - `MAIL_FROM`, the sender, on a domain verified with Brevo, e.g.
     `MapChat <no-reply@mail.example.com>`. Brevo refuses the default,
     `MapChat <no-reply@localhost>`.
   - `GROQ_API_KEY` for MapChat and the AI characters to chat back.
   - `VAPID_PUBLIC_KEY` and `VAPID_PRIVATE_KEY` for notifications that reach
     people with MapChat closed. Make the pair once on your computer and keep
     it, since a new pair drops everyone's subscriptions:
     `docker compose run --rm app php -r 'require "vendor/autoload.php"; print_r(Minishlink\WebPush\VAPID::createVapidKeys());'`
     Without them, notifications still come while MapChat is open in a tab. More in
     [notifications.md](notifications.md).

   Only the database settings are required.
4. Photo uploads go from the browser to Cloudinary, and need both
   `CLOUDINARY_CLOUD_NAME` and `CLOUDINARY_UPLOAD_PRESET` (an unsigned upload
   preset) for your own account, set in the service's **Environment** tab.
   There's no default account: without both, uploads are off and the profile
   says so. Removing a photo and the drawn avatars still work.
5. Optional: `IPSTACK_API_KEY` adds ipstack as a last place to look up where
   a visitor is, after ipapi.co, ipwho.is and GeoJS. It comes last because its
   free plan is plain http, with the key in the address.

Free Render services sleep after 15 minutes without visitors. The `Keep awake`
workflow visits the site every 10 minutes, well within Render's 750 free hours
a month. GitHub turns scheduled workflows off in a fork, so turn them on in
your fork's **Actions** tab.

The container brings an older database up to date each time it starts
(`database/migrate.php`): it adds the tables, columns and keys it's missing,
and fills in the demo people's countries and MapChat's place on Null Island. If that fails, the site
starts anyway and the error is in the service's logs.

## A site that used the old default Cloudinary account

Earlier versions uploaded photos to a built-in Cloudinary account when the
two settings were empty. If your site relied on that, add
`CLOUDINARY_CLOUD_NAME` and `CLOUDINARY_UPLOAD_PRESET` in Render's
**Environment** tab, with the values that account used, before you deploy
this version. Otherwise photo uploads turn off.
