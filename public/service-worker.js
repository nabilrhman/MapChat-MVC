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

async function showPushedMessage(message) {
    const pages = await getOpenPages();
    if (pages.some((page) => isChat(page) && page.visibilityState === 'visible')) return;

    await self.registration.showNotification(message.title, {
        body: message.body,
        icon: message.icon,
        tag: message.tag,
        data: {conversation: message.conversation, name: message.name},
    });
}

self.addEventListener('push', (event) => {
    const message = event.data?.json();
    if (!message) return;
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
