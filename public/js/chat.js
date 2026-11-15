// The chat page: messages, tabs for private chats, and sending. The people
// list is in people.js. The server's values come from data attributes on
// #chat (see base_chat.html)

const MESSAGES_INTERVAL = 1000;
const MESSAGES_HIDDEN_INTERVAL = 8000;
// The MapChat bot's made-up country, at 0°, 0° (a code free for private use,
// so Intl has no name for it)
const NULL_ISLAND = 'XN';
// Scrolling down to new messages; none for people who ask for less motion
const SCROLL_BEHAVIOR = window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth';
// How far up you scroll before the "Latest" button shows
const SCROLLED_UP_DISTANCE = 200;
// Closer to the bottom than this, new messages scroll into view
const AT_BOTTOM_DISTANCE = 80;
const EVERYONE = 'everyone';

const chatPanel = document.getElementById('chat');
const chatTabs = document.getElementById('chat-tabs');
const chatList = document.getElementById('chat-list');
const composerForm = document.getElementById('input-container-form');
const messageInput = document.getElementById('textarea-send');
const sendButton = document.getElementById('button-send');

// The tab being shown: "everyone", or the id of the person in a private chat
let activeConversation = EVERYONE;

// Ids of the people online, from the last people list (people.js)
let onlineUserIds = new Set();

// Messages that came into the open tab while you were scrolled up
let newMessagesWhileScrolledUp = 0;

const getConversation = (chat) => chat.dataset.conversation;
const getChats = () => Array.from(chatList.querySelectorAll('.chat'));
const getDistanceFromBottom = () => chatList.scrollHeight - chatList.scrollTop - chatList.clientHeight;

function scrollToNewest(behavior = SCROLL_BEHAVIOR) {
    chatList.scrollTo({top: chatList.scrollHeight, behavior});
}

/* Tabs */

// Run a change that resizes or removes tabs, then let the tabs that moved
// slide to their new place instead of jumping. translate, not transform,
// which would undo the tabs' flip
function slideTabs(change) {
    const tabs = Array.from(chatTabs.querySelectorAll('.chat-tab'));
    const leftsBefore = tabs.map((tab) => tab.getBoundingClientRect().left);

    change();

    const shiftedTabs = [];
    tabs.forEach((tab, index) => {
        if (!tab.isConnected) return;
        const shift = leftsBefore[index] - tab.getBoundingClientRect().left;
        if (Math.round(shift) === 0) return;
        tab.style.translate = `${shift}px 0`;
        shiftedTabs.push(tab);
    });

    requestAnimationFrame(() => {
        shiftedTabs.forEach((tab) => {
            tab.classList.add('is-shifting');
            tab.style.translate = '';
            onTransitionEnd(tab, () => tab.classList.remove('is-shifting'));
        });
    });
}

// Tabs from the history just appear; ones opened later ease in
let shouldAnimateNewTabs = false;

function getTab(conversation) {
    return chatTabs.querySelector(`.chat-tab[data-conversation="${CSS.escape(conversation)}"]:not(.is-leaving)`);
}

function addTab(conversation, name) {
    const existingTab = getTab(conversation);
    if (existingTab) return existingTab;

    const tab = createElement('a', 'chat-tab');
    tab.href = '#';
    tab.dataset.conversation = conversation;
    tab.classList.toggle('is-entering', shouldAnimateNewTabs);

    const onlineDot = createElement('span', 'chat-tab__online');
    onlineDot.title = 'Online';
    onlineDot.hidden = !onlineUserIds.has(Number(conversation));

    const nameLabel = createElement('span', 'chat-tab__name', name);
    nameLabel.title = name;

    const unreadCount = createElement('span', 'chat-tab__unread');
    unreadCount.hidden = true;

    const closeButton = createElement('span', 'chat-tab__close', '×');
    closeButton.title = 'Close';

    tab.append(onlineDot, nameLabel, unreadCount, closeButton);
    chatTabs.append(tab);
    return tab;
}

