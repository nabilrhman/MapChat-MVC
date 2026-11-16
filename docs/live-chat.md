# Live chat

How MapChat's chat stays live: one WebSocket per open chat page, served by a
PHP process of its own on [amphp](https://amphp.org). Back to the
[README](../README.md).

Each open chat page keeps one WebSocket to `/ws`, on the site's own address.
Everything the chat does goes over it: new messages, sending, older pages,
read marks, typing, and who's online. Nothing polls while it's connected.

- **One reader for everyone.** The chat socket (`bin/chat-socket.php`,
  `App/ChatSocket/`) reads new messages from MySQL in id order and sends each
  to the pages allowed to see it: the open room's to everyone, a private one
  only to its sender's and recipient's pages. Every process that saves a
  message (PHP-FPM, the worker, the socket itself) signals it over local UDP,
  so it's read at once; without a signal it's read within half a second.
- **Sending.** A page sends each message with an id it made, and the server
  answers once the message is in the database. If the answer is lost, the page
  sends it again with the same id after reconnecting, and gets back the first
  message instead of a second copy (a unique key on `chats.client_id`).
- **Reconnecting.** A page says which message it has last whenever it
  connects, and is caught up from MySQL from there, a page of 100 at a time,
  before new messages go straight to it. So a dropped network, a restart or a
  deploy loses nothing, and each message shows once and in order. A page that
  can't keep up is cut and catches up the same way.
- **Falling back.** If the socket can't connect twice in a row, the page goes
  back to asking every second over HTTP, and keeps trying the socket; once it
  connects, polling stops. Both ways resume from the same last message.
- **Who's online.** Someone is online while any of their tabs has a socket
  open, and for 10 seconds after their last one closes, so a reload or a brief
  drop doesn't show them leaving. The socket pings each page every 10 seconds
  and drops one that misses three.
- **Typing.** "Typing..." goes only to the other person in a private chat,
  never the text, at most every 2 seconds, and stops after 6 without a key, on
  sending, on switching chats, or when the tab closes. MapChat and the AI
  characters show it for about as long as their reply would take to type.
- **Who's let in.** Only pages from the site itself (checked by Origin), with
  a logged-in session cookie, as the HTTP chat. Every action is the session's
  user's, checked again by the server, and logging out (or a guest account
  expiring) closes the socket within 15 seconds. Messages over 16 KB, anything
  but JSON text, and floods are refused.

## In the container

The container's main process (`docker/start.sh`) starts PHP-FPM and Apache at
once, brings the database up to date (`database/migrate.php`), then starts
the chat socket and the chat worker, and starts either again a second after
it stops. On SIGTERM, a deploy or a restart, the socket closes every page's
socket with "restarting" and the pages reconnect to the new container within
a few seconds, at random, so they don't all arrive at once. The chat worker
does what each poll used to set off: MapChat's due replies every second, and
the demo people's due lines every minute.

The AI's replies wait their turn as jobs in `mapchat_jobs`, which the chat
worker runs as they come due, each checked against the room again just before
it posts.

## On a host

The chat socket runs in the same container as the site, so it needs no
service, port or address of its own: the host's HTTPS address carries
`wss://…/ws` to Apache, which passes it on. It's one process for one
container; more than one instance would need the instances to share new
messages with each other, which it doesn't do.

How it compares with polling under load is in the [load test](load-test.md).
