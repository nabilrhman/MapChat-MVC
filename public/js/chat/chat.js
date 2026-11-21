// The chat page: messages, tabs for private chats, and the composer. Talking
// to the server about messages is in messages.js, the people list in
// people.js, dragging tabs in tab-dragging.js and the phone's sheet in
// chat-sheet.js. The server's values come from data attributes on #chat (see
// base_chat.html)

// How far up you scroll before the "Latest" button shows
const SCROLLED_UP_DISTANCE = 200;
// A little after a demo person you called comes online (Bot::COME_ONLINE_SECONDS)
const COME_ONLINE_DELAY = 7000;
// Closer to the bottom than this, new messages scroll into view
const AT_BOTTOM_DISTANCE = 80;
// Within this many screens of the top, the next page of older messages
// loads: early enough that a quick scroll up doesn't hit the top and stop
// while they come
const LOAD_OLDER_SCREENS = 5;
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

const conversationOf = (chat) => chat.dataset.conversation;
const getChats = () => [...chatList.querySelectorAll('[data-part="message"]')];
// The newest message on the page, in any tab, or 0
const getNewestId = () => Number(getChats().at(-1)?.dataset.id ?? 0);
const getDistanceFromBottom = () => chatList.scrollHeight - chatList.scrollTop - chatList.clientHeight;

// At once: any motion is slideUpToNewest's
function scrollToNewest() {
    chatList.scrollTop = chatList.scrollHeight;
}

// True while the newest messages slide up into place. The list then reads as
// scrolled up by the slide, as it scrolls over the shifted messages too, so
// the "Latest" button waits till the slide ends
let isSlidingToNewest = false;
let slideEndTimer = null;

function endSlideToNewest() {
    clearTimeout(slideEndTimer);
    isSlidingToNewest = false;
    updateJumpButton();
}

function startSlideToNewest(shifts) {
    const [firstItem] = shifts.keys();
    if (!firstItem) return;

    isSlidingToNewest = true;
    clearTimeout(slideEndTimer);
    firstItem.addEventListener('transitionend', function onSlid(event) {
        if (event.target !== firstItem || event.propertyName !== 'translate') return;
        firstItem.removeEventListener('transitionend', onSlid);
        endSlideToNewest();
    });
    // For a hidden page, which runs no transitions
    slideEndTimer = setTimeout(endSlideToNewest, 1000);
    slideFromShifts(shifts);
}

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
    const conversation = conversationOf(chat);
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

    const openButton = createButton('label');
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
        chat.hidden = conversationOf(chat) !== activeConversation;
    });
    replayAnimation(chatList, 'is-switching');

    const isPrivate = activeConversation !== EVERYONE;

    showChatHeader();
    messageInput.placeholder = isPrivate ? `Message ${getTabName(activeConversation)}` : 'Your message';
    addDayDividers();
    scrollToNewest();
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

    const unseenCount = getChats().filter((chat) => conversationOf(chat) === conversation
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
    // Hidden with their place when they don't share their location: null, or missing
    if (person.lng != null) placeAndTime.push(createLocalTime(person));
    where.append(...createFlipToCountry(placeAndTime, person));
}

// Their city and time, which flip up together to show their country on
// hover (.chat-header .line-swap in chat-panel.css). Stays put without a city to flip
// from, or a name for the country
function createFlipToCountry(placeAndTime, person) {
    if (!person.city || !person.country || countryName(person.country) === person.country) return placeAndTime;

    const cityLine = createElement('span', 'line');
    cityLine.append(...placeAndTime);
    const countryLine = createElement('span', 'line');
    countryLine.append(createPlace(null, person.country));
    const track = createElement('span', 'track');
    track.append(cityLine, countryLine);
    const lineSwap = createElement('span', 'line-swap');
    lineSwap.dataset.swaps = '';
    lineSwap.append(track);
    return [lineSwap];
}

// Their time now, with a sun or moon, as their pin and messages show it
function createLocalTime(person) {
    const time = createElement('span', 'time');
    showLocalTime(time, personTimeOf(new Date(), person.country, person.timezone, person.lng));
    return time;
}

// The time where someone is, or was. Always midnight on Null Island, where
// the time zones meet
function personTimeOf(date, country, timezone, longitude) {
    if (country === NULL_ISLAND) return {text: '00:00', hour: 0};
    return localTimeOf(date, timezone, parseFloat(longitude));
}

// Keeps the time in the header current
setInterval(() => {
    if (activeConversation !== EVERYONE) showChatHeader();
}, 60000);

