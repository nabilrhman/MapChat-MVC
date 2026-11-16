/**
 * Records the tour in the README, docs/images/mapchat-tour.mp4, from the
 * app running locally (docker compose up).
 *
 *   MAPCHAT_SESSION=<PHPSESSID of a signed-in guest> MAPCHAT_GUEST_EMAIL=<their email> npm run tour
 *
 * It drives headless Chrome with Playwright: the login page, the
 * notifications offer closed, pins pointed out from the online list and a
 * message's city, an @mention in Everyone that Lukas answers, then a private
 * chat with Maya, one with Rafael while Maya's answer comes in, back to Maya,
 * a tab dragged into another place and closed, and the profile.
 * The pointer moves and types like a person (person.ts). Headless Chrome
 * draws no pointer, so the page gets one, a hand over anything clickable
 * (pointer.ts). Chrome sends a frame for each repaint, which ffmpeg turns
 * into 60fps, with any stretch where nothing moves cut to a second.
 *
 * The replies are real, so it needs GROQ_API_KEY in .env. The chat worker is
 * paused while it records, so the demo people don't talk over the tour (with
 * DEMO_SPEED they would); the replies' jobs are run from here every second
 * instead, as the worker would. The worker is let go again however the run
 * ends, Ctrl+C included. It clears that guest's earlier private chats with
 * Maya and Rafael first, so the tour starts with none.
 *
 * Needs Chrome and ffmpeg. MAPCHAT_URL changes the address (default
 * http://localhost:8080). MAPCHAT_ROOT is the checkout whose docker compose
 * project runs the app (default this one): set it when recording from a git
 * worktree. MAPCHAT_SCALE, MAPCHAT_GPU and MAPCHAT_CHROME_FLAGS are below
 */
import {execFile} from 'node:child_process';
import {mkdtempSync, rmSync, writeFileSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join, resolve} from 'node:path';
import {promisify} from 'node:util';
import {type Browser, chromium, type Locator, type Page} from 'playwright';

import {Person, type Point, SeededRandom, waitSeconds} from './person.ts';
import {drawPointer, readThemeCursors} from './pointer.ts';

const run = promisify(execFile);

const REPO = resolve(import.meta.dirname, '../..');
const COMPOSE_ROOT = process.env.MAPCHAT_ROOT ?? REPO;
const URL = (process.env.MAPCHAT_URL ?? 'http://localhost:8080').replace(/\/+$/, '');
const SESSION = process.env.MAPCHAT_SESSION ?? '';
const GUEST_EMAIL = (process.env.MAPCHAT_GUEST_EMAIL ?? '').replaceAll("'", '');
const OUT = join(REPO, 'docs/images/mapchat-tour.mp4');
const WIDTH = 1440;
const HEIGHT = 900;
// Pixels per CSS pixel; 2 is a Retina screen, and the video is WIDTH×HEIGHT times this
const SCALE = Number(process.env.MAPCHAT_SCALE ?? 2);
// Chrome draws on the GPU (through ANGLE and EGL), as software drawing
// can't keep up at SCALE 2; MAPCHAT_GPU=0 turns it off
const USES_GPU = process.env.MAPCHAT_GPU !== '0';
const GPU_FLAGS = ['--enable-gpu', '--ignore-gpu-blocklist', '--use-gl=angle', '--use-angle=gl-egl'];
const CHROME_FLAGS = [
    ...(USES_GPU ? GPU_FLAGS : []),
    ...(process.env.MAPCHAT_CHROME_FLAGS ?? '').split(/\s+/).filter(Boolean),
];
const FPS = 60;
// A frame unchanged for longer than this is shown for only this long
const LONGEST_STILL_SECONDS = 1.0;
const SEED = 7;

/** A frame from Chrome's screencast: when it was drawn, in seconds, and its file */
type Frame = {drawnAt: number, file: string};

/** Runs a command in the app's container */
async function app(...command: string[]): Promise<string> {
    const {stdout} = await run('docker', ['compose', 'exec', '-T', 'app', ...command], {cwd: COMPOSE_ROOT});
    return stdout;
}

