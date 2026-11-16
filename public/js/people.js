// The people list beside the chat: who's online and who was here lately.
// chat.js starts it with keepPeopleUpdated

// The list changes slowly, so every 15 seconds is enough
const PEOPLE_INTERVAL = 15000;
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

function showPeople(people, onPeopleOnline) {
    const onlinePeople = people.filter(isOnline);
    const recentPeople = people.filter((person) => !isOnline(person));

    onlinePeople.sort((a, b) => getListRank(a) - getListRank(b) || a.shown_name.localeCompare(b.shown_name));
    peopleToMention = [...onlinePeople, ...recentPeople].filter((person) => Number(person.id) !== myUserId);

    // The rows are replaced, so a hovered row never gets its mouseleave
    document.querySelectorAll('.map-pin.is-highlighted').forEach((pin) => pin.classList.remove('is-highlighted'));

    onPeopleOnline(new Set(onlinePeople.map((person) => Number(person.id))));

    document.getElementById('online-count').textContent = onlinePeople.length;
    document.getElementById('people-toggle-count').textContent = onlinePeople.length;
    fillPeople(document.getElementById('people-online'), onlinePeople);
    document.getElementById('recent-heading').hidden = recentPeople.length === 0;
    fillPeople(document.getElementById('people-recent'), recentPeople);
    fadeInPhotos(peoplePanel.querySelectorAll('img'));
}

function loadPeople(onPeopleOnline) {
    postForm('/app/get-people', {timezone: Intl.DateTimeFormat().resolvedOptions().timeZone})
        .then((response) => showPeople(response.data, onPeopleOnline))
        .catch(() => {
            // Try again on the next round
        })
        .finally(() => setTimeout(() => loadPeople(onPeopleOnline), PEOPLE_INTERVAL));
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

// Fetches the list now and every round after. onPeopleOnline gets the ids
// of who's online each round; onPersonChosen(id, name) runs when a row is
// clicked
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

    loadPeople(onPeopleOnline);
}
