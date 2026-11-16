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
// Matches the breakpoint in chat.css
const NARROW_SCREEN = '(max-width: 1100px)';

const peoplePanel = document.getElementById('people');

const myUserId = Number(document.getElementById('chat').dataset.myId);

const isOnline = (person) => Number(person.is_online) === 1;

// MapChat first, so there's always someone to talk to at the top, then you,
// then everyone else
function getListRank(person) {
    if (Number(person.is_bot) === 1) return 0;
    if (Number(person.id) === myUserId) return 1;
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
    document.querySelector(`.map-pin[data-user-id="${CSS.escape(userId)}"]`)?.classList.toggle('is-highlighted', isHighlighted);
    // With its label (map.js)
    setPinLabelShown(userId, isHighlighted);
}

// Online: flag and city. Recently here: how long ago
function createPersonDetail(person) {
    const detail = createElement('span', 'person__detail');
    if (!isOnline(person)) {
        detail.textContent = getTimeAgo(person);
        return detail;
    }
    detail.append(createPlace(person.city, person.country));
    return detail;
}

function createPersonRow(person) {
    const isMe = Number(person.id) === myUserId;
    const userId = String(person.id);

    const row = createElement('div', 'person');
    row.classList.toggle('is-online', isOnline(person));
    row.classList.toggle('person--can-message', !isMe);
    row.dataset.userId = userId;
    row.title = isMe ? '' : `Chat privately with ${person.shown_name}`;
    row.dataset.tipSide = 'left'; // over the map, not the rows above

    const avatarFrame = createElement('div', 'person__avatar');
    const photo = createElement('img', 'person__photo');
    photo.alt = '';
    photo.src = person.avatar_src;
    avatarFrame.append(photo);

    const text = createElement('div', 'person__text');
    text.append(
        createElement('span', 'person__name', person.shown_name + (isMe ? ' (you)' : '')),
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
    section.querySelectorAll(':scope > .person:not(.is-leaving)').forEach((row) => {
        tops.set(row.dataset.userId, row.getBoundingClientRect().top);
    });
    return tops;
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
        // A row still sliding from the last change starts again from where it is
        row.classList.remove('is-shifting');
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
function getRowContent(person) {
    const detail = isOnline(person) ? [person.city, person.country] : [getTimeAgo(person)];
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
    section.querySelectorAll(':scope > :not(.person)').forEach((element) => element.remove());
    const topsBefore = getRowTops(section);
    const oldRows = new Map();
    section.querySelectorAll(':scope > .person:not(.is-leaving)').forEach((row) => oldRows.set(row.dataset.userId, row));

    const rows = people.map((person) => {
        const content = getRowContent(person);
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
            if (next.classList.contains('person') && !next.classList.contains('is-leaving')) easeOutRow(section, next);
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

function showPeople(people, onPeopleOnline) {
    const onlinePeople = people.filter(isOnline);
    const recentPeople = people.filter((person) => !isOnline(person));

    onlinePeople.sort((a, b) => getListRank(a) - getListRank(b) || a.shown_name.localeCompare(b.shown_name));
    peopleToMention = [...onlinePeople, ...recentPeople].filter((person) => Number(person.id) !== myUserId);

    // The rows are replaced, so a hovered row never gets its mouseleave
    document.querySelectorAll('.map-pin.is-highlighted').forEach((pin) => highlightPin(pin.dataset.userId, false));

    onPeopleOnline(new Set(onlinePeople.map((person) => Number(person.id))));

    document.getElementById('online-count').textContent = onlinePeople.length;
    document.getElementById('people-toggle-count').textContent = onlinePeople.length;
    fillPeople(document.getElementById('people-online'), onlinePeople);
    document.getElementById('recent-heading').hidden = recentPeople.length === 0;
    fillPeople(document.getElementById('people-recent'), recentPeople);
    fadeInPhotos(peoplePanel.querySelectorAll('img'));
}

// Set by keepPeopleUpdated
let reportPeopleOnline = () => {};

// Everyone in the list, by id
const peopleById = new Map();

function showAllPeople() {
    clearTimeout(peopleRenderTimer);
    peopleRenderTimer = null;
    showPeople(Array.from(peopleById.values()), reportPeopleOnline);
}

// Changes that come close together (several people at once) move the list
// in one go, rather than each starting before the last has settled
let peopleRenderTimer = null;

function showAllPeopleSoon() {
    if (peopleRenderTimer === null) peopleRenderTimer = setTimeout(showAllPeople, PEOPLE_RENDER_DELAY);
}

// When they were last here, as a time, so the list can count on from it
function withSeenAt(person) {
    return {...person, seenAt: Date.now() - (Number(person.minutes_ago) || 0) * 60000};
}

// The whole list, as the server has it now
function setPeople(people) {
    peopleById.clear();
    people.forEach((person) => peopleById.set(String(person.id), withSeenAt(person)));
    showAllPeople();
}

// Someone came online or went. Guests are only listed while online, and a
// deleted account not at all
function updatePerson(person) {
    const isGoneForGood = !isOnline(person) && (Number(person.is_guest) === 1 || person.is_deleted);
    if (isGoneForGood) {
        peopleById.delete(String(person.id));
    } else {
        peopleById.set(String(person.id), withSeenAt(person));
    }
    showAllPeopleSoon();
}

setInterval(() => {
    if (Array.from(peopleById.values()).some((person) => !isOnline(person))) showAllPeople();
}, TIME_AGO_INTERVAL);

/* The HTTP fallback: the whole list every round */

let isPollingPeople = false;
let nextPeopleLoad = null;

function loadPeople() {
    postForm('/app/get-people', {timezone: Intl.DateTimeFormat().resolvedOptions().timeZone})
        .then((response) => {
            if (isPollingPeople) setPeople(response.data);
        })
        .catch(() => {
            // Try again on the next round
        })
        .finally(() => {
            // One round at a time, even after a refreshPeople()
            clearTimeout(nextPeopleLoad);
            if (isPollingPeople) nextPeopleLoad = setTimeout(loadPeople, PEOPLE_INTERVAL);
        });
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

// onPeopleOnline gets the ids of who's online whenever the list changes;
// onPersonChosen(id, name) runs when a row is clicked
function keepPeopleUpdated(onPeopleOnline, onPersonChosen) {
    peoplePanel.addEventListener('click', (event) => {
        const row = event.target.closest('.person--can-message');
        if (!row) return;

        const person = personByRow.get(row);
        if (narrowScreen.matches) {
            setPeopleOpen(false);
        }
        onPersonChosen(person.id, person.shown_name);
    });

    reportPeopleOnline = onPeopleOnline;
}