/** Runs a statement on the app's database */
async function sql(statement: string): Promise<string> {
    const {stdout} = await run(
        'docker',
        ['compose', 'exec', '-T', 'db', 'mysql', '--default-character-set=utf8mb4', '-uroot', '-pmapchat', 'mapchat', '-N', '-e', statement],
        {cwd: COMPOSE_ROOT},
    );
    return stdout.trim();
}

let isWorkerPaused = false;

/** Pauses the chat worker, so the demo people don't talk over the tour */
async function pauseWorker(): Promise<void> {
    isWorkerPaused = true;
    await app('pkill', '-STOP', '-f', 'chat-worker.php');
}

/** Lets the chat worker go on, once, however the run ends */
async function resumeWorker(): Promise<void> {
    if (!isWorkerPaused) {
        return;
    }
    isWorkerPaused = false;
    await app('pkill', '-CONT', '-f', 'chat-worker.php');
}

/** Runs the replies' jobs every second, as the paused worker would */
class JobRunner {
    isStopping = false;
    readonly running: Promise<void>;

    constructor() {
        this.running = this.runJobs();
    }

    async runJobs(): Promise<void> {
        while (!this.isStopping) {
            try {
                await app('php', '-r', "require 'vendor/autoload.php'; App\\Models\\Bot::runDue();");
            } catch (error) {
                console.error('Running the jobs failed:', (error as Error).message);
            }
            await waitSeconds(1);
        }
    }

    async stop(): Promise<void> {
        this.isStopping = true;
        await this.running;
    }
}

/**
 * The guest's private chats with Maya and Rafael, and what they said in the
 * room, so the tour starts afresh. Without MAPCHAT_GUEST_EMAIL they're kept
 */
async function clearEarlierTour(): Promise<void> {
    if (!GUEST_EMAIL) {
        return;
    }
    for (const character of ['maya', 'rafael']) {
        const demoEmail = `${character}@demo.mapchat.local`;
        await sql(`DELETE FROM chats WHERE (email = '${GUEST_EMAIL}' AND to_email = '${demoEmail}') OR (email = '${demoEmail}' AND to_email = '${GUEST_EMAIL}')`);
    }
    // And what they said in the room, with the answers to it
    await sql(`DELETE FROM chats WHERE id IN (SELECT posted_id FROM mapchat_jobs WHERE email = '${GUEST_EMAIL}' AND posted_id IS NOT NULL)`);
    await sql(`DELETE FROM chats WHERE email = '${GUEST_EMAIL}' AND to_email IS NULL`);
}

/** Polls a quarter of a second at a time until it's true; false if it never was */
async function waitFor(isDone: () => Promise<boolean>, seconds = 40): Promise<boolean> {
    const deadline = Date.now() + seconds * 1000;
    while (Date.now() < deadline) {
        // A page that's navigating can't answer yet
        if (await isDone().catch(() => false)) {
            return true;
        }
        await waitSeconds(0.25);
    }
    return false;
}

/** The middle of an element, or null when it isn't on the page */
async function centreOf(element: Locator): Promise<Point | null> {
    const box = await element.boundingBox({timeout: 5000}).catch(() => null);
    return box ? [box.x + box.width / 2, box.y + box.height / 2] : null;
}

/** The middle of an element the tour can't go on without */
async function requiredCentreOf(element: Locator): Promise<Point> {
    const centre = await centreOf(element);
    if (!centre) {
        throw new Error(`${element} isn't on the page`);
    }
    return centre;
}

