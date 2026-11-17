# Settings

Every setting MapChat reads. Back to the [README](../README.md).

All settings come from environment variables. [`.env.example`](../.env.example) lists them with
explanations. The main ones:

| Variable | What it does |
|---|---|
| `APP_URL` | Public address of the site. Used in links inside emails, by the check that a form, a request from a page or the chat socket comes from the site itself, to mark cookies Secure when it's `https://`, and as the default `VAPID_SUBJECT`. Required on a real site: without it, links point at `http://localhost:8080` and the log says so |
| `SHOW_ERRORS` | `true` shows an error's details on the page. Leave it `false` on a real site, where errors go to the log |
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` | Database connection |
| `DB_SSL` | `true` for hosted MySQL that requires TLS |
| `DB_SSL_CA` | Optional. The CA certificates `DB_SSL` checks the database against. Default `/etc/ssl/certs/ca-certificates.crt` |
| `SECRET_KEY` | Secret for hashing login and reset tokens. Required |
| `MAILER_DSN` | Where outgoing email goes: an SMTP address, or `brevo+api://API_KEY@default`. Empty turns email off: new accounts are active at once, and a password reset says it couldn't send the email. Locally it defaults to Mailpit |
| `MAIL_FROM` | Who emails come from. Default `MapChat <no-reply@localhost>`. Brevo needs an address on a domain verified with it |
| `IPSTACK_API_KEY` | Optional. Adds ipstack as the last place to look up where a visitor is, after ipapi.co, ipwho.is and GeoJS. Last because its free plan is plain HTTP, with the key in the address |
| `DEV_FAKE_IP` | Used in place of any private or reserved IP, in any environment. Locally every visitor has one, so this public IP puts them on the map. Leave it empty on a host |
| `DEMO_TIMEZONE` | Optional. Where the demo people's day starts at midnight. Default `America/Toronto` |
| `DEMO_SPEED` | Optional, for watching the demo chat locally: `60` plays the whole day in 24 minutes. Leave empty on a real site |
| `GROQ_API_KEY` | Optional. Lets MapChat and the demo people chat back, and writes out voice messages, through Groq's free plan (console.groq.com). Without it, MapChat only greets, and the browser writes out voice messages where it can |
| `MAPCHAT_MODEL` | Optional. The Groq model they write with. Default `openai/gpt-oss-120b` |
| `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` | Optional. An OAuth client (Web application) from console.cloud.google.com/apis/credentials, with `APP_URL/login/google/callback` as its redirect URI. With both, the login page offers Continue with Google |
| `CLOUDINARY_CLOUD_NAME`, `CLOUDINARY_UPLOAD_PRESET` | The Cloudinary account and unsigned upload preset photo uploads go to. Both are needed for uploads, with no default: with either empty, uploads are off and the profile says so. Removing a photo still works |
| `CHAT_SOCKET_HOST` | Optional. The address the chat socket listens on inside the container, behind Apache's `/ws`. Default `127.0.0.1` |
| `CHAT_SOCKET_PORT` | Optional. The port the chat socket listens on. Default `8090` |
| `CHAT_PRESENCE_GRACE_SECONDS` | Optional. How long someone still shows online after their last chat page closes. Default `10` |
| `CHAT_SESSION_CHECK_SECONDS` | Optional. How often the chat socket closes the pages of people who logged out or whose guest account ran out. Default `15` |
| `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY` | Optional. The key pair for Web Push, so notifications reach people with MapChat closed. Make it once and keep it ([docs/notifications.md](notifications.md)) |
| `VAPID_SUBJECT` | Optional. Who push services can contact about the site, `https://` or `mailto:`. Default `APP_URL` |
