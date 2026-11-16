// MapChat's service worker: shows Web Push notifications for private
// messages and @mentions (App\Push sends them). While a MapChat tab is in
// view, that tab shows its own (notifications.js), so this stays quiet

self.addEventListener('push', (event) => {
    const message = event.data?.json();
    if (!message) return;

    event.waitUntil(self.clients.matchAll({type: 'window', includeUncontrolled: true}).then((pages) => {
        if (pages.some((page) => page.visibilityState === 'visible')) return undefined;

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
        const page = pages.find((client) => new URL(client.url).pathname === '/app');
        if (page) {
            page.postMessage({openChat: conversation, name});
            return page.focus();
        }
        const query = conversation && conversation !== 'everyone'
            ? `?chat=${encodeURIComponent(conversation)}&name=${encodeURIComponent(name || '')}`
            : '';
        return self.clients.openWindow(`/app${query}`);
    }));
});