/** The tour itself, step by step, while the screencast saves each frame */
async function tour(page: Page, person: Person, startScreencast: () => Promise<void>, stopScreencast: () => Promise<void>): Promise<void> {
    const onlinePerson = (name: string) => page.locator('.person', {hasText: name}).first();
    const chatTab = (name: string) => page.locator('.chat-tab', {hasText: name}).first();
    const composer = page.locator('#textarea-send');

    /** Waits until the map has drawn all its tiles; a blank map isn't a tour */
    const waitForMap = async () => {
        const isMapDrawn = () => page.evaluate("typeof vectorMap !== 'undefined' && !!vectorMap && vectorMap.loaded() && vectorMap.areTilesLoaded()");
        if (!await waitFor(isMapDrawn, 30)) {
            throw new Error("The map's tiles didn't load (OpenFreeMap may be slow); try again");
        }
    };

    /**
     * Clicks a person or tab, measuring again on arrival, as the list moves
     * when people come and go, until their private chat is open
     */
    const openChat = async (where: (name: string) => Locator, name: string) => {
        const isChatOpen = () => page.evaluate(
            (name) => document.querySelector('.chat-tab.is-active, .chat-tab[aria-selected=true]')?.textContent.includes(name) ?? false,
            name,
        );
        for (let attempt = 0; attempt < 3; attempt++) {
            await person.moveTo(await requiredCentreOf(where(name)));
            await waitSeconds(0.15);
            await person.click(await requiredCentreOf(where(name)));
            if (await waitFor(isChatOpen, 2)) {
                return;
            }
        }
        throw new Error(`Couldn't open the private chat with ${name}`);
    };

    /** How many messages from them are on the page, in any conversation */
    const messagesFrom = (name: string) => page.locator('.chat')
        .filter({has: page.locator('.chat__name', {hasText: new RegExp(`^${name}`, 'i')})})
        .count();

    const waitForReply = async (name: string, before: number, seconds = 45) => {
        await waitFor(async () => await messagesFrom(name) > before, seconds);
    };

    // The login page
    await page.goto(`${URL}/login`);
    await waitSeconds(2.5);
    await startScreencast();
    await waitSeconds(1.2);
    await person.moveTo(await requiredCentreOf(page.locator('#guestForm button')));
    await waitSeconds(1);

    // Signed in: the room, the map and who's online
    await page.context().addCookies([{name: 'PHPSESSID', value: SESSION, url: URL}]);
    await page.goto(`${URL}/app`);
    await waitForMap();
    await waitSeconds(1.5);

    // The offer to turn notifications on slides in; close it for the tour
    const isOfferShown = async () => await page.locator('#notify-prompt.is-shown').count() > 0;
    if (await waitFor(isOfferShown, 5)) {
        const closeOffer = page.locator('#notify-prompt-dismiss');
        await waitSeconds(1.2);
        await person.moveTo(await requiredCentreOf(closeOffer));
        await waitSeconds(0.3);
        await person.click(await requiredCentreOf(closeOffer));
        await waitSeconds(1);
    }

    // Hovering someone online points out their pin, with their local time
    await person.moveTo(await requiredCentreOf(onlinePerson('Maya')));
    await waitSeconds(1.8);

    // A message's city does the same, and a click brings the pin to the middle
    const cityLine = page.locator('.chat__location > span').filter({hasNotText: /Toronto|Mountain View/}).last();
    const city = await centreOf(cityLine);
    if (city) {
        await person.moveTo(city);
        await waitSeconds(1.3);
        await person.click(city);
        await waitSeconds(1.2);
        await waitForMap();
        await waitSeconds(1);
    }

    // In Everyone: @ brings up who's online, and Lukas answers in the room,
    // tagging the guest back
    const fromLukas = await messagesFrom('Lukas');
    await person.click(await requiredCentreOf(composer));
    await waitSeconds(0.3);
    await person.type('@Luk');
    await waitSeconds(0.8);
    // Lukas picked from the @ list with the pointer, so its hover shows
    const lukasOption = page.locator('.mention-picker__option', {hasText: 'Lukas'}).first();
    if (await waitFor(async () => await lukasOption.count() > 0, 3)) {
        await person.moveTo(await requiredCentreOf(lukasOption));
        await waitSeconds(0.7);
        await person.click(await requiredCentreOf(lukasOption));
    } else {
        await person.pressEnter();
    }
    await waitSeconds(0.4);
    // Typing goes on after the mention, wherever the click left the caret
    await composer.evaluate((textarea: HTMLTextAreaElement) => {
        textarea.focus();
        textarea.setSelectionRange(textarea.value.length, textarea.value.length);
    });
    await person.type("how's berlin this morning? ☕");
    await waitSeconds(0.5);
    await person.pressEnter();
    await waitForReply('Lukas', fromLukas, 45);
    await waitSeconds(3);

    // A private chat with Maya
    await openChat(onlinePerson, 'Maya');
    await waitSeconds(1);
    let fromMaya = await messagesFrom('Maya');
    await person.click(await requiredCentreOf(composer));
    await person.type('hey maya! still up in toronto? 🌙');
    await waitSeconds(0.4);
    await person.pressEnter();
    await waitSeconds(1.5);

    // Then Rafael; Maya's answer comes in meanwhile, as a count on her tab
    await openChat(onlinePerson, 'Rafael');
    await waitSeconds(1);
    const fromRafael = await messagesFrom('Rafael');
    await person.click(await requiredCentreOf(composer));
    await person.type("hey rafael! i'm visiting lisbon soon, any tips? ✈️");
    await waitSeconds(0.4);
    await person.pressEnter();
    // His "typing", then his answer
    await waitForReply('Rafael', fromRafael);
    await waitSeconds(3.5);

    // Back to Maya, to read her answer and write back
    await waitForReply('Maya', fromMaya, 20);
    await openChat(chatTab, 'Maya');
    await waitSeconds(3);
    fromMaya = await messagesFrom('Maya');
    await person.click(await requiredCentreOf(composer));
    await person.type('haha same, night owls unite 🦉');
    await waitSeconds(0.4);
    await person.pressEnter();
    await waitForReply('Maya', fromMaya);
    await waitSeconds(3);

    // Tabs can be dragged into another order, and closed
    await person.drag(await requiredCentreOf(chatTab('Rafael')), await requiredCentreOf(chatTab('Maya')));
    await waitSeconds(1.2);
    // Rafael's ×, measured again on arrival as the tabs settle after the drag
    const closeRafael = chatTab('Rafael').locator('.chat-tab__close');
    await person.moveTo(await requiredCentreOf(closeRafael));
    await waitSeconds(0.6);
    await person.click(await requiredCentreOf(closeRafael));
    const rafaelTabStaying = page.locator('.chat-tab:not(.is-leaving)', {hasText: 'Rafael'});
    await waitFor(async () => await rafaelTabStaying.count() === 0, 3);
    // Then away, down into the chat, so the pointer isn't left over Maya's
    // × as her tab slides into the gap
    const [x, y] = person.at;
    await person.moveTo([x + 40, y + 160]);
    await waitSeconds(1.5);

    // The profile, from the account menu: hide your pin whenever you like
    const accountMenuButton = page.locator('#account-menu-button');
    await person.click(await requiredCentreOf(accountMenuButton));
    await waitFor(async () => await accountMenuButton.getAttribute('aria-expanded') === 'true', 3);
    await waitSeconds(0.8);
    await person.click(await requiredCentreOf(page.locator('#account-menu-list a[href="/profile/show"]')));
    await page.waitForURL('**/profile/show', {waitUntil: 'load', timeout: 10_000}).catch(() => {});
    await waitSeconds(1.8);
    const pinSwitch = await centreOf(page.locator('.switch, input[type=checkbox]').first());
    if (pinSwitch) {
        await person.moveTo(pinSwitch);
        await waitSeconds(1.5);
    }

    await stopScreencast();
    await waitSeconds(0.3);
}

