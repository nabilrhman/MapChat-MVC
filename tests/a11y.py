"""Checks every page for accessibility problems with axe-core, at desktop and
phone widths, in headless Google Chrome against the app at localhost:8080
(or MAPCHAT_URL). Signs in a new guest for the pages behind the login.

    python3 tests/a11y.py

Prints each problem axe finds, and exits 1 if there are any
"""
import base64, json, os, socket, struct, subprocess, sys, tempfile, time, urllib.request

URL = os.environ.get('MAPCHAT_URL', 'http://localhost:8080').rstrip('/')
AXE_URL = 'https://cdnjs.cloudflare.com/ajax/libs/axe-core/4.10.2/axe.min.js'
PORT = 9337
WIDTHS = {'desktop': (1280, 800), 'phone': (390, 844)}

# Before signing in, then as a guest
PUBLIC_PAGES = ['/login', '/signup/new', '/password/forgot']
SIGNED_IN_PAGES = ['/app', '/profile/show', '/about']


class Chrome:
    """Headless Chrome over its DevTools WebSocket, one page"""

    def __init__(self):
        self.profile = tempfile.mkdtemp(prefix='mapchat-a11y-')
        self.process = subprocess.Popen(
            ['google-chrome', '--headless=new', f'--remote-debugging-port={PORT}', f'--user-data-dir={self.profile}', 'about:blank'],
            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        page = self.first_page()
        path = page['webSocketDebuggerUrl'].split(str(PORT), 1)[1]
        self.sock = socket.create_connection(('127.0.0.1', PORT))
        key = base64.b64encode(os.urandom(16)).decode()
        self.sock.sendall((f'GET {path} HTTP/1.1\r\nHost: 127.0.0.1:{PORT}\r\nUpgrade: websocket\r\n'
                           f'Connection: Upgrade\r\nSec-WebSocket-Key: {key}\r\nSec-WebSocket-Version: 13\r\n\r\n').encode())
        self.buffer = b''
        while b'\r\n\r\n' not in self.buffer:
            self.buffer += self.sock.recv(4096)
        self.buffer = self.buffer.split(b'\r\n\r\n', 1)[1]
        self.next_id = 0

    def first_page(self):
        for _ in range(50):
            try:
                pages = json.load(urllib.request.urlopen(f'http://127.0.0.1:{PORT}/json'))
                return next(page for page in pages if page['type'] == 'page')
            except Exception:
                time.sleep(0.2)
        sys.exit('Chrome did not start')

    def read(self, count):
        while len(self.buffer) < count:
            self.buffer += self.sock.recv(1 << 20)
        data, self.buffer = self.buffer[:count], self.buffer[count:]
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

    def call(self, method, **params):
        self.next_id += 1
        data = json.dumps({'id': self.next_id, 'method': method, 'params': params}).encode()
        mask = os.urandom(4)
        size = len(data)
        header = bytes([0x80 | size]) if size < 126 else bytes([0xFE]) + struct.pack('!H', size) if size < 65536 \
            else bytes([0xFF]) + struct.pack('!Q', size)
        self.sock.sendall(bytes([0x81]) + header + mask + bytes(byte ^ mask[i % 4] for i, byte in enumerate(data)))
        while True:
            message = self.receive()
            if message.get('id') == self.next_id:
                if 'error' in message:
                    raise RuntimeError(f"{method}: {message['error']}")
                return message.get('result', {})

    def run(self, script):
        result = self.call('Runtime.evaluate', expression=script, awaitPromise=True, returnByValue=True)
        if 'exceptionDetails' in result:
            raise RuntimeError(result['exceptionDetails'].get('exception', {}).get('description', result['exceptionDetails']))
        return result.get('result', {}).get('value')

    def go(self, path, settle=3):
        self.call('Page.navigate', url=URL + path)
        time.sleep(settle)

    def close(self):
        self.process.terminate()


def problems_on(chrome, axe):
    """What axe finds on the page as it is, one line each"""
    chrome.run(axe)
    found = chrome.run("axe.run(document, {resultTypes: ['violations']}).then(r => r.violations.map(v => ({"
                       "id: v.id, impact: v.impact, help: v.help, where: v.nodes.slice(0, 3).map(n => n.target.join(' '))})))")
    return [f"{p['impact']}: {p['id']}: {p['help']} ({', '.join(p['where'])})" for p in found]


def main():
    axe = urllib.request.urlopen(AXE_URL).read().decode()
    chrome = Chrome()
    total = 0
    try:
        chrome.call('Page.enable')
        # axe runs from DevTools, which the page's own policy doesn't cover
        chrome.call('Page.setBypassCSP', enabled=True)
        for width_name, (width, height) in WIDTHS.items():
            chrome.call('Emulation.setDeviceMetricsOverride', width=width, height=height, deviceScaleFactor=1,
                        mobile=width_name == 'phone')
            chrome.call('Network.clearBrowserCookies')
            pages = PUBLIC_PAGES + ['sign in'] + SIGNED_IN_PAGES
            for path in pages:
                if path == 'sign in':
                    chrome.go('/login')
                    chrome.run("document.querySelector('#guestForm button').click()")
                    time.sleep(5)
                    continue
                chrome.go(path, settle=4 if path == '/app' else 2.5)
                found = problems_on(chrome, axe)
                total += len(found)
                print(f'{width_name} {path}: {"no problems" if not found else ""}')
                for line in found:
                    print(f'    {line}')
    finally:
        chrome.close()
    sys.exit(1 if total else 0)


if __name__ == '__main__':
    main()
