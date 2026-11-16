// MapChat's service worker: shows Web Push notifications for private
// messages and @mentions (App\Push sends them). While the chat is in view,
// that tab shows its own (notifications.js), so this stays quiet; other
// pages, like the profile, don't show any

// The open room's conversation, as the pages name it (messages.js); a
// private one is the other person's id
const EVERYONE = 'everyone';

const isChat = (page) => new URL(page.url).pathname === '/app';

// A new version takes over at once rather than waiting for every MapChat tab to close
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

self.addEventListener('push', (event) => {
    const message = event.data?.json();
    if (!message) return;

    event.waitUntil(self.clients.matchAll({type: 'window', includeUncontrolled: true}).then((pages) => {
        if (pages.some((page) => isChat(page) && page.visibilityState === 'visible')) return undefined;

        return self.registration.showNotification(message.title, {
            body: message.body,
            icon: message.icon,
            tag: message.tag,
            data: {conversation: message.conversation, name: message.name},
        });
    }));
});

// Opens that chat: in a MapChat tab if one is open, or in a new one
self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const {conversation, name} = event.notification.data || {};

    event.waitUntil(self.clients.matchAll({type: 'window', includeUncontrolled: true}).then((pages) => {
        const page = pages.find(isChat);
        if (page) {
            page.postMessage({openChat: conversation, name});
            return page.focus();
        }
        const query = conversation && conversation !== EVERYONE
            ? `?chat=${encodeURIComponent(conversation)}&name=${encodeURIComponent(name || '')}`
            : '';
        return self.clients.openWindow(`/app${query}`);
    }));
});
