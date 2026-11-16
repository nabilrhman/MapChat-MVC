// Messages to and from the server. Normally over the chat socket, one
// WebSocket for the page: new messages, sending, older pages, read marks,
// typing and who's online all go over it, and nothing polls. When the socket
// can't connect, the page falls back to HTTP, checking for new messages every
// second (less often in a hidden tab), and keeps trying the socket; once it
// connects, polling stops. chat.js starts it with connectChat and shows what
// comes in.
//
// Both ways share lastChatId, the newest message shown: the socket resumes
// from it on every (re)connect, and anything at or below it is skipped, so
// each message shows once and in order however the two overlap

const MESSAGES_INTERVAL = 1000;
const MESSAGES_HIDDEN_INTERVAL = 8000;
// The socket's own check that the server is still there; it also pings
const SOCKET_PING_INTERVAL = 25000;
const SOCKET_SILENCE_LIMIT = 35000;
// Waits before reconnecting, longer each failed try in a row, with jitter
// so a restarted server isn't hit by every page at once
const RECONNECT_DELAYS = [500, 1000, 2000, 4000, 8000, 15000, 30000];
// Failed tries in a row before falling back to polling meanwhile
const FAILURES_BEFORE_POLLING = 2;
// A send not saved by then fails; sending the same text again reuses its id
const SEND_TIMEOUT = 15000;
// A page of older messages not back by then fails; the next scroll asks again
const OLDER_TIMEOUT = 15000;
// Close codes from the chat socket (App\ChatSocket\Tab)
const CLOSE_LOG_IN_AGAIN = 4401;
// The open room's conversation; a private one is the other person's id
const EVERYONE = 'everyone';

// The newest message this page has; each tab keeps its own
let lastChatId = Number(document.getElementById('chat').dataset.lastChatId);

// What chat.js does with what comes in (see connectChat)
let chatHandlers = null;

let socket = null;
// Whether the socket said welcome, so it carries everything
let isSocketLive = false;
let failuresInARow = 0;
let lastHeardAt = 0;
let pingTimer = null;

let isPolling = false;
let pollTimer = null;

// Sends not saved yet, by client id, to send again after a reconnect
const pendingSends = new Map();
// Ids of messages this page sent, so they scroll into view when they arrive
const sentIds = new Set();
// The last send that failed: the same text again gets the same client id,
// so if the first one was saved after all it isn't saved twice
let failedSend = null;

const olderRequests = new Map();
let nextRequestId = 1;

const isChatSocketLive = () => isSocketLive;

/* Showing what arrives */

// Hands chat.js the messages newer than any shown
function showArrivedChats(html, isAfterSending) {
    const newChats = parseHtml(html)
        .filter((element) => element.classList.contains('chat') && Number(element.dataset.id) > lastChatId);
    if (newChats.length === 0) return;

    const hasOwnSend = newChats.some((chat) => sentIds.has(Number(chat.dataset.id)));
    chatHandlers.onNewChats(newChats, isAfterSending || hasOwnSend);
    // Only once they're shown, so a failure there fetches them again
    lastChatId = Number(newChats.at(-1).dataset.id);
}

/* The chat socket */

function getSocketUrl() {
    const protocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
    return `${protocol}//${window.location.host}/ws`;
}

function openSocket() {
    socket = new WebSocket(getSocketUrl());
    const thisSocket = socket;

    thisSocket.addEventListener('open', () => {
        lastHeardAt = Date.now();
        thisSocket.send(JSON.stringify({
            type: 'hello',
            after: lastChatId,
            timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
        }));
    });
    thisSocket.addEventListener('message', (event) => {
        lastHeardAt = Date.now();
        onSocketMessage(JSON.parse(event.data));
    });
    thisSocket.addEventListener('close', (event) => {
        if (socket === thisSocket) onSocketClosed(event.code);
    });
}

