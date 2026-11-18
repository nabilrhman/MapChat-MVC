/**
 * Records the tour in the README, docs/images/mapchat-tour.mp4, from the
 * app running locally (docker compose up).
 *
 *   npm run tour
 *
 * It signs a new guest in first. To record as a guest you already have, set
 * MAPCHAT_SESSION to their PHPSESSID cookie and MAPCHAT_GUEST_EMAIL to their
 * email; their earlier private chats with Maya and Rafael are cleared.
 *
 * It drives headless Chrome with Playwright: the login page, the
 * notifications offer closed, pins pointed out from the online list and a
 * message's city, an @mention in Everyone that Lukas answers, then a private
 * chat with Maya, one with Rafael while Maya's answer comes in, back to Maya,
 * a tab dragged into another place and closed, and the profile, scrolled to
 * its bottom. Each emoji in a message is picked from the emoji menu, and one
 * message to Rafael is voice typed: Chrome plays docs/tools/video/voice-line.wav as
 * the microphone (npm run voice-line makes it), and Whisper writes it out.
 * The pointer moves and types like a person (person.ts). Headless Chrome
 * draws no pointer, so the page gets one, a hand over anything clickable
 * (pointer.ts).
 *
 * The tab is recorded at 2880×1800 and 60fps through a small extension
 * (capture-extension/): Chrome captures the tab itself and encodes it with its
 * real-time VP8, streaming it here over a WebSocket (websocket.ts). ffmpeg
 * then drops repeated frames, cuts any stretch where nothing moves to a
 * second, and encodes 60fps H.264 on the GPU. Chrome's screencast, the old
 * way, couldn't keep up at that size: about 40fps, for a JPEG per frame.
 *
 * The replies are real, so it needs GROQ_API_KEY in .env. The chat worker is
 * paused while it records, so the demo people don't talk over the tour (with
 * DEMO_SPEED they would); the replies' jobs are run from here every second
 * instead, as the worker would. The worker is let go again however the run
 * ends, Ctrl+C included.
 *
 * The login page shows Continue with Google, as the live site does: when
 * the local app has no Google keys, the app container is started again for
 * the recording with placeholder ones (the tour never clicks it), and once
 * more afterwards as it was.
 *
 * It prints each stage as it starts, after the time the run has taken so
 * far, as a run takes a few minutes and waits on real replies; then the rate
 * the recording kept while things moved. Needs
 * Playwright's Chromium (npx playwright install chromium; branded Chrome won't
 * load the extension), ffmpeg, and NVIDIA's NVENC for the GPU encode (x264
 * otherwise). MAPCHAT_SCALE is below; MAPCHAT_CHROMIUM, MAPCHAT_URL, MAPCHAT_ROOT
 * (set it when recording from a git worktree), MAPCHAT_GPU and
 * MAPCHAT_CHROME_FLAGS are in chrome.ts
 */
import {appendFileSync, existsSync, mkdtempSync, rmSync} from 'node:fs';
import {createServer} from 'node:http';
import type {AddressInfo} from 'node:net';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import type {BrowserContext, Locator, Page} from 'playwright';

import {
    app, launchChrome, launchWithExtension, printRenderer, REPO, restoreApp, run, showGoogleSignIn, signInAsNewGuest, sql, URL, USES_GPU, waitFor,
} from './chrome.ts';
import {Person, type Point, SeededRandom, waitSeconds} from './person.ts';
import {drawPointer, readThemeCursors} from './pointer.ts';
import {VOICE_LINE_FILE} from './voice-line.ts';
import {receiveBinaryMessages} from './websocket.ts';

/** The guest the tour signs in as: their session cookie and email */
type Guest = {session: string, email: string};

const GIVEN_GUEST: Guest = {
    session: process.env.MAPCHAT_SESSION ?? '',
    email: (process.env.MAPCHAT_GUEST_EMAIL ?? '').replaceAll("'", ''),
};
const OUT = join(REPO, 'docs/images/mapchat-tour.mp4');
const WIDTH = 1440;
const HEIGHT = 900;
// Pixels per CSS pixel, and the video is WIDTH×HEIGHT times this; 2 is a Retina screen
const SCALE = Number(process.env.MAPCHAT_SCALE ?? 2);
const CAPTURE_EXTENSION = join(import.meta.dirname, 'capture-extension');
// Chrome's VP8 is only the way out of the browser: high enough that the GPU's
// encode, which makes the video, starts from near what was on screen
const CAPTURE_BITS_PER_SECOND = 60_000_000;
const FPS = 60;
// A frame unchanged for longer than this is shown for only this long
const LONGEST_STILL_SECONDS = 1.0;
const SEED = 7;

