"""Simulate N open MapChat chat tabs against the local stack.

Each client is one guest with its own session and one keep-alive connection,
like a browser tab: POST /app/get 1s after each reply, and /app/get-people and
/app/get-locations every 15s. Prints latency percentiles per endpoint.

usage: python3 docs/load-test.py CLIENTS SECONDS COOKIES_FILE AFTER [ROOM_MESSAGES_PER_SECOND]
       python3 docs/load-test.py --pairs [--socket] CLIENTS SECONDS COOKIES_FILE [MESSAGES_PER_SECOND]

With a room rate, clients also send messages so the whole room gets that many
a second, and each client asks for messages after the newest one it has, as
the page does, so every message is rendered once for every client.

With --pairs, clients chat privately in pairs (the first two cookies, the next
two, and so on), sending that many messages a second across all pairs. Each
client opens /app first to learn its id and the page's newest message, then
polls as messages.js does: a check right after each send that can overlap the
regular one, keeping only messages newer than the newest shown. Sending stops
after SECONDS; polling carries on a few seconds more so the last messages
arrive. Also reports how long each message took to reach the other person
(from the send to the poll that showed it), and any message that never
arrived, arrived twice, or reached someone outside the pair.

With --pairs --socket, each client is the same tab on the chat socket
instead: one WebSocket (/ws), a hello from the page's newest message, sends
over it, and messages pushed to it. No polling, and no people or map
requests (presence comes over the socket). The same pairs, rate and checks,
so the two runs compare. The send latency is the time to the socket's
"sent" answer.

PORT sets the port (default 8080). STATS names docker containers to sample
with `docker stats` every 2 s during the run, e.g. STATS="mapchat-mvc-app-1
mapchat-mvc-db-1"; the report gives each one's CPU (100% is one core) and
memory.
"""
import base64, http.client, json, os, random, re, socket, struct, subprocess, sys, threading, time, urllib.parse, uuid

HOST, PORT = 'localhost', int(os.environ.get('PORT', 8080))
pairs = '--pairs' in sys.argv
over_socket = '--socket' in sys.argv
args = [arg for arg in sys.argv[1:] if arg not in ('--pairs', '--socket')]
clients, seconds, cookies_file = int(args[0]), float(args[1]), args[2]
if pairs:
    after, room_rate = None, float(args[3]) if len(args) > 3 else 3
else:
    after, room_rate = args[3], float(args[4]) if len(args) > 4 else 0
cookies = [line.strip() for line in open(cookies_file) if line.strip()][:clients]
assert len(cookies) == clients, f'only {len(cookies)} sessions available'
assert not pairs or clients % 2 == 0, '--pairs needs an even number of clients'

# Time for the last polls to bring the last messages
DRAIN = 5

lock = threading.Lock()
results = []  # (endpoint, seconds, status)
sent = {}  # token -> (sender index, time sent), for sends the server accepted
received = {}  # (client index, token) -> times shown
leaks = []  # (client index, conversation) for messages from outside the pair
socket_errors = []  # what went wrong on the chat socket: closes, refusals
stop_sending_at = time.monotonic() + seconds
stop_at = stop_sending_at + (DRAIN if pairs else 0)



def parse_chats(html):
    """Each message as (id, conversation, body). One message at a time, so a
    body with an @mention chip in it can't run on into the next message"""
    chats = []
    for part in re.split(r'<div class="chat[ "]', html)[1:]:
        found = re.search(r'data-id="(\d+)"', part), re.search(r'data-conversation="([^"]*)"', part), \
            re.search(r'<p class="chat__body">([\s\S]*?)</p>', part)
        if all(found):
            chats.append((int(found[0][1]), found[1][1], re.sub(r'<[^>]+>', '', found[2][1])))
    return chats


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
    return body, status


def open_page(cookie):
    """The page's own id and newest message, as /app renders them"""
    conn = http.client.HTTPConnection(HOST, PORT, timeout=30)
    conn.request('GET', '/app', headers={'Cookie': cookie})
    html = conn.getresponse().read().decode()
    conn.close()
    return int(re.search(r'data-my-id="(\d+)"', html)[1]), int(re.search(r'data-last-chat-id="(\d+)"', html)[1])


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
        html, _ = post(conn, '/app/get', {'after': last_id}, cookie)
        ids = [int(i) for i in re.findall(r'data-id="(\d+)"', html)]
        if ids:
            last_id = max(ids)
        if time.monotonic() >= next_slow:
            post(conn, '/app/get-people', {'timezone': 'UTC'}, cookie)
            post(conn, '/app/get-locations', {}, cookie)
            next_slow = time.monotonic() + 15
        time.sleep(1)


