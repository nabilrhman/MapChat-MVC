// The chat page: messages, tabs for private chats, and the composer. Talking
// to the server about messages is in messages.js, the people list in
// people.js. The server's values come from data attributes on #chat (see
// base_chat.html)

// The MapChat bot's made-up country, at 0°, 0° (a code free for private use,
// so Intl has no name for it)
const NULL_ISLAND = 'XN';
// Scrolling down to new messages; none for people who ask for less motion
const SCROLL_BEHAVIOR = window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth';
// How far up you scroll before the "Latest" button shows
const SCROLLED_UP_DISTANCE = 200;
// A little after a demo person you called comes online (Bot::COME_ONLINE_SECONDS)
const COME_ONLINE_DELAY = 7000;
// Closer to the bottom than this, new messages scroll into view
const AT_BOTTOM_DISTANCE = 80;
// Closer to the top than this, the next page of older messages loads
const LOAD_OLDER_DISTANCE = 200;
// "Typing" goes to the other person at most this often while you type,
// and stops after this long without a key
const TYPING_SEND_INTERVAL = 2000;
const TYPING_IDLE_DELAY = 3000;
// How long their "Typing..." shows without a fresh word (the server's is 6 s)
const TYPING_SHOWN_FOR = 7000;
// More messages than this on the page, the oldest go while you're at the
// bottom, so a tab left open in a busy room stays small
const MAX_MESSAGES = 300;
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

// True while the list scrolls itself down, so the "Latest" button doesn't
// flash up during the smooth scroll
let isScrollingToNewest = false;
let scrollToNewestTimer = null;

function stopScrollingToNewest() {
    if (!isScrollingToNewest) return;
    clearTimeout(scrollToNewestTimer);
    isScrollingToNewest = false;
    updateJumpButton();
}

function scrollToNewest(behavior = SCROLL_BEHAVIOR) {
    // Only when it will move, or nothing would end it until the timer
    if (getDistanceFromBottom() > 1) {
        isScrollingToNewest = true;
        clearTimeout(scrollToNewestTimer);
        // scrollend ends it; the timer is for browsers without scrollend and
        // hidden tabs, which don't animate the scroll
        scrollToNewestTimer = setTimeout(stopScrollingToNewest, 1000);
    }
    chatList.scrollTo({top: chatList.scrollHeight, behavior});
}

chatList.addEventListener('scrollend', stopScrollingToNewest);

/* Tabs */

// Run a change that resizes or removes tabs, then let the tabs that moved
// slide to their new place instead of jumping. translate, not transform,
// which would undo the tabs' flip
function slideTabs(change) {
    const tabs = Array.from(chatTabs.querySelectorAll('.chat-tab'));
    const leftsBefore = tabs.map((tab) => tab.getBoundingClientRect().left);

    change();
    keepOpenTabWhole();

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

/* Closed private tabs stay closed, across reloads and logging out, until a
   newer message comes in or you open the chat again */

// Per account, on this device: conversation → newest message id when closed
const closedTabsKey = `closedTabs:${myUserId}`;
let closedTabs = {};
try {
    closedTabs = JSON.parse(localStorage.getItem(closedTabsKey)) || {};
} catch {
    // No storage (private browsing): tabs just come back on reload
}

function saveClosedTabs() {
    try {
        localStorage.setItem(closedTabsKey, JSON.stringify(closedTabs));
    } catch {
        // As above
    }
}

function forgetClosedTab(conversation) {
    if (!(conversation in closedTabs)) return;
    delete closedTabs[conversation];
    saveClosedTabs();
}

// The chat's tab, unless it was closed after this message; a newer message
// reopens it
function addTabFor(chat) {
    const conversation = getConversation(chat);
    if (Number(chat.dataset.id) <= (closedTabs[conversation] ?? 0)) return null;

    forgetClosedTab(conversation);
    return addTab(conversation, chat.dataset.partner);
}

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

    const unreadCount = createElement('span', 'chat-tab__unread');
    unreadCount.hidden = true;

    const closeButton = createElement('span', 'chat-tab__close', '×');
    closeButton.title = 'Close';

    tab.append(onlineDot, nameLabel, unreadCount, closeButton);
    chatTabs.append(tab);
    return tab;
}

// The open tab is always shown whole, close button and all, however the
// tabs before it grew (an unread count) or went. Only the tab row scrolls,
// at once; slideTabs glides the tabs from where they were. By layout
// position, which a tab growing in already has in full (the row is its
// offsetParent, as it's transformed)
function keepOpenTabWhole() {
    const tab = chatTabs.querySelector('.chat-tab.is-active:not(.is-leaving)');
    if (!tab) return;

    const left = tab.offsetLeft;
    const right = left + tab.offsetWidth;
    if (left < chatTabs.scrollLeft) {
        chatTabs.scrollLeft = left;
    } else if (right > chatTabs.scrollLeft + chatTabs.clientWidth) {
        chatTabs.scrollLeft = right - chatTabs.clientWidth;
    }
}

