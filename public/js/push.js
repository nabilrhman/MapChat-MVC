// Web Push for this browser (App\Push): subscribing it to the signed-in
// person's notifications, so public/sw.js shows them with MapChat closed,
// and letting it go again. notifications.js decides when; header.js lets
// it go on logging out. On every page with the header, for the logout

// This browser is subscribed to Web Push
const PUSH_ON_KEY = 'pushOn';
const pushPublicKey = document.querySelector('meta[name="push-public-key"]')?.content || '';

const isPushSupported = () => pushPublicKey !== '' && 'serviceWorker' in navigator && 'PushManager' in window;

// The key as the browser wants it: bytes, from URL-safe base64
function keyBytes(base64) {
    const plain = (base64 + '='.repeat((4 - (base64.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');
    return Uint8Array.from(atob(plain), (character) => character.charCodeAt(0));
}

// This browser's subscription, or null when it has none
async function getPushSubscription() {
    const registration = await navigator.serviceWorker?.getRegistration();
    return (await registration?.pushManager?.getSubscription()) ?? null;
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
    const subscription = await getPushSubscription();
    if (!subscription) return;
    await postForm('/push/unsubscribe', {endpoint: subscription.endpoint}).catch(() => {});
    await subscription.unsubscribe();
}
