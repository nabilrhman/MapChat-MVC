// The chat page: messages, tabs for private chats, and the composer. Talking
// to the server about messages is in messages.js, the people list in
// people.js. The server's values come from data attributes on #chat (see
// base_chat.html)

// Scrolling down to new messages; none for people who ask for less motion
const SCROLL_BEHAVIOR = prefersLessMotion.matches ? 'instant' : 'smooth';
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

const chatPanel = document.getElementById('chat');
const chatTabs = document.getElementById('chat-tabs');
const chatList = document.getElementById('chat-list');
const composerForm = document.getElementById('input-container-form');
const composerField = document.getElementById('composer-field');
const messageInput = document.getElementById('textarea-send');
const sendButton = document.getElementById('button-send');

// The tab being shown: "everyone", or the id of the person in a private chat
let activeConversation = EVERYONE;

// Ids of the people online, from the last people list (people.js)
let onlineUserIds = new Set();

// Messages that came into the open tab while you were scrolled up
let newMessagesWhileScrolledUp = 0;

const getConversation = (chat) => chat.dataset.conversation;
const getChats = () => [...chatList.querySelectorAll('[data-part="message"]')];
// The newest message on the page, in any tab, or 0
const getNewestId = () => Number(getChats().at(-1)?.dataset.id ?? 0);
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
// slide to their new place instead of jumping
function slideTabs(change) {
    const tabs = [...chatTabs.querySelectorAll('[data-part="tab"]')];
    const leftsBefore = tabs.map((tab) => tab.getBoundingClientRect().left);

    change();
    keepOpenTabWhole();

    const shifts = new Map();
    tabs.forEach((tab, index) => {
        // The tab being dragged stays under the pointer
        if (!tab.isConnected || tab.classList.contains('is-dragging')) return;
        const shift = leftsBefore[index] - tab.getBoundingClientRect().left;
        if (Math.round(shift) !== 0) shifts.set(tab, {x: shift});
    });
    slideFromShifts(shifts);
}

// Tabs from the history just appear; ones opened later ease in
let shouldAnimateNewTabs = false;

/* Closed private tabs stay closed, across reloads and logging out, until a
   newer message comes in or you open the chat again */

// Per account, on this device: conversation → newest message id when closed.
// Without storage (private browsing) tabs just come back on reload
const closedTabsKey = `closedTabs:${myUserId}`;
const closedTabs = readStoredJson(closedTabsKey, {});

const saveClosedTabs = () => writeStoredJson(closedTabsKey, closedTabs);

function forgetClosedTab(conversation) {
    if (!(conversation in closedTabs)) return;
    delete closedTabs[conversation];
    saveClosedTabs();
}

// Whether the tab was closed with this message, or a newer one, on the page
const wasClosedAfter = (conversation, messageId) => Number(messageId) <= (closedTabs[conversation] ?? 0);

// The chat's tab, unless it was closed after this message; a newer message
// reopens it
function addTabFor(chat) {
    const conversation = getConversation(chat);
    if (wasClosedAfter(conversation, chat.dataset.id)) return null;

    forgetClosedTab(conversation);
    return addTab(conversation, chat.dataset.partner);
}

function getTab(conversation) {
    return chatTabs.querySelector(`[data-part="tab"][data-conversation="${CSS.escape(conversation)}"]:not(.is-leaving)`);
}

// The name on a conversation's tab: the other person's, in a private chat
const getTabName = (conversation) => getTab(conversation).querySelector('[data-part="tab-name"]').textContent;

// Whether someone is online, by their id as a number or as the page has it
// (a private conversation, a sender)
const isUserOnline = (userId) => onlineUserIds.has(Number(userId));

function addTab(conversation, name) {
    const existingTab = getTab(conversation);
    if (existingTab) return existingTab;

    const tab = createElement('div', 'tab');
    tab.dataset.part = 'tab';
    tab.dataset.conversation = conversation;
    tab.classList.toggle('is-entering', shouldAnimateNewTabs);

    const onlineDot = createElement('span', 'online');
    onlineDot.dataset.part = 'tab-online';
    onlineDot.title = 'Online';
    onlineDot.hidden = !isUserOnline(conversation);

    const nameLabel = createElement('span', 'name', name);
    nameLabel.dataset.part = 'tab-name';

    const unreadCount = createElement('span', 'unread');
    unreadCount.dataset.part = 'tab-unread';
    unreadCount.hidden = true;

    const openButton = createButton('open');
    openButton.append(onlineDot, nameLabel, unreadCount);

    const closeButton = createButton('close', '×');
    closeButton.dataset.part = 'tab-close';
    closeButton.title = 'Close';

    tab.append(openButton, closeButton);
    placeTab(tab);
    return tab;
}

/* Private tabs can be dragged into any order after # Everyone, which stays
   first. The order is kept like closed tabs: per account, on this device */

// Without storage, tabs go in the order they open
const tabOrderKey = `tabOrder:${myUserId}`;
let tabOrder = readStoredJson(tabOrderKey, []);

function saveTabOrder() {
    tabOrder = [...chatTabs.querySelectorAll('[data-part="tab"]:not(.is-leaving)')]
        .map((tab) => tab.dataset.conversation)
        .filter((conversation) => conversation !== EVERYONE);
    writeStoredJson(tabOrderKey, tabOrder);
}

