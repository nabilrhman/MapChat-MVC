/**
 * Takes the README's main picture, docs/images/mapchat-app.png, from the app
 * running locally (docker compose up).
 *
 *   npm run screenshots
 *
 * Only this one is retaken; the README's profile and email pictures stay as
 * they are. A new guest signs in and opens two private chats as tabs, then
 * the room is shown with a message being written in the box and the label of
 * the pin nearest the middle of the map open, as hovering it shows. The
 * environment is chrome.ts's
 */
import {join} from 'node:path';
import type {Page} from 'playwright';

import {launchChrome, printRenderer, REPO, signInAsNewGuest, URL} from './chrome.ts';
import {waitSeconds} from './person.ts';

const WIDTH = 1440;
const HEIGHT = 900;
const IMAGES = join(REPO, 'docs/images');
// Being written in the box, as if the guest were about to send it
const DRAFT = 'anyone else still up? 🌙 what time is it where you are?';

/** A person in the online list, as people.js has them */
type ListedPerson = {id: number, shown_name: string};

/** Opens a private chat as a click on the person would */
async function openPrivateChat(page: Page, person: ListedPerson): Promise<void> {
    await page.evaluate(({id, shown_name}) => openPrivateChat(id, shown_name), person);
}

/** Hovers the pin nearest the middle of the map between the chat and the people list, so its label opens */
async function hoverMiddlePin(page: Page): Promise<void> {
    const pinCentre = await page.evaluate(() => {
        const left = document.getElementById('chat').getBoundingClientRect().right;
        const right = document.getElementById('people').getBoundingClientRect().left;
        const map = document.getElementById('map').getBoundingClientRect();
        const middle = {x: (left + right) / 2, y: map.top + map.height / 2};

        const centres = [...document.querySelectorAll('[data-part="map-pin"]')].map((pin) => {
            const box = pin.getBoundingClientRect();
            return {x: box.left + box.width / 2, y: box.top + box.height / 2};
        }).filter(({x}) => x > left && x < right);
        const distanceToMiddle = ({x, y}) => Math.hypot(x - middle.x, y - middle.y);
        return centres.sort((a, b) => distanceToMiddle(a) - distanceToMiddle(b))[0] ?? null;
    });
    if (!pinCentre) {
        throw new Error('No pin on the map between the chat and the people list: is the demo running?');
    }
    await page.mouse.move(pinCentre.x, pinCentre.y, {steps: 5});
}

async function main(): Promise<void> {
    const browser = await launchChrome();
    try {
        const context = await browser.newContext({viewport: {width: WIDTH, height: HEIGHT}, deviceScaleFactor: 1});
        const page = await context.newPage();
        await printRenderer(page);

        // A new guest, as anyone trying it would be
        await page.goto(`${URL}/login`);
        await signInAsNewGuest(page);
        await waitSeconds(4);

        const closeNotificationsOffer = page.locator('#notify-prompt-dismiss');
        if (await closeNotificationsOffer.isVisible()) {
            await closeNotificationsOffer.click();
        }

        // Two private chats open as tabs, then back to the room
        const demoPeople: ListedPerson[] = await page.evaluate(() => getPeopleToMention().filter((p) => Number(p.is_demo) && p.isOnline));
        if (demoPeople.length < 2) {
            throw new Error('Fewer than two demo people online: is the demo running?');
        }
        for (const person of demoPeople.slice(0, 2)) {
            await openPrivateChat(page, person);
        }
        await page.evaluate(() => {
            showConversation('everyone');
            scrollToNewest('instant');
        });
        await waitSeconds(4);

        // A message being written, and a pin's label open in the middle of the map
        await page.locator('#textarea-send').fill(DRAFT);
        await hoverMiddlePin(page);
        await waitSeconds(1);
        await page.screenshot({path: join(IMAGES, 'mapchat-app.png')});

        console.log(`${IMAGES}/mapchat-app.png`);
    } finally {
        await browser.close();
    }
}

await main();
