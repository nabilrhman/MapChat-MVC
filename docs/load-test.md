# Load test: chat polling

How many open chat tabs the local Docker stack serves, and what gives out
first. Run on 2026-11-15.

**Result:** up to 100 open tabs, every request came back fast (p95 under
50 ms). At 200 the app ran out of Apache workers, not CPU: about 50 tabs
waited, some for 30 seconds, and 52 polls timed out. Each polling tab keeps a
keep-alive connection open and so holds one of Apache's 150 workers.

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

## Results (measured)

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

## What it means

- **The limit is Apache workers, not CPU or MySQL.** At 200 clients Apache
  sat at its 150 workers (154 processes counting the parent) while using
  little more than one core of 24, and `/app/get` managed 139 polls a second
  where 200 tabs would need about 190. A tab polls every second and the
  keep-alive timeout is 5 seconds, so its connection is never idle long enough
  to close and its worker serves no one else.
- **A busy room costs little more than a quiet one.** Rendering the new
  messages into each reply didn't move p50 or p95 noticeably.
- **Occasional slow requests at every step.** Each step had a few `/app/get`
  replies of 3 to 5.5 seconds (the max column in the raw results), which pull
  p99 up in some rows. The cause wasn't checked; prefork starting new worker
  processes as connections arrive is a likely one.
- **The busy room fell short of its send rate** at 50 (1.5 of 2 a second) and
  200 (3.8 of 5): senders that were waiting on a worker sent less.

## Estimates (not measured)

- About 150 open tabs per Apache container with these settings, since that is
  the worker count.
- Ways to raise it, none of them tried:
  - `KeepAliveTimeout` below the 1-second poll, so a worker is freed between
    polls (costs a new TCP connection per poll)
  - the event MPM with PHP-FPM instead of prefork with mod_php; it keeps idle
    keep-alive connections without holding a worker
  - more `MaxRequestWorkers`; MySQL's 151 connections would then be the next
    limit to watch
- Production on Render sits behind its proxy, which may handle client
  connections differently; these numbers are only for the local stack.

## Running it again

1. Start the stack: `docker compose up -d`
2. Sign in N guests and keep their session cookies, one per line:

   ```bash
   for i in $(seq 200); do curl -s -o /dev/null -D - -X POST -H "Origin: http://localhost:8080" http://localhost:8080/login/guest | grep -oi 'PHPSESSID=[^;]*' | tail -1; done > cookies
   ```

3. Run a step, starting after the newest message id (last argument is the
   room's messages per second; leave it off for a quiet room):

   ```bash
   python3 docs/load-test.py 100 60 cookies "$(docker compose exec -T db mysql -uroot -pmapchat mapchat -N -e 'select max(id) from chats' 2>/dev/null)" 3
   ```

4. Delete the guests afterwards, with the highest user id from before the
   test. Their messages and locations go with them:

   ```sql
   DELETE FROM sessions WHERE id IN ('<each cookie value after PHPSESSID=>');
   DELETE FROM users WHERE is_guest = 1 AND id > <highest id before the test>;
   ```
