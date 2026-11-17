/**
 * Takes the README's screenshots, docs/images/mapchat-login.jpg and
 * mapchat-{app,private,profile}.png, from the app running locally
 * (docker compose up).
 *
 *   npm run screenshots
 *
 * First the login page, then a new guest signs in, and a demo person online with a city is greeted
 * from the guest and answers, so the private chat has something in it. Then
 * the room, that private chat, and the whole profile page. The environment
 * is chrome.ts's
 */
import {join} from 'node:path';
import type {Page} from 'playwright';

import {app, launchChrome, printRenderer, REPO, URL} from './chrome.ts';
import {waitSeconds} from './person.ts';

const WIDTH = 1440;
const HEIGHT = 900;
const IMAGES = join(REPO, 'docs/images');

/** A person in the online list, as people.js has them */
type ListedPerson = {id: number, shown_name: string, city: string | null};

// Run in the app's container with the user ids and text as arguments, so
// nothing in them is read as PHP
const EMAIL_OF_USER_PHP = `require 'vendor/autoload.php';
echo App\\Models\\User::findByID((int) $argv[1])->email;`;
const SEND_MESSAGE_PHP = `require 'vendor/autoload.php';
use App\\Models\\{Chat, User};
[, $fromEmail, $toEmail, $body] = $argv;
$result = Chat::sendFrom(User::findByEmail($fromEmail), $body, (int) User::findByEmail($toEmail)->id);
if (isset($result['error'])) {
    fwrite(STDERR, $result['error'] . PHP_EOL);
    exit(1);
}`;

/** A demo person's email, from the name the list shows */
function demoEmailOf(person: ListedPerson): string {
    return `${person.shown_name.toLowerCase().replace('-', '')}@demo.mapchat.local`;
}

async function sendMessage(fromEmail: string, toEmail: string, body: string): Promise<void> {
    await app('php', '-r', SEND_MESSAGE_PHP, '--', fromEmail, toEmail, body);
}

/** Opens a private chat as a click on the person would, and takes the focus off the composer */
async function openPrivateChat(page: Page, person: ListedPerson): Promise<void> {
    await page.evaluate(({id, shown_name}) => {
        openPrivateChat(id, shown_name);
        document.activeElement.blur();
    }, person);
}

async function main(): Promise<void> {
    const browser = await launchChrome();
    try {
        const context = await browser.newContext({viewport: {width: WIDTH, height: HEIGHT}, deviceScaleFactor: 1});
        const page = await context.newPage();
        await printRenderer(page);

        // The login page, once its map has drawn; a JPEG, as the map behind it is a photo-like texture
        await page.goto(`${URL}/login`);
        await waitSeconds(3);
        await page.evaluate(() => document.activeElement.blur()); // the email box takes the focus on load
        await waitSeconds(1); // its label settles back into the box
        await page.screenshot({path: join(IMAGES, 'mapchat-login.jpg'), type: 'jpeg', quality: 85});

        // A new guest, as anyone trying it would be
        await page.locator('#guestForm button').click();
        await waitSeconds(5);
        const guestId = await page.evaluate('myUserId');
        const guestEmail = await app('php', '-r', EMAIL_OF_USER_PHP, '--', String(guestId));

        // Someone online with a city, and someone else to have a tab open for
        await page.goto(`${URL}/app`);
        await waitSeconds(4);
        const demoPeople: ListedPerson[] = await page.evaluate(() => getPeopleToMention().filter((p) => Number(p.is_demo)));
        const greeted = await page.evaluate(() => getPeopleToMention().find((p) => Number(p.is_demo) && p.isOnline && p.city));
        const otherPerson = demoPeople.find((person) => person.id !== greeted?.id);
        if (!greeted || !otherPerson) {
            throw new Error('No demo people online with a city: is the demo running?');
        }

        await sendMessage(guestEmail, demoEmailOf(greeted), `hi ${greeted.shown_name}! how's the weather in ${greeted.city}?`);
        await waitSeconds(1);
        await sendMessage(demoEmailOf(greeted), guestEmail, 'grey and windy, so tea weather ☕ and yours?');

        // The room, with both private chats open as tabs
        await page.reload();
        await waitSeconds(4);
        const closeNotificationsOffer = page.locator('#notify-prompt-dismiss');
        if (await closeNotificationsOffer.isVisible()) {
            await closeNotificationsOffer.click();
        }
        await openPrivateChat(page, greeted);
        await openPrivateChat(page, otherPerson);
        await page.evaluate(() => {
            showConversation('everyone');
            scrollToNewest('instant');
            document.activeElement.blur();
        });
        await waitSeconds(5);
        await page.screenshot({path: join(IMAGES, 'mapchat-app.png')});

        // The private chat, with their city and local time
        await openPrivateChat(page, greeted);
        await waitSeconds(3);
        await page.screenshot({path: join(IMAGES, 'mapchat-private.png')});

        // The whole profile page; with notifications allowed, their switch shows on
        await context.grantPermissions(['notifications'], {origin: URL});
        await page.goto(`${URL}/profile/show`);
        await waitSeconds(3);
        const pageHeight = await page.evaluate(() => document.documentElement.scrollHeight);
        await page.setViewportSize({width: WIDTH, height: pageHeight});
        await page.evaluate(() => window.scrollTo(0, 0));
        await waitSeconds(3);
        await page.screenshot({path: join(IMAGES, 'mapchat-profile.png')});

        console.log(`${IMAGES}: mapchat-login.jpg, mapchat-app.png, mapchat-private.png, mapchat-profile.png, with ${greeted.shown_name}`);
    } finally {
        await browser.close();
    }
}

await main();
