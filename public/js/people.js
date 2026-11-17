// The people list beside the chat: who's online and who was here lately.
// chat.js starts it with keepPeopleUpdated. The chat socket sends the whole
// list on connecting and each change as it happens (setPeople,
// updatePerson); on the HTTP fallback the list is fetched every round

// On the HTTP fallback the list changes slowly, so every 15 seconds is enough
const PEOPLE_INTERVAL = 15000;
// "5 min ago" moves on by itself, without asking the server
const TIME_AGO_INTERVAL = 60000;
// Presence changes this close together are shown as one (updatePerson)
const PEOPLE_RENDER_DELAY = 300;
// Matches the breakpoint in chat-people.css
const NARROW_SCREEN = '(max-width: 1100px)';

const peoplePanel = document.getElementById('people');

const myUserId = Number(document.getElementById('chat').dataset.myId);

// MapChat first, so there's always someone to talk to at the top, then you,
// then everyone else
function listRankOf(person) {
    if (person.isBot) return 0;
    if (person.id === myUserId) return 1;
    return 2;
}

const personByRow = new WeakMap();

// Everyone in the list but you, online first, for @mentions
let peopleToMention = [];

function getPeopleToMention() {
    return peopleToMention;
}

function getTimeAgo(person) {
    const minutes = Math.max(0, Math.floor((Date.now() - person.seenAt) / 60000));
    if (minutes < 60) return `${minutes} min ago`;
    return `${Math.floor(minutes / 60)} h ago`;
}

// Point out the person's pin on the map
function highlightPin(userId, isHighlighted) {
    document.querySelector(`[data-part="map-pin"][data-user-id="${CSS.escape(userId)}"]`)?.classList.toggle('is-highlighted', isHighlighted);
    // With its label (map.js)
    setPinLabelShown(userId, isHighlighted);
}

// Online: flag and city. Recently here: how long ago
function createPersonDetail(person) {
    const detail = createElement('span', 'detail');
    if (!person.isOnline) {
        detail.textContent = getTimeAgo(person);
        return detail;
    }
    detail.append(createPlace(person.city, person.country));
    return detail;
}

function createPersonRow(person) {
    const isMe = person.id === myUserId;
    const userId = String(person.id);

    // Your own row does nothing; anyone else's opens a private chat
    const row = isMe ? createElement('div', 'person') : createButton('person');
    row.classList.toggle('is-online', person.isOnline);
    row.classList.toggle('is-messageable', !isMe);
    row.dataset.part = 'person';
    row.dataset.userId = userId;
    row.title = isMe ? '' : `Message ${person.shown_name}`;
    row.dataset.tipSide = 'left'; // over the map, not the rows above

    const avatarFrame = createElement('span', 'avatar');
    const photo = createElement('img', 'photo');
    photo.dataset.part = 'person-photo';
    photo.alt = '';
    photo.width = 32;
    photo.height = 32;
    photo.loading = 'lazy';
    photo.src = person.avatar_src;
    avatarFrame.append(photo);

    const text = createElement('span', 'text');
    text.append(
        createElement('span', 'name', person.shown_name + (isMe ? ' (you)' : '')),
        createPersonDetail(person),
    );

    row.append(avatarFrame, text);
    row.addEventListener('mouseenter', () => highlightPin(userId, true));
    row.addEventListener('mouseleave', () => highlightPin(userId, false));
    personByRow.set(row, person);
    return row;
}

// Where each row is now, by user id, leaving out rows on their way out
function getRowTops(section) {
    const tops = new Map();
    section.querySelectorAll(':scope > [data-part="person"]:not(.is-leaving)').forEach((row) => {
        tops.set(row.dataset.userId, row.getBoundingClientRect().top);
    });
    return tops;
}

// Rows that were already there start where they were and slide to their
// new place, so someone joining pushes the list down smoothly
function slideToNewPlaces(section, topsBefore) {
    const shifts = new Map();
    for (const row of section.querySelectorAll(':scope > [data-part="person"]:not(.is-entering, .is-leaving)')) {
        const topBefore = topsBefore.get(row.dataset.userId);
        if (topBefore === undefined) continue;
        const shift = topBefore - row.getBoundingClientRect().top;
        if (Math.round(shift) !== 0) shifts.set(row, {y: shift});
    }
    slideFromShifts(shifts);
}