// A gap between two frames shorter than this is the page moving, not resting
const MOTION_GAP_SECONDS = 0.1;

// When the run started (ms), for the elapsed time on each progress line
const RUN_STARTED_AT = Date.now();

/** Prints how long the run has taken so far and what it's doing now, e.g. "1:05 a private chat with Maya" */
function printProgress(stage: string): void {
    const seconds = Math.round((Date.now() - RUN_STARTED_AT) / 1000);
    const elapsed = `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
    console.log(`${elapsed} ${stage}`);
}

let isWorkerPaused = false;
// When the tab's recording started and the voice line began playing into the
// mic, by this script's clock (ms), so the voice can be put back in the video
let recordingStartedAt = 0;
let voiceStartedAt: number | null = null;

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
 * Takes a tour guest's private chats with Maya and Rafael, and what they said
 * in the room with the answers to it, out of the chat: before a run, so a
 * given guest starts afresh, and after, so the next pictures don't show this
 * run's. Without an email there's nothing to clear
 */
async function clearTourMessages(guestEmail: string): Promise<void> {
    if (!guestEmail) {
        return;
    }
    for (const character of ['maya', 'rafael']) {
        const demoEmail = `${character}@demo.mapchat.local`;
        await sql(`DELETE FROM chats WHERE (email = '${guestEmail}' AND to_email = '${demoEmail}') OR (email = '${demoEmail}' AND to_email = '${guestEmail}')`);
    }
    // And what they said in the room, with the answers to it
    await sql(`DELETE FROM chats WHERE id IN (SELECT posted_id FROM mapchat_jobs WHERE email = '${guestEmail}' AND posted_id IS NOT NULL)`);
    await sql(`DELETE FROM chats WHERE email = '${guestEmail}' AND to_email IS NULL`);
}

/** A new guest, signed in from the login page in a browser of its own */
async function signInNewGuest(): Promise<Guest> {
    const browser = await launchChrome();
    try {
        const context = await browser.newContext();
        const page = await context.newPage();
        await page.goto(`${URL}/login`);
        const {email} = await signInAsNewGuest(page);
        const session = (await context.cookies()).find((cookie) => cookie.name === 'PHPSESSID')?.value ?? '';
        return {session, email};
    } finally {
        await browser.close();
    }
}

/** The frames a second a video has while its picture moves, from when each frame shows */
async function motionFps(video: string): Promise<number> {
    const {stdout} = await run('ffprobe', ['-v', 'error', '-select_streams', 'v', '-show_entries', 'frame=pts_time', '-of', 'csv=p=0', video], {maxBuffer: 64 * 1024 * 1024});
    const times = stdout.split('\n').map(Number).filter((time) => !Number.isNaN(time) && time > 0);
    const gaps = times.slice(1).map((time, i) => time - times[i]).filter((gap) => gap > 0 && gap < MOTION_GAP_SECONDS);
    const seconds = gaps.reduce((total, gap) => total + gap, 0);
    return seconds ? gaps.length / seconds : 0;
}

// An emoji: a pictograph, with the variation selector some carry (✈️)
const EMOJI = /(\p{Extended_Pictographic}\uFE0F?)/u;

/** Puts the caret at the end of the message box, wherever a click left it */
async function caretToEnd(composer: Locator): Promise<void> {
    await composer.evaluate((textarea: HTMLTextAreaElement) => {
        textarea.focus();
        textarea.setSelectionRange(textarea.value.length, textarea.value.length);
    });
}

/**
 * Picks an emoji from the emoji menu as a person would: opens it, scrolls to
 * the emoji, clicks it, then clicks back into the box, which closes the menu
 */
async function pickEmoji(page: Page, person: Person, composer: Locator, emoji: string): Promise<void> {
    await person.click(await requiredCentreOf(page.locator('#emoji-toggle')));
    const option = page.locator('#emoji-picker [data-part="emoji"]').filter({hasText: new RegExp(`^${emoji}$`, 'u')}).first();
    if (!await waitFor(async () => await option.isVisible(), 3)) {
        throw new Error(`${emoji} isn't in the emoji menu`);
    }
    await waitSeconds(0.5);
    await option.evaluate((button) => button.scrollIntoView({block: 'nearest', behavior: 'smooth'}));
    await waitSeconds(0.8);
    await person.moveTo(await requiredCentreOf(option));
    await waitSeconds(0.4);
    await person.click(await requiredCentreOf(option));
    await waitSeconds(0.5);
    await person.click(await requiredCentreOf(composer));
    await caretToEnd(composer);
}

