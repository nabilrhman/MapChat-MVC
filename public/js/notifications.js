// System notifications for private messages and @mentions you haven't seen.
// chat.js starts it with setUpNotifications and hands it new messages; the
// profile's switch turns them on and off for this browser

const NOTIFY_BODY_LENGTH = 120;
// Per browser, like the permission itself
const NOTIFY_OFF_KEY = 'notificationsOff';
const NOTIFY_DISMISSED_KEY = 'notifyPromptDismissed';

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

// Asks the browser if it hasn't been asked yet; must come from a click.
// Resolves to whether notifications are on now
async function turnNotificationsOn() {
    if (!isNotifySupported()) return false;
    writeFlag(NOTIFY_OFF_KEY, false);
    if (Notification.permission === 'default') await Notification.requestPermission();
    return areNotificationsOn();
}

function turnNotificationsOff() {
    writeFlag(NOTIFY_OFF_KEY, true);
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
// shows its chat
function notifyOf(chat, onOpen) {
    const isPrivate = chat.dataset.conversation !== 'everyone';
    const isMention = chat.classList.contains('chat--mentions-me');
    const isMine = Number(chat.dataset.senderId) === myUserId;

    if (!areNotificationsOn() || isMine || (!isPrivate && !isMention)) return;
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
