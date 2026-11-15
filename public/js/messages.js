// Messages to and from the server: checking for new ones every second (less
// often in a hidden tab), fetching older ones, and sending. chat.js starts it
// with keepMessagesUpdated and shows what comes in

const MESSAGES_INTERVAL = 1000;
const MESSAGES_HIDDEN_INTERVAL = 8000;

// The newest message this page has; each tab keeps its own
let lastChatId = Number(document.getElementById('chat').dataset.lastChatId);

// Calls onNewChats(chats, isAfterSending) with the messages newer than any
// shown, and onBuild(build) with the version of the site that's live
let onNewChats = () => {};
let onBuild = () => {};

async function checkForMessages(isAfterSending = false) {
    const response = await postForm('/app/get', {after: lastChatId});
    onBuild(response.headers['x-build']);

    // Two checks can overlap (e.g. right after sending), so skip any already shown
    const newChats = parseHtml(response.data)
        .filter((element) => element.classList.contains('chat') && Number(element.dataset.id) > lastChatId);
    if (newChats.length === 0) return;

    onNewChats(newChats, isAfterSending);
    // Only once they're shown, so a failure there fetches them again
    lastChatId = Number(newChats.at(-1).dataset.id);
}

function keepCheckingForMessages() {
    checkForMessages()
        .catch(() => {
            // Try again on the next round
        })
        .finally(() => {
            setTimeout(keepCheckingForMessages, document.hidden ? MESSAGES_HIDDEN_INTERVAL : MESSAGES_INTERVAL);
        });
}

// Checks now and every round after. Catches up at once when the tab is
// shown again
function keepMessagesUpdated(newChatsHandler, buildHandler) {
    onNewChats = newChatsHandler;
    onBuild = buildHandler;

    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) {
            checkForMessages().catch(() => {});
        }
    });

    keepCheckingForMessages();
}

// A page of messages older than the one with id before, oldest first
async function fetchOlderChats(before) {
    const response = await postForm('/app/get-older', {before});
    return parseHtml(response.data).filter((element) => element.classList.contains('chat'));
}

// Sends to everyone, or privately when to is a person's id, then fetches it
// back so it shows at once. That check failing doesn't fail the send: the
// message went out, and the next round brings it
async function sendMessage(body, to = null) {
    const message = {body};
    if (to !== null) {
        message.to = to;
    }

    await postForm('/app/send', message);
    checkForMessages(true).catch(() => {});
}
