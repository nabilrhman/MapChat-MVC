# Load test: chat polling, and the chat socket

How many open chat tabs the local Docker stack serves, and what gives out
first. Run on 2026-11-15. The comparison of polling with the chat socket
(WebSockets) was run on 2026-11-16 and comes first.

## Polling against the chat socket: 50 private pairs (measured locally)

100 guests chatting privately in 50 pairs, sending about 3 messages a second
in all for 120 seconds, once polling as the page did (`/app/get` every
second and right after each send, `/app/get-people` and `/app/get-locations`
every 15 seconds) and once over the chat socket (one WebSocket per tab,
messages pushed, no polling). Same build, same guests, same machine, one run
right after the other. Latency is from the send to the moment the partner's
tab had the message.

**Measured on the local Docker stack only, not on Render.** Nothing here has
been run against the live site or a staging service on Render.

| | Polling | Chat socket |
|---|---:|---:|
| Messages sent | 339 | 357 |
| Sender to partner, p50 | 508 ms | 11 ms |
| Sender to partner, p95 | 968 ms | **19 ms** |
| Sender to partner, max | 1,098 ms | 1,279 ms |
| Missing / duplicated / reached someone outside the pair | 0 / 0 / 0 | 0 / 0 / 0 |
| Errors (HTTP or socket) | 0 | 0 |
| Requests a second to the server | 111 | 3.5 |
| App container CPU, avg / max | 72.6% / 115% | **1.9%** / 17.9% |
| MySQL container CPU, avg / max | 129% / 195% | **4.9%** / 37.5% |
| App container memory, max | 134 MB | 186 MB |
| Send answered, p95 (`/app/send`, or the socket's "sent") | 29 ms | 19 ms |
| Socket connect (handshake through Apache), p50 / max | | 81 / 314 ms |

What it means:

- **Polling's latency is its interval.** A message waits for the partner's
  next poll, up to a second, so p50 is about half a second and p95 nearly a
  whole one. On the socket it arrives as soon as it's saved and read.
- **Nearly all the work went.** 100 polling tabs made 111 requests a second,
  each a PHP request and several MySQL queries, mostly finding nothing. On the
  socket the server only works when something happens: CPU fell by about 97%
  in the app and 96% in MySQL.
- **Memory went up by about 50 MB:** the chat socket and the chat worker are
  two more PHP processes that stay running.
- **The socket's one slow message** (1.3 s, the max) was sent while the 100
  tabs were still connecting; 99% of sends were answered within 93 ms.
- Polling's numbers match the 2026-11-15 runs below at 100 tabs.

Setup: the local `docker compose` stack (Apache event MPM, PHP-FPM with 20
processes, the chat socket and chat worker in the same container; MySQL 8.4)
on a 24-core, 30 GB Linux machine, with another copy of the stack running
idle beside it. The load script ran on the same machine. CPU is `docker stats`
sampled every 2 seconds; 100% is one core. The raw reports are in
`docs/load-test-results/`.

Found and fixed along the way: the first socket run took 5 s to connect 100
tabs, because working out the demo people's day (`DemoChat::schedule`, about
40 ms) ran several times for every tab that connected, and the socket
process does one thing at a time. It's now worked out once a day.

### Estimates for Render (not measured)

Render's free plan has about a tenth of one CPU core. Polling at 100 tabs
used about three quarters of a core here, so it would run out there; the
socket's 2% of a core would not come near it. What a free instance does with
many open WebSockets, and how its proxy treats idle ones (the socket pings
every 10 s), has not been checked.

**Before** (Apache prefork + mod_php): up to 100 open tabs every request
came back fast (p95 under 50 ms). At 200 the app ran out of Apache workers,
not CPU: about 50 tabs waited, some for 30 seconds, and 52 polls timed out.
Each polling tab keeps a keep-alive connection open and so held one of
Apache's 150 workers.