function showConversation(conversation) {
    activeConversation = String(conversation);

    chatTabs.querySelectorAll('.chat-tab').forEach((tab) => tab.classList.remove('is-active'));
    const tab = getTab(activeConversation);
    tab.classList.add('is-active');
    slideTabs(() => {
        const unreadCount = tab.querySelector('.chat-tab__unread');
        unreadCount.hidden = true;
        unreadCount.textContent = '';
    });
    tab.scrollIntoView({block: 'nearest', inline: 'nearest'});

    getChats().forEach((chat) => {
        chat.style.display = getConversation(chat) === activeConversation ? '' : 'none';
    });
    replayAnimation(chatList, 'is-switching');

    const name = tab.querySelector('.chat-tab__name').textContent;
    const isPrivate = activeConversation !== EVERYONE;

    document.getElementById('private-note').hidden = !isPrivate;
    document.getElementById('private-note-text').textContent = `Private chat with ${name}`;
    messageInput.placeholder = isPrivate ? `Message ${name}` : 'Type your message';
    addDayDividers();
    scrollToNewest('instant');
    newMessagesWhileScrolledUp = 0;
}

function closeTab(tab) {
    if (tab.classList.contains('is-active')) {
        showConversation(EVERYONE);
    }
    tab.classList.add('is-leaving');
    onAnimationEnd(tab, () => slideTabs(() => tab.remove()));
}

function openPrivateChat(userId, name) {
    addTab(String(userId), name);
    showConversation(userId);
    messageInput.focus();
}

// A green dot on the tab of each private chat partner who's online;
// it fades only when that changes
function updateOnlineDots(userIds) {
    onlineUserIds = userIds;
    chatTabs.querySelectorAll('.chat-tab__online').forEach((dot) => {
        const isPartnerOnline = onlineUserIds.has(Number(dot.closest('.chat-tab').dataset.conversation));
        if (isPartnerOnline === !dot.hidden) return;

        if (isPartnerOnline) {
            dot.hidden = false;
            return;
        }
        dot.classList.add('is-leaving');
        onAnimationEnd(dot, () => {
            dot.hidden = true;
            dot.classList.remove('is-leaving');
        });
    });
}

chatTabs.addEventListener('click', (event) => {
    const tab = event.target.closest('.chat-tab');
    if (!tab) return;
    event.preventDefault();
    // A tab of a folded chat brings the chat back up
    if (isChatFolded()) setChatFolded(false);

    if (event.target.closest('.chat-tab__close')) {
        closeTab(tab);
    } else {
        showConversation(tab.dataset.conversation);
    }
});

// A mouse wheel scrolls the tabs sideways
chatTabs.addEventListener('wheel', (event) => {
    chatTabs.scrollLeft += event.deltaY;
});

/* Messages */

// The time where the sender was. Without their time zone, an estimate from
// longitude (an hour per 15 degrees), marked with "~"
function getSenderTime(date, timezone, longitude) {
    const format = {hour: 'numeric', minute: '2-digit'};

    if (timezone) {
        return {
            text: date.toLocaleTimeString([], {...format, timeZone: timezone}),
            hour: Number(date.toLocaleString('en-US', {hour: 'numeric', hourCycle: 'h23', timeZone: timezone})),
        };
    }

    const shiftedDate = new Date(date.getTime() + Math.round(longitude / 15) * 3600000);
    return {
        text: `~${shiftedDate.toLocaleTimeString([], {...format, timeZone: 'UTC'})}`,
        hour: shiftedDate.getUTCHours(),
    };
}

// Older browsers lack Intl.DisplayNames; they show the country code instead
const countryNames = window.Intl?.DisplayNames ? new Intl.DisplayNames(['en'], {type: 'region'}) : null;

function getCountryName(code) {
    if (code === NULL_ISLAND) return 'Republic of Null Island';
    return countryNames ? countryNames.of(code) : code;
}

const getSentDate = (chat) => new Date(chat.querySelector('time').getAttribute('datetime'));

