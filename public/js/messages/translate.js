/* Translating what you write: the translate button beside the mic opens a
   From and To bar above the message box. While it's on, a message is put
   into the To language (Room::translateAction) just before it's sent, and
   what was typed goes with it as its original, for "See original" */

// The server gives Groq 20 seconds (Groq::TIMEOUT); this leaves room for the trip
const TRANSLATE_TIME_LIMIT = 30_000;

// Kept in this browser, so the choice lasts between visits
const TRANSLATE_ON_KEY = 'translate-on';
const TRANSLATE_FROM_KEY = 'translate-from';
const TRANSLATE_TO_KEY = 'translate-to';

// The button and the two pickers, once set up; null where translation is off
let translateParts = null;

// The text being sent and its translation, till it's sent. A send that
// failed is tried again with the same words, so the server knows it for the
// same message (forgetTranslation, once it's sent)
let lastTranslation = null;

/**
 * Lets `toggle` show and hide `bar` with its From and To pickers and the
 * swap button between them, and remembers all three. Does nothing where
 * the server can't translate
 */
function setUpTranslation(toggle, bar) {
    if (!toggle) return;
    const fromPicker = document.getElementById('translate-from');
    const toPicker = document.getElementById('translate-to');
    const swapButton = document.getElementById('translate-swap');
    translateParts = {toggle, bar, fromPicker, toPicker, swapButton};

    setUpLanguagePicker(fromPicker, TRANSLATE_FROM_KEY);
    setUpLanguagePicker(toPicker, TRANSLATE_TO_KEY);
    // Left on last time: there from the start, with nothing to slide open
    if (readFlag(TRANSLATE_ON_KEY)) setTranslating(true, {isAtOnce: true});
    updateSwapButton();

    swapButton.addEventListener('click', swapLanguages);

    // The emoji grid opening turns translating off (emoji-picker.js): one
    // or the other, so the button never looks on while its bar is away
    document.addEventListener('panel-open', (event) => {
        if (event.detail === bar || bar.contains(event.detail)) return;
        closeLanguageMenus();
        if (!isTranslating()) return;
        setTranslating(false);
        writeFlag(TRANSLATE_ON_KEY, false);
    });

    toggle.addEventListener('click', () => {
        const isOn = !isTranslating();
        setTranslating(isOn);
        writeFlag(TRANSLATE_ON_KEY, isOn);
    });
}

// Turns translating on or off: the button, and the bar sliding in or out
function setTranslating(isOn, {isAtOnce = false} = {}) {
    const {toggle, bar} = translateParts;
    toggle.setAttribute('aria-pressed', String(isOn));
    toggle.classList.toggle('is-open', isOn);

    if (isOn) {
        // Turned on, it closes the emoji grid (emoji-picker.js)
        document.dispatchEvent(new CustomEvent('panel-open', {detail: bar}));
    } else {
        closeLanguageMenus();
    }
    placeBar(isOn, {isAtOnce});
}

// Ends the bar's slide at once, laying the page out as it ends; null
// while none is running
let finishBarSlide = null;