function onSocketMessage(message) {
    switch (message.type) {
        case 'welcome':
            onSocketWelcome(message);
            break;
        case 'chats':
            showArrivedChats(message.html, false);
            break;
        case 'sent':
            settleSend(message.clientId, (send) => {
                sentIds.add(message.id);
                send.resolve(message.id);
            });
            break;
        case 'refused':
            settleSend(message.clientId, (send) => send.reject(createSendError(message.error, message.status)));
            break;
        case 'older':
            settleOlderRequest(message.requestId, (request) => request.resolve(message.html));
            break;
        case 'read':
            chatHandlers.onRead(message.conversation, message.upTo);
            break;
        case 'typing':
            chatHandlers.onTyping(String(message.from), message.isTyping);
            break;
        case 'presence':
            chatHandlers.onPresence(message.person);
            break;
    }
}

// Connected: the socket carries everything now, polling stops, and any
// send whose answer was lost goes again under the same id
function onSocketWelcome(message) {
    isSocketLive = true;
    failuresInARow = 0;
    stopPolling();

    chatHandlers.onBuild(message.build);
    chatHandlers.onPeople(message.people);
    pendingSends.forEach((send, clientId) => sendOverSocket(clientId, send));

    clearInterval(pingTimer);
    pingTimer = setInterval(checkSocketIsAlive, SOCKET_PING_INTERVAL);
}

// A socket that went quiet (a laptop waking, a dropped network) may never
// fire close; end it so a new one is made
function checkSocketIsAlive() {
    if (Date.now() - lastHeardAt > SOCKET_SILENCE_LIMIT) {
        socket.close();
        onSocketClosed(0);
        return;
    }
    socket.send(JSON.stringify({type: 'ping'}));
}

function onSocketClosed(code) {
    const wasLive = isSocketLive;
    socket = null;
    isSocketLive = false;
    clearInterval(pingTimer);

    if (code === CLOSE_LOG_IN_AGAIN) {
        window.location.href = '/login';
        return;
    }

    // Older pages asked for are asked again on the next scroll
    olderRequests.forEach((request, requestId) => {
        settleOlderRequest(requestId, () => request.reject(new Error('The chat reconnected')));
    });
    chatHandlers.onDisconnected();

    if (!wasLive) failuresInARow += 1;
    if (failuresInARow >= FAILURES_BEFORE_POLLING) startPolling();

    const delay = RECONNECT_DELAYS[Math.min(failuresInARow, RECONNECT_DELAYS.length - 1)];
    setTimeout(openSocket, delay * (0.5 + Math.random()));
}

function sendOverSocket(clientId, send) {
    socket.send(JSON.stringify({type: 'send', clientId, body: send.body, to: send.to}));
}

/* The HTTP fallback */

async function checkForMessages(isAfterSending = false) {
    const response = await postForm('/app/get', {after: lastChatId});
    chatHandlers.onBuild(response.headers['x-build']);
    showArrivedChats(response.data, isAfterSending);
}

async function keepCheckingForMessages() {
    if (!isPolling) return;

    try {
        await checkForMessages();
    } catch {
        // Try again on the next round
    }
    if (!isPolling) return;
    pollTimer = setTimeout(keepCheckingForMessages, document.hidden ? MESSAGES_HIDDEN_INTERVAL : MESSAGES_INTERVAL);
}

function startPolling() {
    if (isPolling) return;
    isPolling = true;
    keepCheckingForMessages();
    chatHandlers.onPollingChanged(true);
    // Sends waiting for the socket go by HTTP instead
    pendingSends.forEach((send, clientId) => sendOverHttp(clientId, send));
}

function stopPolling() {
    if (!isPolling) return;
    isPolling = false;
    clearTimeout(pollTimer);
    chatHandlers.onPollingChanged(false);
}

async function sendOverHttp(clientId, send) {
    const message = {body: send.body, client_id: clientId};
    if (send.to !== null) message.to = send.to;

    let response;
    try {
        response = await postForm('/app/send', message);
    } catch (error) {
        // No answer (a network error): it stays pending for the next try
        if (error.response) settleSend(clientId, (pending) => pending.reject(error));
        return;
    }
    settleSend(clientId, (pending) => pending.resolve(response.data.id));
    // So it shows at once rather than on the next round. That failing
    // doesn't fail the send: the message went out, the next round brings it
    checkForMessages(true).catch(() => {});
}