// And when the row itself gets narrower (a smaller window, the people
// list opening beside it)
new ResizeObserver(keepOpenTabWhole).observe(chatTabs);

function showConversation(conversation) {
    // Switching chats stops "typing" in the one you leave
    stopTyping();
    activeConversation = String(conversation);

    chatTabs.querySelectorAll('.chat-tab').forEach((tab) => tab.classList.remove('is-active'));
    const tab = getTab(activeConversation);
    tab.classList.add('is-active');
    // Also shows the tab whole (keepOpenTabWhole)
    clearUnreadCount(tab);

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

    showPartnerTyping();
    markConversationRead(activeConversation);
    // A private chat a busy room pushed out of the page loads its own messages
    if (!hasChatsIn(activeConversation)) loadOlderIfAny(activeConversation);
}

function clearUnreadCount(tab) {
    slideTabs(() => {
        const unreadCount = tab.querySelector('.chat-tab__unread');
        unreadCount.hidden = true;
        unreadCount.textContent = '';
        unreadCount.classList.remove('chat-tab__unread--mention');
    });
}

function setUnreadCount(tab, count) {
    const unreadCount = tab.querySelector('.chat-tab__unread');
    unreadCount.textContent = count;
    unreadCount.hidden = false;
}

/* Read private chats: the server keeps which messages you've seen, so ones
   that came while you were away get a count on their tab when you're back */

// Per conversation, the message id the server last heard you'd seen
const markedReadUpTo = {};

function markConversationRead(conversation) {
    if (conversation === EVERYONE) return;
    const upTo = Number(getChats().at(-1)?.dataset.id ?? 0);
    if (upTo <= (markedReadUpTo[conversation] ?? 0)) return;

    markedReadUpTo[conversation] = upTo;
    markChatRead(conversation, upTo).catch(() => {
        // The next message or visit to the tab tries again
        markedReadUpTo[conversation] = 0;
    });
}

// One of your other tabs marked the chat read: its count goes here too,
// leaving any that came after
function showReadElsewhere(conversation, upTo) {
    markedReadUpTo[conversation] = Math.max(markedReadUpTo[conversation] ?? 0, upTo);
    const tab = getTab(conversation);
    if (!tab || conversation === activeConversation) return;

    const unseenCount = getChats().filter((chat) => getConversation(chat) === conversation
        && Number(chat.dataset.id) > upTo && Number(chat.dataset.senderId) !== myUserId).length;
    if (unseenCount === 0) {
        clearUnreadCount(tab);
    } else {
        setUnreadCount(tab, unseenCount);
    }
}

function closeTab(tab) {
    closedTabs[tab.dataset.conversation] = Number(getChats().at(-1)?.dataset.id ?? 0);
    saveClosedTabs();

    if (tab.classList.contains('is-active')) {
        showConversation(EVERYONE);
    }
    tab.classList.add('is-leaving');
    onAnimationEnd(tab, () => slideTabs(() => tab.remove()));
}