// Gives the bar its room above the box, or takes it back. It slides as a
// layer over the composer's top edge (.is-sliding), clipped where the
// composer starts, so the edge rises and falls with it, and the messages
// move with the edge. The page is laid out for real only once
function placeBar(shouldShow, {isAtOnce = false} = {}) {
    finishBarSlide?.();
    const {bar} = translateParts;
    if (bar.classList.contains('is-shown') === shouldShow) return;
    const list = document.getElementById('chat-list');
    const composer = document.getElementById('input-container-form');

    // Read before changing anything: a read after a change makes the
    // browser work out the page's styles again (about 20ms here)
    const oldScrollTop = list.scrollTop;
    const isAtNewest = list.scrollHeight - oldScrollTop - list.clientHeight < 2;
    const composerHeight = composer.offsetHeight;

    if (isAtOnce || prefersLessMotion.matches) {
        bar.classList.toggle('is-shown', shouldShow);
        if (isAtNewest) list.scrollTop = list.scrollHeight;
        return;
    }

    const timing = {...motionTiming(), fill: 'forwards'};
    if (shouldShow) {
        // Laid over the composer first: how far its edge will rise is the
        // layer's height less the composer's own edge, which it covers
        bar.classList.add('is-shown', 'is-sliding');
        const composerStyle = getComputedStyle(composer);
        const edgeHeight = parseFloat(composerStyle.borderBlockStartWidth) + parseFloat(composerStyle.paddingBlockStart);
        const rise = bar.offsetHeight - edgeHeight;

        const slide = bar.animate([edgeAt(rise), edgeAt(0)], timing);
        // The newest message stays in view, moving up with the edge
        const messageSlides = isAtNewest ? moveMessagesBy(list, -rise, timing) : [];
        finishBarSlide = () => {
            finishBarSlide = null;
            bar.classList.remove('is-sliding');
            if (isAtNewest) list.scrollTop = list.scrollHeight;
            slide.cancel();
            for (const messageSlide of messageSlides) messageSlide.cancel();
        };
        slide.addEventListener('finish', () => finishBarSlide?.(), {once: true});
        return;
    }

    // Going: its room is given back at once, and the layer falls behind
    // the composer's edge while the messages move down into the room
    bar.classList.add('is-sliding');
    const fall = composerHeight - composer.offsetHeight;
    if (isAtNewest) list.scrollTop = list.scrollHeight;
    const movedUpBy = list.scrollTop - oldScrollTop;
    if (movedUpBy !== 0) slideMessagesFrom(list, movedUpBy);

    const slide = bar.animate([edgeAt(0), edgeAt(fall)], timing);
    finishBarSlide = () => {
        finishBarSlide = null;
        bar.classList.remove('is-sliding', 'is-shown');
        slide.cancel();
    };
    slide.addEventListener('finish', () => finishBarSlide?.(), {once: true});
}

// The sliding bar moved down by `distance` and cut off that far from its
// bottom, so what shows ends where the composer starts. Its shadow, above
// it, stays in
const edgeAt = (distance) => ({
    transform: `translateY(${distance}px)`,
    clipPath: `inset(-8px 0 ${distance}px 0)`,
});

// The messages in view moved by `distance` (up, when it's less than 0) and
// held there, until the page is laid out to match
function moveMessagesBy(list, distance, timing) {
    return messagesInView(list, distance)
        .map((message) => message.animate([{transform: 'none'}, {transform: `translateY(${distance}px)`}], timing));
}

// The messages that show in the list, or will once moved by `distance`
function messagesInView(list, distance) {
    const listBox = list.getBoundingClientRect();
    return [...list.children].filter((message) => {
        const box = message.getBoundingClientRect();
        return box.bottom > listBox.top - Math.abs(distance) && box.top < listBox.bottom + Math.abs(distance);
    });
}

// The messages in view start drawn where they were before the list moved
// them up by `distance` (down, when it's less than 0), and ease into place.
// Only transform moves, so no frame lays out the page
function slideMessagesFrom(list, distance) {
    if (prefersLessMotion.matches) return;
    const timing = motionTiming();
    for (const message of messagesInView(list, distance)) {
        message.animate([{transform: `translateY(${distance}px)`}, {transform: 'none'}], timing);
    }
}

/* A picker is a button that names its language and, over the messages, a
   search above a list of languages. The search's keys move through the list
   and choose, as a select's do. Its choice is the button's data-value, kept
   in this browser under storageKey */

// The list a From or To button opens
const menuOf = (button) => button.closest('[data-part="language-picker"]').querySelector('[data-part="language-menu"]');

// The languages in a list, Auto first where it has it
const optionsIn = (menu) => [...menu.querySelectorAll('[data-part="language-option"]')];

