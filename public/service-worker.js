// MapChat's service worker: shows Web Push notifications for private
// messages and @mentions (App\Notify\PushNotifications sends them). While the chat is in view,
// that tab shows its own (notifications.js, through this worker where the
// browser has no other way), so a push stays quiet; other pages, like the
// profile, don't show any

// The open room's conversation, as the pages name it (messages.js); a
// private one is the other person's id
const EVERYONE = 'everyone';

const isChat = (page) => new URL(page.url).pathname === '/app';

const getOpenPages = () => self.clients.matchAll({type: 'window', includeUncontrolled: true});

// A new version takes over at once rather than waiting for every MapChat tab to close
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

// Shown for a push that came with no message
const NOTICE_WITHOUT_MESSAGE = {title: 'MapChat', body: 'You have a new message.', tag: 'chat-everyone', conversation: EVERYONE};

// The browser was promised a notification for every push (userVisibleOnly).
// Chrome waives it while one of the site's tabs is visible; Safari takes the
// push subscription back after pushes that show none
const canSkipNotificationWhileInView = self.navigator.userAgentData?.brands.some(({brand}) => brand === 'Chromium') ?? false;

// A push shows the message, unless the chat is in view: that tab shows its
// own, or none for the chat you're in. Chrome then shows nothing, as one
// shown and closed at once still pops up on some desktops, like GNOME; other
// browsers show it silently and close it at once
async function showPushedMessage(message) {
    const pages = await getOpenPages();
    const isChatInView = pages.some((page) => isChat(page) && page.visibilityState === 'visible');
    if (isChatInView && canSkipNotificationWhileInView) return;

    await self.registration.showNotification(message.title, {
        body: message.body,
        icon: message.icon,
        tag: message.tag,
        silent: isChatInView,
        data: {conversation: message.conversation, name: message.name},
    });
    if (!isChatInView) return;

    const quietNotifications = await self.registration.getNotifications({tag: message.tag});
    quietNotifications.forEach((notification) => notification.close());
}

self.addEventListener('push', (event) => {
    const message = event.data?.json() ?? NOTICE_WITHOUT_MESSAGE;
    event.waitUntil(showPushedMessage(message));
});

// Opens that chat: in a MapChat tab if one is open, or in a new one
async function openNotifiedChat({conversation, name}) {
    const pages = await getOpenPages();
    const chatPage = pages.find(isChat);
    if (chatPage) {
        chatPage.postMessage({openChat: conversation, name});
        await chatPage.focus();
        return;
    }

    const isPrivate = (conversation ?? EVERYONE) !== EVERYONE;
    const query = isPrivate ? `?chat=${encodeURIComponent(conversation)}&name=${encodeURIComponent(name ?? '')}` : '';
    await self.clients.openWindow(`/app${query}`);
}

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    event.waitUntil(openNotifiedChat(event.notification.data ?? {}));
});