// A green dot on the tab of each private chat partner who's online;
// it fades only when that changes
function updateOnlineDots() {
    chatTabs.querySelectorAll('[data-part="tab-online"]').forEach((dot) => {
        const isOnline = isUserOnline(dot.closest('[data-part="tab"]').dataset.conversation);
        const isLeaving = dot.classList.contains('is-leaving');
        const isDotShown = !dot.hidden && !isLeaving;
        if (isOnline === isDotShown) return;

        if (isOnline) {
            // Back before the dot finished fading: it stays
            dot.classList.remove('is-leaving');
            dot.hidden = false;
            return;
        }
        dot.classList.add('is-leaving');
        onAnimationEnd(dot, () => {
            // Came back online while it faded
            if (!dot.classList.contains('is-leaving')) return;
            dot.hidden = true;
            dot.classList.remove('is-leaving');
        });
    });
}

// The people list changed who's online: the header and the tabs' dots follow
function onPeopleOnline(userIds) {
    onlineUserIds = userIds;
    showChatHeader();
    updateOnlineDots();
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

/* Messages */

const sentDateOf = (chat) => new Date(chat.querySelector('[data-part="sent-at"]').getAttribute('datetime'));

function showSenderTime(chat) {
    const senderTimeLine = chat.querySelector('[data-part="sender-time"]');
    if (!senderTimeLine) return;

    const timeInfo = chat.querySelector('[data-part="time"]');
    const {country, timezone, lng} = timeInfo.dataset;
    showLocalTime(senderTimeLine, personTimeOf(sentDateOf(chat), country, timezone, lng));
}

// Each message shows its time; hovering it slides to the sender's own time.
// Also names the sender's country, shown when hovering the city
function showTimes(chats) {
    chats.forEach((chat) => {
        chat.querySelectorAll('[data-part="country"]').forEach((country) => {
            country.textContent = countryName(country.dataset.country);
        });

        chat.querySelector('[data-part="sent-at"]').textContent = sentDateOf(chat).toLocaleTimeString([], {hour: 'numeric', minute: '2-digit'});
        showSenderTime(chat);
    });
}

function dayLabelOf(date) {
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
        .filter((chat) => conversationOf(chat) === activeConversation)
        .forEach((chat) => {
            const date = sentDateOf(chat);
            if (date.toDateString() === lastDay) return;

            const divider = createElement('div', 'day-divider');
            divider.dataset.part = 'day-divider';
            divider.append(createElement('span', 'label', dayLabelOf(date)));
            chat.before(divider);
            lastDay = date.toDateString();
        });
}

// One more message on a tab's count, which pops in at the first
function countUnread(tab) {
    const unreadCount = tab.querySelector('[data-part="tab-unread"]');
    slideTabs(() => {
        unreadCount.classList.toggle('is-entering', unreadCount.hidden);
        unreadCount.textContent = (parseInt(unreadCount.textContent, 10) || 0) + 1;
        unreadCount.hidden = false;
    });
}

// Put new messages in their tab and count the ones you haven't seen: in
// other tabs, or in this one while the page is hidden. The room is too busy
// to count everything, so its tab counts only messages that @mention you.
// One ping for the lot, however many ping-worthy messages came at once
function placeMessages(chats) {
    let shouldPing = false;
    chats.forEach((chat) => {
        const tab = addTabFor(chat);
        const isInOpenTab = conversationOf(chat) === activeConversation;
        const isUnseen = !isInOpenTab || document.hidden;
        const isMention = 'mentionsMe' in chat.dataset;
        const isCounted = conversationOf(chat) !== EVERYONE || isMention;

        chat.hidden = !isInOpenTab;
        if (tab && isUnseen) {
            if (isCounted) countUnread(tab);
            notifyOf(chat, openNotifiedChat);
        }
        if (shouldPingFor(chat, !isUnseen)) shouldPing = true;
    });
    if (shouldPing) playPing();
    if (!document.hidden && chats.some((chat) => conversationOf(chat) === activeConversation)) {
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

/* Touch screens have no hover: a tap slides a two-line window (.line-swap in
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

// A translated message shows what its sender typed, and back
chatList.addEventListener('click', (event) => {
    const seeOriginal = event.target.closest('[data-part="see-original"]');
    if (!seeOriginal) return;
    const message = seeOriginal.closest('[data-part="message"]');
    const shouldShowOriginal = seeOriginal.getAttribute('aria-pressed') !== 'true';

    const translation = message.querySelector('[data-part="message-body"]');
    const original = message.querySelector('[data-part="original-body"]');
    swapMessageText(shouldShowOriginal ? translation : original, shouldShowOriginal ? original : translation);
    seeOriginal.setAttribute('aria-pressed', String(shouldShowOriginal));
    seeOriginal.textContent = shouldShowOriginal ? 'See translation' : 'See original';
});

// One text fades out as the other fades in over it, while the message eases
// from one's height to the other's (.texts in chat-messages.css), so the
// messages under it slide instead of jumping. At once under reduced motion
function swapMessageText(leaving, arriving) {
    const texts = leaving.closest('[data-part="message-texts"]');
    for (const text of [leaving, arriving]) {
        text.classList.remove('is-leaving', 'is-arriving');
    }

    if (prefersLessMotion.matches) {
        leaving.hidden = true;
        arriving.hidden = false;
        return;
    }

    const startHeight = texts.offsetHeight;
    // Measured as it will be once the swap is done, margins and all
    arriving.hidden = false;
    leaving.hidden = true;
    const endHeight = texts.offsetHeight;
    leaving.hidden = false;
    leaving.classList.add('is-leaving');
    arriving.classList.add('is-arriving');

    texts.classList.add('is-resizing');
    const resize = texts.animate([{height: `${startHeight}px`}, {height: `${endHeight}px`}], motionTiming());
    resize.addEventListener('finish', () => texts.classList.remove('is-resizing'), {once: true});

    onAnimationEnd(leaving, () => {
        if (!leaving.classList.contains('is-leaving')) return;
        leaving.classList.remove('is-leaving');
        leaving.hidden = true;
    });
    onAnimationEnd(arriving, () => arriving.classList.remove('is-arriving'));
}

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
// scaled to the old width (--pill-scale, .jump-latest::before in chat-panel.css)
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
    const isScrolledUp = !isSlidingToNewest && getDistanceFromBottom() > SCROLLED_UP_DISTANCE;
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
    slideUpToNewest();
    if (jumpButton.matches(':focus-visible')) messageInput.focus();
});

/* Older messages: the open tab's next page loads as you scroll near the
   top, straight from that chat, however busy the others have been */

// The server's page size; a shorter page means there are no more
const pageSize = Number(chatPanel.dataset.pageSize);
// Per tab: false once the server has no older messages for it
const hasOlderIn = {};
// The tabs whose older page is on its way: one tab's load doesn't hold up another's
const loadingOlderIn = new Set();

const hasChatsIn = (conversation) => getChats().some((chat) => conversationOf(chat) === conversation);

// The tab's oldest message here; with none, the server sends its newest page
function getOldestIdIn(conversation) {
    const chat = getChats().find((chat) => conversationOf(chat) === conversation);
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
        chat.hidden = conversationOf(chat) !== activeConversation;
    });
    insertInOrder(olderChats);
    fadeInPhotos(olderChats.map((chat) => chat.querySelector('[data-part="sender-photo"]')));
    addDayDividers();

    chatList.scrollTop = chatList.scrollHeight - chatList.clientHeight - distanceFromBottom;
}

async function loadOlderIfAny(conversation) {
    if (loadingOlderIn.has(conversation) || hasOlderIn[conversation] === false) return;

    loadingOlderIn.add(conversation);
    try {
        await loadOlderMessages(conversation);
    } catch {
        // The next scroll near the top tries again
    } finally {
        loadingOlderIn.delete(conversation);
    }
}

function loadOlderIfNearTop() {
    if (chatList.scrollTop <= chatList.clientHeight * LOAD_OLDER_SCREENS) loadOlderIfAny(activeConversation);
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
    if (chats.length <= MAX_MESSAGES || loadingOlderIn.size > 0) return;
    // Nor while an open private chat would lose messages: its list could empty
    const toDrop = chats.slice(0, chats.length - MAX_MESSAGES);
    if (activeConversation !== EVERYONE && toDrop.some((chat) => conversationOf(chat) === activeConversation)) return;

    toDrop.forEach((chat) => {
        hasOlderIn[conversationOf(chat)] = true;
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

// Where each message and day divider on screen is now, by element
function getTopsInView() {
    const listBox = chatList.getBoundingClientRect();
    const tops = new Map();
    for (const item of chatList.children) {
        if (item.hidden) continue;
        const box = item.getBoundingClientRect();
        if (box.bottom > listBox.top && box.top < listBox.bottom) tops.set(item, box.top);
    }
    return tops;
}

// Adds messages at the bottom, if any, and shows the newest: the list jumps
// there, and what's on screen slides up as one, in the app's easing. Only
// translate moves
function slideUpToNewest(addMessages = () => {}) {
    if (prefersLessMotion.matches || document.hidden) {
        addMessages();
        scrollToNewest();
        return;
    }

    const topsBefore = getTopsInView();
    const lastBefore = [...topsBefore.keys()].at(-1);
    addMessages();
    // Ones still sliding from the last arrival snap to their places, so the
    // places read below are where they really are
    for (const item of topsBefore.keys()) {
        item.classList.remove('is-shifting');
        item.style.translate = '';
    }
    scrollToNewest();

    // How far the list rose: new messages start that far down, with the rest
    const rise = lastBefore?.isConnected ? topsBefore.get(lastBefore) - lastBefore.getBoundingClientRect().top : 0;
    const shifts = new Map();
    // From further up than a screen, only the last screen slides, up from
    // below: sliding the whole way would sweep a blank gap up the list
    if (rise > chatList.clientHeight) {
        for (const item of getTopsInView().keys()) shifts.set(item, {y: chatList.clientHeight});
        startSlideToNewest(shifts);
        return;
    }
    // Ones that rose out of view slide too, or a gap would open at the top
    const itemsToSlide = new Set([...topsBefore.keys(), ...getTopsInView().keys()]);
    for (const item of itemsToSlide) {
        if (!item.isConnected) continue;
        const topBefore = topsBefore.get(item);
        const shift = topBefore === undefined ? rise : topBefore - item.getBoundingClientRect().top;
        if (Math.round(shift) !== 0) shifts.set(item, {y: shift});
    }
    startSlideToNewest(shifts);
}

// The messages that came in last, laid out at once (.is-new)
let newestChats = [];

// Shows new messages at the bottom if you're there already, or after you
// send; someone reading older messages stays where they are
function showNewChats(newChats, isAfterSending) {
    const wasAtBottom = getDistanceFromBottom() < AT_BOTTOM_DISTANCE;

    // Someone the list still has as away just spoke: they're here
    if (newChats.some((chat) => !isUserOnline(chat.dataset.senderId))) {
        refreshPeople();
    }
    // Their message is in: they've stopped typing it
    newChats.forEach((chat) => hidePartnerTyping(chat.dataset.senderId));

    showTimes(newChats);
    const addNewChats = () => {
        // Only the latest are laid out at once; the rest skip layout again
        newestChats.forEach((chat) => chat.classList.remove('is-new'));
        newestChats = newChats;
        newChats.forEach((chat) => chat.classList.add('is-new'));
        chatList.append(...newChats);
        fadeInPhotos(newChats.map((chat) => chat.querySelector('[data-part="sender-photo"]')));
        placeMessages(newChats);
        addDayDividers();
    };

    if (wasAtBottom || isAfterSending) {
        slideUpToNewest(() => {
            addNewChats();
            trimOldMessages();
        });
    } else {
        addNewChats();
        // Only ones in the open tab; the others get a count on their tab
        newMessagesWhileScrolledUp += newChats.filter((chat) => !chat.hidden).length;
        updateJumpButton();
    }
}

/* Sending */

// Send stays off until the message is out, so a second Enter can't post it twice
let isSending = false;

// While voice typing listens, Send is its stop button, empty box or not;
// while it writes the words out, Send waits
function updateSendButton() {
    const voiceState = getVoiceState();
    if (voiceState === 'listening') {
        sendButton.disabled = false;
        return;
    }
    sendButton.disabled = isSending || voiceState === 'transcribing' || messageInput.value.trim() === '';
}

const canFieldFitText = CSS.supports('field-sizing', 'content');

// The box sits in one row with its buttons while the text fits there; once
// it wraps, the text gets the whole width and grows, up to its CSS max-height.
// shouldWrap wraps it anyway, for voice typing's dots (voice.js)
function fitMessageInputToText({shouldWrap = false} = {}) {
    // Measured in the one-row layout, or text that fits there would never go back
    composerField.classList.remove('is-multiline');
    messageInput.style.height = 'auto';
    const {lineHeight, paddingTop} = getComputedStyle(messageInput);
    // The top's padding twice: voice typing can add room below for its dots (voice.js)
    const oneLineHeight = parseFloat(lineHeight) + 2 * parseFloat(paddingTop);
    // Half a line's slack: a scaled screen rounds one line a pixel taller
    const isWrapped = shouldWrap || messageInput.scrollHeight > oneLineHeight + parseFloat(lineHeight) / 2;
    composerField.classList.toggle('is-multiline', isWrapped);

    messageInput.style.height = 'auto';
    // scrollHeight leaves out the border, which the box's height includes
    const borderHeight = messageInput.offsetHeight - messageInput.clientHeight;
    const neededHeight = messageInput.scrollHeight + borderHeight;
    const maxHeight = parseFloat(getComputedStyle(messageInput).maxHeight);
    messageInput.classList.toggle('is-full', neededHeight > maxHeight);
    // field-sizing grows the box itself (chat-composer.css); 'auto' above lets it
    if (!canFieldFitText) messageInput.style.height = `${neededHeight}px`;
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
    replaceLastEmoticonIn(messageInput);
    const sentText = messageInput.value.trim();

    isSending = true;
    updateSendButton();
    stopTyping();

    try {
        await sendMessage(await messageToSend(sentText), to);
        forgetTranslation();
        // The box stays open while it sends (a translation can take a while):
        // anything typed meanwhile stays
        if (messageInput.value.trim() === sentText) {
            messageInput.value = '';
            messageInput.dispatchEvent(new Event('input'));
        }
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
setUpEmoticons(messageInput);
const getVoiceState = setUpVoiceTyping(messageInput, document.getElementById('voice-button'), sendButton, document.getElementById('voice-dots'));
setUpTranslation(document.getElementById('translate-toggle'), document.getElementById('translate-band'));

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

// The people typing to you, with when it stops showing unless they type on,
// and the one timer each that hides it then
const partnerTypingUntil = {};
const partnerTypingTimers = {};

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
        clearTimeout(partnerTypingTimers[from]);
        partnerTypingTimers[from] = setTimeout(showPartnerTyping, TYPING_SHOWN_FOR + 50);
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
JSON.parse(chatPanel.dataset.conversations ?? '[]').forEach((partner) => {
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
// So others see your local time, on the HTTP fallback too; the socket's hello says it again
postForm('/profile/update-timezone', {timezone: Intl.DateTimeFormat().resolvedOptions().timeZone}).catch(() => {});

// The room, or a person's id: anything else in a link is not a chat
const isConversation = (conversation) => conversation === EVERYONE || /^[1-9][0-9]*$/.test(conversation);

// A private chat partner's name as this page knows it: their tab, the people
// list, or one of their messages here; null when none of those has them
function knownNameOf(conversation) {
    const tab = getTab(conversation);
    if (tab) return getTabName(conversation);
    const person = personWithId(conversation);
    if (person) return person.shown_name;
    const chat = getChats().find((chat) => conversationOf(chat) === conversation);
    return chat?.dataset.partner ?? null;
}

// The server's people list, for a name this page doesn't know yet
async function fetchNameOf(conversation) {
    try {
        const response = await axios.get('/people');
        const row = response.data.find((person) => String(person.id) === conversation);
        return row?.shown_name ?? null;
    } catch {
        return null;
    }
}

// A notification's chat: from a click that opened this page
// (public/service-worker.js adds ?chat=), or one in a page already open.
// Its name is never taken from the link, which anyone can write; a link
// to no chat, or to someone the server doesn't know of, opens nothing
async function openNotifiedChat(conversation) {
    const chatToOpen = String(conversation ?? '');
    // Not a private chat with yourself either
    if (!isConversation(chatToOpen) || Number(chatToOpen) === myUserId) return;
    if (chatToOpen === EVERYONE) {
        showConversation(EVERYONE);
        return;
    }

    const name = knownNameOf(chatToOpen) ?? await fetchNameOf(chatToOpen);
    if (name !== null) openPrivateChat(chatToOpen, name);
}

const pageQuery = new URLSearchParams(location.search);
if (pageQuery.has('chat')) {
    openNotifiedChat(pageQuery.get('chat'));
    history.replaceState(null, '', location.pathname);
}
navigator.serviceWorker?.addEventListener('message', (event) => {
    if (event.data?.openChat) openNotifiedChat(event.data.openChat);
});

// A row in the people list opens their chat and, when the map shows, brings
// their pin to its middle. On a phone with the chat all the way up the map
// stays put, as the keyboard is coming up to write to them
function onPersonChosen(userId, name) {
    openPrivateChat(userId, name);
    if (isMapOpen()) focusPin(userId);
}

keepPeopleUpdated({onPeopleOnline, onPersonChosen});
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
