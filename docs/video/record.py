"""Records the tour in the README, docs/images/mapchat-tour.mp4, from the
app running locally (docker compose up).

    MAPCHAT_SESSION=<PHPSESSID of a signed-in guest> MAPCHAT_GUEST_EMAIL=<their email> python3 docs/video/record.py

It drives headless Chrome over the DevTools protocol: the login page, pins
pointed out from the online list and a message's city, then a private chat
with Maya, one with Rafael while Maya's answer comes in, back to Maya, then
a tab dragged into another place and closed.
The pointer moves and types like a person: curved, unevenly paced, a little
past the target and back. Headless Chrome draws no pointer, so the page gets
one, a hand over anything clickable. Chrome sends a frame for each repaint,
which ffmpeg turns into 60fps, with any stretch where nothing moves cut to a
second.

The replies are real, so it needs GROQ_API_KEY in .env. The chat worker is
paused while it records, so the demo people don't talk over the tour (with
DEMO_SPEED they would); the replies' jobs are run from here every second
instead, as the worker would. It clears that guest's earlier private chats
with Maya and Rafael first, so the tour starts with none.

Needs google-chrome and ffmpeg. MAPCHAT_URL changes the address
(default http://localhost:8080).
"""
import base64, json, math, os, queue, random, shutil, socket, struct, subprocess, sys, tempfile, threading, time, urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
URL = os.environ.get('MAPCHAT_URL', 'http://localhost:8080').rstrip('/')
SESSION = os.environ.get('MAPCHAT_SESSION') or sys.exit('Set MAPCHAT_SESSION to the PHPSESSID cookie of a signed-in guest')
OUT = os.path.join(ROOT, 'docs/images/mapchat-tour.mp4')
W, H = 1440, 900
PORT = 9334
FPS = 60
# A frame unchanged for longer than this is shown for only this long
LONGEST_STILL = 1.0

# The pointer: a classic arrow, and a pointing hand over anything
# clickable. It presses in on a click, sends out a soft ring, and is put
# back where the last page left it
CURSOR_JS = """
(() => {
    const ARROW = '<svg width="24" height="30" viewBox="0 0 24 30"><path d="M4.5 3.2v19.6c0 .9 1 1.3 1.7.7l4.3-4.1 3.1 7.1c.3.6 1 .9 1.6.6l2.1-.9c.6-.3.9-1 .6-1.6l-3-6.9h6c.9 0 1.4-1.1.7-1.7L6.2 2.5c-.7-.6-1.7-.1-1.7.7z" fill="#16131f" stroke="#fff" stroke-width="1.8" stroke-linejoin="round"/></svg>';
    const HAND = '<svg width="26" height="30" viewBox="0 0 26 30"><path d="M9.2 14.5V4.6a2 2 0 0 1 4 0v8.2-1.6a2 2 0 0 1 4 0v2.2-.9a2 2 0 0 1 4 0v6.8c0 5.3-3.4 9-8.4 9h-.8c-3.2 0-5.4-1.3-7.2-4.2l-3-5a2 2 0 0 1 3.2-2.4l3 3.2z" fill="#fff" stroke="#16131f" stroke-width="1.8" stroke-linejoin="round" stroke-linecap="round"/><path d="M13.2 13v4.8M17.2 13.5v4.3" stroke="#16131f" stroke-width="1.4" stroke-linecap="round"/></svg>';
    // Where each one's tip is in its box
    const TIPS = {arrow: [4.5, 3], hand: [11.2, 2.6]};
    const start = window.name.startsWith('cursor:') ? window.name.slice(7).split(',').map(Number) : [720, 450];
    function make() {
        const cursor = document.createElement('div');
        cursor.style.cssText = 'position:fixed;left:0;top:0;z-index:2147483647;pointer-events:none;will-change:transform';
        const inner = document.createElement('div');
        inner.style.cssText = 'filter:drop-shadow(0 1.5px 2.5px rgba(20,0,60,.4));transition:transform .12s ease-out';
        cursor.appendChild(inner);
        let shape = '';
        let at = start;
        const show = (name) => {
            if (name === shape) return;
            shape = name;
            inner.innerHTML = name === 'hand' ? HAND : ARROW;
            inner.style.transformOrigin = TIPS[name].map((n) => n + 'px').join(' ');
            place(...at);
        };
        const place = (x, y) => {
            at = [x, y];
            const [tipX, tipY] = TIPS[shape];
            cursor.style.transform = `translate(${x - tipX}px, ${y - tipY}px)`;
            window.name = `cursor:${x},${y}`;
        };
        show('arrow');
        document.documentElement.appendChild(cursor);
        document.addEventListener('mousemove', (event) => {
            show(getComputedStyle(event.target).cursor === 'pointer' ? 'hand' : 'arrow');
            place(event.clientX, event.clientY);
        }, true);
        document.addEventListener('mousedown', (event) => {
            inner.style.transform = 'scale(.85)';
            setTimeout(() => { inner.style.transform = ''; }, 140);
            const ring = document.createElement('div');
            ring.style.cssText = `position:fixed;left:${event.clientX - 20}px;top:${event.clientY - 20}px;width:40px;height:40px;border-radius:50%;background:rgba(103,58,183,.22);border:2px solid rgba(103,58,183,.55);z-index:2147483646;pointer-events:none;transition:transform .5s cubic-bezier(.2,.7,.3,1),opacity .5s ease-out;transform:scale(.2)`;
            document.documentElement.appendChild(ring);
            requestAnimationFrame(() => requestAnimationFrame(() => { ring.style.transform = 'scale(1)'; ring.style.opacity = '0'; }));
            setTimeout(() => ring.remove(), 650);
        }, true);
    }
    if (document.documentElement) make(); else document.addEventListener('DOMContentLoaded', make);
})();
"""