// The row eases out where it is; then the rows below slide up into its
// place instead of jumping
function easeOutRow(section, row) {
    if (!isShown(row)) {
        row.remove();
        return;
    }
    row.classList.add('is-leaving');
    // Its own ending, not the arrival tint's of a row that only just came
    row.addEventListener('animationend', function onLeft(event) {
        if (event.animationName !== 'person-out') return;
        row.removeEventListener('animationend', onLeft);
        const topsBefore = getRowTops(section);
        row.remove();
        slideToNewPlaces(section, topsBefore);
    });
}

// What a row shows. A row whose person shows the same is kept as it is,
// so one still easing in or sliding isn't started over
function rowContentOf(person) {
    const detail = person.isOnline ? [person.city, person.country] : [getTimeAgo(person)];
    return [person.shown_name, person.avatar_src, ...detail].join('|');
}

function createEnteringRow(person) {
    const row = createPersonRow(person);
    row.classList.add('is-entering', 'is-arrived');
    row.addEventListener('animationend', (event) => {
        if (event.animationName === 'person-in') row.classList.remove('is-entering');
        if (event.animationName === 'person-arrived') row.classList.remove('is-arrived');
    });
    return row;
}

// Only what changed moves: people new to the section ease in, those gone
// ease out where they were, a changed row is swapped in place, and the rest
// slide to their new places. Moving between online and recent counts as
// leaving one and joining the other, so the green dot fades with the row
function fillPeople(section, people) {
    // The loading dots, the first time
    section.querySelectorAll(':scope > :not([data-part="person"])').forEach((element) => element.remove());
    const topsBefore = getRowTops(section);
    const oldRows = new Map();
    section.querySelectorAll(':scope > [data-part="person"]:not(.is-leaving)').forEach((row) => oldRows.set(row.dataset.userId, row));

    const rows = people.map((person) => {
        const content = rowContentOf(person);
        const oldRow = oldRows.get(String(person.id));
        if (oldRow?.dataset.content === content) {
            personByRow.set(oldRow, person);
            return oldRow;
        }

        const row = oldRow ? createPersonRow(person) : createEnteringRow(person);
        row.dataset.content = content;
        oldRow?.replaceWith(row);
        return row;
    });

    // Walk the section in the new order. Rows not in it any more ease out
    // where they are; rows already easing out stay until they've gone
    const keptRows = new Set(rows);
    let next = section.firstElementChild;
    const passRowsNotKept = () => {
        while (next && !keptRows.has(next)) {
            if (next.dataset.part === 'person' && !next.classList.contains('is-leaving')) easeOutRow(section, next);
            next = next.nextElementSibling;
        }
    };
    rows.forEach((row) => {
        passRowsNotKept();
        if (row === next) {
            next = next.nextElementSibling;
            return;
        }
        section.insertBefore(row, next);
    });
    passRowsNotKept();

    slideToNewPlaces(section, topsBefore);
}

function showPeople(people) {
    const onlinePeople = people.filter((person) => person.isOnline);
    const recentPeople = people.filter((person) => !person.isOnline);

    onlinePeople.sort((a, b) => listRankOf(a) - listRankOf(b) || a.shown_name.localeCompare(b.shown_name));
    peopleToMention = [...onlinePeople, ...recentPeople].filter((person) => person.id !== myUserId);

    // The rows are replaced, so a hovered row never gets its mouseleave
    document.querySelectorAll('[data-part="map-pin"].is-highlighted').forEach((pin) => highlightPin(pin.dataset.userId, false));

    peopleHandlers?.onPeopleOnline(new Set(onlinePeople.map((person) => person.id)));

    countTo(document.getElementById('online-count'), onlinePeople.length);
    countTo(document.getElementById('people-toggle-count'), onlinePeople.length);
    fillPeople(document.getElementById('people-online'), onlinePeople);
    document.getElementById('recent-heading').hidden = recentPeople.length === 0;
    fillPeople(document.getElementById('people-recent'), recentPeople);
    fadeInPhotos(peoplePanel.querySelectorAll('[data-part="person-photo"]'));
}

// What chat.js does when the list changes (see keepPeopleUpdated)
let peopleHandlers = null;