function showSenderTime(chat) {
    const senderTimeLine = chat.querySelector('.chat__sender-time');
    if (!senderTimeLine) return;

    const timeInfo = chat.querySelector('.chat__time');
    // Always midnight on Null Island, where the time zones meet
    const senderTime = timeInfo.dataset.country === NULL_ISLAND
        ? {text: '00:00', hour: 0}
        : getSenderTime(getSentDate(chat), timeInfo.dataset.timezone, parseFloat(timeInfo.dataset.lng));
    const isDaytime = senderTime.hour >= 6 && senderTime.hour < 18;

    const daylightIcon = senderTimeLine.querySelector('.chat__daylight');
    daylightIcon.textContent = isDaytime ? 'wb_sunny' : 'nights_stay';
    daylightIcon.classList.add(isDaytime ? 'chat__daylight--day' : 'chat__daylight--night');
    senderTimeLine.querySelector('span').textContent = senderTime.text;
}

// Each message shows its time; hovering it slides to the sender's own time.
// Also names the sender's country, shown when hovering the city
function showTimes(chats) {
    chats.forEach((chat) => {
        chat.querySelectorAll('.chat__country').forEach((country) => {
            country.textContent = getCountryName(country.dataset.country);
        });

        chat.querySelector('time').textContent = getSentDate(chat).toLocaleTimeString([], {hour: 'numeric', minute: '2-digit'});
        showSenderTime(chat);
    });
}

function getDayLabel(date) {
    const yesterday = new Date();
    yesterday.setDate(yesterday.getDate() - 1);

    if (date.toDateString() === new Date().toDateString()) return 'Today';
    if (date.toDateString() === yesterday.toDateString()) return 'Yesterday';
    return date.toLocaleDateString([], {month: 'short', day: 'numeric'});
}

// A divider wherever the day changes in the conversation being shown
function addDayDividers() {
    chatList.querySelectorAll('.day-divider').forEach((divider) => divider.remove());

    let lastDay = null;
    getChats()
        .filter((chat) => getConversation(chat) === activeConversation)
        .forEach((chat) => {
            const date = getSentDate(chat);
            if (date.toDateString() === lastDay) return;

            const divider = createElement('div', 'day-divider');
            divider.append(createElement('span', 'day-divider__label', getDayLabel(date)));
            chat.before(divider);
            lastDay = date.toDateString();
        });
}

function countUnread(tab) {
    const unreadCount = tab.querySelector('.chat-tab__unread');
    slideTabs(() => {
        unreadCount.classList.toggle('is-entering', unreadCount.hidden);
        unreadCount.textContent = (parseInt(unreadCount.textContent, 10) || 0) + 1;
        unreadCount.hidden = false;
    });
}

// Put new messages in their tab and count the ones in other tabs
function placeMessages(chats) {
    chats.forEach((chat) => {
        const conversation = getConversation(chat);
        const tab = addTab(conversation, chat.dataset.partner);

        if (conversation !== activeConversation) {
            chat.style.display = 'none';
            countUnread(tab);
        }
    });
}

chatList.addEventListener('click', (event) => {
    const senderName = event.target.closest('.chat__name--can-message');
    if (senderName) {
        openPrivateChat(senderName.dataset.userId, senderName.dataset.userName);
    }
});

/* The map toggle and the chat's fold caret, on phones */

const mapToggle = document.getElementById('map-toggle');
const chatFoldButton = document.getElementById('chat-fold');

const isChatFolded = () => document.body.classList.contains('is-chat-folded');

// With the map open, the chat folds down to its tab row and the map gets
// the screen
function setChatFolded(isFolded) {
    const label = isFolded ? 'Show the chat' : 'Fold the chat away';
    document.body.classList.toggle('is-chat-folded', isFolded);
    chatFoldButton.setAttribute('aria-expanded', String(!isFolded));
    chatFoldButton.setAttribute('aria-label', label);
    chatFoldButton.title = label;
    // What's folded away can't be tabbed to
    chatList.parentElement.inert = isFolded;
    composerForm.inert = isFolded;
}