class PairClient:
    """One tab in a private chat, polling the way messages.js does"""

    def __init__(self, index, cookie, page):
        self.index, self.cookie = index, cookie
        self.my_id, self.last_id = page
        self.state = threading.Lock()

    def check(self, conn):
        with self.state:
            asked = self.last_id
        html, _ = post(conn, '/app/get', {'after': asked}, self.cookie)
        self.show(html)

    def show(self, html):
        now = time.monotonic()
        chats = parse_chats(html)
        with self.state:
            # Two checks can overlap, so keep only what's newer than the newest shown
            new = [chat for chat in chats if chat[0] > self.last_id]
            if new:
                self.last_id = new[-1][0]
        with lock:
            for _, conversation, body in new:
                if conversation not in ('everyone', str(self.partner_id)):
                    leaks.append((self.index, conversation))
                if body.startswith('pair '):
                    token = body.split()[-1]
                    received.setdefault((self.index, token), []).append(now)

    def run(self):
        time.sleep(random.random())
        conn = http.client.HTTPConnection(HOST, PORT, timeout=30)
        next_slow = time.monotonic() + random.random() * 15
        send_gap = clients / room_rate if room_rate else None
        next_send = time.monotonic() + random.expovariate(1 / send_gap) if send_gap else float('inf')
        while time.monotonic() < stop_at:
            now = time.monotonic()
            if now >= next_send and now < stop_sending_at:
                token = uuid.uuid4().hex
                _, status = post(conn, '/app/send', {'body': f'pair {self.index // 2} {token}', 'to': self.partner_id},
                                 self.cookie)
                if status == 200:
                    with lock:
                        sent[token] = (self.index, now)
                    # The page checks at once after sending, alongside its regular round
                    threading.Thread(target=self.check_once).start()
                next_send = time.monotonic() + random.expovariate(1 / send_gap)
            try:
                self.check(conn)
            except Exception:
                pass
            if time.monotonic() >= next_slow:
                post(conn, '/app/get-people', {'timezone': 'UTC'}, self.cookie)
                post(conn, '/app/get-locations', {}, self.cookie)
                next_slow = time.monotonic() + 15
            time.sleep(1)

    def check_once(self):
        conn = http.client.HTTPConnection(HOST, PORT, timeout=30)
        try:
            self.check(conn)
        except Exception:
            pass
        conn.close()


class Socket:
    """A WebSocket from a page: masked text frames out, pings answered"""

    def __init__(self, cookie):
        self.sock = socket.create_connection((HOST, PORT), timeout=30)
        key = base64.b64encode(os.urandom(16)).decode()
        self.sock.sendall((f'GET /ws HTTP/1.1\r\nHost: {HOST}:{PORT}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n'
                           f'Sec-WebSocket-Key: {key}\r\nSec-WebSocket-Version: 13\r\n'
                           f'Origin: http://{HOST}:{PORT}\r\nCookie: {cookie}\r\n\r\n').encode())
        head = b''
        while b'\r\n\r\n' not in head:
            chunk = self.sock.recv(4096)
            if not chunk:
                raise ConnectionError('closed during the handshake')
            head += chunk
        head, self.unread = head.split(b'\r\n\r\n', 1)
        if b' 101 ' not in head.split(b'\r\n')[0]:
            raise ConnectionError(head.split(b'\r\n')[0].decode())
        self.write_lock = threading.Lock()

    def send_frame(self, opcode, data):
        mask = os.urandom(4)
        length = len(data)
        if length < 126:
            header = struct.pack('!BB', 0x80 | opcode, 0x80 | length)
        elif length < 65536:
            header = struct.pack('!BBH', 0x80 | opcode, 0xFE, length)
        else:
            header = struct.pack('!BBQ', 0x80 | opcode, 0xFF, length)
        masked = bytes(byte ^ mask[i % 4] for i, byte in enumerate(data))
        with self.write_lock:
            self.sock.sendall(header + mask + masked)

    def send(self, message):
        self.send_frame(0x1, json.dumps(message).encode())

    def read(self, count):
        while len(self.unread) < count:
            chunk = self.sock.recv(65536)
            if not chunk:
                raise ConnectionError('closed')
            self.unread += chunk
        data, self.unread = self.unread[:count], self.unread[count:]
        return data

    def receive(self):
        """The next message, or raises ConnectionError on close"""
        message = b''
        while True:
            first, second = self.read(2)
            length = second & 0x7F
            if length == 126:
                length = struct.unpack('!H', self.read(2))[0]
            elif length == 127:
                length = struct.unpack('!Q', self.read(8))[0]
            payload = self.read(length)
            opcode = first & 0x0F
            if opcode == 0x9:
                self.send_frame(0xA, payload)
            elif opcode == 0x8:
                code = struct.unpack('!H', payload[:2])[0] if len(payload) >= 2 else None
                raise ConnectionError(f'closed with {code}')
            elif opcode in (0x0, 0x1):
                message += payload
                if first & 0x80:
                    return json.loads(message)