// Everyone in the list, by their (number) id
const peopleById = new Map();

// The person with that id, given as a number or as a page's string
const personWithId = (userId) => peopleById.get(Number(userId));

function showAllPeople() {
    clearTimeout(peopleRenderTimer);
    peopleRenderTimer = null;
    showPeople([...peopleById.values()]);
}

// Changes that come close together (several people at once) move the list
// in one go, rather than each starting before the last has settled
let peopleRenderTimer = null;

function showAllPeopleSoon() {
    if (peopleRenderTimer === null) peopleRenderTimer = setTimeout(showAllPeople, PEOPLE_RENDER_DELAY);
}

// A person as the server sends them, with numbers and yes-or-no flags as
// such, and when they were last here as a time, so the list can count on
// from it. The rest of this file and chat.js only see these
function personFromServer(row) {
    const {is_online, is_bot, is_guest, is_deleted, minutes_ago, ...details} = row;
    return {
        ...details,
        id: Number(row.id),
        isOnline: Number(is_online) === 1,
        isBot: Number(is_bot) === 1,
        isGuest: Number(is_guest) === 1,
        isDeleted: Boolean(is_deleted),
        seenAt: Date.now() - (Number(minutes_ago) || 0) * 60000,
    };
}

// The whole list, as the server has it now
function setPeople(rows) {
    peopleById.clear();
    for (const row of rows) {
        const person = personFromServer(row);
        peopleById.set(person.id, person);
    }
    showAllPeople();
}

// Someone came online or went. Guests are only listed while online, and a
// deleted account not at all
function updatePerson(row) {
    const person = personFromServer(row);
    const isGoneForGood = !person.isOnline && (person.isGuest || person.isDeleted);
    if (isGoneForGood) {
        peopleById.delete(person.id);
    } else {
        peopleById.set(person.id, person);
    }
    showAllPeopleSoon();
}

setInterval(() => {
    if ([...peopleById.values()].some((person) => !person.isOnline)) showAllPeople();
}, TIME_AGO_INTERVAL);

/* The HTTP fallback: the whole list every round */

let isPollingPeople = false;
let nextPeopleLoad = null;

async function loadPeople() {
    try {
        const response = await postForm('/app/get-people', {timezone: Intl.DateTimeFormat().resolvedOptions().timeZone});
        if (isPollingPeople) setPeople(response.data);
    } catch {
        // Try again on the next round
    }
    // One round at a time, even after a refreshPeople()
    clearTimeout(nextPeopleLoad);
    if (isPollingPeople) nextPeopleLoad = setTimeout(loadPeople, PEOPLE_INTERVAL);
}

// While the chat socket is down; its own updates take over when it's back
function setPeoplePolling(isOn) {
    if (isOn === isPollingPeople) return;
    isPollingPeople = isOn;
    clearTimeout(nextPeopleLoad);
    if (isOn) loadPeople();
}

// The list now rather than on the next round: when whoever you messaged
// has just come online, so they show up before they answer. The chat
// socket says so by itself
function refreshPeople() {
    if (!isPollingPeople) return;
    clearTimeout(nextPeopleLoad);
    loadPeople();
}

// A drawer on narrow screens, a column that can be closed on wide ones
const narrowScreen = window.matchMedia(NARROW_SCREEN);

const peopleToggle = document.getElementById('people-toggle');

function setPeopleOpen(isOpen) {
    peopleToggle.setAttribute('aria-expanded', String(isOpen));
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

peopleToggle.addEventListener('click', () => {
    setPeopleOpen(!isShown(peoplePanel));
});

document.getElementById('people-close').addEventListener('click', () => {
    setPeopleOpen(false);
});

// handlers: onPeopleOnline(ids) gets the (number) ids of who's online
// whenever the list changes; onPersonChosen(id, name) runs when a row is
// clicked
function keepPeopleUpdated(handlers) {
    peopleHandlers = handlers;
    peoplePanel.addEventListener('click', (event) => {
        // Anyone's row but your own is a button, which opens a private chat
        const row = event.target.closest('button[data-part="person"]');
        if (!row) return;

        const person = personByRow.get(row);
        if (narrowScreen.matches) {
            setPeopleOpen(false);
        }
        handlers.onPersonChosen(person.id, person.shown_name);
    });
}