function setUpLanguagePicker(button, storageKey) {
    const menu = menuOf(button);
    const search = menu.querySelector('[data-part="language-search"]');
    const options = optionsIn(menu);

    const storedOption = options.find((option) => option.dataset.code === readStored(storageKey));
    if (storedOption) chooseLanguage(button, storedOption);

    const choose = (option) => {
        chooseLanguage(button, option);
        writeStored(storageKey, option.dataset.code);
        closeLanguageMenu(button, menu, {shouldFocusButton: true});
    };

    button.addEventListener('click', () => {
        if (button.getAttribute('aria-expanded') === 'false') {
            openLanguageMenu(button, menu);
        } else {
            closeLanguageMenu(button, menu);
        }
    });

    // Up or down on the button opens it, as on a select
    button.addEventListener('keydown', (event) => {
        if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') return;
        event.preventDefault();
        if (button.getAttribute('aria-expanded') === 'false') openLanguageMenu(button, menu);
    });

    menu.addEventListener('click', (event) => {
        const option = event.target.closest('[data-part="language-option"]');
        if (option) choose(option);
    });

    search.addEventListener('input', () => filterLanguages(menu, search.value));
    menu.querySelector('[data-part="language-search-clear"]').addEventListener('click', () => {
        search.value = '';
        filterLanguages(menu, '');
        search.focus();
    });
    search.addEventListener('keydown', (event) => onLanguageSearchKey(event, button, menu, choose));

    // Clicking or tabbing away closes it, as a select's list does
    document.addEventListener('click', (event) => {
        if (!menu.contains(event.target) && !button.contains(event.target)) closeLanguageMenu(button, menu);
    });
    menu.addEventListener('focusout', (event) => {
        if (event.relatedTarget && !menu.contains(event.relatedTarget) && event.relatedTarget !== button) {
            closeLanguageMenu(button, menu);
        }
    });
}

function chooseLanguage(button, chosenOption) {
    for (const option of optionsIn(menuOf(button))) {
        option.setAttribute('aria-selected', String(option === chosenOption));
    }
    button.dataset.value = chosenOption.dataset.code;
    button.querySelector('[data-part="language-name"]').textContent = chosenOption.textContent;
    updateSwapButton();
}

// Chooses the language with this code in a picker, and keeps it
function chooseLanguageByCode(button, code, storageKey) {
    const option = optionsIn(menuOf(button)).find((option) => option.dataset.code === code);
    chooseLanguage(button, option);
    writeStored(storageKey, code);
}

function swapLanguages() {
    const {fromPicker, toPicker} = translateParts;
    const fromCode = fromPicker.dataset.value;
    const toCode = toPicker.dataset.value;
    chooseLanguageByCode(fromPicker, toCode, TRANSLATE_FROM_KEY);
    chooseLanguageByCode(toPicker, fromCode, TRANSLATE_TO_KEY);
}

// Auto can't be a language to translate into, so there's nothing to swap
function updateSwapButton() {
    if (!translateParts?.swapButton) return;
    translateParts.swapButton.disabled = translateParts.fromPicker.dataset.value === '';
}

function openLanguageMenu(button, menu) {
    closeLanguageMenus();
    // One floating panel at a time: the emoji grid closes (emoji-picker.js)
    document.dispatchEvent(new CustomEvent('panel-open', {detail: menu}));
    menu.classList.remove('is-closing');
    const search = menu.querySelector('[data-part="language-search"]');
    search.value = '';
    filterLanguages(menu, '');
    menu.hidden = false;
    button.setAttribute('aria-expanded', 'true');
    button.classList.add('is-open');

    const list = menu.querySelector('[data-part="language-list"]');
    const chosenOption = optionsIn(menu).find((option) => option.getAttribute('aria-selected') === 'true');
    showActiveOption(menu, chosenOption);
    // The chosen language in the middle of the list, as a select opens on it
    list.scrollTop = chosenOption.offsetTop - (list.clientHeight - chosenOption.offsetHeight) / 2;
    // Not on a touch screen, where the keyboard would cover the list
    if (!matchMedia('(hover: none)').matches) search.focus({preventScroll: true});
}

function closeLanguageMenu(button, menu, {shouldFocusButton = false} = {}) {
    if (menu.hidden || menu.classList.contains('is-closing')) return;
    button.setAttribute('aria-expanded', 'false');
    button.classList.remove('is-open');
    if (shouldFocusButton) button.focus();

    // Fades out first, then hides; unless it was opened again meanwhile
    menu.classList.add('is-closing');
    onAnimationEnd(menu, () => {
        if (!menu.classList.contains('is-closing')) return;
        menu.classList.remove('is-closing');
        menu.hidden = true;
    });
}