class SocketPairClient(PairClient):
    """The same tab on the chat socket: nothing polls, messages are pushed"""

    def run(self):
        time.sleep(random.random())
        start = time.monotonic()
        try:
            self.socket = Socket(self.cookie)
        except Exception as error:
            with lock:
                socket_errors.append(f'connect: {error}')
            return
        with lock:
            results.append(('ws connect', time.monotonic() - start, 200))
        self.unanswered = {}  # client id -> (token, time sent)
        self.socket.send({'type': 'hello', 'after': self.last_id})
        reader = threading.Thread(target=self.listen, daemon=True)
        reader.start()

        send_gap = clients / room_rate if room_rate else None
        next_send = time.monotonic() + random.expovariate(1 / send_gap) if send_gap else float('inf')
        while time.monotonic() < stop_at:
            now = time.monotonic()
            if now >= next_send and now < stop_sending_at:
                token = uuid.uuid4().hex
                client_id = uuid.uuid4().hex
                with self.state:
                    self.unanswered[client_id] = (token, now)
                try:
                    self.socket.send({'type': 'send', 'clientId': client_id, 'body': f'pair {self.index // 2} {token}',
                                      'to': self.partner_id})
                except OSError as error:
                    with lock:
                        socket_errors.append(f'send: {error}')
                    break
                next_send = time.monotonic() + random.expovariate(1 / send_gap)
            time.sleep(0.05)
        self.socket.sock.close()

    def listen(self):
        while True:
            try:
                message = self.socket.receive()
            except (ConnectionError, OSError) as error:
                if time.monotonic() < stop_at:
                    with lock:
                        socket_errors.append(f'receive: {error}')
                return
            kind = message.get('type')
            if kind == 'chats':
                self.show(message['html'])
            elif kind in ('sent', 'refused'):
                with self.state:
                    token, sent_at = self.unanswered.pop(message['clientId'], (None, None))
                if token is None:
                    continue
                with lock:
                    results.append(('ws send', time.monotonic() - sent_at, 200 if kind == 'sent' else message['status']))
                    if kind == 'sent':
                        sent[token] = (self.index, sent_at)


samples = {}  # container -> [(cpu %, memory MB)]


def sample_stats(containers):
    """docker stats for the containers, every 2 s until the run ends"""
    units = {'B': 1 / 2**20, 'KiB': 1 / 1024, 'MiB': 1, 'GiB': 1024}
    while time.monotonic() < stop_at:
        output = subprocess.run(['docker', 'stats', '--no-stream', '--format', '{{.Name}} {{.CPUPerc}} {{.MemUsage}}', *containers],
                                capture_output=True, text=True).stdout
        for line in output.splitlines():
            name, cpu, memory = line.split()[:3]
            amount, unit = re.match(r'([\d.]+)(\w+)', memory).groups()
            samples.setdefault(name, []).append((float(cpu.rstrip('%')), float(amount) * units[unit]))
        time.sleep(2)


def pct(values, p):
    values = sorted(values)
    return values[min(len(values) - 1, int(len(values) * p))] * 1000


if pairs:
    tab_class = SocketPairClient if over_socket else PairClient
    tabs = [tab_class(i, cookie, open_page(cookie)) for i, cookie in enumerate(cookies)]
    for tab in tabs:
        tab.partner_id = tabs[tab.index ^ 1].my_id
    # Opening the pages took time; start the clock now
    stop_sending_at = time.monotonic() + seconds
    stop_at = stop_sending_at + DRAIN
    threads = [threading.Thread(target=tab.run) for tab in tabs]
else:
    threads = [threading.Thread(target=client, args=(c,)) for c in cookies]
if os.environ.get('STATS'):
    threads.append(threading.Thread(target=sample_stats, args=(os.environ['STATS'].split(),)))
began = time.monotonic()
for t in threads:
    t.start()
for t in threads:
    t.join()
elapsed = time.monotonic() - began

report = {'clients': clients, 'over': 'socket' if over_socket else 'polling', 'seconds': round(elapsed, 1), 'endpoints': {}}
for path in ['/app/get', '/app/send', '/app/get-people', '/app/get-locations', 'ws connect', 'ws send']:
    rows = [r for r in results if r[0] == path]
    if not rows:
        continue
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
if over_socket:
    report['socket_errors'] = len(socket_errors)
    report['socket_error_kinds'] = sorted(set(socket_errors))[:10]
if samples:
    report['containers'] = {name: {
        'cpu_avg_percent': round(sum(cpu for cpu, _ in rows) / len(rows), 1),
        'cpu_max_percent': round(max(cpu for cpu, _ in rows), 1),
        'memory_max_mb': round(max(memory for _, memory in rows), 1),
        'samples': len(rows),
    } for name, rows in samples.items()}

if pairs:
    # The partner must get each message once, and the sender sees it once too
    latencies, missing, duplicates = [], 0, 0
    for token, (sender, sent_at) in sent.items():
        for reader in (sender ^ 1, sender):
            shown = received.get((reader, token), [])
            if not shown:
                missing += 1
            duplicates += max(0, len(shown) - 1)
            if shown and reader != sender:
                latencies.append(shown[0] - sent_at)
    report['delivery'] = {
        'messages_sent': len(sent),
        'delivered_to_partner': len(latencies),
        'missing': missing,
        'duplicates': duplicates,
        'outside_the_pair': len(leaks),
        'p50_ms': round(pct(latencies, 0.50), 1) if latencies else None,
        'p95_ms': round(pct(latencies, 0.95), 1) if latencies else None,
        'max_ms': round(max(latencies) * 1000, 1) if latencies else None,
    }
print(json.dumps(report))