// A tab that was dragged before goes back to its place among the tabs
// there; one that never was goes at the end
function placeTab(tab) {
    const place = tabOrder.indexOf(tab.dataset.conversation);
    const nextTab = place === -1 ? null : [...chatTabs.children].find((other) => {
        if (other.dataset.conversation === EVERYONE) return false;
        const otherPlace = tabOrder.indexOf(other.dataset.conversation);
        return otherPlace === -1 || otherPlace > place;
    });
    chatTabs.insertBefore(tab, nextTab ?? null);
}

// The open tab is always shown whole, close button and all, however the
// tabs before it grew (an unread count) or went. Only the tab row scrolls,
// at once; slideTabs glides the tabs from where they were. By layout
// position, which a tab growing in already has in full (the row is its
// offsetParent, as it's transformed)
function keepOpenTabWhole() {
    const tab = chatTabs.querySelector('[data-part="tab"].is-active:not(.is-leaving)');
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

    chatTabs.querySelectorAll('[data-part="tab"]').forEach((tab) => tab.classList.remove('is-active'));
    const tab = getTab(activeConversation);
    tab.classList.add('is-active');
    // Also shows the tab whole (keepOpenTabWhole)
    clearUnreadCount(tab);

    getChats().forEach((chat) => {
        chat.style.display = getConversation(chat) === activeConversation ? '' : 'none';
    });
    replayAnimation(chatList, 'is-switching');

    const isPrivate = activeConversation !== EVERYONE;

    showChatHeader();
    messageInput.placeholder = isPrivate ? `Message ${getTabName(activeConversation)}` : 'Your message';
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
        const unreadCount = tab.querySelector('[data-part="tab-unread"]');
        unreadCount.hidden = true;
        unreadCount.textContent = '';
        unreadCount.classList.remove('is-mention');
    });
}

function setUnreadCount(tab, count) {
    const unreadCount = tab.querySelector('[data-part="tab-unread"]');
    unreadCount.textContent = count;
    unreadCount.hidden = false;
}

/* Read private chats: the server keeps which messages you've seen, so ones
   that came while you were away get a count on their tab when you're back */

// Per conversation, the message id the server last heard you'd seen
const markedReadUpTo = {};

