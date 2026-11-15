// The chat page: messages, tabs for private chats, and the people list.
// The server's values come from data attributes on #chat (see base_chat.html)

const MESSAGES_INTERVAL = 1000;
const MESSAGES_HIDDEN_INTERVAL = 8000;
// The list changes slowly, so every 15 seconds is enough
const PEOPLE_INTERVAL = 15000;
// Matches the breakpoint in chat.css
const NARROW_SCREEN = '(max-width: 1100px)';
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
const peoplePanel = document.getElementById('people');

const myUserId = Number(chatPanel.dataset.myId);

// The tab being shown: "everyone", or the id of the person in a private chat
let activeConversation = EVERYONE;

// Ids of the people online, from the last people list
let onlineUserIds = new Set();

// Messages that came into the open tab while you were scrolled up
let newMessagesWhileScrolledUp = 0;

const isOnline = (person) => Number(person.is_online) === 1;
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

// Uploaded photos fade in over a soft circle once loaded; letter avatars
// are inline images and show at once
function fadeInPhotos(images) {
    images.forEach((image) => {
        if (image.complete || image.src.startsWith('data:')) return;

        const frame = image.parentElement;
        frame.classList.add('is-loading');
        const onPhotoSettled = () => frame.classList.remove('is-loading');
        image.addEventListener('load', onPhotoSettled, {once: true});
        image.addEventListener('error', onPhotoSettled, {once: true});
    });
}

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

/* The people list */

const personByRow = new WeakMap();

function getTimeAgo(minutes) {
    if (minutes < 60) return `${minutes} min ago`;
    return `${Math.floor(minutes / 60)} h ago`;
}

// Point out the person's pin on the map
function highlightPin(userId, isHighlighted) {
    document.querySelector(`.map-pin[data-user-id="${CSS.escape(userId)}"]`)?.classList.toggle('is-highlighted', isHighlighted);
}

// Online: flag and city. Recently here: how long ago
function createPersonDetail(person) {
    const detail = createElement('span', 'person__detail');
    if (!isOnline(person)) {
        detail.textContent = getTimeAgo(person.minutes_ago);
        return detail;
    }
    if (person.country) {
        detail.append(createElement('span', `fi fi-${person.country.toLowerCase()}`));
    }
    detail.append(createElement('span', 'person__city', person.city || ''));
    return detail;
}

function createPersonRow(person) {
    const isMe = Number(person.id) === myUserId;
    const userId = String(person.id);

    const row = createElement('div', 'person');
    row.classList.toggle('is-online', isOnline(person));
    row.classList.toggle('person--can-message', !isMe);
    row.dataset.userId = userId;
    row.title = isMe ? '' : `Chat privately with ${person.name}`;
    row.dataset.tipSide = 'left'; // over the map, not the rows above

    const avatarFrame = createElement('div', 'person__avatar');
    const photo = createElement('img', 'person__photo');
    photo.alt = '';
    photo.src = person.avatar_src;
    avatarFrame.append(photo);

    const text = createElement('div', 'person__text');
    text.append(
        createElement('span', 'person__name', person.name + (isMe ? ' (you)' : '')),
        createPersonDetail(person),
    );

    row.append(avatarFrame, text);
    row.addEventListener('mouseenter', () => highlightPin(userId, true));
    row.addEventListener('mouseleave', () => highlightPin(userId, false));
    personByRow.set(row, person);
    return row;
}

// Rows that were already there start where they were and slide to their
// new place, so someone joining pushes the list down smoothly
function slideToNewPlaces(section, topsBefore) {
    const shiftedRows = [];
    section.querySelectorAll(':scope > .person:not(.is-entering, .is-leaving)').forEach((row) => {
        const topBefore = topsBefore.get(row.dataset.userId);
        if (topBefore === undefined) return;
        const shift = topBefore - row.getBoundingClientRect().top;
        if (Math.round(shift) === 0) return;
        row.style.transform = `translateY(${shift}px)`;
        shiftedRows.push(row);
    });

    // The next frame, so the browser draws them at the old place first
    requestAnimationFrame(() => {
        shiftedRows.forEach((row) => {
            row.classList.add('is-shifting');
            row.style.transform = '';
            onTransitionEnd(row, () => row.classList.remove('is-shifting'));
        });
    });
}

