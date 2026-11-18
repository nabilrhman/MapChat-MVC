/**
 * What the tour and the screenshots share: where the app is, the commands
 * run in its containers, starting the app with the settings a recording
 * needs (Continue with Google without real keys), headless Chrome drawing
 * on the GPU, and signing a new guest in.
 *
 * MAPCHAT_URL is the app's address (default http://localhost:8080).
 * MAPCHAT_ROOT is the checkout whose docker compose project runs the app
 * (default this one): set it when running from a git worktree.
 * MAPCHAT_GPU=0 draws without the GPU, and MAPCHAT_CHROME_FLAGS adds flags
 */
import {execFile} from 'node:child_process';
import {createHash} from 'node:crypto';
import {existsSync, mkdtempSync, readdirSync, writeFileSync} from 'node:fs';
import {homedir, tmpdir} from 'node:os';
import {join, resolve} from 'node:path';
import {setTimeout as sleep} from 'node:timers/promises';
import {promisify} from 'node:util';
import {type Browser, type BrowserContext, chromium, type Page} from 'playwright';

export const run = promisify(execFile);

export const REPO = resolve(import.meta.dirname, '../../..');
const COMPOSE_ROOT = process.env.MAPCHAT_ROOT ?? REPO;
export const URL = (process.env.MAPCHAT_URL ?? 'http://localhost:8080').replace(/\/+$/, '');
// Chrome draws on the GPU (through ANGLE and EGL), as software drawing
// can't keep up with a Retina-sized tour
export const USES_GPU = process.env.MAPCHAT_GPU !== '0';
const GPU_FLAGS = ['--enable-gpu', '--ignore-gpu-blocklist', '--use-gl=angle', '--use-angle=gl-egl'];
const CHROME_FLAGS = [
    ...(USES_GPU ? GPU_FLAGS : []),
    ...(process.env.MAPCHAT_CHROME_FLAGS ?? '').split(/\s+/).filter(Boolean),
];

/** Runs a command in the app's container, and gives what it printed */
export async function app(...command: string[]): Promise<string> {
    const {stdout} = await run('docker', ['compose', 'exec', '-T', 'app', ...command], {cwd: COMPOSE_ROOT});
    return stdout;
}

/** Runs docker compose for the app's project, with more compose files laid over its own */
export async function compose(args: string[], extraFiles: string[] = []): Promise<void> {
    const files = extraFiles.length ? ['-f', 'docker-compose.yml', ...extraFiles.flatMap((file) => ['-f', file])] : [];
    await run('docker', ['compose', ...files, ...args], {cwd: COMPOSE_ROOT});
}

/** Runs a statement on the app's database */
export async function sql(statement: string): Promise<string> {
    const {stdout} = await run(
        'docker',
        ['compose', 'exec', '-T', 'db', 'mysql', '--default-character-set=utf8mb4', '-uroot', '-pmapchat', 'mapchat', '-N', '-e', statement],
        {cwd: COMPOSE_ROOT},
    );
    return stdout.trim();
}

/** Polls a quarter of a second at a time until it's true; false if it never was */
export async function waitFor(isDone: () => Promise<boolean>, seconds = 40): Promise<boolean> {
    const deadline = Date.now() + seconds * 1000;
    while (Date.now() < deadline) {
        // A page that's navigating can't answer yet
        if (await isDone().catch(() => false)) {
            return true;
        }
        await sleep(250);
    }
    return false;
}

// The app container's Google keys for a recording, when it has none
const PLACEHOLDER_GOOGLE_KEYS = {
    GOOGLE_CLIENT_ID: 'tour-recording',
    GOOGLE_CLIENT_SECRET: 'tour-recording',
};

/** Whether the login page offers Continue with Google */
export async function isGoogleSignInShown(): Promise<boolean> {
    const loginPage = await fetch(`${URL}/login`).then((response) => response.text()).catch(() => '');
    return loginPage.includes('href="/login/google"');
}

/** Waits for the restarted app to answer, with the Google button or without */
async function waitForApp(isGoogleWanted: boolean): Promise<void> {
    const isReady = async () => {
        const isAnswering = await fetch(`${URL}/login`).then((response) => response.ok).catch(() => false);
        return isAnswering && await isGoogleSignInShown() === isGoogleWanted;
    };
    if (!await waitFor(isReady, 90)) {
        throw new Error(`The app didn't come back ${isGoogleWanted ? 'with' : 'without'} the Google button`);
    }
}

/**
 * Starts the app again with the settings a recording needs, when it needs
 * any: the ones given, and placeholder Google keys when it has none, so the
 * login page shows the button, as the live site's does
 *
 * @param environment More of the app's settings, by name
 * @returns Whether it did, so the app is started again as it was afterwards
 */
