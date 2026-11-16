// System notifications for private messages and @mentions you haven't seen.
// chat.js starts it with setUpNotifications and hands it new messages; the
// profile's switch turns them on and off for this browser. With Web Push
// set up (App\Push), this browser also subscribes, so public/sw.js shows
// them while no MapChat tab is in view, even with MapChat closed

const NOTIFY_BODY_LENGTH = 120;
// Per browser, like the permission itself
const NOTIFY_OFF_KEY = 'notificationsOff';
const NOTIFY_DISMISSED_KEY = 'notifyPromptDismissed';
// This browser is subscribed to Web Push; header.js clears it on logging out
const PUSH_ON_KEY = 'pushOn';
const pushPublicKey = document.querySelector('meta[name="push-public-key"]')?.content || '';

// The socket and the polling fallback can both bring the same message
const notifiedIds = new Set();

const isNotifySupported = () => 'Notification' in window;

function readFlag(key) {
    try {
        return localStorage.getItem(key) === '1';
    } catch {
        return false;
    }
}

function writeFlag(key, isOn) {
    try {
        if (isOn) localStorage.setItem(key, '1');
        else localStorage.removeItem(key);
    } catch {
        // No storage (private browsing): only lasts for this page
    }
}

const areNotificationsOn = () => isNotifySupported() && Notification.permission === 'granted' && !readFlag(NOTIFY_OFF_KEY);

const isPushSupported = () => pushPublicKey !== '' && 'serviceWorker' in navigator && 'PushManager' in window;

// The key as the browser wants it: bytes, from URL-safe base64
function keyBytes(base64) {
    const plain = (base64 + '='.repeat((4 - (base64.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');
    return Uint8Array.from(atob(plain), (character) => character.charCodeAt(0));
}

// Subscribes this browser, or reuses its subscription, and tells the server
// it's the signed-in person's. Run on each page load too, so a browser
// someone else signs in on moves over to them
async function subscribeToPush() {
    if (!isPushSupported()) return;
    try {
        const registration = await navigator.serviceWorker.register('/sw.js');
        const subscription = await registration.pushManager.getSubscription()
            || await registration.pushManager.subscribe({userVisibleOnly: true, applicationServerKey: keyBytes(pushPublicKey)});
        const {endpoint, keys} = subscription.toJSON();
        await postForm('/push/subscribe', {endpoint, p256dh: keys.p256dh, auth: keys.auth});
        writeFlag(PUSH_ON_KEY, true);
    } catch (error) {
        // Notifications still come while a tab is open
        console.warn('Web Push is unavailable:', error);
    }
}

async function unsubscribeFromPush() {
    writeFlag(PUSH_ON_KEY, false);
    const subscription = await (await navigator.serviceWorker?.getRegistration())?.pushManager.getSubscription();
    if (!subscription) return;
    await postForm('/push/unsubscribe', {endpoint: subscription.endpoint}).catch(() => {});
    await subscription.unsubscribe();
}

// Asks the browser if it hasn't been asked yet; must come from a click.
// Resolves to whether notifications are on now
async function turnNotificationsOn() {
    if (!isNotifySupported()) return false;
    writeFlag(NOTIFY_OFF_KEY, false);
    if (Notification.permission === 'default') await Notification.requestPermission();
    if (areNotificationsOn()) await subscribeToPush();
    return areNotificationsOn();
}

function turnNotificationsOff() {
    writeFlag(NOTIFY_OFF_KEY, true);
    unsubscribeFromPush();
}

// On each page: keep this browser's subscription the signed-in person's
function keepPushUpToDate() {
    if (areNotificationsOn()) subscribeToPush();
}

// Scrolls the bar's text when it's cut off, at a steady reading speed. With
// less motion asked for, it stays cut off with an ellipsis
const MARQUEE_SPEED = 30; // px a second
const prefersLessMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

function scrollTextIfCutOff(text) {
    const overflow = text.scrollWidth - text.clientWidth;
    const shouldScroll = overflow > 1 && !prefersLessMotion.matches;
    text.classList.toggle('is-scrolling', shouldScroll);
    if (!shouldScroll) return;

    text.style.setProperty('--marquee-distance', `${overflow}px`);
    // The glide is 60% of each pass; the rest is the pause at either end
    text.style.setProperty('--marquee-time', `${(overflow / MARQUEE_SPEED / 0.6).toFixed(2)}s`);
}

// Browsers only ask from a click, so the bar asks instead of the page load.
// It slides down once the chat panel has slid in
function setUpNotifications() {
    const prompt = document.getElementById('notify-prompt');
    if (!isNotifySupported() || Notification.permission !== 'default' || readFlag(NOTIFY_DISMISSED_KEY)) return;

    const panel = document.getElementById('chat');
    panel.addEventListener('animationend', function onPanelIn(event) {
        // Its tabs and messages animate too, and their ends bubble up here
        if (event.target !== panel) return;
        panel.removeEventListener('animationend', onPanelIn);
        prompt.classList.add('is-shown');
    });

    // Measured again whenever the panel's width changes
    const text = document.getElementById('notify-prompt-text');
    new ResizeObserver(() => scrollTextIfCutOff(text)).observe(text);

    document.getElementById('notify-prompt-on').addEventListener('click', async () => {
        prompt.classList.remove('is-shown');
        if (await turnNotificationsOn()) showToast('Notifications are on');
    });
    document.getElementById('notify-prompt-dismiss').addEventListener('click', () => {
        prompt.classList.remove('is-shown');
        writeFlag(NOTIFY_DISMISSED_KEY, true);
    });
}

// For a message you haven't seen (another tab, or the page hidden); onOpen
// shows its chat. With Web Push on, a hidden page leaves it to public/sw.js
function notifyOf(chat, onOpen) {
    const isPrivate = chat.dataset.conversation !== 'everyone';
    const isMention = chat.classList.contains('chat--mentions-me');
    const isMine = Number(chat.dataset.senderId) === myUserId;

    if (!areNotificationsOn() || isMine || (!isPrivate && !isMention)) return;
    if (document.hidden && readFlag(PUSH_ON_KEY)) return;
    if (notifiedIds.has(chat.dataset.id)) return;
    notifiedIds.add(chat.dataset.id);

    const name = chat.querySelector('.chat__name').textContent.trim();
    const body = chat.querySelector('.chat__body').textContent.trim();
    const notification = new Notification(isMention ? `${name} mentioned you` : name, {
        body: body.length > NOTIFY_BODY_LENGTH ? `${body.slice(0, NOTIFY_BODY_LENGTH - 1)}…` : body,
        icon: chat.querySelector('.chat__photo')?.src,
        // One per chat: a newer message replaces the last one
        tag: `chat-${chat.dataset.conversation}`,
    });
    notification.addEventListener('click', () => {
        window.focus();
        onOpen(chat.dataset.conversation);
        notification.close();
    });
}
