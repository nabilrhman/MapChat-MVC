// Dragging the chat's private tabs into any order after # Everyone, which
// stays first. chat.js keeps the order (saveTabOrder) and places new tabs
// by it; loaded after chat.js, whose tab row this works on

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
    const rowBounds = chatTabs.getBoundingClientRect();
    if (tabDrag.clientX < rowBounds.left + DRAG_SCROLL_EDGE) {
        chatTabs.scrollLeft -= DRAG_SCROLL_SPEED;
    } else if (tabDrag.clientX > rowBounds.right - DRAG_SCROLL_EDGE) {
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