**After** (Apache event MPM + PHP-FPM, PR #70): 200 tabs are all served
(p95 57 ms, no errors) and 400 tabs get every poll answered at p95 under
150 ms. The one remaining fault: at 400 tabs in a quiet room, 105 of about
23,000 polls (0.4%) hit a kept-alive connection Apache had just closed.

## What was measured

Each simulated client is one guest account with its own session and one
keep-alive connection, doing what an open chat tab does:

- `POST /app/get` one second after the last reply (the page's `setTimeout`)
- `POST /app/get-people` and `POST /app/get-locations` every 15 seconds

Two passes, 60 seconds per step:

- **Quiet room:** nobody sends; almost every poll is empty
- **Busy room:** clients send messages, about 1, 2, 3 and 5 a second across
  the room at 25, 50, 100 and 200 clients. Each client asks for messages
  after the newest one it has, so every message is rendered for every client

Setup: the local `docker compose` stack (Apache prefork with mod_php,
`MaxRequestWorkers 150`, `KeepAlive On`, `KeepAliveTimeout 5`; MySQL 8.4,
`max_connections 151`) on a 24-core, 30 GB Linux machine. The load script
ran on the same machine. CPU is `docker stats` sampled about every 2
seconds; 100% is one core.

## Before: prefork + mod_php (measured)

### Quiet room

| Clients | Requests/s | `/app/get` p50 | p95 | p99 | Errors | App CPU avg / max | DB CPU avg | Apache processes max |
|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| 25 | 26.8 | 20 ms | 34 ms | 419 ms | 0 | 16% / 22% | 9% | 46 |
| 50 | 53.4 | 19 ms | 35 ms | 119 ms | 0 | 33% / 43% | 16% | 91 |
| 100 | 104.8 | 24 ms | 46 ms | 101 ms | 0 | 50% / 80% | 22% | 115 |
| 200 | 159.8 | 26 ms | 45 ms | 97 ms | 51 timeouts | 87% / 116% | 34% | 154 |

### Busy room

| Clients | Messages/s sent | Requests/s | `/app/get` p50 | p95 | p99 | `/app/send` p95 | Errors | App CPU avg / max | DB CPU avg |
|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| 25 | 1.0 | 28.3 | 16 ms | 24 ms | 30 ms | 16 ms | 0 | 13% / 18% | 9% |
| 50 | 1.5 | 54.3 | 23 ms | 38 ms | 180 ms | 28 ms | 0 | 28% / 45% | 15% |
| 100 | 3.1 | 107.6 | 25 ms | 46 ms | 194 ms | 36 ms | 0 | 57% / 74% | 27% |
| 200 | 3.8 | 163.2 | 25 ms | 43 ms | 76 ms | 31 ms | 52 timeouts | 84% / 119% | 39% |

`/app/get-people` and `/app/get-locations` stayed under 13 ms p99 up to 100
clients. At 200, `/app/get-people` p95 was 29.3 s in both passes: those are
the clients waiting for a free worker.

MySQL never had more than 21 connections open.

## After: event MPM + PHP-FPM (measured)

Same script and machine, 60 seconds per step, after PR #70: Apache's event
MPM (64 threads per process, up to 1,024) in front of PHP-FPM with 20
processes always running. The test suite ran alongside the 100 and 200 steps,
which may have made those a little slower.

### Quiet room

| Clients | Requests/s | `/app/get` p50 | p95 | p99 | Errors | App CPU avg / max | DB CPU avg | MySQL connections max |
|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| 100 | 108.9 | 20 ms | 40 ms | 60 ms | 0 | 44% / 109% | 36% | 12 |
| 200 | 216.1 | 25 ms | 57 ms | 109 ms | 0 | 74% / 204% | 50% | 19 |
| 300 | 321.9 | 28 ms | 64 ms | 331 ms | 0 | 134% / 275% | 72% | 20 |
| 400 | 425.8 | 31 ms | 138 ms | 227 ms | 105 closed connections | 198% / 452% | 92% | 21 |

### Busy room (5 messages a second)

| Clients | Messages/s sent | Requests/s | `/app/get` p50 | p95 | p99 | `/app/send` p95 | Errors | App CPU avg / max | DB CPU avg |
|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| 200 | 4.6 | 219.9 | 30 ms | 60 ms | 174 ms | 37 ms | 0 | 140% / 208% | 58% |
| 400 | 4.3 | 426.8 | 37 ms | 145 ms | 243 ms | 151 ms | 0 | 331% / 498% | 105% |

`/app/get-people` and `/app/get-locations` stayed under 14 ms p95 up to 300
tabs and under 90 ms at 400. The whole app container used 100 to 136 MB.

### Tuning the event MPM

The first event MPM settings served every tab but dropped kept-alive
connections that prefork never did: the next poll on each failed. Measured
along the way (`/app/get` errors per run; the first two rows are 60 s runs,
the last two 40 s runs; raising `AsyncRequestWorkerFactor` and `StartServers`
between rows 2 and 3 barely helped):

| Setting | 200 tabs | 400 tabs |
|---|---:|---:|
| Default pool (4–20 PHP processes), 25 threads per Apache process | 504 | 1,400 |
| + never stop "spare" Apache processes | 253 | 802 |
| + PHP-FPM with all 20 processes from the start | 0 | 931 (one burst) |
| + 64 threads per Apache process (shipped) | 0 | 0 in a 40 s run, 105 in the 60 s run above |

The cause, from when the drops happened: an event MPM process closes all its
idle keep-alive connections at once when it has more open than its threads
plus `AsyncRequestWorkerFactor` times its idle threads, and threads waiting
on PHP-FPM don't count as idle. So whenever PHP was briefly short of
processes, dozens of polling tabs were cut together. Prefork has no such
rule.

## What it means

- **Before: the limit was Apache workers, not CPU or MySQL.** At 200 clients Apache
  sat at its 150 workers (154 processes counting the parent) while using
  little more than one core of 24, and `/app/get` managed 139 polls a second
  where 200 tabs would need about 190. A tab polls every second and the
  keep-alive timeout is 5 seconds, so its connection is never idle long enough
  to close and its worker serves no one else.
- **After: the limit is CPU.** At 400 tabs the app used 2 to 5 cores and
  MySQL about one, and latency rose with it; the 20 PHP processes were all
  busy at times (MySQL never passed 21 connections). This machine has 24
  cores; a host with less CPU reaches this point sooner.
- **A busy room costs little more than a quiet one.** Rendering the new
  messages into each reply didn't move p50 or p95 noticeably.
- **Occasional slow requests at every step.** Each step had a few `/app/get`
  replies of 3 to 5.5 seconds (the max column in the raw results), which pull
  p99 up in some rows. The cause wasn't checked; prefork starting new worker
  processes as connections arrive is a likely one.
- **The busy room fell short of its send rate** at 50 (1.5 of 2 a second) and
  200 (3.8 of 5): senders that were waiting on a worker sent less.

## Estimates (not measured)

- On this machine, somewhere above 400 open tabs per container before
  latency or the 0.4% of closed connections matters; 400 was the most tested.
- Render's free plan has about a tenth of one CPU core, far less than the 2
  to 5 cores used here at 400 tabs, so it will run out of CPU at a small
  fraction of these numbers. Not measured there: load-testing the live site
  would create hundreds of accounts on it.
- A shorter `KeepAliveTimeout` was not tried: with the event MPM an idle
  connection holds no worker, so it would only add a new TCP connection to
  every poll.

## Running it again

For the comparison of polling with the chat socket, steps 1 and 2 below,
then (PORT if the stack isn't on 8080; STATS names the containers to sample):

```bash
STATS="mapchat-mvc-app-1 mapchat-mvc-db-1" python3 docs/tools/load-test.py --pairs 100 120 cookies 3
STATS="mapchat-mvc-app-1 mapchat-mvc-db-1" python3 docs/tools/load-test.py --pairs --socket 100 120 cookies 3
```

For the polling steps:

1. Start the stack: `docker compose up -d`
2. Sign in N guests and keep their session cookies, one per line:

   ```bash
   for i in $(seq 200); do curl -s -o /dev/null -D - -X POST -H "Origin: http://localhost:8080" http://localhost:8080/login/guest | grep -oi 'PHPSESSID=[^;]*' | tail -1; done > cookies
   ```

3. Run a step, starting after the newest message id (last argument is the
   room's messages per second; leave it off for a quiet room):

   ```bash
   python3 docs/tools/load-test.py 100 60 cookies "$(docker compose exec -T db mysql -uroot -pmapchat mapchat -N -e 'select max(id) from chats' 2>/dev/null)" 3
   ```

4. Delete the guests afterwards, with the highest user id from before the
   test. Their messages and locations go with them:

   ```sql
   DELETE FROM sessions WHERE id IN ('<each cookie value after PHPSESSID=>');
   DELETE FROM users WHERE is_guest = 1 AND id > <highest id before the test>;
   ```