function markConversationRead(conversation) {
    if (conversation === EVERYONE) return;
    const upTo = getNewestId();
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
    closedTabs[tab.dataset.conversation] = getNewestId();
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

// The open chat's header. The room: what it is. A private chat: their photo
// and name, then on the left whether they're online or when they were last
// seen, and on the right their flag, city and time there when known
const ROOM_STATUS = 'Chat with people worldwide';

function showChatHeader() {
    const isPrivate = activeConversation !== EVERYONE;
    const photo = document.getElementById('chat-header-photo');
    const status = document.getElementById('chat-header-status');
    const where = document.getElementById('chat-header-where');

    document.getElementById('chat-header-room-icon').hidden = isPrivate;
    photo.hidden = !isPrivate;
    document.getElementById('chat-header-tag').hidden = isPrivate;
    document.getElementById('chat-header-private-tag').hidden = !isPrivate;
    where.replaceChildren();

    const isOnline = isPrivate && isUserOnline(activeConversation);
    document.getElementById('chat-header').classList.toggle('is-online', isOnline);

    if (!isPrivate) {
        document.getElementById('chat-header-name').textContent = 'Everyone';
        status.replaceChildren(ROOM_STATUS);
        return;
    }

    const person = personWithId(activeConversation);
    photo.src = getPhotoSrc(activeConversation);
    document.getElementById('chat-header-name').textContent = getTabName(activeConversation);

    if (isOnline) {
        status.replaceChildren('Online');
    } else {
        status.replaceChildren(person ? `Seen ${getTimeAgo(person)}` : 'Away');
    }

    if (!person?.city && !person?.country) return;
    const placeAndTime = [createPlace(person.city, person.country)];
    // Hidden with their place when they don't share their location
    if (person.lng !== null && person.lng !== undefined) placeAndTime.push(createLocalTime(person));
    where.append(...createFlipToCountry(placeAndTime, person));
}

// Their city and time, which flip up together to show their country on
// hover (.chat-header .flip in chat.css). Stays put without a city to flip
// from, or a name for the country
function createFlipToCountry(placeAndTime, person) {
    if (!person.city || !person.country || countryName(person.country) === person.country) return placeAndTime;

    const cityLine = createElement('span', 'line');
    cityLine.append(...placeAndTime);
    const countryLine = createElement('span', 'line');
    countryLine.append(createPlace(null, person.country));
    const track = createElement('span', 'track');
    track.append(cityLine, countryLine);
    const flip = createElement('span', 'swap flip');
    flip.dataset.swaps = '';
    flip.append(track);
    return [flip];
}

// Their time now, with a sun or moon, as their pin and messages show it
function createLocalTime(person) {
    const localTime = localTimeOf(new Date(), person.timezone, parseFloat(person.lng));
    const daylightIcon = createIcon('sun', 'daylight');
    showDaylight(daylightIcon, localTime.hour);

    const time = createElement('span', 'time');
    time.append(daylightIcon, localTime.text);
    return time;
}

// Keeps the time in the header current
setInterval(() => {
    if (activeConversation !== EVERYONE) showChatHeader();
}, 60000);

// A green dot on the tab of each private chat partner who's online;
// it fades only when that changes
function updateOnlineDots(userIds) {
    onlineUserIds = userIds;
    showChatHeader();
    chatTabs.querySelectorAll('[data-part="tab-online"]').forEach((dot) => {
        const isOnline = isUserOnline(dot.closest('[data-part="tab"]').dataset.conversation);
        if (isOnline === !dot.hidden) return;

        if (isOnline) {
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
    const tab = event.target.closest('[data-part="tab"]');
    if (!tab) return;
    event.preventDefault();
    if (isEndingTabDrag) return;
    // A tab of a folded chat brings the chat back up
    if (isChatFolded()) moveSheetTo('chosen');

    if (event.target.closest('[data-part="tab-close"]')) {
        closeTab(tab);
    } else {
        showConversation(tab.dataset.conversation);
    }
});

// A mouse wheel scrolls the tabs sideways
chatTabs.addEventListener('wheel', (event) => {
    chatTabs.scrollLeft += event.deltaY;
});

/* Dragging tabs */

// A mouse press that moves this far drags the tab. A finger first holds the
// tab still this long, since a swipe scrolls the tabs
const DRAG_DISTANCE = 5;
const TOUCH_HOLD_DELAY = 400;
// Dragged this close to an end of the row, the tabs scroll along
const DRAG_SCROLL_EDGE = 32;
const DRAG_SCROLL_SPEED = 8;

// The press on a private tab, until it's let go
let tabDrag = null;
// The click that ends a drag doesn't open the tab
let isEndingTabDrag = false;

// Where the pointer is along the whole row, scrolled part included
const getRowX = (clientX) => clientX + chatTabs.scrollLeft;

chatTabs.addEventListener('pointerdown', (event) => {
    const tab = event.target.closest('[data-part="tab"]');
    if (!tab || tab.dataset.conversation === EVERYONE || tab.classList.contains('is-leaving')) return;
    if (event.button !== 0 || event.target.closest('[data-part="tab-close"]')) return;

    tabDrag = {tab, pointerId: event.pointerId, startClientX: event.clientX, clientX: event.clientX, isDragging: false};
    if (event.pointerType === 'touch') {
        tabDrag.holdTimer = setTimeout(startTabDrag, TOUCH_HOLD_DELAY);
    }
});

function startTabDrag() {
    const {tab, pointerId, startClientX} = tabDrag;
    tabDrag.isDragging = true;
    tabDrag.startRowX = getRowX(startClientX);
    tab.classList.remove('is-shifting');
    tab.classList.add('is-dragging');
    tab.setPointerCapture(pointerId);
    scrollWhileDragging();
}

window.addEventListener('pointermove', (event) => {
    if (!tabDrag || event.pointerId !== tabDrag.pointerId) return;
    tabDrag.clientX = event.clientX;

    if (tabDrag.isDragging) {
        moveDraggedTab();
    } else if (Math.abs(event.clientX - tabDrag.startClientX) > DRAG_DISTANCE) {
        // A finger that moves before the hold is scrolling, not dragging
        if (tabDrag.holdTimer) {
            clearTimeout(tabDrag.holdTimer);
            tabDrag = null;
        } else {
            startTabDrag();
            moveDraggedTab();
        }
    }
});

// The dragged tab follows the pointer, kept within the private tabs, and
// trades places with a neighbour once it's past that neighbour's middle
function moveDraggedTab() {
    const {tab} = tabDrag;
    const everyoneTab = chatTabs.firstElementChild;
    const firstLeft = everyoneTab.offsetLeft + everyoneTab.offsetWidth;
    const lastTab = chatTabs.lastElementChild;
    const lastLeft = lastTab.offsetLeft + lastTab.offsetWidth - tab.offsetWidth;

    const left = Math.min(Math.max(tab.offsetLeft + getRowX(tabDrag.clientX) - tabDrag.startRowX, firstLeft), lastLeft);
    const right = left + tab.offsetWidth;

    // It passes a neighbour once its leading edge is past that neighbour's
    // middle, so it can reach either end next to a narrower tab. A quick
    // drag can pass several tabs between two moves
    for (;;) {
        const nextTab = tab.nextElementSibling;
        const previousTab = tab.previousElementSibling;
        if (nextTab && right > nextTab.offsetLeft + nextTab.offsetWidth / 2) {
            swapTabs(nextTab, () => nextTab.after(tab));
        } else if (previousTab.dataset.conversation !== EVERYONE
                   && left < previousTab.offsetLeft + previousTab.offsetWidth / 2) {
            swapTabs(previousTab, () => previousTab.before(tab));
        } else {
            break;
        }
    }
    tab.style.translate = `${left - tab.offsetLeft}px 0`;
}

// Moves the dragged tab past a neighbour, which slides over to make room
function swapTabs(neighbour, change) {
    const {tab} = tabDrag;
    const tabLeftBefore = tab.offsetLeft;
    const neighbourLeftBefore = neighbour.offsetLeft;
    change();
    tabDrag.startRowX += tab.offsetLeft - tabLeftBefore;

    slideFromShifts(new Map([[neighbour, {x: neighbourLeftBefore - neighbour.offsetLeft}]]));
}

// Held near an end of the row, the tabs keep scrolling
function scrollWhileDragging() {
    if (!tabDrag?.isDragging) return;
    const row = chatTabs.getBoundingClientRect();
    if (tabDrag.clientX < row.left + DRAG_SCROLL_EDGE) {
        chatTabs.scrollLeft -= DRAG_SCROLL_SPEED;
    } else if (tabDrag.clientX > row.right - DRAG_SCROLL_EDGE) {
        chatTabs.scrollLeft += DRAG_SCROLL_SPEED;
    }
    moveDraggedTab();
    requestAnimationFrame(scrollWhileDragging);
}

function endTabDrag(event) {
    if (!tabDrag || event.pointerId !== tabDrag.pointerId) return;
    const {tab, isDragging, holdTimer} = tabDrag;
    clearTimeout(holdTimer);
    if (isDragging && event.type === 'pointerup') {
        // Where it's let go, which can be past the last move
        tabDrag.clientX = event.clientX;
        moveDraggedTab();
    }
    tabDrag = null;
    if (!isDragging) return;

    // Settles into its place, from where it was let go
    const droppedAt = parseFloat(tab.style.translate) || 0;
    tab.classList.remove('is-dragging');
    slideFromShifts(new Map([[tab, {x: droppedAt}]]));
    saveTabOrder();

    isEndingTabDrag = true;
    setTimeout(() => {
        isEndingTabDrag = false;
    });
}

window.addEventListener('pointerup', endTabDrag);
window.addEventListener('pointercancel', endTabDrag);

// While a finger drags a tab, the row doesn't scroll and no menu opens
chatTabs.addEventListener('touchmove', (event) => {
    if (tabDrag?.isDragging) event.preventDefault();
}, {passive: false});
chatTabs.addEventListener('contextmenu', (event) => {
    if (tabDrag) event.preventDefault();
});

/* Messages */

// The time where someone is, or was when they sent a message. Without their
// time zone, an estimate from longitude (an hour per 15 degrees), marked
// with "~"
function localTimeOf(date, timezone, longitude) {
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

// A daylight icon shows the sun from six in the morning to six at night,
// and the moon otherwise, coloured by its day or night class
function showDaylight(icon, hour) {
    const isDaytime = hour >= 6 && hour < 18;
    setIcon(icon, isDaytime ? 'sun' : 'moon');
    icon.classList.toggle('day', isDaytime);
    icon.classList.toggle('night', !isDaytime);
}

const getSentDate = (chat) => new Date(chat.querySelector('time').getAttribute('datetime'));

function showSenderTime(chat) {
    const senderTimeLine = chat.querySelector('[data-part="sender-time"]');
    if (!senderTimeLine) return;

    const timeInfo = chat.querySelector('[data-part="time"]');
    // Always midnight on Null Island, where the time zones meet
    const senderTime = timeInfo.dataset.country === NULL_ISLAND
        ? {text: '00:00', hour: 0}
        : localTimeOf(getSentDate(chat), timeInfo.dataset.timezone, parseFloat(timeInfo.dataset.lng));

    showDaylight(senderTimeLine.querySelector('svg'), senderTime.hour);
    senderTimeLine.querySelector('span').textContent = senderTime.text;
}

// Each message shows its time; hovering it slides to the sender's own time.
// Also names the sender's country, shown when hovering the city
function showTimes(chats) {
    chats.forEach((chat) => {
        chat.querySelectorAll('[data-part="country"]').forEach((country) => {
            country.textContent = countryName(country.dataset.country);
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
    chatList.querySelectorAll('[data-part="day-divider"]').forEach((divider) => divider.remove());

    let lastDay = null;
    getChats()
        .filter((chat) => getConversation(chat) === activeConversation)
        .forEach((chat) => {
            const date = getSentDate(chat);
            if (date.toDateString() === lastDay) return;

            const divider = createElement('div', 'day-divider');
            divider.dataset.part = 'day-divider';
            divider.append(createElement('span', 'label', getDayLabel(date)));
            chat.before(divider);
            lastDay = date.toDateString();
        });
}

// A message that @mentions you turns the count purple until you look
function countUnread(tab, isMention) {
    const unreadCount = tab.querySelector('[data-part="tab-unread"]');
    slideTabs(() => {
        unreadCount.classList.toggle('is-entering', unreadCount.hidden);
        if (isMention) unreadCount.classList.add('is-mention');
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
            countUnread(tab, 'mentionsMe' in chat.dataset);
            notifyOf(chat, showConversation);
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

// The city and country under a message: the text only, not the whole
// width of its row
const CITY = '[data-part="city"]';

// Hovering a message's city points out its sender's pin, as the people
// list does. mouseover and mouseout, as they reach the list from each line.
// It takes a pointer cursor only when there's a pin to click through to
function onCityHover(event, isHighlighted) {
    const city = event.target.closest(CITY);
    if (!city || city.contains(event.relatedTarget)) return;
    const senderId = city.closest('[data-part="message"]').dataset.senderId;
    city.classList.toggle('is-on-map', pinLabelsByUserId.has(senderId));
    highlightPin(senderId, isHighlighted);
}

chatList.addEventListener('mouseover', (event) => onCityHover(event, true));
chatList.addEventListener('mouseout', (event) => onCityHover(event, false));

// Clicking it brings their pin to the middle of the map; on a phone with
// the chat all the way up, the sheet comes down first
chatList.addEventListener('click', (event) => {
    const city = event.target.closest(CITY);
    if (!city) return;
    const senderId = city.closest('[data-part="message"]').dataset.senderId;
    if (isMapOpen()) {
        focusPin(senderId);
        return;
    }
    // Moving the sheet starts the map loading (map.js). The pin waits for
    // the sheet to settle, as the room it leaves sets where the middle of
    // the map is
    moveSheetTo('chosen');
    // Its own slide: the tapped city's swap ends inside it first
    const onChatSlid = (slide) => {
        if (slide.target !== chatPanel || slide.propertyName !== 'top') return;
        chatPanel.removeEventListener('transitionend', onChatSlid);
        focusPin(senderId);
    };
    chatPanel.addEventListener('transitionend', onChatSlid);
});

/* Touch screens have no hover: a tap slides a two-line window (.swap in
   chat.css) to its second line, and it slides back by itself */

const SWAP_BACK_DELAY = 2000;
const hasNoHover = matchMedia('(hover: none)');
const swapBackTimers = new WeakMap();

document.addEventListener('click', (event) => {
    if (!hasNoHover.matches) return;
    const swap = event.target.closest('[data-swaps]');
    if (!swap) return;

    clearTimeout(swapBackTimers.get(swap));
    swap.classList.add('is-swapped');
    swapBackTimers.set(swap, setTimeout(() => swap.classList.remove('is-swapped'), SWAP_BACK_DELAY));
});

/* Phones: the chat header is one line, and a tap on it slides up the
   second (online or last seen, their city and time), which slides back by
   itself. Not after a drag, as the header moves the sheet too */

const chatHeader = document.getElementById('chat-header');
let headerSlideBackTimer = null;

chatHeader.addEventListener('click', () => {
    if (!phoneScreen.matches || isEndingSheetDrag) return;
    clearTimeout(headerSlideBackTimer);
    chatHeader.classList.add('is-showing-where');
    headerSlideBackTimer = setTimeout(() => chatHeader.classList.remove('is-showing-where'), SWAP_BACK_DELAY);
});

chatList.addEventListener('click', (event) => {
    const senderName = event.target.closest('[data-part="name-button"]');
    if (senderName) {
        openPrivateChat(senderName.dataset.userId, senderName.dataset.userName);
    }
});

/* Phones: the chat is a sheet over the map, as in a maps app. Dragging its
   handle or header sets its height, between limits: above them it goes all
   the way up, below them it folds down to its tabs. Tapping the handle then
   switches between all the way up and the height you chose */

const sheetHandle = document.getElementById('sheet-handle');
const SHEET_STOP_KEY = 'sheetStop';
const SHEET_CHOSEN_SHARE_KEY = 'sheetChosenShare';
// Top to bottom: up to the header, the height you chose, down to its tabs
const SHEET_STOP_ORDER = ['full', 'chosen', 'tabs'];
// What a tap on the handle does at each stop, and what it says it does
const SHEET_TAPS = {
    full: {nextStop: 'chosen', label: 'Show the map'},
    chosen: {nextStop: 'full', label: 'Show all of the chat'},
    tabs: {nextStop: 'full', label: 'Show the chat'},
};
// The highest a chosen height goes, as a share of the run: let go above
// it, the sheet goes all the way up
const SHEET_HIGHEST_CHOSEN_SHARE = 0.125;
// Until you choose, the sheet rests a little under halfway
const SHEET_FIRST_CHOSEN_SHARE = 0.45;
// The lowest a chosen height goes shows this many messages of about this
// height (px): a name, a line or two, and its place. Let go below it, the
// sheet folds
const SHEET_MESSAGE_HEIGHT = 103;
const SHEET_LOWEST_MESSAGES = 1;
// How far a finger moves (px) before a press becomes a drag, so a tap stays a tap
const SHEET_DRAG_THRESHOLD = 6;
// Let go faster than this (px per ms), the sheet goes on to the next stop
// that way, as a flick does
const SHEET_FLICK_SPEED = 0.4;
// A drag's speed is measured over stretches this long (ms), and a finger
// still for three of them before letting go isn't flicking
const SHEET_SPEED_STRETCH = 50;
const SHEET_REST_TIME = SHEET_SPEED_STRETCH * 3;

let sheetStop = 'chosen';
// The chosen height's top as a share of the run, so it keeps its place
// when the screen's height changes
let sheetChosenShare = SHEET_FIRST_CHOSEN_SHARE;
let sheetDrag = null;
let isEndingSheetDrag = false;

const isChatFolded = () => sheetStop === 'tabs';

// The map fills the room between the header and the footer, so its height
// is the sheet's whole run
const sheetRunHeight = () => document.getElementById('map').clientHeight;

// A stop's distance below the header, in px
function sheetTopOf(stop) {
    const runHeight = sheetRunHeight();
    const tabsTop = runHeight - sheetHandle.offsetHeight - chatTabs.offsetHeight;
    const tops = {
        full: 0,
        // No lower than the lowest, after the screen's height changed
        chosen: Math.min(Math.round(runHeight * sheetChosenShare), lowestChosenTop(tabsTop)),
        tabs: tabsTop,
    };
    return tops[stop];
}

// The lowest chosen height's top (px): room under it for the chat's header,
// the lowest messages and the message box, above the tabs stop at `tabsTop`
function lowestChosenTop(tabsTop) {
    const messagesHeight = SHEET_MESSAGE_HEIGHT * SHEET_LOWEST_MESSAGES;
    return tabsTop - chatHeader.offsetHeight - messagesHeight - composerForm.offsetHeight;
}

// chat.css places the sheet and the map's credits from this
function setSheetTop(top) {
    document.documentElement.style.setProperty('--sheet-top', `${top}px`);
}

// Down to its tabs, what's folded away can't be tabbed to
function setChatFolded(isFolded) {
    document.body.classList.toggle('is-chat-folded', isFolded);
    chatList.parentElement.inert = isFolded;
    composerForm.inert = isFolded;
}

// Rests the sheet at a stop, remembered for next time
function moveSheetTo(stop) {
    sheetStop = stop;
    setSheetTop(sheetTopOf(stop));
    setChatFolded(stop === 'tabs');
    const {label} = SHEET_TAPS[stop];
    sheetHandle.setAttribute('aria-label', label);
    sheetHandle.title = label;
    writeStored(SHEET_STOP_KEY, stop);
    // map.js starts the map once some of it shows
    document.dispatchEvent(new Event('sheet-moved'));
}

// Makes `top` px the chosen height, remembered for next time
function chooseSheetHeight(top) {
    sheetChosenShare = top / sheetRunHeight();
    writeStored(SHEET_CHOSEN_SHARE_KEY, String(sheetChosenShare));
}

// The stop nearest where the sheet was let go
function nearestSheetStop(top) {
    const distanceTo = (stop) => Math.abs(sheetTopOf(stop) - top);
    return SHEET_STOP_ORDER.reduce((nearest, stop) => (distanceTo(stop) < distanceTo(nearest) ? stop : nearest));
}

// Where a drag lets the sheet settle: after a flick, the next stop the way
// it was going. Otherwise all the way up above the highest chosen height,
// folded below the lowest, and anywhere between becomes the chosen height
function sheetStopAfterDrag(top, speed) {
    if (Math.abs(speed) >= SHEET_FLICK_SPEED) {
        const isGoingDown = speed > 0;
        const stopsThatWay = isGoingDown
            ? SHEET_STOP_ORDER.filter((stop) => sheetTopOf(stop) > top)
            : SHEET_STOP_ORDER.filter((stop) => sheetTopOf(stop) < top).reverse();
        return stopsThatWay[0] ?? nearestSheetStop(top);
    }

    if (top < sheetRunHeight() * SHEET_HIGHEST_CHOSEN_SHARE) return 'full';
    if (top > lowestChosenTop(sheetTopOf('tabs'))) return 'tabs';
    chooseSheetHeight(top);
    return 'chosen';
}

function onSheetPress(event) {
    if (!phoneScreen.matches || !event.isPrimary) return;
    const startTop = sheetTopOf(sheetStop);
    sheetDrag = {
        pointerId: event.pointerId,
        startY: event.clientY,
        startTop,
        top: startTop,
        lastY: event.clientY,
        lastTime: event.timeStamp,
        speed: 0,
        isMoving: false,
    };
}

// The sheet follows the finger, no further than its top and bottom stops
function onSheetDrag(event) {
    if (sheetDrag?.pointerId !== event.pointerId) return;
    const distance = event.clientY - sheetDrag.startY;
    if (!sheetDrag.isMoving) {
        if (Math.abs(distance) < SHEET_DRAG_THRESHOLD) return;
        sheetDrag.isMoving = true;
        document.body.classList.add('is-sheet-dragging');
    }

    // Its speed over the last stretch, as one event to the next is too jittery
    const elapsed = event.timeStamp - sheetDrag.lastTime;
    if (elapsed >= SHEET_SPEED_STRETCH) {
        sheetDrag.speed = (event.clientY - sheetDrag.lastY) / elapsed;
        sheetDrag.lastY = event.clientY;
        sheetDrag.lastTime = event.timeStamp;
    }

    const lowestTop = sheetTopOf('tabs');
    sheetDrag.top = Math.min(Math.max(sheetDrag.startTop + distance, 0), lowestTop);
    setSheetTop(sheetDrag.top);
}

// How fast the finger was going as it let go: the last stretch, a quick
// flick shorter than a stretch, or none if it rested first
function speedAtLetGo(letGo, {speed, lastY, lastTime}) {
    const elapsed = letGo.timeStamp - lastTime;
    if (elapsed > SHEET_REST_TIME) return 0;
    if (speed === 0 && elapsed > 0) return (letGo.clientY - lastY) / elapsed;
    return speed;
}

function onSheetLetGo(event) {
    if (sheetDrag?.pointerId !== event.pointerId) return;
    const {isMoving, top, speed, lastY, lastTime} = sheetDrag;
    sheetDrag = null;
    if (!isMoving) return;
    document.body.classList.remove('is-sheet-dragging');
    moveSheetTo(sheetStopAfterDrag(top, speedAtLetGo(event, {speed, lastY, lastTime})));
    // The click that ends the drag isn't a tap on the handle
    isEndingSheetDrag = true;
    setTimeout(() => {
        isEndingSheetDrag = false;
    });
}

for (const sheetGrip of [sheetHandle, chatHeader]) {
    sheetGrip.addEventListener('pointerdown', onSheetPress);
}
window.addEventListener('pointermove', onSheetDrag);
window.addEventListener('pointerup', onSheetLetGo);
window.addEventListener('pointercancel', onSheetLetGo);

sheetHandle.addEventListener('click', () => {
    if (isEndingSheetDrag) return;
    moveSheetTo(SHEET_TAPS[sheetStop].nextStop);
});

// Without storage, or the first time, it starts at the first chosen height
const storedSheetStop = readStored(SHEET_STOP_KEY);
const storedChosenShare = Number(readStored(SHEET_CHOSEN_SHARE_KEY));
if (storedChosenShare >= SHEET_HIGHEST_CHOSEN_SHARE && storedChosenShare < 1) sheetChosenShare = storedChosenShare;
if (phoneScreen.matches) moveSheetTo(Object.hasOwn(SHEET_TAPS, String(storedSheetStop)) ? storedSheetStop : 'chosen');

// The stops move with the screen's height (turning the phone, its keyboard)
window.addEventListener('resize', () => {
    if (phoneScreen.matches) setSheetTop(sheetTopOf(sheetStop));
});

// Wider screens have no sheet, so nothing there is folded away
phoneScreen.addEventListener('change', () => {
    if (phoneScreen.matches) {
        moveSheetTo(sheetStop);
    } else {
        setChatFolded(false);
    }
});

/* The "Latest" button: shows once you've scrolled up, counts messages in
   this tab that arrive meanwhile, and takes you back down */

const jumpButton = document.getElementById('jump-latest');
const jumpLabel = document.getElementById('jump-latest-text');

// The count's number, which rolls up as messages come in (countTo in dom.js)
const jumpCount = createElement('span');
jumpCount.dataset.part = 'jump-count';
// What the label shows: 0 for "Latest", or how many new messages
let shownJumpCount = 0;

// "Latest", or the count and "new message(s)". A count shown afresh
// appears at once; a count already showing rolls to the new number
function showJumpCount(count, {shouldRoll}) {
    shownJumpCount = count;
    if (count === 0) {
        jumpLabel.textContent = 'Latest';
        return;
    }

    if (!jumpLabel.contains(jumpCount)) {
        jumpLabel.replaceChildren(jumpCount, '');
    }
    if (!shouldRoll) {
        delete jumpCount.dataset.count;
    }
    countTo(jumpCount, count);
    jumpLabel.lastChild.textContent = count === 1 ? ' new message' : ' new messages';
}

// The pill eases to the new label's width instead of jumping: it starts
// scaled to the old width (--pill-scale, .jump-latest::before in chat.css)
// and grows to the new. Going from "Latest" to a count, the words fade in;
// after that only the number changes, so nothing fades and nothing flickers
function changeJumpLabel(count) {
    const wasLatest = shownJumpCount === 0;
    const widthBefore = jumpButton.offsetWidth;
    showJumpCount(count, {shouldRoll: !wasLatest});
    const widthAfter = jumpButton.offsetWidth;

    jumpButton.classList.remove('is-resizing');
    jumpButton.style.setProperty('--pill-scale', widthBefore / widthAfter);
    void jumpButton.offsetWidth; // start from the old width
    jumpButton.classList.add('is-resizing');
    jumpButton.style.removeProperty('--pill-scale');
    if (wasLatest) replayAnimation(jumpLabel, 'is-changing');
}

function updateJumpButton() {
    const isScrolledUp = !isScrollingToNewest && getDistanceFromBottom() > SCROLLED_UP_DISTANCE;
    if (!isScrolledUp) {
        newMessagesWhileScrolledUp = 0;
        hideJumpButton();
        return;
    }

    const count = newMessagesWhileScrolledUp;
    const wasHidden = jumpButton.hidden || jumpButton.classList.contains('is-leaving');
    jumpButton.classList.remove('is-leaving');
    jumpButton.hidden = false;

    if (wasHidden) {
        showJumpCount(count, {shouldRoll: false});
        return;
    }
    if (count !== shownJumpCount) changeJumpLabel(count);
}

// Eases out down where it is, as it eased in. Its label stays as it was
// while it goes, so the pill doesn't change width on the way out
function hideJumpButton() {
    if (jumpButton.hidden || jumpButton.classList.contains('is-leaving')) return;

    const finishHiding = () => {
        // Shown again meanwhile
        if (!jumpButton.classList.contains('is-leaving')) return;
        jumpButton.classList.remove('is-leaving');
        jumpButton.hidden = true;
        showJumpCount(newMessagesWhileScrolledUp, {shouldRoll: false});
    };

    jumpButton.classList.add('is-leaving');
    jumpButton.addEventListener('animationend', function onLeft(event) {
        // Not the label's own animation
        if (event.target !== jumpButton) return;
        jumpButton.removeEventListener('animationend', onLeft);
        finishHiding();
    });
    // A hidden page doesn't run animations
    setTimeout(finishHiding, 500);
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
        .filter((chat) => !chatList.querySelector(`[data-part="message"][data-id="${chat.dataset.id}"]`));
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
    fadeInPhotos(olderChats.map((chat) => chat.querySelector('[data-part="sender-photo"]')));
    addDayDividers();

    chatList.scrollTop = chatList.scrollHeight - chatList.clientHeight - distanceFromBottom;
}

async function loadOlderIfAny(conversation) {
    if (isLoadingOlder || hasOlderIn[conversation] === false) return;

    isLoadingOlder = true;
    try {
        await loadOlderMessages(conversation);
    } catch {
        // The next scroll near the top tries again
    } finally {
        isLoadingOlder = false;
    }
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
    if (newChats.some((chat) => !isUserOnline(chat.dataset.senderId))) {
        refreshPeople();
    }
    // Their message is in: they've stopped typing it
    newChats.forEach((chat) => hidePartnerTyping(chat.dataset.senderId));

    showTimes(newChats);
    chatList.append(...newChats);
    fadeInPhotos(newChats.map((chat) => chat.querySelector('[data-part="sender-photo"]')));
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

// The box sits in one row with its buttons while the text fits there; once
// it wraps, the text gets the whole width and grows, up to its CSS max-height
function fitMessageInputToText() {
    // Measured in the one-row layout, or text that fits there would never go back
    composerField.classList.remove('is-multiline');
    messageInput.style.height = 'auto';
    const {lineHeight, paddingTop, paddingBottom} = getComputedStyle(messageInput);
    const oneLineHeight = parseFloat(lineHeight) + parseFloat(paddingTop) + parseFloat(paddingBottom);
    // Half a line's slack: a scaled screen rounds one line a pixel taller
    const isWrapped = messageInput.scrollHeight > oneLineHeight + parseFloat(lineHeight) / 2;
    composerField.classList.toggle('is-multiline', isWrapped);

    messageInput.style.height = 'auto';
    // scrollHeight leaves out the border, which the box's height includes
    const borderHeight = messageInput.offsetHeight - messageInput.clientHeight;
    const neededHeight = messageInput.scrollHeight + borderHeight;
    const maxHeight = parseFloat(getComputedStyle(messageInput).maxHeight);
    messageInput.classList.toggle('is-full', neededHeight > maxHeight);
    messageInput.style.height = `${neededHeight}px`;
}

function showCharacterCount() {
    const length = messageInput.value.length;
    const maxLength = messageInput.maxLength;
    const characterCount = document.getElementById('char-count');

    characterCount.hidden = length < maxLength - 100;
    characterCount.classList.toggle('is-near-limit', length > maxLength - 20);
    characterCount.textContent = `${length} / ${maxLength}`;
}

composerForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (isSending) return;

    const to = activeConversation === EVERYONE ? null : activeConversation;

    isSending = true;
    updateSendButton();
    stopTyping();

    try {
        await sendMessage(messageInput.value.trim(), to);
        messageInput.value = '';
        messageInput.dispatchEvent(new Event('input'));
        // Someone you called who was away comes online a moment later
        // (Bot::COME_ONLINE_SECONDS); look then, not on the next round
        setTimeout(refreshPeople, COME_ONLINE_DELAY);
    } catch (error) {
        showToast(errorMessageOf(error, 'Your message could not be sent.'), 'warning');
    } finally {
        isSending = false;
        updateSendButton();
    }
});

// @ suggests names in the open room. Set up before Enter sends, so
// choosing a name with Enter doesn't send the message too
setUpMentions(messageInput, () => activeConversation === EVERYONE, getPeopleToMention);

setUpEmojiPicker(messageInput, document.getElementById('emoji-toggle'), document.getElementById('emoji-picker'));
setUpVoiceTyping(messageInput, document.getElementById('voice-button'), sendButton, document.getElementById('voice-dots'));

// Enter sends, Shift+Enter starts a new line. On a touch screen Enter is
// the keyboard's return key, which starts a new line; Send sends
messageInput.addEventListener('keydown', (event) => {
    if (event.key !== 'Enter' || event.shiftKey || event.isComposing || hasNoHover.matches) return;
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

// A wider or narrower box can fit more or less on one row
window.addEventListener('resize', fitMessageInputToText);

/* Typing: in a private chat, the other person sees "Typing..." while you
   write to them. Only that you are, never what */

const typingRow = document.getElementById('typing-row');

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

// Their face as the list or their messages show it
function getPhotoSrc(userId) {
    const person = personWithId(userId);
    if (person) return person.avatar_src;
    return chatList.querySelector(`[data-part="message"][data-sender-id="${CSS.escape(String(userId))}"] [data-part="sender-photo"]`)?.src ?? '';
}

// While the row opens or closes, the list keeps its newest message in view,
// frame by frame, if that's where you were
function keepNewestInViewWhileTypingRowMoves() {
    if (getDistanceFromBottom() >= AT_BOTTOM_DISTANCE) return;

    let isMoving = true;
    onTransitionEnd(typingRow, () => {
        isMoving = false;
    });
    // In case no transition runs (less motion, a hidden page)
    setTimeout(() => {
        isMoving = false;
    }, 1000);

    const follow = () => {
        chatList.scrollTop = chatList.scrollHeight;
        if (isMoving) requestAnimationFrame(follow);
    };
    follow();
}

function showPartnerTyping() {
    const isTyping = (partnerTypingUntil[activeConversation] ?? 0) >= Date.now();
    if (isTyping === typingRow.classList.contains('is-shown')) return;

    if (isTyping) {
        document.getElementById('typing-row-photo').src = getPhotoSrc(activeConversation);
        document.getElementById('typing-row-label').textContent = `${getTabName(activeConversation)} is typing`;
    } else {
        document.getElementById('typing-row-label').textContent = '';
    }
    keepNewestInViewWhileTypingRowMoves();
    typingRow.classList.toggle('is-shown', isTyping);
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
fadeInPhotos(chatList.querySelectorAll('[data-part="sender-photo"]'));
historyChats.forEach(addTabFor);
// Your newest private chats, loaded or not, counting what came while you were away
JSON.parse(chatPanel.dataset.conversations || '[]').forEach((partner) => {
    const conversation = String(partner.user_id);
    if (wasClosedAfter(conversation, partner.newest_id)) return;

    forgetClosedTab(conversation);
    const tab = addTab(conversation, partner.shown_name);
    if (Number(partner.unread) > 0) setUnreadCount(tab, partner.unread);
});
showConversation(EVERYONE);
shouldAnimateNewTabs = true;
setUpNotifications();
keepPushUpToDate();

// A notification's chat: from a click that opened this page (public/sw.js
// adds ?chat=), or one in a page already open
function openNotifiedChat(conversation, name) {
    if (!conversation || conversation === EVERYONE) {
        showConversation(EVERYONE);
    } else {
        openPrivateChat(conversation, name || '');
    }
}

const notifiedChat = new URLSearchParams(location.search);
if (notifiedChat.has('chat')) {
    openNotifiedChat(notifiedChat.get('chat'), notifiedChat.get('name'));
    history.replaceState(null, '', location.pathname);
}
navigator.serviceWorker?.addEventListener('message', (event) => {
    if (event.data?.openChat) openNotifiedChat(event.data.openChat, event.data.name);
});

keepPeopleUpdated({onPeopleOnline: updateOnlineDots, onPersonChosen: openPrivateChat});
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
