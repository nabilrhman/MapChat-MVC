"""Records the tour in the README, docs/images/mapchat-tour.mp4, from the
app running locally (docker compose up).

    MAPCHAT_SESSION=<PHPSESSID of a signed-in guest> python3 docs/video/record.py

It drives headless Chrome over the DevTools protocol: logs in as that guest,
points out pins, @mentions Maya, messages Rafael privately, and shows the
profile and About pages. Headless Chrome draws no pointer, so the page gets
one. Chrome sends a frame for each repaint, which ffmpeg turns into 60fps,
with any stretch where nothing moves cut to a second.

The replies are real, so it needs GROQ_API_KEY in .env. The chat worker is
paused while it records, so the demo people don't talk over the tour (with
DEMO_SPEED they would), and the replies are run from here when they're due.

Needs google-chrome and ffmpeg. MAPCHAT_URL changes the address
(default http://localhost:8080).
"""
import base64, json, os, queue, shutil, socket, struct, subprocess, sys, tempfile, threading, time, urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
URL = os.environ.get('MAPCHAT_URL', 'http://localhost:8080').rstrip('/')
SESSION = os.environ.get('MAPCHAT_SESSION') or sys.exit('Set MAPCHAT_SESSION to the PHPSESSID cookie of a signed-in guest')
OUT = os.path.join(ROOT, 'docs/images/mapchat-tour.mp4')
W, H = 1440, 900
PORT = 9334
# A frame unchanged for longer than this is shown for only this long
LONGEST_STILL = 1.0

