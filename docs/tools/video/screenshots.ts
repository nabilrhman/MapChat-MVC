/**
 * Takes the README's main picture, its two phone pictures, the profile and
 * the login page, at 2x, from the app running locally (docker compose up).
 *
 *   npm run screenshots
 *
 * The README's email pictures stay as they are.
 *
 * Each picture is taken logged in as Alex Morgan, as in the tour: an account
 * made for the run, and deleted with what Alex said at the end.
 *
 * - mapchat-app.png: Alex opens two private chats as tabs and sends
 *   the room a message that @mentions Aiko, translated into Japanese with
 *   the translate bar; she answers in Japanese, translated back into
 *   English. Then the room is shown with a message being written in the box
 *   that @mentions someone, and the pin nearest the middle of the map panned
 *   to the middle with its label open, as hovering it shows. Translating
 *   needs GROQ_API_KEY in .env
 * - mapchat-phone.png: the room on a phone, with a pin in the middle of the
 *   map and its label open as a tap opens it.
 * - mapchat-phone-online.png: the same phone with the online list slid open.
 * - mapchat-profile.png: Alex's profile page.
 * - mapchat-login.png: the login page, with Continue with Google.
 *
 * The environment is chrome.ts's
 */
import {mkdtempSync, rmSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import type {Browser, Page} from 'playwright';

import {
    ALEX_EMAIL, ALEX_SHOWN_NAME, type AlexAccount, deleteAlexAccount, isGoogleSignInShown, launchChrome, logInAsAlex, makeAlexAccount, printRenderer, REPO, restoreApp,
    sql, startAppForRecording, URL,
} from './chrome.ts';
import {waitSeconds} from './person.ts';

const DESKTOP = {width: 1440, height: 900};
// An iPhone 15's screen in CSS pixels
const PHONE = {width: 393, height: 852};
// Close enough in for a phone's map to pan a pin to its middle, and still show a continent or two
const PHONE_LEAST_ZOOM = 1.5;
// The README wants sharp pictures on high-density screens
const PIXEL_RATIO = 2;
const IMAGES = join(REPO, 'docs/images');
// Being written in the box, as if Alex were about to send it, with a mention's chip
const draftFor = (person: ListedPerson): string => `@${person.shown_name} anyone else still up? 🌙 what time is it where you are?`;

/** What Alex sends to Aiko, so a mention chip shows */
const mentionFor = (person: ListedPerson): string => `@${person.shown_name} what's the weather like where you are? ☀️`;

/** Who Alex writes to in her own language: she answers in it, and it's translated back */
const TRANSLATED_FOR = 'Aiko';
/** Her language, by the code the To list knows it by */
const TRANSLATE_TO_CODE = 'ja';

/** A person in the online list, as people.js has them */
type ListedPerson = {id: number, shown_name: string};

/** Opens a private chat as a click on the person would */
async function openPrivateChat(page: Page, person: ListedPerson): Promise<void> {
    await page.evaluate(({id, shown_name}) => openPrivateChat(id, shown_name), person);
}

/** The centre of the pin nearest the middle of the map's open part: between the chat and the people list where both show */
async function middlePinCentre(page: Page): Promise<{x: number, y: number}> {
    const pinCentre = await page.evaluate(() => {
        const map = document.getElementById('map').getBoundingClientRect();
        const chat = document.getElementById('chat').getBoundingClientRect();
        const people = document.getElementById('people').getBoundingClientRect();
        // On a phone the chat sits under the map and the list is a closed drawer
        const left = chat.right < map.right ? chat.right : map.left;
        const right = people.left > left && people.width > 0 ? people.left : map.right;
        const bottom = chat.top > map.top ? Math.min(map.bottom, chat.top) : map.bottom;
        const middle = {x: (left + right) / 2, y: (map.top + bottom) / 2};

        const centres = [...document.querySelectorAll('[data-part="map-pin"]')].map((pin) => {
            const box = pin.getBoundingClientRect();
            return {x: box.left + box.width / 2, y: box.top + box.height / 2};
        }).filter(({x, y}) => x > left && x < right && y > map.top && y < bottom);
        const distanceToMiddle = ({x, y}) => Math.hypot(x - middle.x, y - middle.y);
        return centres.sort((a, b) => distanceToMiddle(a) - distanceToMiddle(b))[0] ?? null;
    });
    if (!pinCentre) {
        throw new Error('No pin in the open part of the map: is the demo running?');
    }
    return pinCentre;
}

/**
 * Pans the map so the pin nearest the middle of its open part sits right in
 * the middle, and returns where that pin now is
 *
 * @param leastZoom zooms in to this first if the map is further out: on a
 *   phone the whole world only just fills the map's height, so it can't pan
 *   north or south at all
 */
async function centreMiddlePin(page: Page, leastZoom = 0): Promise<{x: number, y: number}> {
    const pinCentre = await middlePinCentre(page);
    await page.evaluate(({x, y, leastZoom}) => {
        const map = document.getElementById('map').getBoundingClientRect();
        const pinLngLat = vectorMap.unproject([x - map.left, y - map.top]);
        const zoom = Math.max(vectorMap.getZoom(), leastZoom);
        vectorMap.jumpTo({center: pinLngLat, zoom, padding: getMapPadding()});
    }, {...pinCentre, leastZoom});
    await waitSeconds(1.5);
    return middlePinCentre(page);
}

/** Taps the pin nearest the middle until its label shows: a tap while the map is still settling misses */
async function tapMiddlePinUntilLabelShows(page: Page): Promise<void> {
    const label = page.locator('.maplibregl-popup');
    for (let attempt = 1; attempt <= 5; attempt++) {
        const pinCentre = await centreMiddlePin(page, PHONE_LEAST_ZOOM);
        await page.touchscreen.tap(pinCentre.x, pinCentre.y);
        await waitSeconds(1);
        if (await label.isVisible()) {
            return;
        }
    }
    throw new Error('Tapping a pin never opened its label');
}

/** Logs Alex in, and closes the offer of notifications */
async function logIn(page: Page, account: AlexAccount): Promise<void> {
    await logInAsAlex(page, account);
    await waitSeconds(4);

    const closeNotificationsOffer = page.locator('#notify-prompt-dismiss');
    if (await closeNotificationsOffer.isVisible()) {
        await closeNotificationsOffer.click();
    }
}

/** Logs Alex out, so the next picture's login starts afresh */
async function signOut(page: Page): Promise<void> {
    await page.evaluate(() => fetch('/logout', {method: 'POST'}));
}

/**
 * Takes what Alex said, and what was said to Alex, out of the room, so the
 * next run's pictures don't show this one's too
 */
async function removeWhatWasSaid(): Promise<void> {
    await sql(`DELETE FROM chats WHERE email = '${ALEX_EMAIL}' OR to_email = '${ALEX_EMAIL}'
        OR ((email = 'bot@mapchat.local' OR email LIKE '%@demo.mapchat.local') AND body LIKE '@${ALEX_SHOWN_NAME} %')`);
}

/**
 * The room with two private chats open as tabs, a draft in the box and a
 * pin's label open. The app is started again with Aiko kept online, as she
 * answers in it, then as it was
 */
async function takeDesktopPicture(browser: Browser, account: AlexAccount): Promise<void> {
    const workDir = mkdtempSync(join(tmpdir(), 'mapchat-screenshots-'));
    const hadGoogleKeys = await isGoogleSignInShown();
    await startAppForRecording(workDir, {DEMO_HERE: TRANSLATED_FOR.toLowerCase()});
    try {
        await takeRoomPicture(browser, account);
    } finally {
        await restoreApp(hadGoogleKeys);
        rmSync(workDir, {recursive: true, force: true});
    }
}

/** The room as takeDesktopPicture describes it, on the app as it's running */
async function takeRoomPicture(browser: Browser, account: AlexAccount): Promise<void> {
    const context = await browser.newContext({viewport: DESKTOP, deviceScaleFactor: PIXEL_RATIO});
    const page = await context.newPage();
    await printRenderer(page);
    await logIn(page, account);

    // Two private chats open as tabs, then back to the room
    const onlineDemoPeople: ListedPerson[] = await page.evaluate(() => getPeopleToMention().filter((p) => Number(p.is_demo) && p.isOnline));
    const translatedFor = onlineDemoPeople.find((person) => person.shown_name === TRANSLATED_FOR);
    if (!translatedFor || onlineDemoPeople.length < 2) {
        throw new Error(`${TRANSLATED_FOR} or another demo person isn't online: is the demo running?`);
    }
    // Aiko first: her tab, then the message to her
    const demoPeople = [translatedFor, ...onlineDemoPeople.filter((person) => person !== translatedFor)];
    for (const person of demoPeople.slice(0, 2)) {
        await openPrivateChat(page, person);
    }
    await page.evaluate(() => showConversation('everyone'));

    // Translating on, into Japanese, so the bar shows over the box
    await page.locator('#translate-toggle').click();
    await page.locator('#translate-to').click();
    await page.locator(`#translate-to-${TRANSLATE_TO_CODE}`).click();

    // A message that @mentions Aiko, sent in Japanese, so a mention chip and
    // "Translated · See original" show among the newest. Her answer, in
    // Japanese, comes back translated too
    const translatedBefore = await page.locator('[data-part="see-original"]').count();
    await page.locator('#textarea-send').fill(mentionFor(demoPeople[0]));
    await page.locator('#button-send').click();
    await page.waitForFunction(
        (count) => document.querySelectorAll('[data-part="see-original"]').length >= count,
        translatedBefore + 2,
        {timeout: 90_000},
    );
    await waitSeconds(2);

    // A message being written, and a pin's label open in the middle of the map
    await page.locator('#textarea-send').fill(draftFor(demoPeople[1]));
    // The demo keeps talking: a message that came in since is shown whole
    await page.evaluate(() => scrollToNewest('instant'));
    const pinCentre = await centreMiddlePin(page);
    await page.mouse.move(pinCentre.x, pinCentre.y, {steps: 5});
    await waitSeconds(1);
    await savePicture(page, 'mapchat-app.png');
    await signOut(page);
    await context.close();
}

/** The room on a phone with a pin's label open, then with the online list slid open */
async function takePhonePictures(browser: Browser, account: AlexAccount): Promise<void> {
    const context = await browser.newContext({
        viewport: PHONE,
        deviceScaleFactor: PIXEL_RATIO,
        isMobile: true,
        hasTouch: true,
    });
    const page = await context.newPage();
    await logIn(page, account);
    await waitSeconds(2);
    await page.evaluate(() => scrollToNewest('instant'));

    // Scrolled before the tap, as scrolling the messages closes a pin's label
    await page.evaluate(() => scrollToNewest('instant'));
    await tapMiddlePinUntilLabelShows(page);
    await savePicture(page, 'mapchat-phone.png');

    // Long enough for a row that has just come online to stop flashing
    await page.locator('#people-toggle').tap();
    await waitSeconds(4);
    // Chrome leaves a row under its emulated pointer hovered, which a finger wouldn't
    await page.mouse.move(1, 1);
    await waitSeconds(0.5);
    await savePicture(page, 'mapchat-phone-online.png');
    await signOut(page);
    await context.close();
}

/**
 * The login page with Continue with Google, as the live site has it. Without
 * Google keys the app is started again with placeholder ones, then as it was
 */
async function takeLoginPicture(browser: Browser): Promise<void> {
    const workDir = mkdtempSync(join(tmpdir(), 'mapchat-screenshots-'));
    const hasPlaceholderGoogle = await startAppForRecording(workDir);
    try {
        const context = await browser.newContext({viewport: DESKTOP, deviceScaleFactor: PIXEL_RATIO});
        const page = await context.newPage();
        await page.goto(`${URL}/login`);
        // The live map in, and a pin saying hello in English, which the
        // README's readers can read. The pins take turns, so it can take a while
        await page.locator('#login-map.is-shown').waitFor({state: 'attached', timeout: 20_000});
        await page.locator('.bubble.is-shown:has(.message[lang^="en"])').first().waitFor({state: 'attached', timeout: 90_000});
        await waitSeconds(1);
        await savePicture(page, 'mapchat-login.png');
        await context.close();
    } finally {
        if (hasPlaceholderGoogle) {
            await restoreApp();
        }
        rmSync(workDir, {recursive: true, force: true});
    }
}

/** Alex's profile page, one screen of it: the same size as the login's, so they share a row evenly */
async function takeProfilePicture(browser: Browser, account: AlexAccount): Promise<void> {
    const context = await browser.newContext({viewport: DESKTOP, deviceScaleFactor: PIXEL_RATIO});
    const page = await context.newPage();
    await logIn(page, account);
    await page.goto(`${URL}/profile/show`);
    await waitSeconds(2);
    await savePicture(page, 'mapchat-profile.png');
    await signOut(page);
    await context.close();
}

/** Saves the page as it shows now to docs/images */
async function savePicture(page: Page, name: string): Promise<void> {
    const path = join(IMAGES, name);
    // A count rolling to a new number or a row easing in, caught halfway, looks
    // broken; loops like the loading dots never end, so they don't count
    const isStill = () => document.getAnimations().every((animation) => animation.playState !== 'running' || animation.effect?.getTiming().iterations === Infinity);
    await page.waitForFunction(isStill, undefined, {timeout: 5000})
        .catch(() => console.warn(`${name}: something was still moving after 5s; look at it`));
    await page.screenshot({path});
    console.log(path);
}

async function main(): Promise<void> {
    await removeWhatWasSaid();
    const account = await makeAlexAccount();
    const browser = await launchChrome();
    try {
        await takeDesktopPicture(browser, account);
        await takePhonePictures(browser, account);
        await takeProfilePicture(browser, account);
        // Last, as it may start the app again
        await takeLoginPicture(browser);
    } finally {
        await browser.close();
        // Last, so an answer that came in late goes too
        await removeWhatWasSaid();
        await deleteAlexAccount();
    }
}

await main();
