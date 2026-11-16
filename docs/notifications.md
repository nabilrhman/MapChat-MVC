# Notifications

How MapChat tells someone about a private message or an @mention: from the
open page while MapChat is in a tab, and by Web Push when it's closed. Back to
the [README](../README.md).

## Who gets one

- **A private message** notifies the other person in the chat.
- **A room message** notifies whoever it @mentions.
- **Nobody** is notified of their own message, and a room message that
  mentions no one notifies no one.

The notification is titled with the sender's name, or "Name mentioned you",
shows the message (cut at 120 characters), and opens that chat when clicked.
A newer message from the same chat replaces the last one instead of stacking.

## Turning them on

Notifications are a setting of the browser, not the account: the bar at the
top of the chat ("Get notified of messages and mentions") and the switch on
the profile both turn them on for this browser. The browser asks for
permission the first time.

- **Allowed:** the switch stays on, and the browser subscribes to Web Push
  (`public/js/push.js`), sending its push address to `/push/subscribe`.
- **Blocked:** the switch turns off and can't be used, and its hint says to
  change it in the browser's site settings. It follows a change made there at
  once, without a reload.
- **Closed without choosing:** the switch turns back off, and the browser can
  ask again next time.

Turning the switch off unsubscribes this browser. Logging out does too: the
logout form carries the browser's push address, the server deletes it, and
the browser drops its own subscription.

## Who shows it

Two places can show a notification, and only one does for each message:

| MapChat is | Shown by |
|---|---|
| Open on the chat, in view | The page itself (`public/js/notifications.js`) |
| Open, but the chat isn't in view (another tab, or only the profile or About open) | The service worker, from a push |
| Closed | The service worker, from a push |

The service worker (`public/sw.js`) receives every push, and stays quiet
only while a chat page is in view, since that page shows its own. Without
Web Push set up, the page shows them whenever it's open, in view or not. A new
version of `sw.js` takes over at once, without waiting for every MapChat tab
to close.

## Sending a push

The chat worker (`bin/chat-worker.php`) reads new messages every second, in id
order, and hands them to `App\Push`, which works out each message's
recipients and sends to every browser they've subscribed, encrypted
(aes128gcm) and signed with the site's VAPID key. A message that saves late
with an earlier id is waited for, not skipped (`NewMessageReader`, as the chat
socket does). A push service gets 5 seconds before it's given up on, and a
browser its push service no longer knows is deleted.

Subscriptions live in `push_subscriptions`, one row per browser. A browser
belongs to whoever subscribed it last, so signing in as someone else on it
moves it over.

## Setting it up

Web Push needs a VAPID key pair, the site's identity to push services. Without
one, notifications still come while MapChat is open in a tab.

1. Make the pair once:

   ```bash
   docker compose run --rm app php -r 'require "vendor/autoload.php"; print_r(Minishlink\WebPush\VAPID::createVapidKeys());'
   ```

2. Set `VAPID_PUBLIC_KEY` and `VAPID_PRIVATE_KEY`: in `.env` locally, or in
   Render's Environment for the live site. `VAPID_SUBJECT` is optional (an
   `https://` or `mailto:` contact for push services, `APP_URL` by default).
3. Keep the pair. Browsers subscribe with the public key, so a new pair
   silently stops every existing subscription until each browser turns
   notifications on again.

Keep the private key out of the repo and out of chat logs: anyone with it can
push to everyone subscribed.

## Testing it

- **On one machine:** sign in in Chrome and turn notifications on, then
  message that account from a private window signed in as someone else.
  Switch to another tab to see the service worker show it, or close MapChat
  for a push with it closed.
- **Desktop popups:** Chrome on Linux sends them to the desktop's notification
  area; if nothing shows, check that Do Not Disturb is off.
- **iPhone and iPad:** Web Push works only once MapChat is added to the Home
  Screen.
- `tests/PushTest.php` covers who receives a push, what it says, a browser
  moving between accounts, unsubscribing and refused addresses.
