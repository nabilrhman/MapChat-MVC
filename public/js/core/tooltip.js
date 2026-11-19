// Tooltips in the app's style instead of the browser's grey box. Anything
// with a title gets one: on hover the title moves to data-tip (so the
// browser's own doesn't show too) and a small dark label appears above it,
// or below when there's no room. Touch screens keep no tooltips, as before

// Just long enough not to flicker when the mouse passes over
const TOOLTIP_DELAY = 150;
// Between the label and the screen's edge, and the label and its thing
const TOOLTIP_EDGE_GAP = 8;
const TOOLTIP_GAP = 6;
const TOOLTIP_TARGETS_SELECTOR = '[title], [data-tip]';

// Moves the element's title to data-tip and gives back the tip's text
function moveTitleToTip(element) {
    const title = element.getAttribute('title');
    if (title) {
        element.dataset.tip = title;
        element.removeAttribute('title');
        // Keep a name for screen readers on things with no text of their own
        if (!element.getAttribute('aria-label') && !element.textContent.trim()) {
            element.setAttribute('aria-label', title);
        }
    } else if (title === '') {
        delete element.dataset.tip; // the title was cleared, e.g. your own row
    }
    return element.dataset.tip || '';
}

// Where the thing's text actually is: a name spans its whole row, but
// the tooltip should sit over the name itself. Only for plain text: with an
// icon or a photo inside, the tooltip sits over the whole thing
function textBoxOf(element) {
    const isPlainText = element.childElementCount === 0 && element.textContent.trim() !== '';
    if (isPlainText) {
        const range = document.createRange();
        range.selectNodeContents(element);
        const rect = range.getBoundingClientRect();
        if (rect.width) return rect;
    }
    return element.getBoundingClientRect();
}

function setUpTooltips() {
    if (!window.matchMedia('(hover: hover)').matches) return;

    const tooltip = createElement('div', 'tooltip');
    // It repeats the label moveTitleToTip gave the element, which screen readers read there
    tooltip.setAttribute('aria-hidden', 'true');
    document.body.append(tooltip);

    let tipTarget = null;
    let showTimer = null;
    let isTooltipShown = false;
    const mousePosition = {x: 0, y: 0};

    document.addEventListener('mousemove', (event) => {
        mousePosition.x = event.clientX;
        mousePosition.y = event.clientY;
    });

    function showTooltip() {
        // Redrawn while we waited: use what's under the mouse now
        if (tipTarget && !tipTarget.isConnected) {
            tipTarget = document.elementFromPoint(mousePosition.x, mousePosition.y)?.closest(TOOLTIP_TARGETS_SELECTOR) ?? null;
        }

        const text = tipTarget && moveTitleToTip(tipTarget);
        // None for a button that can't be pressed now, as Swap while From is Auto
        if (!text || tipTarget.disabled) return;

        tooltip.textContent = text;
        tooltip.classList.remove('is-below', 'is-on-left');
        const box = textBoxOf(tipTarget);
        const width = tooltip.offsetWidth;
        const height = tooltip.offsetHeight;

        // To the left, for rows in a list at the screen's right edge, where
        // above would cover the row before
        const leftOfBox = box.left - width - TOOLTIP_EDGE_GAP;
        if (tipTarget.dataset.tipSide === 'left' && leftOfBox > TOOLTIP_EDGE_GAP) {
            tooltip.classList.add('is-on-left');
            tooltip.style.left = `${leftOfBox}px`;
            tooltip.style.top = `${box.top + box.height / 2 - height / 2}px`;
            setTooltipShown(true);
            return;
        }

        const centredLeft = box.left + box.width / 2 - width / 2;
        const left = Math.min(Math.max(TOOLTIP_EDGE_GAP, centredLeft), window.innerWidth - width - TOOLTIP_EDGE_GAP);
        let top = box.top - height - TOOLTIP_GAP;
        if (top < TOOLTIP_EDGE_GAP) {
            top = box.bottom + TOOLTIP_GAP;
            tooltip.classList.add('is-below');
        }

        // The arrow points at the middle of the thing, even when the label is
        // pushed in from the screen's edge
        tooltip.style.setProperty('--arrow-x', `${box.left + box.width / 2 - left}px`);
        tooltip.style.left = `${left}px`;
        tooltip.style.top = `${top}px`;
        setTooltipShown(true);
    }

    function setTooltipShown(isShown) {
        isTooltipShown = isShown;
        tooltip.classList.toggle('is-shown', isShown);
    }

    function hideTooltip() {
        clearTimeout(showTimer);
        tipTarget = null;
        setTooltipShown(false);
    }

    document.addEventListener('mouseover', (event) => {
        const element = event.target.closest(TOOLTIP_TARGETS_SELECTOR);
        if (element === tipTarget) return;

        // A list that redraws itself (the people list) swaps the row under
        // the mouse for an identical one; keep the tooltip on the new row
        if (element && isTooltipShown && moveTitleToTip(element) === tooltip.textContent) {
            tipTarget = element;
            return;
        }

        hideTooltip();
        if (!element) return;

        tipTarget = element;
        moveTitleToTip(element); // now, before the browser shows its own
        showTimer = setTimeout(showTooltip, TOOLTIP_DELAY);
    });

    document.addEventListener('mousedown', hideTooltip);
    // Typing means the mouse is resting, not pointing; something opening
    // under it (the @ list) mustn't have a tooltip showing through
    document.addEventListener('keydown', hideTooltip);
    // Only a scroll that moves the thing hides its tooltip: a new message
    // scrolling the chat leaves the emoji button where it was
    document.addEventListener('scroll', (event) => {
        if (tipTarget && event.target.contains(tipTarget)) hideTooltip();
    }, true);
    window.addEventListener('blur', hideTooltip);
}

setUpTooltips();