/** NVIDIA's hardware encoder when ffmpeg has it and the GPU's in use, x264 otherwise */
async function encoderFlags(): Promise<string[]> {
    const {stdout: encoders} = await run('ffmpeg', ['-hide_banner', '-encoders']);
    if (USES_GPU && encoders.includes('h264_nvenc')) {
        return ['-c:v', 'h264_nvenc', '-preset', 'p7', '-tune', 'hq', '-rc', 'vbr', '-cq', '27', '-b:v', '0'];
    }
    return ['-c:v', 'libx264', '-preset', 'slow', '-crf', '26'];
}

/** Each frame for as long as it was on screen, then 60fps for the video */
async function encode(frames: Frame[], workDir: string): Promise<void> {
    const lastFrame = frames.at(-1);
    if (!lastFrame) {
        throw new Error('Chrome sent no frames');
    }
    const lines: string[] = [];
    frames.forEach((frame, i) => {
        // The last frame stays a moment
        const nextDrawnAt = frames[i + 1]?.drawnAt ?? frame.drawnAt + 0.8;
        const seconds = Math.min(nextDrawnAt - frame.drawnAt, LONGEST_STILL_SECONDS);
        lines.push(`file '${frame.file}'`, `duration ${seconds.toFixed(4)}`);
    });
    // ffmpeg's concat needs the last file again for its duration to count
    lines.push(`file '${lastFrame.file}'`);
    const listing = join(workDir, 'frames.txt');
    writeFileSync(listing, `${lines.join('\n')}\n`);
    await run('ffmpeg', [
        '-hide_banner', '-loglevel', 'error', '-y', '-f', 'concat', '-safe', '0', '-i', listing,
        '-vf', `fps=${FPS},format=yuv420p`, ...await encoderFlags(),
        '-movflags', '+faststart', OUT,
    ]);
}

