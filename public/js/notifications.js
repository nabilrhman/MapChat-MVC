// System notifications for private messages and @mentions you haven't seen.
// chat.js starts it with setUpNotifications and hands it new messages; the
// profile's switch turns them on and off for this browser. With Web Push
// set up (App\Notify\PushNotifications), this browser also subscribes (push.js), so public/sw.js
// shows them while no MapChat tab is in view, even with MapChat closed

const NOTIFY_BODY_LENGTH = 120;
// Per browser, like the permission itself
const NOTIFY_OFF_KEY = 'notificationsOff';
const NOTIFY_DISMISSED_KEY = 'notifyPromptDismissed';

// The socket and the polling fallback can both bring the same message
const notifiedIds = new Set();

const isNotifySupported = () => 'Notification' in window;

const areNotificationsOn = () => isNotifySupported() && Notification.permission === 'granted' && !readFlag(NOTIFY_OFF_KEY);

// Asks the browser if it hasn't been asked yet; must come from a click.
// Resolves to whether notifications are on now
async function turnNotificationsOn() {
    if (!isNotifySupported()) return false;
    writeFlag(NOTIFY_OFF_KEY, false);
    if (Notification.permission === 'default') await Notification.requestPermission();
    if (areNotificationsOn()) await subscribeToPush();
    return areNotificationsOn();
}

// How long turning them off waits on the browser's push service before it
// goes on without it, as logging out does (header.js)
const UNSUBSCRIBE_PUSH_WAIT = 1000;

// They're off for this browser at once; a push service that fails or
// doesn't answer only leaves its subscription for the next try
async function turnNotificationsOff() {
    writeFlag(NOTIFY_OFF_KEY, true);
    await withTimeLimit(unsubscribeFromPush(), UNSUBSCRIBE_PUSH_WAIT).catch((error) => {
        console.warn('Could not let go of Web Push:', error);
    });
}

// On each page: keep this browser's subscription the signed-in person's
function keepPushUpToDate() {
    if (areNotificationsOn()) subscribeToPush();
}

// Scrolls the bar's text when it's cut off, at a steady reading speed. With
// less motion asked for, it stays cut off with an ellipsis
const MARQUEE_SPEED = 30; // px a second

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
    const isPrivate = chat.dataset.conversation !== EVERYONE;
    const isMention = 'mentionsMe' in chat.dataset;
    const isMine = Number(chat.dataset.senderId) === myUserId;

    if (!areNotificationsOn() || isMine || (!isPrivate && !isMention)) return;
    if (document.hidden && readFlag(PUSH_ON_KEY)) return;
    if (notifiedIds.has(chat.dataset.id)) return;
    notifiedIds.add(chat.dataset.id);

    const name = chat.querySelector('[data-part="sender-name"]').textContent.trim();
    const body = chat.querySelector('[data-part="message-body"]').textContent.trim();
    const title = isMention ? `${name} mentioned you` : name;
    const options = {
        body: body.length > NOTIFY_BODY_LENGTH ? `${body.slice(0, NOTIFY_BODY_LENGTH - 1)}…` : body,
        icon: chat.querySelector('[data-part="sender-photo"]')?.src,
        // One per chat: a newer message replaces the last one
        tag: `chat-${chat.dataset.conversation}`,
    };
    // A notification that fails mustn't stop the message from showing
    showNotification(title, options, {conversation: chat.dataset.conversation, name}, onOpen).catch((error) => {
        console.warn('Could not show a notification:', error);
    });
}

// Through the service worker when this browser has one, as Android Chrome
// has no Notification constructor; public/sw.js opens the chat on a click
// (openNotifiedChat in chat.js). chatToOpen: {conversation, name}
async function showNotification(title, options, chatToOpen, onOpen) {
    const registration = await navigator.serviceWorker?.getRegistration();
    if (registration?.active) {
        await registration.showNotification(title, {...options, data: chatToOpen});
        return;
    }

    const notification = new Notification(title, options);
    notification.addEventListener('click', () => {
        window.focus();
        onOpen(chatToOpen.conversation);
        notification.close();
    });
}