function closeLanguageMenus() {
    if (!translateParts) return;
    for (const button of [translateParts.fromPicker, translateParts.toPicker]) {
        closeLanguageMenu(button, menuOf(button));
    }
}

// Shows only the languages whose name has the search in it, and keys onto the first
function filterLanguages(menu, searchText) {
    const wanted = searchText.trim().toLowerCase();
    const options = optionsIn(menu);
    for (const option of options) {
        option.hidden = !option.textContent.toLowerCase().includes(wanted);
    }
    const shownOptions = options.filter((option) => !option.hidden);
    menu.querySelector('[data-part="no-language"]').hidden = shownOptions.length > 0;
    if (wanted !== '') showActiveOption(menu, shownOptions[0] ?? null);
}

// The option the keys are on, which the screen reader reads out
function showActiveOption(menu, activeOption) {
    const search = menu.querySelector('[data-part="language-search"]');
    for (const option of optionsIn(menu)) {
        option.classList.toggle('is-active', option === activeOption);
    }
    if (!activeOption) {
        search.removeAttribute('aria-activedescendant');
        return;
    }
    search.setAttribute('aria-activedescendant', activeOption.id);
    scrollListTo(menu.querySelector('[data-part="language-list"]'), activeOption);
}

// Scrolls the list just enough to show the option. Not scrollIntoView,
// which would scroll the chat sheet and the page around it too
function scrollListTo(list, option) {
    const optionBottom = option.offsetTop + option.offsetHeight;
    if (option.offsetTop < list.scrollTop) {
        list.scrollTop = option.offsetTop;
    } else if (optionBottom > list.scrollTop + list.clientHeight) {
        list.scrollTop = optionBottom - list.clientHeight;
    }
}

function onLanguageSearchKey(event, button, menu, choose) {
    const shownOptions = optionsIn(menu).filter((option) => !option.hidden);
    const activeIndex = shownOptions.findIndex((option) => option.classList.contains('is-active'));
    const lastIndex = shownOptions.length - 1;
    // Up and down move one at a time, as in a select
    const nextIndexByKey = {
        ArrowDown: Math.min(activeIndex + 1, lastIndex),
        ArrowUp: Math.max(activeIndex - 1, 0),
    };

    if (event.key in nextIndexByKey) {
        event.preventDefault();
        showActiveOption(menu, shownOptions[nextIndexByKey[event.key]] ?? null);
    } else if (event.key === 'Enter') {
        event.preventDefault();
        if (activeIndex >= 0) choose(shownOptions[activeIndex]);
    } else if (event.key === 'Escape') {
        event.preventDefault();
        closeLanguageMenu(button, menu, {shouldFocusButton: true});
    }
}

function isTranslating() {
    return translateParts?.toggle.getAttribute('aria-pressed') === 'true';
}

/**
 * What to send for `text`: its translation with `text` as the original
 * while translating is on, or `text` as it is. Rejects, with the server's
 * reason, when it couldn't be translated
 */
async function messageToSend(text) {
    if (!isTranslating()) return {body: text, original: null};

    const {toggle, fromPicker, toPicker} = translateParts;
    const request = {text, from: fromPicker.dataset.value, to: toPicker.dataset.value};
    const isSameAsLast = lastTranslation
        && lastTranslation.text === request.text
        && lastTranslation.from === request.from
        && lastTranslation.to === request.to;
    if (isSameAsLast) return {body: lastTranslation.translation, original: text};

    toggle.classList.add('is-translating');
    try {
        const response = await axios.post('/app/translate', new URLSearchParams(request), {timeout: TRANSLATE_TIME_LIMIT});
        lastTranslation = {...request, translation: response.data.text};
        return {body: response.data.text, original: text};
    } finally {
        toggle.classList.remove('is-translating');
    }
}

// The message went out: the next one is translated afresh
function forgetTranslation() {
    lastTranslation = null;
}