function openPrivateChat(userId, name) {
    forgetClosedTab(String(userId));
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
    setIcon(daylightIcon, isDaytime ? 'sun' : 'moon');
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

// A message that @mentions you turns the count purple until you look
function countUnread(tab, isMention) {
    const unreadCount = tab.querySelector('.chat-tab__unread');
    slideTabs(() => {
        unreadCount.classList.toggle('is-entering', unreadCount.hidden);
        if (isMention) unreadCount.classList.add('chat-tab__unread--mention');
        unreadCount.textContent = (parseInt(unreadCount.textContent, 10) || 0) + 1;
        unreadCount.hidden = false;
    });
}

// Put new messages in their tab and count the ones you haven't seen: in
// other tabs, or in this one while the page is hidden
function placeMessages(chats) {
    chats.forEach((chat) => {
        const tab = addTabFor(chat);
        const isInOpenTab = getConversation(chat) === activeConversation;

        if (!isInOpenTab) {
            chat.style.display = 'none';
        }
        if (tab && (!isInOpenTab || document.hidden)) {
            countUnread(tab, chat.classList.contains('chat--mentions-me'));
        }
    });
    if (!document.hidden && chats.some((chat) => getConversation(chat) === activeConversation)) {
        markConversationRead(activeConversation);
    }
}

// Back to the page: what came into the open tab meanwhile is seen now
document.addEventListener('visibilitychange', () => {
    if (document.hidden) return;
    clearUnreadCount(getTab(activeConversation));
    markConversationRead(activeConversation);
});

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
    const isScrolledUp = !isScrollingToNewest && getDistanceFromBottom() > SCROLLED_UP_DISTANCE;
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

/* Older messages: the open tab's next page loads as you scroll near the
   top, straight from that chat, however busy the others have been */

// The server's page size; a shorter page means there are no more
const pageSize = Number(chatPanel.dataset.pageSize);
// Per tab: false once the server has no older messages for it
const hasOlderIn = {};
let isLoadingOlder = false;

const hasChatsIn = (conversation) => getChats().some((chat) => getConversation(chat) === conversation);

// The tab's oldest message here; with none, the server sends its newest page
function getOldestIdIn(conversation) {
    const chat = getChats().find((chat) => getConversation(chat) === conversation);
    return chat ? Number(chat.dataset.id) : Number.MAX_SAFE_INTEGER;
}

// Each older message goes in id order among the others, so the list stays
// in the order the room had them
function insertInOrder(olderChats) {
    const chats = getChats();
    let next = 0;
    olderChats.forEach((chat) => {
        const id = Number(chat.dataset.id);
        while (next < chats.length && Number(chats[next].dataset.id) < id) next += 1;
        if (next < chats.length) {
            chats[next].before(chat);
        } else {
            chatList.append(chat);
        }
    });
}

async function loadOlderMessages(conversation) {
    const olderChats = (await fetchOlderChats(getOldestIdIn(conversation), conversation))
        .filter((chat) => !chatList.querySelector(`.chat[data-id="${chat.dataset.id}"]`));
    hasOlderIn[conversation] = olderChats.length === pageSize;
    if (olderChats.length === 0) return;

    // What you were reading stays put as the list grows above it
    const distanceFromBottom = getDistanceFromBottom();

    showTimes(olderChats);
    olderChats.forEach((chat) => {
        addTabFor(chat);
        if (getConversation(chat) !== activeConversation) chat.style.display = 'none';
    });
    insertInOrder(olderChats);
    fadeInPhotos(olderChats.map((chat) => chat.querySelector('.chat__photo')));
    addDayDividers();

    chatList.scrollTop = chatList.scrollHeight - chatList.clientHeight - distanceFromBottom;
}

function loadOlderIfAny(conversation) {
    if (isLoadingOlder || hasOlderIn[conversation] === false) return;

    isLoadingOlder = true;
    loadOlderMessages(conversation)
        .catch(() => {
            // The next scroll near the top tries again
        })
        .finally(() => {
            isLoadingOlder = false;
        });
}

function loadOlderIfNearTop() {
    if (chatList.scrollTop <= LOAD_OLDER_DISTANCE) loadOlderIfAny(activeConversation);
}

// Only when you scroll up: a short tab sits at the top while it scrolls
// itself down to new messages, and shouldn't load and trim on every one
let lastScrollTop = 0;

chatList.addEventListener('scroll', () => {
    if (chatList.scrollTop < lastScrollTop) loadOlderIfNearTop();
    lastScrollTop = chatList.scrollTop;
});

// A wheel turned up at the very top, or in a tab too short to scroll
chatList.addEventListener('wheel', (event) => {
    if (event.deltaY < 0) loadOlderIfNearTop();
});

// Drop the oldest messages past the limit. Only while you're at the bottom,
// so nothing you're reading disappears; the scroll up fetches them again
function trimOldMessages() {
    const chats = getChats();
    if (chats.length <= MAX_MESSAGES || isLoadingOlder) return;
    // Nor while an open private chat would lose messages: its list could empty
    const toDrop = chats.slice(0, chats.length - MAX_MESSAGES);
    if (activeConversation !== EVERYONE && toDrop.some((chat) => getConversation(chat) === activeConversation)) return;

    toDrop.forEach((chat) => {
        hasOlderIn[getConversation(chat)] = true;
        chat.remove();
    });
    addDayDividers();
}

/* Showing new messages */

// A new version went live: reload, so new messages don't arrive in markup
// the page's old CSS and JS don't know. Not while something is typed
const pageBuild = chatPanel.dataset.build;

function reloadIfUpdated(liveBuild) {
    if (pageBuild && liveBuild && liveBuild !== pageBuild && messageInput.value.trim() === '') {
        window.location.reload();
    }
}

// Scrolls down for new messages only if you're at the bottom already, or
// after you send; someone reading older messages stays where they are
function showNewChats(newChats, isAfterSending) {
    const wasAtBottom = getDistanceFromBottom() < AT_BOTTOM_DISTANCE;

    // Someone the list still has as away just spoke: they're here
    if (newChats.some((chat) => !onlineUserIds.has(Number(chat.dataset.senderId)))) {
        refreshPeople();
    }
    // Their message is in: they've stopped typing it
    newChats.forEach((chat) => hidePartnerTyping(chat.dataset.senderId));

    showTimes(newChats);
    chatList.append(...newChats);
    fadeInPhotos(newChats.map((chat) => chat.querySelector('.chat__photo')));
    placeMessages(newChats);
    addDayDividers();

    if (wasAtBottom || isAfterSending) {
        trimOldMessages();
        scrollToNewest();
    } else {
        // Only ones in the open tab; the others get a count on their tab
        newMessagesWhileScrolledUp += newChats.filter((chat) => chat.style.display !== 'none').length;
        updateJumpButton();
    }
}

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

    const to = activeConversation === EVERYONE ? null : activeConversation;

    isSending = true;
    updateSendButton();
    stopTyping();

    sendMessage(messageInput.value.trim(), to)
        .then(() => {
            messageInput.value = '';
            messageInput.dispatchEvent(new Event('input'));
            // Someone you called who was away comes online a moment later
            // (Bot::COME_ONLINE_SECONDS); look then, not on the next round
            setTimeout(refreshPeople, COME_ONLINE_DELAY);
        })
        .catch((error) => {
            showToast(getErrorMessage(error, 'Your message could not be sent.'), 'warning');
        })
        .finally(() => {
            isSending = false;
            updateSendButton();
        });
});