// The rows are rebuilt each round, but only people new to the section
// ease in; those gone ease out where they were. Moving between online and
// recent counts as both, so the green dot fades with the row
function fillPeople(section, people) {
    const oldRows = new Map();
    section.querySelectorAll(':scope > .person:not(.is-leaving)').forEach((row, index) => {
        oldRows.set(row.dataset.userId, {row, index, top: row.getBoundingClientRect().top});
    });
    const topsBefore = new Map(Array.from(oldRows, ([userId, oldRow]) => [userId, oldRow.top]));

    const rows = people.map((person) => {
        const userId = String(person.id);
        const row = createPersonRow(person);
        row.classList.toggle('is-entering', !oldRows.has(userId));
        oldRows.delete(userId);
        return row;
    });
    section.replaceChildren(...rows);

    // Whoever is left in oldRows has gone
    oldRows.forEach(({row, index}) => {
        section.insertBefore(row, section.children[index] ?? null);
        removeWithAnimation(row, 'is-leaving');
    });

    slideToNewPlaces(section, topsBefore);
}

// A green dot on the tab of each private chat partner who's online;
// it fades only when that changes
function updateOnlineDots() {
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

function showPeople(people) {
    const onlinePeople = people.filter(isOnline);
    const recentPeople = people.filter((person) => !isOnline(person));

    // You first, then everyone else by name
    const isMeFirst = (person) => Number(Number(person.id) === myUserId);
    onlinePeople.sort((a, b) => isMeFirst(b) - isMeFirst(a) || a.name.localeCompare(b.name));

    // The rows are replaced, so a hovered row never gets its mouseleave
    document.querySelectorAll('.map-pin.is-highlighted').forEach((pin) => pin.classList.remove('is-highlighted'));

    onlineUserIds = new Set(onlinePeople.map((person) => Number(person.id)));
    updateOnlineDots();

    document.getElementById('online-count').textContent = onlinePeople.length;
    document.getElementById('people-toggle-count').textContent = onlinePeople.length;
    fillPeople(document.getElementById('people-online'), onlinePeople);
    document.getElementById('recent-heading').hidden = recentPeople.length === 0;
    fillPeople(document.getElementById('people-recent'), recentPeople);
    fadeInPhotos(peoplePanel.querySelectorAll('img'));
}

function loadPeople() {
    postForm('/app/get-people', {timezone: Intl.DateTimeFormat().resolvedOptions().timeZone})
        .then((response) => showPeople(response.data))
        .catch(() => {
            // Try again on the next round
        })
        .finally(() => setTimeout(loadPeople, PEOPLE_INTERVAL));
}

// A drawer on narrow screens, a column that can be closed on wide ones
const narrowScreen = window.matchMedia(NARROW_SCREEN);

function setPeopleOpen(isOpen) {
    const applyOpen = () => {
        if (narrowScreen.matches) {
            peoplePanel.classList.toggle('is-open', isOpen);
        } else {
            document.body.classList.toggle('is-people-closed', !isOpen);
        }
    };

    if (isOpen) {
        applyOpen();
        return;
    }

    // Slide out first, then hide
    peoplePanel.classList.add('is-closing');
    onAnimationEnd(peoplePanel, () => {
        peoplePanel.classList.remove('is-closing');
        applyOpen();
    });
}

document.getElementById('people-toggle').addEventListener('click', () => {
    setPeopleOpen(!isShown(peoplePanel));
});

document.getElementById('people-close').addEventListener('click', () => {
    setPeopleOpen(false);
});

peoplePanel.addEventListener('click', (event) => {
    const row = event.target.closest('.person--can-message');
    if (!row) return;

    const person = personByRow.get(row);
    if (narrowScreen.matches) {
        setPeopleOpen(false);
    }
    openPrivateChat(person.id, person.name);
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

loadPeople();
keepCheckingForMessages();

// Slide the panels in
document.body.classList.add('is-ready');