class Browser:
    """Headless Chrome, spoken to over its DevTools WebSocket. Screencast
    frames are saved as they arrive, on their own thread"""

    def __init__(self, frames_dir):
        self.frames_dir = frames_dir
        self.frames = []
        self.profile = tempfile.mkdtemp(prefix='mapchat-record-')
        self.chrome = subprocess.Popen(['google-chrome', '--headless=new', f'--remote-debugging-port={PORT}', f'--user-data-dir={self.profile}',
                                        f'--window-size={W},{H}', '--hide-scrollbars', '--force-device-scale-factor=1', 'about:blank'],
                                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        for _ in range(50):
            try:
                page = next(p for p in json.load(urllib.request.urlopen(f'http://127.0.0.1:{PORT}/json')) if p['type'] == 'page')
                break
            except Exception:
                time.sleep(0.2)
        path = page['webSocketDebuggerUrl'].split(str(PORT), 1)[1]
        self.sock = socket.create_connection(('127.0.0.1', PORT))
        self.sock.sendall((f'GET {path} HTTP/1.1\r\nHost: 127.0.0.1:{PORT}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n'
                           f'Sec-WebSocket-Key: {base64.b64encode(os.urandom(16)).decode()}\r\nSec-WebSocket-Version: 13\r\n\r\n').encode())
        self.buffer = b''
        while b'\r\n\r\n' not in self.buffer:
            self.buffer += self.sock.recv(4096)
        self.buffer = self.buffer.split(b'\r\n\r\n', 1)[1]
        self.lock = threading.Lock()
        self.waiting = {}
        self.next_id = 0
        threading.Thread(target=self.read_messages, daemon=True).start()

    def read(self, n):
        while len(self.buffer) < n:
            self.buffer += self.sock.recv(1 << 20)
        data, self.buffer = self.buffer[:n], self.buffer[n:]
        return data

    def receive(self):
        message = b''
        while True:
            first, second = self.read(2)
            length = second & 0x7F
            if length == 126:
                length = struct.unpack('!H', self.read(2))[0]
            elif length == 127:
                length = struct.unpack('!Q', self.read(8))[0]
            message += self.read(length)
            if first & 0x80:
                return json.loads(message)

    def send(self, method, params, box=None):
        with self.lock:
            self.next_id += 1
            if box is not None:
                self.waiting[self.next_id] = box
            data = json.dumps({'id': self.next_id, 'method': method, 'params': params}).encode()
            mask = os.urandom(4)
            n = len(data)
            size = bytes([0x80 | n]) if n < 126 else bytes([0xFE]) + struct.pack('!H', n) if n < 65536 else bytes([0xFF]) + struct.pack('!Q', n)
            self.sock.sendall(bytes([0x81]) + size + mask + bytes(b ^ mask[i % 4] for i, b in enumerate(data)))

    def read_messages(self):
        while True:
            message = self.receive()
            if message.get('method') == 'Page.screencastFrame':
                params = message['params']
                # Ack first, so Chrome sends the next frame as soon as it has one
                self.send('Page.screencastFrameAck', {'sessionId': params['sessionId']})
                name = os.path.join(self.frames_dir, f'{len(self.frames):05d}.jpg')
                with open(name, 'wb') as file:
                    file.write(base64.b64decode(params['data']))
                self.frames.append((params['metadata']['timestamp'], name))
            elif message.get('id') in self.waiting:
                self.waiting.pop(message['id']).put(message)

    def call(self, method, **params):
        box = queue.Queue()
        self.send(method, params, box)
        message = box.get(timeout=30)
        if 'error' in message:
            raise RuntimeError(message['error'])
        return message.get('result', {})

    def run(self, js):
        return self.call('Runtime.evaluate', expression=js, awaitPromise=True, returnByValue=True).get('result', {}).get('value')

    def close(self):
        self.chrome.terminate()
        shutil.rmtree(self.profile, ignore_errors=True)


def app(*command):
    return subprocess.run(['docker', 'compose', 'exec', '-T', 'app', *command], cwd=ROOT, capture_output=True, check=True)


def sql(statement):
    return subprocess.run(['docker', 'compose', 'exec', '-T', 'db', 'mysql', '--default-character-set=utf8mb4', '-uroot', '-pmapchat', 'mapchat', '-N', '-e', statement],
                          cwd=ROOT, capture_output=True, check=True, text=True).stdout.strip()


class JobRunner:
    """Runs the replies' jobs every second, as the paused worker would"""

    def __init__(self):
        self.stopping = threading.Event()
        self.thread = threading.Thread(target=self.loop, daemon=True)
        self.thread.start()

    def loop(self):
        while not self.stopping.is_set():
            app('php', '-r', "require 'vendor/autoload.php'; App\\Models\\Bot::runDue();")
            self.stopping.wait(1)

    def stop(self):
        self.stopping.set()
        self.thread.join()


class Person:
    """The pointer and keyboard, at a person's pace"""

    def __init__(self, browser):
        self.browser = browser
        self.at = [W * 0.55, H * 0.6]

    def glide(self, points, seconds, holding=False):
        steps = max(6, int(seconds * FPS))
        pressed = {'button': 'left', 'buttons': 1} if holding else {}
        for i in range(1, steps + 1):
            x, y = points(i / steps)
            self.browser.call('Input.dispatchMouseEvent', type='mouseMoved', x=x, y=y, **pressed)
            time.sleep(seconds / steps)
        self.at = list(points(1))

    def drag(self, start, end):
        """Press, carry it across a little slower than a plain move, let go"""
        self.move_to(*start)
        time.sleep(random.uniform(0.15, 0.25))
        self.browser.call('Input.dispatchMouseEvent', type='mousePressed', x=start[0], y=start[1], button='left', buttons=1, clickCount=1)
        time.sleep(0.12)
        x0, y0 = start
        self.glide(lambda t: (x0 + (end[0] - x0) * (t * t * t * (10 - 15 * t + 6 * t * t)), y0 + (end[1] - y0) * (t * t * t * (10 - 15 * t + 6 * t * t)) + math.sin(math.pi * t) * 3),
                   random.uniform(0.7, 0.9), holding=True)
        time.sleep(0.15)
        self.browser.call('Input.dispatchMouseEvent', type='mouseReleased', x=end[0], y=end[1], button='left', buttons=0, clickCount=1)

    def move_to(self, x, y):
        """On a gentle curve, slow-fast-slow, longer for longer moves
        (Fitts), and a little past a far target, then back onto it"""
        x0, y0 = self.at
        dx, dy = x - x0, y - y0
        distance = math.hypot(dx, dy)
        if distance < 2:
            return
        seconds = (0.22 + 0.11 * math.log2(distance / 20 + 1)) * random.uniform(0.9, 1.15)
        overshoot = min(9, distance * 0.035) if distance > 150 else 0
        ux, uy = dx / distance, dy / distance
        end = (x + ux * overshoot + random.uniform(-2, 2), y + uy * overshoot + random.uniform(-2, 2))
        bend = distance * random.uniform(0.06, 0.16) * random.choice((-1, 1))
        c1 = (x0 + dx * 0.3 - uy * bend, y0 + dy * 0.3 + ux * bend)
        c2 = (x0 + dx * 0.75 - uy * bend * 0.5, y0 + dy * 0.75 + ux * bend * 0.5)

        def curve(t):
            s = t * t * t * (10 - 15 * t + 6 * t * t)
            m = 1 - s
            return (m ** 3 * x0 + 3 * m * m * s * c1[0] + 3 * m * s * s * c2[0] + s ** 3 * end[0],
                    m ** 3 * y0 + 3 * m * m * s * c1[1] + 3 * m * s * s * c2[1] + s ** 3 * end[1])

        self.glide(curve, seconds)
        if overshoot:
            sx, sy = self.at
            self.glide(lambda t: (sx + (x - sx) * (t * t * (3 - 2 * t)), sy + (y - sy) * (t * t * (3 - 2 * t))), random.uniform(0.12, 0.18))

    def click(self, x, y):
        self.move_to(x, y)
        time.sleep(random.uniform(0.08, 0.16))
        for kind in ('mousePressed', 'mouseReleased'):
            self.browser.call('Input.dispatchMouseEvent', type=kind, x=x, y=y, button='left', clickCount=1)
            time.sleep(0.05)

    def type(self, text):
        for ch in text:
            self.browser.call('Input.insertText', text=ch)
            pause = random.gauss(0.11, 0.035)
            if ch in ' ,?!' and random.random() < 0.25:
                pause += random.uniform(0.15, 0.4)
            time.sleep(min(0.5, max(0.04, pause)))

    def enter(self):
        for kind in ('rawKeyDown', 'keyUp'):
            self.browser.call('Input.dispatchKeyEvent', type=kind, key='Enter', code='Enter', windowsVirtualKeyCode=13)


def tour(browser):
    person = Person(browser)

    def centre(js_element):
        return browser.run(f"(() => {{ const e = {js_element}; if (!e) return null; const r = e.getBoundingClientRect(); return [r.left + r.width / 2, r.top + r.height / 2]; }})()")

    def online(name):
        return centre(f"[...document.querySelectorAll('.person')].find((p) => p.textContent.includes({json.dumps(name)}))")

    def tab(name):
        return centre(f"[...document.querySelectorAll('.chat-tab')].find((t) => t.textContent.includes({json.dumps(name)}))")

    def composer():
        return centre("document.getElementById('textarea-send')")

    def wait_for(js, seconds=40):
        for _ in range(int(seconds * 4)):
            if browser.run(js):
                return True
            time.sleep(0.25)
        return False

    def messages_from(name):
        """How many messages from them are on the page, in any conversation"""
        return browser.run(f"[...document.querySelectorAll('.chat')].filter((c) => c.querySelector('.chat__name')?.textContent.trim().toLowerCase().startsWith({json.dumps(name.lower())})).length")

    def wait_for_reply(name, before, seconds=45):
        wait_for(f"[...document.querySelectorAll('.chat')].filter((c) => c.querySelector('.chat__name')?.textContent.trim().toLowerCase().startsWith({json.dumps(name.lower())})).length > {before}", seconds)

    def close_button(name):
        return centre(f"[...document.querySelectorAll('.chat-tab')].find((t) => t.textContent.includes({json.dumps(name)}))?.querySelector('.chat-tab__close')")

    browser.call('Emulation.setDeviceMetricsOverride', width=W, height=H, deviceScaleFactor=1, mobile=False)
    browser.call('Network.enable')
    browser.call('Page.enable')
    browser.call('Page.addScriptToEvaluateOnNewDocument', source=CURSOR_JS)

    # The login page
    browser.call('Page.navigate', url=f'{URL}/login')
    time.sleep(2.5)
    browser.call('Page.startScreencast', format='jpeg', quality=90, everyNthFrame=1)
    time.sleep(1.2)
    person.move_to(*centre("document.querySelector('#guestForm button')"))
    time.sleep(1)

    # Signed in: the room, the map and who's online
    browser.call('Network.setCookie', name='PHPSESSID', value=SESSION, url=URL)
    browser.call('Page.navigate', url=f'{URL}/app')
    time.sleep(5)

    # Hovering someone online points out their pin, with their local time
    person.move_to(*online('Maya'))
    time.sleep(1.8)

    # A message's city does the same, and a click brings the pin to the middle
    city = centre("[...document.querySelectorAll('.chat__location > span')].reverse().find((c) => !/Toronto|Mountain View/.test(c.textContent))")
    if city:
        person.move_to(*city)
        time.sleep(1.3)
        person.click(*city)
        time.sleep(2.2)

    # A private chat with Maya
    person.click(*online('Maya'))
    time.sleep(1)
    from_maya = messages_from('Maya')
    person.click(*composer())
    person.type('hey maya! still up in toronto? 🌙')
    time.sleep(0.4)
    person.enter()
    time.sleep(1.5)

    # Then Rafael; Maya's answer comes in meanwhile, as a count on her tab
    person.click(*online('Rafael'))
    time.sleep(1)
    from_rafael = messages_from('Rafael')
    person.click(*composer())
    person.type("hey rafael! i'm visiting lisbon soon, any tips? ✈️")
    time.sleep(0.4)
    person.enter()
    # His "typing", then his answer
    wait_for_reply('Rafael', from_rafael)
    time.sleep(3.5)

    # Back to Maya, to read her answer and write back
    wait_for_reply('Maya', from_maya, 20)
    person.click(*tab('Maya'))
    time.sleep(3)
    from_maya = messages_from('Maya')
    person.click(*composer())
    person.type('haha same, night owls unite 🦉')
    time.sleep(0.4)
    person.enter()
    wait_for_reply('Maya', from_maya)
    time.sleep(3)

    # Tabs can be dragged into another order, and closed
    person.drag(tab('Rafael'), tab('Maya'))
    time.sleep(1.2)
    person.move_to(*close_button('Rafael'))
    time.sleep(0.6)
    person.click(*close_button('Rafael'))
    time.sleep(1.5)

    # The profile: hide your pin whenever you like
    browser.call('Page.navigate', url=f'{URL}/profile/show')
    time.sleep(1.8)
    toggle = centre("document.querySelector('.switch') || document.querySelector('input[type=checkbox]')")
    if toggle:
        person.move_to(*toggle)
        time.sleep(1.5)

    browser.call('Page.stopScreencast')
    time.sleep(0.3)


def encode(frames, workdir):
    """Each frame for as long as it was on screen, then 60fps for the video"""
    listing = os.path.join(workdir, 'frames.txt')
    with open(listing, 'w') as file:
        for (at, name), (next_at, _) in zip(frames, frames[1:] + [(frames[-1][0] + 0.8, None)]):
            file.write(f"file '{name}'\nduration {min(next_at - at, LONGEST_STILL):.4f}\n")
        file.write(f"file '{frames[-1][1]}'\n")
    subprocess.run(['ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', '-f', 'concat', '-safe', '0', '-i', listing,
                    '-vf', f'fps={FPS},scale=1280:-2:flags=lanczos,format=yuv420p', '-c:v', 'libx264', '-preset', 'slow', '-crf', '24',
                    '-movflags', '+faststart', OUT], check=True)


def clear_earlier_tour():
    """The guest's private chats with Maya and Rafael, so the tour starts
    with none. MAPCHAT_GUEST_EMAIL says whose; without it they're kept"""
    guest = os.environ.get('MAPCHAT_GUEST_EMAIL', '').replace("'", '')
    if not guest:
        return
    for character in ('maya', 'rafael'):
        sql(f"DELETE FROM chats WHERE (email = '{guest}' AND to_email = '{character}@demo.mapchat.local') OR (email = '{character}@demo.mapchat.local' AND to_email = '{guest}')")


def main():
    random.seed(7)
    workdir = tempfile.mkdtemp(prefix='mapchat-frames-')
    clear_earlier_tour()
    app('pkill', '-STOP', '-f', 'chat-worker.php')
    jobs = JobRunner()
    browser = Browser(workdir)
    try:
        tour(browser)
    finally:
        browser.close()
        jobs.stop()
        app('pkill', '-CONT', '-f', 'chat-worker.php')
    encode(browser.frames, workdir)
    shutil.rmtree(workdir, ignore_errors=True)
    print(f'{OUT}: {len(browser.frames)} frames')


main()
