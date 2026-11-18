# How it's built

Each part of MapChat and what it's made with. Back to the
[README](../README.md).

![A request goes from public/index.php to the router and a controller, which reads from MySQL through a model and answers with a Twig view or JSON. The browser then opens a WebSocket at /ws, which Apache passes to the chat socket's Hub, which also hears from the chat worker](images/request-path.svg)

- **Framework:** a model-view-controller (MVC) framework written from scratch in
  PHP 8.4, in `Core/`. The app itself is in `App/`.
- **Database:** MySQL, queried with prepared statements to block SQL injection.
- **Views:** Twig 3 templates, which escape everything users type to block
  cross-site scripting. A strict Content-Security-Policy backs that up: a
  script runs only if it carries the page's one-time nonce, or a script that
  did loaded it (`Core/ContentSecurityPolicy.php`).
- **Requests:** a form, a request from a page or the chat socket that changes
  anything must say it comes from the site itself (its Origin or Referer),
  which blocks cross-site request forgery.
- **Front end:** the site's own CSS, in Material 3's colours and states
  (generated from MapChat's purple) with square corners, and Beer CSS for
  the form fields, scoped so it styles nothing else. Plain JavaScript with no
  jQuery, and axios for requests to the server.
- **Live chat:** one WebSocket per open chat page, served by a PHP process of
  its own on [amphp](https://amphp.org); see [live-chat.md](live-chat.md).
- **Notifications:** while a chat page is in view it shows them itself; with
  MapChat closed they come by Web Push, sent by the chat worker with
  [minishlink/web-push](https://github.com/web-push-libs/web-push-php) and
  shown by a service worker, `public/service-worker.js`. Signed with VAPID
  keys; without them, only an open page notifies. How it all works, and setting it up, is in
  [docs/notifications.md](notifications.md).
- **Icons:** the Solid weight of [Heroicons](https://heroicons.com) by
  Tailwind Labs, under the MIT licence, in one sprite, `public/icons.svg`. The GitHub mark is from Simple Icons.
- **Map:** MapLibre with OpenStreetMap data from OpenFreeMap, styled to match
  MapChat's colours. Browsers without WebGL get OpenStreetMap tiles through
  Leaflet instead, recoloured to the same theme in the browser. No API key is
  needed for either.
- **AI replies:** Groq's chat API, run by the chat worker; see
  [ai-chat.md](ai-chat.md).
- **Location:** looked up from the IP address someone logs in from, which is
  kept with their account. ipapi.co, ipwho.is and GeoJS are tried in turn,
  then ipstack when `IPSTACK_API_KEY` is set. The place is stored and shown
  rounded to two decimal places, about a kilometre.
- **Email:** Symfony Mailer, sending through any SMTP provider or Brevo's API.
- **Signing in with Google:** OpenID Connect, by hand in `App/SignIn/GoogleSignIn.php`,
  with a state, a nonce and PKCE. The ID token comes straight from Google's
  token endpoint, so its claims are checked but not its signature, as the
  standard allows.
- **Sessions:** stored in MySQL rather than files, so restarting or waking up
  the container doesn't log anyone out.
- **Avatars:** uploaded from the browser straight to the site's own
  Cloudinary account. Without one, uploads are off and everyone keeps a
  drawn avatar.
- **Hosting:** a Docker image with Apache, PHP-FPM, the chat socket and the
  chat worker.