/* What chat.js calls */

// Connects, or polls where WebSockets aren't available. handlers:
// onNewChats(chats, isAfterSending), onBuild(build), onPeople(people),
// onPresence(person), onRead(conversation, upTo), onTyping(from, isTyping),
// onDisconnected(), onPollingChanged(isPolling)
function connectChat(handlers) {
    chatHandlers = handlers;

    document.addEventListener('visibilitychange', () => {
        if (!document.hidden && isPolling) {
            checkForMessages().catch(() => {});
        }
    });

    if (!window.WebSocket) {
        startPolling();
        return;
    }
    openSocket();
}

function settleOlderRequest(requestId, settle) {
    const request = olderRequests.get(requestId);
    if (!request) return;

    olderRequests.delete(requestId);
    clearTimeout(request.timer);
    settle(request);
}

function settleSend(clientId, settle) {
    const send = pendingSends.get(clientId);
    if (!send) return;

    pendingSends.delete(clientId);
    clearTimeout(send.timer);
    settle(send);
}

// An error shaped like axios's, so errorMessageOf reads both the same
function createSendError(message, status) {
    return Object.assign(new Error(message), {response: {status, data: {error: message}}});
}

function createClientId() {
    if (window.crypto?.randomUUID) return crypto.randomUUID();
    return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 14)}`;
}

// Sends to everyone, or privately when to is a person's id. Resolves with
// the message's id once it's saved; the message itself arrives like any
// other. A send whose answer is lost is sent again with the same client id
// on reconnecting, and the server gives back the first one's message
function sendMessage(body, to = null) {
    const toId = to === null ? null : Number(to);
    const isRetry = failedSend && failedSend.body === body && failedSend.to === toId;
    const clientId = isRetry ? failedSend.clientId : createClientId();
    failedSend = null;

    return new Promise((resolve, reject) => {
        const send = {body, to: toId, resolve, reject};
        send.timer = setTimeout(() => {
            settleSend(clientId, () => {
                failedSend = {body, to: toId, clientId};
                reject(new Error('No answer from the server'));
            });
        }, SEND_TIMEOUT);
        pendingSends.set(clientId, send);

        if (isSocketLive) {
            sendOverSocket(clientId, send);
        } else if (isPolling) {
            sendOverHttp(clientId, send);
        }
        // Otherwise it goes when the socket connects, or polling starts
    });
}

// A page of a tab's messages older than the one with id before, oldest
// first: conversation is "everyone" or the other person's id
async function fetchOlderChats(before, conversation) {
    let html;
    if (isSocketLive) {
        const requestId = nextRequestId++;
        html = await new Promise((resolve, reject) => {
            const request = {resolve, reject};
            request.timer = setTimeout(() => {
                settleOlderRequest(requestId, () => reject(new Error('No answer from the server')));
            }, OLDER_TIMEOUT);
            olderRequests.set(requestId, request);
            socket.send(JSON.stringify({type: 'older', requestId, before, conversation}));
        });
    } else {
        html = (await postForm('/app/get-older', {before, conversation})).data;
    }

    // Anything not yet shown arrives with the new ones instead
    return parseHtml(html)
        .filter((element) => element.classList.contains('chat') && Number(element.dataset.id) <= lastChatId);
}

// Tells the server you've seen the private messages from a person, up to
// an id; your other open tabs hear of it too
function markChatRead(from, upTo) {
    if (isSocketLive) {
        socket.send(JSON.stringify({type: 'read', from: Number(from), upTo}));
        return Promise.resolve();
    }
    return postForm('/app/mark-read', {from, up_to: upTo});
}

// Tells the person you're writing to privately that you are, or have
// stopped. Only over the socket; the HTTP fallback goes without
function sendTyping(to, isTyping) {
    if (!isSocketLive) return;
    socket.send(JSON.stringify({type: 'typing', to: Number(to), isTyping}));
}