# The pointer: a rounded arrow that presses in on a click and sends out a
# soft ring. It's put back where the last page left it
CURSOR_JS = """
(() => {
    const ARROW = '<svg width="24" height="30" viewBox="0 0 24 30"><path d="M4.5 3.2v19.6c0 .9 1 1.3 1.7.7l4.3-4.1 3.1 7.1c.3.6 1 .9 1.6.6l2.1-.9c.6-.3.9-1 .6-1.6l-3-6.9h6c.9 0 1.4-1.1.7-1.7L6.2 2.5c-.7-.6-1.7-.1-1.7.7z" fill="#16131f" stroke="#fff" stroke-width="1.8" stroke-linejoin="round"/></svg>';
    const start = window.name.startsWith('cursor:') ? window.name.slice(7).split(',').map(Number) : [720, 450];
    function make() {
        const cursor = document.createElement('div');
        cursor.style.cssText = 'position:fixed;left:0;top:0;z-index:2147483647;pointer-events:none;will-change:transform';
        cursor.innerHTML = `<div style="filter:drop-shadow(0 2px 3px rgba(20,0,60,.35));transform-origin:4.5px 3px;transition:transform .12s ease-out">${ARROW}</div>`;
        const arrow = cursor.firstChild;
        const place = (x, y) => {
            cursor.style.transform = `translate(${x - 4.5}px, ${y - 3}px)`;
            window.name = `cursor:${x},${y}`;
        };
        place(...start);
        document.documentElement.appendChild(cursor);
        document.addEventListener('mousemove', (event) => place(event.clientX, event.clientY), true);
        document.addEventListener('mousedown', (event) => {
            arrow.style.transform = 'scale(.82)';
            setTimeout(() => { arrow.style.transform = ''; }, 140);
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

    def send(self, method, params):
        with self.lock:
            self.next_id += 1
            data = json.dumps({'id': self.next_id, 'method': method, 'params': params}).encode()
            mask = os.urandom(4)
            n = len(data)
            size = bytes([0x80 | n]) if n < 126 else bytes([0xFE]) + struct.pack('!H', n) if n < 65536 else bytes([0xFF]) + struct.pack('!Q', n)
            self.sock.sendall(bytes([0x81]) + size + mask + bytes(b ^ mask[i % 4] for i, b in enumerate(data)))
            return self.next_id

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
        with self.lock:
            # Registered before it's sent, so the answer can't arrive first
            pending = self.next_id + 1
            self.waiting[pending] = box
        assert self.send(method, params) == pending
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


def run_due_replies():
    app('php', '-r', "require 'vendor/autoload.php'; App\\Models\\Bot::runDue();")


def tour(browser):
    mouse = [W / 2, H / 2]

    def move_to(x, y, steps=60, pause=0.016):
        """Glide the pointer, eased, so hovers happen as a person's would"""
        x0, y0 = mouse
        for i in range(1, steps + 1):
            t = i / steps
            t = t * t * (3 - 2 * t)
            browser.call('Input.dispatchMouseEvent', type='mouseMoved', x=x0 + (x - x0) * t, y=y0 + (y - y0) * t)
            time.sleep(pause)
        mouse[:] = [x, y]

    def centre(selector):
        return browser.run(f"(() => {{ const e = document.querySelector({json.dumps(selector)}); if (!e) return null; const r = e.getBoundingClientRect(); return [r.left + r.width / 2, r.top + r.height / 2]; }})()")

    def click(x, y):
        move_to(x, y, steps=40)
        for kind in ('mousePressed', 'mouseReleased'):
            browser.call('Input.dispatchMouseEvent', type=kind, x=x, y=y, button='left', clickCount=1)

    def type_text(text, delay=0.06):
        for ch in text:
            browser.call('Input.insertText', text=ch)
            time.sleep(delay)

    def enter():
        for kind in ('rawKeyDown', 'keyUp'):
            browser.call('Input.dispatchKeyEvent', type=kind, key='Enter', code='Enter', windowsVirtualKeyCode=13)

    browser.call('Emulation.setDeviceMetricsOverride', width=W, height=H, deviceScaleFactor=1, mobile=False)
    browser.call('Network.enable')
    browser.call('Page.enable')
    browser.call('Page.addScriptToEvaluateOnNewDocument', source=CURSOR_JS)

    # The login page
    browser.call('Page.navigate', url=f'{URL}/login')
    time.sleep(2.5)
    browser.call('Page.startScreencast', format='jpeg', quality=90, everyNthFrame=1)
    time.sleep(1.5)
    move_to(*centre('#guestForm button'))
    time.sleep(1.2)

    # Signed in: the room, the map and who's online
    browser.call('Network.setCookie', name='PHPSESSID', value=SESSION, url=URL)
    browser.call('Page.navigate', url=f'{URL}/app')
    time.sleep(5.5)

    # Hovering someone online points out their pin, with their local time
    spot = centre('.person[data-user-id="4"]')
    if spot:
        move_to(*spot)
        time.sleep(2)

    # A message's city does the same, and a click brings the pin to the middle
    city = browser.run("""(() => {
        const e = [...document.querySelectorAll('.chat__location > span')].reverse().find((c) => !/Toronto|Mountain View/.test(c.textContent));
        if (!e) return null;
        const r = e.getBoundingClientRect();
        return [r.left + r.width / 2, r.top + r.height / 2];
    })()""")
    if city:
        move_to(*city)
        time.sleep(1.5)
        click(*city)
        time.sleep(2.5)

    # Writing to the room: @ brings up who's online, and Maya answers
    click(*centre('#textarea-send'))
    time.sleep(0.4)
    type_text('@May', 0.12)
    time.sleep(1.3)
    enter()
    time.sleep(0.3)
    type_text('how is Toronto tonight? 🌙')
    time.sleep(0.6)
    enter()
    time.sleep(12)
    run_due_replies()
    time.sleep(3)

    # A private chat: click someone in the list; "typing" shows while they write
    click(*centre('.person[data-user-id="3"]'))
    time.sleep(1.5)
    click(*centre('#textarea-send'))
    type_text('hey rafael, any tips for lisbon?')
    time.sleep(0.4)
    enter()
    time.sleep(12)
    run_due_replies()
    time.sleep(3.5)

    # The profile: hide your pin whenever you like
    browser.call('Page.navigate', url=f'{URL}/profile/show')
    time.sleep(2)
    toggle = centre('.switch') or centre('input[type=checkbox]')
    if toggle:
        move_to(*toggle)
        time.sleep(1.5)

    # About
    browser.call('Page.navigate', url=f'{URL}/about')
    time.sleep(1.5)
    for _ in range(50):
        browser.call('Input.dispatchMouseEvent', type='mouseWheel', x=W / 2, y=H / 2, deltaX=0, deltaY=40)
        time.sleep(0.03)
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
                    '-vf', 'fps=60,scale=1280:-2:flags=lanczos,format=yuv420p', '-c:v', 'libx264', '-preset', 'slow', '-crf', '24',
                    '-movflags', '+faststart', OUT], check=True)


def main():
    workdir = tempfile.mkdtemp(prefix='mapchat-frames-')
    app('pkill', '-STOP', '-f', 'chat-worker.php')
    browser = Browser(workdir)
    try:
        tour(browser)
    finally:
        browser.close()
        app('pkill', '-CONT', '-f', 'chat-worker.php')
    encode(browser.frames, workdir)
    shutil.rmtree(workdir, ignore_errors=True)
    print(f'{OUT}: {len(browser.frames)} frames')


main()
