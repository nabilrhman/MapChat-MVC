// Phones: the chat is a sheet over the map, as in a maps app. Dragging its
// handle or header sets its height, between limits: above them it goes all
// the way up, below them it folds down to its tabs. Tapping the handle then
// switches between all the way up and the height you chose. Loaded after
// chat.js, whose panel it moves, and before map.js, which fits the map to
// the room the sheet leaves

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

// What follows the sheet: chat-map.css places the map's loading dots and
// credits from --sheet-top
const sheetFollowers = ['map', 'map-loading'].map((id) => document.getElementById(id));

// Moves the sheet `top` px below the header. The sheet gets its own top
// rather than --sheet-top: a variable set on it, or on the page, reaches
// every message, and restyling them all on each frame of a drag stutters
// on a phone
function setSheetTop(top) {
    chatPanel.style.top = `calc(var(--header-height) + ${top}px)`;
    for (const follower of sheetFollowers) {
        follower.style.setProperty('--sheet-top', `${top}px`);
    }
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

// Wider screens have no sheet, so nothing there is folded away, and the
// panel goes back to its place in chat-panel.css
phoneScreen.addEventListener('change', () => {
    if (phoneScreen.matches) {
        moveSheetTo(sheetStop);
    } else {
        setChatFolded(false);
        chatPanel.style.removeProperty('top');
    }
});