export async function startAppForRecording(workDir: string, environment: Record<string, string> = {}): Promise<boolean> {
    const settings = await isGoogleSignInShown() ? environment : {...environment, ...PLACEHOLDER_GOOGLE_KEYS};
    if (Object.keys(settings).length === 0) {
        return false;
    }
    const settingLines = Object.entries(settings).map(([name, value]) => `      ${name}: ${value}`);
    const overrideFile = join(workDir, 'recording.yml');
    writeFileSync(overrideFile, ['services:', '  app:', '    environment:', ...settingLines, ''].join('\n'));
    await compose(['up', '-d', '--no-deps', 'app'], [overrideFile]);
    await waitForApp(true);
    return true;
}

/**
 * Starts the app again with its own .env, as it was before
 *
 * @param hasGoogleKeys Whether its own settings show Continue with Google
 */
export async function restoreApp(hasGoogleKeys = false): Promise<void> {
    await compose(['up', '-d', '--no-deps', 'app']);
    await waitForApp(hasGoogleKeys);
}

/**
 * Starts the installed Chrome, headless. Playwright's own Ctrl+C handling
 * is off when the caller has its own, to tidy up before it exits
 */
export function launchChrome(extraFlags: string[] = [], {handlesSignals = false} = {}): Promise<Browser> {
    return chromium.launch({
        channel: 'chrome',
        headless: true,
        args: [...extraFlags, ...CHROME_FLAGS],
        handleSIGINT: !handlesSignals,
        handleSIGTERM: !handlesSignals,
    });
}

// Run in the app's container with the id as an argument, so nothing in it is read as PHP
const EMAIL_OF_USER_PHP = `require 'vendor/autoload.php';
echo App\\Models\\User::findByID((int) $argv[1])->email;`;

/**
 * Signs a new guest in from the login page the page is on, as anyone trying
 * MapChat would
 *
 * @returns Their user id, and the email the app made for them
 */
export async function signInAsNewGuest(page: Page): Promise<{id: number, email: string}> {
    await page.locator('#guest-form button').click();
    await page.waitForURL(/\/app/);
    const id = Number(await page.evaluate('myUserId'));
    const email = await app('php', '-r', EMAIL_OF_USER_PHP, '--', String(id));
    return {id, email};
}

/**
 * Playwright's own Chromium, the newest installed (npx playwright install
 * chromium), or MAPCHAT_CHROMIUM. Branded Chrome stopped loading unpacked
 * extensions in version 137, so the tour's recorder needs this one
 */
function playwrightChromium(): string {
    if (process.env.MAPCHAT_CHROMIUM) {
        return process.env.MAPCHAT_CHROMIUM;
    }
    const browsers = join(homedir(), '.cache/ms-playwright');
    const builds = readdirSync(browsers)
        .filter((name) => /^chromium-\d+$/.test(name))
        .sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]));
    const executable = builds.map((build) => join(browsers, build, 'chrome-linux64/chrome')).find((path) => existsSync(path));
    if (!executable) {
        throw new Error('No Playwright Chromium: run npx playwright install chromium');
    }
    return executable;
}

/** An unpacked extension's id: the SHA-256 of its folder, the first 32 hex digits written a to p */
function extensionIdOf(folder: string): string {
    const hex = createHash('sha256').update(folder).digest('hex').slice(0, 32);
    return [...hex].map((digit) => String.fromCharCode('a'.charCodeAt(0) + parseInt(digit, 16))).join('');
}

/**
 * Starts Playwright's Chromium, headless, with the extension in `extensionFolder`
 * loaded and let capture a tab without being clicked; `extraFlags` add to its own
 */
export function launchWithExtension(
    extensionFolder: string,
    {width, height, scale}: {width: number, height: number, scale: number},
    {handlesSignals = false, extraFlags = [] as string[]} = {},
): Promise<BrowserContext> {
    const folder = resolve(extensionFolder);
    return chromium.launchPersistentContext(mkdtempSync(join(tmpdir(), 'mapchat-chromium-')), {
        executablePath: playwrightChromium(),
        headless: true,
        viewport: {width, height},
        deviceScaleFactor: scale,
        handleSIGINT: !handlesSignals,
        handleSIGTERM: !handlesSignals,
        args: [
            `--disable-extensions-except=${folder}`,
            `--load-extension=${folder}`,
            `--allowlisted-extension-id=${extensionIdOf(folder)}`,
            '--auto-accept-this-tab-capture',
            `--window-size=${width},${height}`,
            `--force-device-scale-factor=${scale}`,
            '--hide-scrollbars',
            ...extraFlags,
            ...CHROME_FLAGS,
        ],
    });
}

/** Prints what the page draws with, so a run without the GPU shows */
export async function printRenderer(page: Page): Promise<void> {
    const renderer = await page.evaluate(() => {
        const gl = document.createElement('canvas').getContext('webgl');
        if (!gl) {
            return 'no WebGL';
        }
        const rendererInfo = gl.getExtension('WEBGL_debug_renderer_info');
        return rendererInfo ? gl.getParameter(rendererInfo.UNMASKED_RENDERER_WEBGL) : gl.getParameter(gl.RENDERER);
    });
    console.log('Drawing with', renderer);
}