/** Types a message, picking each emoji in it from the emoji menu */
async function writeMessage(page: Page, person: Person, composer: Locator, message: string): Promise<void> {
    for (const part of message.split(EMOJI)) {
        if (EMOJI.test(part)) {
            await pickEmoji(page, person, composer, part);
        } else if (part) {
            await person.type(part);
        }
    }
}

/** How long the voice line runs, in seconds */
async function voiceLineSeconds(): Promise<number> {
    const {stdout} = await run('ffprobe', ['-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', VOICE_LINE_FILE]);
    return Number(stdout.trim());
}

/**
 * Says a message with voice typing: the mic is pressed, the voice line plays
 * into it while its wave moves, Stop (Send, while it listens) is pressed, and
 * once Whisper has written it into the box, it's sent
 */
async function speakMessage(page: Page, person: Person, composer: Locator): Promise<void> {
    // Granted only now: granting any permission denies the rest, notifications
    // too, and the page offers those only while they haven't been asked
    await page.context().grantPermissions(['microphone'], {origin: URL});
    const mic = page.locator('#voice-button');
    await person.moveTo(await requiredCentreOf(mic));
    await waitSeconds(0.4);
    await person.click(await requiredCentreOf(mic));
    // The fake mic plays the line from when the click opens it
    voiceStartedAt = performance.now();
    if (!await waitFor(async () => await mic.getAttribute('aria-pressed') === 'true', 5)) {
        throw new Error("The mic didn't start listening");
    }
    await waitSeconds(await voiceLineSeconds() + 0.5);

    const stop = page.locator('#button-send');
    await person.moveTo(await requiredCentreOf(stop));
    await waitSeconds(0.3);
    await person.click(await requiredCentreOf(stop));
    if (!await waitFor(async () => (await composer.inputValue()).trim() !== '', 30)) {
        throw new Error("Whisper didn't write the voice line into the box");
    }
    await waitSeconds(1.5);
    await person.pressEnter();
}

