"""Simulate N open MapChat chat tabs against the local stack.

Each client is one guest with its own session and one keep-alive connection,
like a browser tab: POST /app/get 1s after each reply, and /app/get-people and
/app/get-locations every 15s. Prints latency percentiles per endpoint.

usage: python3 docs/load-test.py CLIENTS SECONDS COOKIES_FILE AFTER_ID [ROOM_MESSAGES_PER_SECOND]

With a room rate, clients also send messages so the whole room gets that many
a second, and each client asks for messages after the newest one it has, as
the page does, so every message is rendered once for every client.
"""
import http.client, json, random, re, sys, threading, time, urllib.parse

HOST, PORT = 'localhost', 8080
clients, seconds, cookies_file, after = int(sys.argv[1]), float(sys.argv[2]), sys.argv[3], sys.argv[4]
room_rate = float(sys.argv[5]) if len(sys.argv) > 5 else 0
cookies = [line.strip() for line in open(cookies_file) if line.strip()][:clients]
assert len(cookies) == clients, f'only {len(cookies)} sessions available'

lock = threading.Lock()
results = []  # (endpoint, seconds, status)
stop_at = time.monotonic() + seconds


def post(conn, path, body, cookie):
    headers = {'Cookie': cookie, 'Origin': f'http://{HOST}:{PORT}', 'X-Requested-With': 'XMLHttpRequest',
               'Content-Type': 'application/x-www-form-urlencoded'}
    start = time.monotonic()
    try:
        conn.request('POST', path, urllib.parse.urlencode(body), headers)
        response = conn.getresponse()
        body = response.read().decode()
        status = response.status
    except Exception as error:
        body = ''
        status = type(error).__name__
        conn.close()
    with lock:
        results.append((path, time.monotonic() - start, status))
    return body


def client(cookie):
    time.sleep(random.random())  # tabs don't all open in the same millisecond
    conn = http.client.HTTPConnection(HOST, PORT, timeout=30)
    next_slow = time.monotonic() + random.random() * 15
    last_id = int(after)
    # Each client's share of the room's messages, at random moments
    send_gap = clients / room_rate if room_rate else None
    next_send = time.monotonic() + random.expovariate(1 / send_gap) if send_gap else float('inf')
    while time.monotonic() < stop_at:
        if time.monotonic() >= next_send:
            post(conn, '/app/send', {'body': f'load test {random.randrange(10**6)}'}, cookie)
            next_send = time.monotonic() + random.expovariate(1 / send_gap)
        html = post(conn, '/app/get', {'after': last_id}, cookie)
        ids = [int(i) for i in re.findall(r'data-id="(\d+)"', html)]
        if ids:
            last_id = max(ids)
        if time.monotonic() >= next_slow:
            post(conn, '/app/get-people', {'timezone': 'UTC'}, cookie)
            post(conn, '/app/get-locations', {}, cookie)
            next_slow = time.monotonic() + 15
        time.sleep(1)


threads = [threading.Thread(target=client, args=(c,)) for c in cookies]
began = time.monotonic()
for t in threads:
    t.start()
for t in threads:
    t.join()
elapsed = time.monotonic() - began


def pct(values, p):
    values = sorted(values)
    return values[min(len(values) - 1, int(len(values) * p))] * 1000


report = {'clients': clients, 'seconds': round(elapsed, 1), 'endpoints': {}}
for path in ['/app/get', '/app/send', '/app/get-people', '/app/get-locations']:
    rows = [r for r in results if r[0] == path]
    ok = [r[1] for r in rows if r[2] == 200]
    report['endpoints'][path] = {
        'requests': len(rows),
        'per_second': round(len(rows) / elapsed, 1),
        'errors': len(rows) - len(ok),
        'error_kinds': sorted({str(r[2]) for r in rows if r[2] != 200}),
        'mean_ms': round(sum(ok) / len(ok) * 1000, 1) if ok else None,
        'p50_ms': round(pct(ok, 0.50), 1) if ok else None,
        'p95_ms': round(pct(ok, 0.95), 1) if ok else None,
        'p99_ms': round(pct(ok, 0.99), 1) if ok else None,
        'max_ms': round(max(ok) * 1000, 1) if ok else None,
    }
report['total_per_second'] = round(len(results) / elapsed, 1)
print(json.dumps(report))