chatFoldButton.addEventListener('click', () => setChatFolded(!isChatFolded()));

// Phones start with the map hidden; the button brings a strip of it back,
// and the choice is remembered for next time
function setMapOpen(isOpen) {
    const label = isOpen ? 'Hide the map' : 'Show the map';
    document.body.classList.toggle('is-map-open', isOpen);
    if (!isOpen) setChatFolded(false);
    mapToggle.setAttribute('aria-pressed', String(isOpen));
    mapToggle.setAttribute('aria-label', label);
    mapToggle.title = label;
    try {
        localStorage.setItem('mapOpen', isOpen ? '1' : '0');
    } catch {
        // Storage blocked: the choice just isn't remembered
    }
}

try {
    setMapOpen(localStorage.getItem('mapOpen') === '1');
} catch {
    // Storage blocked: the map starts hidden, as it does the first time
}

mapToggle.addEventListener('click', () => {
    setMapOpen(!document.body.classList.contains('is-map-open'));
});

/* The "Latest" button: shows once you've scrolled up, counts messages in
   this tab that arrive meanwhile, and takes you back down */

const jumpButton = document.getElementById('jump-latest');
const jumpLabel = document.getElementById('jump-latest-text');

function getJumpLabelText() {
    if (newMessagesWhileScrolledUp === 0) return 'Latest';
    if (newMessagesWhileScrolledUp === 1) return '1 new message';
    return `${newMessagesWhileScrolledUp} new messages`;
}

// The pill eases to the new label's width instead of jumping. Going from
// "Latest" to a count, the words fade in; after that only the number
// changes, so nothing fades and nothing flickers
function changeJumpLabel(text) {
    const wasLatest = jumpLabel.textContent === 'Latest';
    const widthBefore = jumpButton.offsetWidth;
    jumpLabel.textContent = text;
    jumpButton.style.width = '';
    const widthAfter = jumpButton.offsetWidth;

    jumpButton.style.width = `${widthBefore}px`;
    void jumpButton.offsetWidth; // start from the old width
    jumpButton.style.width = `${widthAfter}px`;
    if (wasLatest) replayAnimation(jumpLabel, 'is-changing');
}

function updateJumpButton() {
    const isScrolledUp = getDistanceFromBottom() > SCROLLED_UP_DISTANCE;
    if (!isScrolledUp) newMessagesWhileScrolledUp = 0;

    const text = getJumpLabelText();
    jumpButton.hidden = !isScrolledUp;

    if (!isScrolledUp || jumpLabel.textContent === text) {
        jumpLabel.textContent = text;
        jumpButton.style.width = '';
        return;
    }
    changeJumpLabel(text);
}

chatList.addEventListener('scroll', updateJumpButton);

// The button hides once you're down, so keyboard focus moves on to the
// composer instead of falling back to the top of the page. Taps leave
// focus alone so a phone's keyboard doesn't pop up
jumpButton.addEventListener('click', () => {
    scrollToNewest();
    if (jumpButton.matches(':focus-visible')) messageInput.focus();
});

/* Checking for new messages */

// The newest message this page has; each tab keeps its own
let lastChatId = Number(chatPanel.dataset.lastChatId);

// A new version went live: reload, so new messages don't arrive in markup
// the page's old CSS and JS don't know. Not while something is typed
const pageBuild = chatPanel.dataset.build;

function reloadIfUpdated(liveBuild) {
    if (pageBuild && liveBuild && liveBuild !== pageBuild && messageInput.value.trim() === '') {
        window.location.reload();
    }
}