/** Scrolls the page down to its bottom with the mouse wheel, a notch at a time */
async function scrollToBottom(page: Page): Promise<void> {
    const distance = await page.evaluate(() => document.documentElement.scrollHeight - window.innerHeight - window.scrollY);
    for (let scrolled = 0; scrolled < distance; scrolled += 100) {
        await page.mouse.wheel(0, 100);
        await waitSeconds(0.12);
    }
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
async function tour(page: Page, person: Person, guest: Guest, startCapture: () => Promise<void>, stopCapture: () => Promise<void>): Promise<void> {
    const onlinePerson = (name: string) => page.locator('[data-part="person"]', {hasText: name}).first();
    const chatTab = (name: string) => page.locator('[data-part="tab"]', {hasText: name}).first();
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
            (name) => document.querySelector('[data-part="tab"].is-active')?.textContent.includes(name) ?? false,
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
    const messagesFrom = (name: string) => page.locator('[data-part="message"]')
        .filter({has: page.locator('[data-part="sender-name"]', {hasText: new RegExp(`^${name}`, 'i')})})
        .count();

    const waitForReply = async (name: string, before: number, seconds = 45) => {
        await waitFor(async () => await messagesFrom(name) > before, seconds);
    };

    printProgress('the login page');
    // The login page
    await page.goto(`${URL}/login`);
    await waitSeconds(2.5);
    await startCapture();
    await waitSeconds(1.2);
    await person.moveTo(await requiredCentreOf(page.locator('#guest-form button')));
    await waitSeconds(1);

    printProgress('the room');
    // Signed in: the room, the map and who's online
    await page.context().addCookies([{name: 'PHPSESSID', value: guest.session, url: URL}]);
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

    printProgress('a pin pointed out from the online list');
    // Hovering someone online points out their pin, with their local time
    await person.moveTo(await requiredCentreOf(onlinePerson('Maya')));
    await waitSeconds(1.8);

    printProgress('a city clicked, its pin flown to the middle');
    // A message's city does the same, and a click brings the pin to the
    // middle. The newest city whose sender has a pin, so the map flies:
    // MapChat greets the guest from Null Island, which has none
    const pinnedCityIndex = await page.evaluate(() => [...document.querySelectorAll('[data-part="city"]')].findLastIndex((cityButton) => {
        const senderId = cityButton.closest('[data-part="message"]').dataset.senderId;
        return pinLabelsByUserId.has(senderId) && !/Toronto|Mountain View/.test(cityButton.textContent);
    }));
    if (pinnedCityIndex < 0) {
        throw new Error('No message in the room from someone with a pin: is the demo running?');
    }
    const cityLine = page.locator('[data-part="city"]').nth(pinnedCityIndex);
    await cityLine.scrollIntoViewIfNeeded();
    // The room's list can still scroll to its newest message, which once
    // slid the city away under the pointer, so the click missed it
    const isCityStill = async () => {
        const before = await requiredCentreOf(cityLine);
        await waitSeconds(0.4);
        const after = await requiredCentreOf(cityLine);
        return before.y === after.y;
    };
    await waitFor(isCityStill, 10);
    await person.moveTo(await requiredCentreOf(cityLine));
    await waitSeconds(1.3);
    const city = await requiredCentreOf(cityLine);
    await person.moveTo(city);
    const firstPinAt = () => page.locator('[data-part="map-pin"]').first().boundingBox();
    const pinBeforeClick = await firstPinAt();
    await person.click(city);
    await waitSeconds(1.2);
    await waitForMap();
    const pinAfterClick = await firstPinAt();
    if (pinBeforeClick?.x === pinAfterClick?.x && pinBeforeClick?.y === pinAfterClick?.y) {
        throw new Error("The city click didn't move the map");
    }
    await waitSeconds(1);

    printProgress('an @mention that Lukas answers');
    // In Everyone: @ brings up who's online, and Lukas answers in the room,
    // tagging the guest back
    const fromLukas = await messagesFrom('Lukas');
    await person.click(await requiredCentreOf(composer));
    await waitSeconds(0.3);
    // @ on its own first, so the list of everyone online shows, then it
    // narrows to Lukas
    await person.type('@');
    await waitSeconds(1.4);
    await person.type('Luk');
    await waitSeconds(0.8);
    // Lukas picked from the @ list with the pointer, so its hover shows
    const lukasOption = page.locator('#mention-picker [role="option"]', {hasText: 'Lukas'}).first();
    if (await waitFor(async () => await lukasOption.count() > 0, 3)) {
        await person.moveTo(await requiredCentreOf(lukasOption));
        await waitSeconds(0.7);
        await person.click(await requiredCentreOf(lukasOption));
    } else {
        await person.pressEnter();
    }
    await waitSeconds(0.4);
    // Typing goes on after the mention, wherever the click left the caret
    await caretToEnd(composer);
    await writeMessage(page, person, composer, "how's berlin this morning? ☕");
    await waitSeconds(0.5);
    await person.pressEnter();
    await waitForReply('Lukas', fromLukas, 45);
    await waitSeconds(3);

    printProgress('a private chat with Maya');
    // A private chat with Maya
    await openChat(onlinePerson, 'Maya');
    await waitSeconds(1);
    let fromMaya = await messagesFrom('Maya');
    await person.click(await requiredCentreOf(composer));
    await writeMessage(page, person, composer, 'hey maya! still up in toronto? 🌙');
    await waitSeconds(0.4);
    await person.pressEnter();
    await waitSeconds(1.5);

    printProgress('a private chat with Rafael');
    // Then Rafael; Maya's answer comes in meanwhile, as a count on her tab
    await openChat(onlinePerson, 'Rafael');
    await waitSeconds(1);
    const fromRafael = await messagesFrom('Rafael');
    await person.click(await requiredCentreOf(composer));
    await writeMessage(page, person, composer, "hey rafael! i'm visiting lisbon soon, any tips? ✈️");
    await waitSeconds(0.4);
    await person.pressEnter();
    // His "typing", then his answer
    await waitForReply('Rafael', fromRafael);
    await waitSeconds(3.5);
    printProgress('a question voice typed');
    // And a question back, by voice
    await speakMessage(page, person, composer);
    await waitSeconds(2);

    printProgress('back to Maya');
    // Back to Maya, to read her answer and write back
    await waitForReply('Maya', fromMaya, 20);
    await openChat(chatTab, 'Maya');
    await waitSeconds(3);
    fromMaya = await messagesFrom('Maya');
    await person.click(await requiredCentreOf(composer));
    // ✨, as the emoji menu has no owl
    await writeMessage(page, person, composer, 'haha same, night owls unite ✨');
    await waitSeconds(0.4);
    await person.pressEnter();
    await waitForReply('Maya', fromMaya);
    await waitSeconds(3);

    printProgress('a tab dragged and closed');
    // Tabs can be dragged into another order, and closed
    await person.drag(await requiredCentreOf(chatTab('Rafael')), await requiredCentreOf(chatTab('Maya')));
    await waitSeconds(1.2);
    // Rafael's ×, measured again on arrival as the tabs settle after the drag
    const closeRafael = chatTab('Rafael').locator('[data-part="tab-close"]');
    await person.moveTo(await requiredCentreOf(closeRafael));
    await waitSeconds(0.6);
    await person.click(await requiredCentreOf(closeRafael));
    const rafaelTabStaying = page.locator('[data-part="tab"]:not(.is-leaving)', {hasText: 'Rafael'});
    await waitFor(async () => await rafaelTabStaying.count() === 0, 3);
    // Then away, down into the chat, so the pointer isn't left over Maya's
    // × as her tab slides into the gap
    const [x, y] = person.at;
    await person.moveTo([x + 40, y + 160]);
    await waitSeconds(1.5);

    printProgress('the profile');
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
    // Then down to the bottom of the profile, where it can be deleted
    await scrollToBottom(page);
    await waitSeconds(2);

    await stopCapture();
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

// Repeated frames dropped, the same way for working out times and for the video
const DROP_REPEATS = 'mpdecimate';

/** When each frame that survives DROP_REPEATS shows in the recording, in seconds */
async function keptFrameTimes(recording: string): Promise<number[]> {
    const {stderr} = await run('ffmpeg', ['-hide_banner', '-i', recording, '-vf', `${DROP_REPEATS},showinfo`, '-f', 'null', '-'], {maxBuffer: 256 * 1024 * 1024});
    return [...stderr.matchAll(/pts_time:([\d.]+)/g)].map((match) => Number(match[1]));
}

/**
 * Where a moment in the recording falls in the video, once each gap between
 * kept frames is capped at LONGEST_STILL_SECONDS, as encode cuts them
 *
 * @param seconds The moment, in the recording's own time
 */
function videoTimeOf(seconds: number, keptTimes: number[]): number {
    let videoTime = 0;
    for (let i = 1; i < keptTimes.length && keptTimes[i] <= seconds; i++) {
        videoTime += Math.min(keptTimes[i] - keptTimes[i - 1], LONGEST_STILL_SECONDS);
    }
    const lastKept = [...keptTimes].reverse().find((time) => time <= seconds) ?? 0;
    return videoTime + Math.min(seconds - lastKept, LONGEST_STILL_SECONDS);
}

/**
 * The recording made into the video: repeated frames dropped, any stretch
 * where nothing moves cut to LONGEST_STILL_SECONDS (each gap between frames
 * capped, by setpts against the frame before), then 60fps on the GPU. The
 * voice line is laid in where it played into the mic, the video's only sound
 */
async function encode(recording: string): Promise<void> {
    const capGaps = `setpts='if(eq(N,0),0,PREV_OUTPTS+min(PTS-PREV_INPTS,${LONGEST_STILL_SECONDS}/TB))'`;
    const video = `[0:v]${DROP_REPEATS},${capGaps},fps=${FPS},format=yuv420p[video]`;
    if (voiceStartedAt === null) {
        await run('ffmpeg', [
            '-hide_banner', '-loglevel', 'error', '-y', '-i', recording,
            '-filter_complex', video, '-map', '[video]', ...await encoderFlags(),
            '-an', '-movflags', '+faststart', OUT,
        ]);
        return;
    }

    const voiceInRecording = (voiceStartedAt - recordingStartedAt) / 1000;
    const voiceInVideo = videoTimeOf(voiceInRecording, await keptFrameTimes(recording));
    const voice = `[1:a]adelay=${Math.round(voiceInVideo * 1000)}:all=1,apad[voice]`;
    await run('ffmpeg', [
        '-hide_banner', '-loglevel', 'error', '-y', '-i', recording, '-i', VOICE_LINE_FILE,
        '-filter_complex', `${video};${voice}`, '-map', '[video]', '-map', '[voice]',
        ...await encoderFlags(), '-c:a', 'aac', '-b:a', '128k', '-shortest',
        '-movflags', '+faststart', OUT,
    ]);
    console.log(`The voice line plays at ${voiceInVideo.toFixed(2)} s in the video`);
}

/**
 * Starts recording the tour's tab through the capture extension, its file
 * streamed to `recording`
 *
 * @returns Stops the recording, settling once the whole file has arrived
 */
async function startRecording(context: BrowserContext, recording: string): Promise<() => Promise<void>> {
    const receiver = createServer();
    const received = receiveBinaryMessages(receiver, (piece) => appendFileSync(recording, piece));
    await new Promise<void>((resolve) => receiver.listen(0, '127.0.0.1', resolve));
    const socketUrl = `ws://127.0.0.1:${(receiver.address() as AddressInfo).port}/recording`;

    const extension = context.serviceWorkers()[0] ?? await context.waitForEvent('serviceworker');
    const started = await extension.evaluate(
        ([appUrl, url, width, height, bitsPerSecond]) => self.startRecording(appUrl, url, width, height, bitsPerSecond),
        [URL, socketUrl, WIDTH * SCALE, HEIGHT * SCALE, CAPTURE_BITS_PER_SECOND] as const,
    );
    if (!started?.ok) {
        receiver.close();
        throw new Error(`The tab's recording didn't start: ${started?.error}`);
    }
    recordingStartedAt = performance.now();

    return async () => {
        await extension.evaluate(() => self.stopRecording());
        await received;
        receiver.close();
    };
}

async function main(): Promise<void> {
    if (!existsSync(VOICE_LINE_FILE)) {
        throw new Error(`No ${VOICE_LINE_FILE} for the voice typing: run npm run voice-line, or save a WAV of your own voice there`);
    }
    const workDir = mkdtempSync(join(tmpdir(), 'mapchat-tour-'));
    const recording = join(workDir, 'tour.webm');
    printProgress('setting up: the app, a guest, the paused worker');
    // Sessions live in the database, so a guest's outlasts the app starting again
    const hasPlaceholderGoogle = await showGoogleSignIn(workDir);
    const guest = GIVEN_GUEST.session ? GIVEN_GUEST : await signInNewGuest();
    let context: BrowserContext | undefined;

    // An interrupted run still closes Chrome and lets the worker go
    for (const signal of ['SIGINT', 'SIGTERM'] as const) {
        process.once(signal, async () => {
            await context?.close().catch(() => {});
            await resumeWorker().catch(() => {});
            if (hasPlaceholderGoogle) await restoreApp().catch(() => {});
            rmSync(workDir, {recursive: true, force: true});
            process.exit(130);
        });
    }

    await clearTourMessages(GIVEN_GUEST.email);
    await pauseWorker();
    const jobs = new JobRunner();
    try {
        // The handlers above close it and let the worker go
        // The voice line plays once as the microphone, from when the mic opens
        // it. The permission is granted when it's used (speakMessage), not by
        // --use-fake-ui-for-media-stream, which stops the tab's capture finding its source
        const fakeMicrophone = ['--use-fake-device-for-media-stream', `--use-file-for-fake-audio-capture=${VOICE_LINE_FILE}%noloop`];
        printProgress('opening Chrome with the capture extension');
        context = await launchWithExtension(CAPTURE_EXTENSION, {width: WIDTH, height: HEIGHT, scale: SCALE}, {handlesSignals: true, extraFlags: fakeMicrophone});
        await context.addInitScript(drawPointer, readThemeCursors(SCALE));
        const page = context.pages()[0] ?? await context.newPage();
        await printRenderer(page);

        let stopRecording: () => Promise<void> = async () => {};
        const startCapture = async () => {
            stopRecording = await startRecording(context!, recording);
        };
        const stopCapture = () => stopRecording();

        const person = new Person(page, new SeededRandom(SEED), [WIDTH * 0.55, HEIGHT * 0.6]);
        await tour(page, person, guest, startCapture, stopCapture);
    } finally {
        await context?.close();
        await jobs.stop();
        await resumeWorker();
        if (hasPlaceholderGoogle) await restoreApp();
    }

    printProgress('encoding the video');
    await encode(recording);
    await clearTourMessages(guest.email);
    console.log(`${OUT}: ${(await motionFps(recording)).toFixed(1)}fps recorded while moving, at ${WIDTH * SCALE}×${HEIGHT * SCALE}`);
    printProgress('done');
    rmSync(workDir, {recursive: true, force: true});
}

await main();