async function main(): Promise<void> {
    if (!SESSION) {
        console.error('Set MAPCHAT_SESSION to the PHPSESSID cookie of a signed-in guest');
        process.exit(1);
    }
    const workDir = mkdtempSync(join(tmpdir(), 'mapchat-frames-'));
    const frames: Frame[] = [];
    let browser: Browser | undefined;

    // An interrupted run still closes Chrome and lets the worker go
    for (const signal of ['SIGINT', 'SIGTERM'] as const) {
        process.once(signal, async () => {
            await browser?.close().catch(() => {});
            await resumeWorker().catch(() => {});
            rmSync(workDir, {recursive: true, force: true});
            process.exit(130);
        });
    }

    await clearEarlierTour();
    await pauseWorker();
    const jobs = new JobRunner();
    try {
        browser = await chromium.launch({
            channel: 'chrome',
            headless: true,
            args: [`--window-size=${WIDTH},${HEIGHT}`, '--hide-scrollbars', `--force-device-scale-factor=${SCALE}`, ...CHROME_FLAGS],
            // The handlers above close it and let the worker go
            handleSIGINT: false,
            handleSIGTERM: false,
        });
        const context = await browser.newContext({viewport: {width: WIDTH, height: HEIGHT}, deviceScaleFactor: SCALE});
        await context.addInitScript(drawPointer, readThemeCursors(SCALE));
        const page = await context.newPage();
        const renderer = await page.evaluate(() => {
            const gl = document.createElement('canvas').getContext('webgl');
            const rendererInfo = gl?.getExtension('WEBGL_debug_renderer_info');
            if (!gl) {
                return 'no WebGL';
            }
            return rendererInfo ? gl.getParameter(rendererInfo.UNMASKED_RENDERER_WEBGL) : gl.getParameter(gl.RENDERER);
        });
        console.log('Drawing with', renderer);

        // Every repaint, with when it was drawn, as Chrome sends it
        const devTools = await context.newCDPSession(page);
        devTools.on('Page.screencastFrame', ({data, metadata, sessionId}) => {
            // Acknowledged first, so Chrome sends the next frame as soon as it has one
            devTools.send('Page.screencastFrameAck', {sessionId}).catch(() => {});
            const file = join(workDir, `${String(frames.length).padStart(5, '0')}.jpg`);
            writeFileSync(file, Buffer.from(data, 'base64'));
            frames.push({drawnAt: metadata.timestamp ?? 0, file});
        });
        const startScreencast = async () => {
            await devTools.send('Page.startScreencast', {
                format: 'jpeg', quality: 92, maxWidth: Math.round(WIDTH * SCALE), maxHeight: Math.round(HEIGHT * SCALE), everyNthFrame: 1,
            });
        };
        const stopScreencast = async () => {
            await devTools.send('Page.stopScreencast');
        };

        const person = new Person(page, new SeededRandom(SEED), [WIDTH * 0.55, HEIGHT * 0.6]);
        await tour(page, person, startScreencast, stopScreencast);
    } finally {
        await browser?.close();
        await jobs.stop();
        await resumeWorker();
    }

    await encode(frames, workDir);
    rmSync(workDir, {recursive: true, force: true});
    console.log(`${OUT}: ${frames.length} frames`);
}

await main();