// @ suggests names in the open room. Set up before Enter sends, so
// choosing a name with Enter doesn't send the message too
setUpMentions(messageInput, () => activeConversation === EVERYONE, getPeopleToMention);

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
    noteTyping();
});

/* Typing: in a private chat, the other person sees "Typing..." while you
   write to them. Only that you are, never what */

const typingNote = document.getElementById('private-note-typing');

// Who you're telling, when you last told them, and the timer that stops it
let typingTo = null;
let typingSentAt = 0;
let typingIdleTimer = null;

function noteTyping() {
    const isPrivate = activeConversation !== EVERYONE;
    if (!isPrivate || messageInput.value.trim() === '') {
        stopTyping();
        return;
    }

    clearTimeout(typingIdleTimer);
    typingIdleTimer = setTimeout(stopTyping, TYPING_IDLE_DELAY);
    if (typingTo === activeConversation && Date.now() - typingSentAt < TYPING_SEND_INTERVAL) return;

    typingTo = activeConversation;
    typingSentAt = Date.now();
    sendTyping(typingTo, true);
}

function stopTyping() {
    clearTimeout(typingIdleTimer);
    if (typingTo === null) return;
    sendTyping(typingTo, false);
    typingTo = null;
}

// The people typing to you, with when it stops showing unless they type on
const partnerTypingUntil = {};

function showPartnerTyping() {
    typingNote.hidden = (partnerTypingUntil[activeConversation] ?? 0) < Date.now();
}

function onPartnerTyping(from, isTyping) {
    if (isTyping) {
        partnerTypingUntil[from] = Date.now() + TYPING_SHOWN_FOR;
        setTimeout(showPartnerTyping, TYPING_SHOWN_FOR + 50);
    } else {
        delete partnerTypingUntil[from];
    }
    showPartnerTyping();
}

function hidePartnerTyping(from) {
    if (!(from in partnerTypingUntil)) return;
    delete partnerTypingUntil[from];
    showPartnerTyping();
}

// Typing never shows from before a reconnect: whoever still is says so again
function hideAllTyping() {
    Object.keys(partnerTypingUntil).forEach((from) => delete partnerTypingUntil[from]);
    showPartnerTyping();
    typingTo = null;
}

/* Who's online, as the chat socket says it changes */

function onPresence(person) {
    updatePerson(person);
    refreshPinsSoon();
}

// The socket went down and polling took over, or the other way round
function onPollingChanged(isPolling) {
    setPeoplePolling(isPolling);
    refreshPinsSoon();
}

/* Start */

const historyChats = getChats();
// A short first page is the whole history, of every tab
if (historyChats.length < pageSize) hasOlderIn[EVERYONE] = false;
showTimes(historyChats);
fadeInPhotos(chatList.querySelectorAll('.chat__photo'));
historyChats.forEach(addTabFor);
// Your newest private chats, loaded or not, counting what came while you were away
JSON.parse(chatPanel.dataset.conversations || '[]').forEach((partner) => {
    const conversation = String(partner.user_id);
    if (Number(partner.newest_id) <= (closedTabs[conversation] ?? 0)) return;

    forgetClosedTab(conversation);
    const tab = addTab(conversation, partner.shown_name);
    if (Number(partner.unread) > 0) setUnreadCount(tab, partner.unread);
});
showConversation(EVERYONE);
shouldAnimateNewTabs = true;

keepPeopleUpdated(updateOnlineDots, openPrivateChat);
connectChat({
    onNewChats: showNewChats,
    onBuild: reloadIfUpdated,
    onPeople: setPeople,
    onPresence,
    onRead: showReadElsewhere,
    onTyping: onPartnerTyping,
    onDisconnected: hideAllTyping,
    onPollingChanged,
});

// Slide the panels in
document.body.classList.add('is-ready');