// Scrolls down for new messages only if you're at the bottom already, or forced
// (after you send); someone reading older messages stays where they are
async function checkForMessages(shouldScrollToNewest = false) {
    const response = await postForm('/app/get', {after: lastChatId});
    reloadIfUpdated(response.headers['x-build']);

    // Two checks can overlap (e.g. right after sending), so skip any already shown
    const newChats = parseHtml(response.data)
        .filter((element) => element.classList.contains('chat') && Number(element.dataset.id) > lastChatId);
    if (newChats.length === 0) return;

    const wasAtBottom = getDistanceFromBottom() < AT_BOTTOM_DISTANCE;

    showTimes(newChats);
    chatList.append(...newChats);
    fadeInPhotos(newChats.map((chat) => chat.querySelector('.chat__photo')));
    placeMessages(newChats);
    addDayDividers();
    // Only once they're shown, so a failure above fetches them again
    lastChatId = Number(newChats.at(-1).dataset.id);

    if (wasAtBottom || shouldScrollToNewest) {
        scrollToNewest();
    } else {
        // Only ones in the open tab; the others get a count on their tab
        newMessagesWhileScrolledUp += newChats.filter((chat) => chat.style.display !== 'none').length;
        updateJumpButton();
    }
}

// Every second, or less often while the tab is hidden
function keepCheckingForMessages() {
    checkForMessages()
        .catch(() => {
            // Try again on the next round
        })
        .finally(() => {
            setTimeout(keepCheckingForMessages, document.hidden ? MESSAGES_HIDDEN_INTERVAL : MESSAGES_INTERVAL);
        });
}

// Catch up at once when the tab is shown again
document.addEventListener('visibilitychange', () => {
    if (!document.hidden) {
        checkForMessages().catch(() => {});
    }
});

/* Sending */

// Send stays off until the message is out, so a second Enter can't post it twice
let isSending = false;

function updateSendButton() {
    sendButton.disabled = isSending || messageInput.value.trim() === '';
}

// The box grows with what's typed, up to its CSS max-height
function fitMessageInputToText() {
    messageInput.style.height = 'auto';
    // scrollHeight leaves out the border, which the box's height includes
    const borderHeight = messageInput.offsetHeight - messageInput.clientHeight;
    messageInput.style.height = `${messageInput.scrollHeight + borderHeight}px`;
}

function showCharacterCount() {
    const length = messageInput.value.length;
    const maxLength = messageInput.maxLength;
    const characterCount = document.getElementById('char-count');

    characterCount.hidden = length < maxLength - 100;
    characterCount.classList.toggle('is-near-limit', length > maxLength - 20);
    characterCount.textContent = `${length} / ${maxLength}`;
}

composerForm.addEventListener('submit', (event) => {
    event.preventDefault();
    if (isSending) return;

    const message = {body: messageInput.value.trim()};
    if (activeConversation !== EVERYONE) {
        message.to = activeConversation;
    }

    isSending = true;
    updateSendButton();

    postForm('/app/send', message)
        .then(() => {
            messageInput.value = '';
            messageInput.dispatchEvent(new Event('input'));
            return checkForMessages(true);
        })
        .catch((error) => {
            showToast(getErrorMessage(error, 'Your message could not be sent.'), 'warning');
        })
        .finally(() => {
            isSending = false;
            updateSendButton();
        });
});

// Enter sends, Shift+Enter starts a new line
messageInput.addEventListener('keydown', (event) => {
    if (event.key !== 'Enter' || event.shiftKey || event.isComposing) return;
    event.preventDefault();
    if (!sendButton.disabled) {
        composerForm.requestSubmit();
    }
});

// Grey out Send while the box is empty; show the count near the limit
messageInput.addEventListener('input', () => {
    updateSendButton();
    fitMessageInputToText();
    showCharacterCount();
});

/* Start */

const historyChats = getChats();
showTimes(historyChats);
fadeInPhotos(chatList.querySelectorAll('.chat__photo'));
historyChats.forEach((chat) => addTab(getConversation(chat), chat.dataset.partner));
showConversation(EVERYONE);
shouldAnimateNewTabs = true;

keepPeopleUpdated(updateOnlineDots, openPrivateChat);
keepCheckingForMessages();

// Slide the panels in
document.body.classList.add('is-ready');
