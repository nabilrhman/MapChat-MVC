// Small helpers for building and animating the page, shared by every script

function createElement(tagName, className = '', text = '') {
    const element = document.createElement(tagName);
    element.className = className;
    element.textContent = text;
    return element;
}

// A button that acts on the page, not one that submits a form
function createButton(className = '', text = '') {
    const button = createElement('button', className, text);
    button.type = 'button';
    return button;
}

// The MapChat bot's made-up country, at 0°, 0° (a code free for private use,
// so Intl has no name for it)
const NULL_ISLAND = 'XN';
// Older browsers lack Intl.DisplayNames; they show the code instead
const regionNames = window.Intl?.DisplayNames ? new Intl.DisplayNames(['en'], {type: 'region'}) : null;

// A country's name from its code
function countryName(country) {
    if (!country) return '';
    if (country === NULL_ISLAND) return 'Republic of Null Island';
    return regionNames?.of(country) ?? country;
}

// Where someone is, as shown: their city, or their country's name when the
// IP lookup found only the country
function placeName(city, country) {
    return city || countryName(country);
}

// Flag and place (.place in chat.css): the city, or the country without one
function createPlace(city, country) {
    const place = createElement('span', 'place');
    if (country) place.append(createElement('span', `fi fi-${country.toLowerCase()}`));
    place.append(createElement('span', 'name', placeName(city, country)));
    return place;
}

// Phones, where the chat is a sheet over the map (matches chat.css)
const phoneScreen = window.matchMedia('(max-width: 700px)');

// Asked for less motion in the system's settings; it can change while the
// page is open, so read .matches when it's needed
const prefersLessMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

// Counts a number shown in an element up or down to the target instead of
// snapping, as Rove's useCountUp does: each frame covers a fraction of the
// distance left, so it slows as it lands. Each number it passes rolls in
// (.count-roll in animations.css), quicker while it's still far off.
// Snaps under reduced motion
const COUNT_EASE = 0.18;
const COUNT_SNAP = 0.05;
const countFrames = new WeakMap();

function countTo(element, target) {
    cancelAnimationFrame(countFrames.get(element));
    // The first number shows at once, like the hook's starting value
    if (element.dataset.count === undefined) {
        element.dataset.count = target;
        element.classList.add('count-roll');
        element.replaceChildren(createCountNumber(target));
        return;
    }
    let current = Number(element.dataset.count);

    const step = () => {
        const remaining = target - current;
        const shouldSnap = Math.abs(remaining) < COUNT_SNAP || prefersLessMotion.matches;
        const shown = Math.round(current);
        current = shouldSnap ? target : current + remaining * COUNT_EASE;
        element.dataset.count = current;
        if (Math.round(current) !== shown) rollTo(element, Math.round(current), Math.abs(remaining));
        if (!shouldSnap) countFrames.set(element, requestAnimationFrame(step));
    };
    countFrames.set(element, requestAnimationFrame(step));
}

// Slides the old number out and the new one in, up or down with the count
function rollTo(element, number, distanceLeft) {
    const isUp = number > Number(element.lastElementChild?.textContent);
    element.classList.toggle('is-rolling-up', isUp);
    element.classList.toggle('is-rolling-down', !isUp);
    // Far off, numbers pass every frame or two, so each rolls quicker
    element.classList.toggle('is-far-off', distanceLeft > 3);

    element.querySelectorAll('[data-part="count-number"].is-leaving').forEach((leavingNumber) => leavingNumber.remove());
    const oldNumber = element.lastElementChild;
    if (oldNumber) {
        oldNumber.classList.add('is-leaving');
        onAnimationEnd(oldNumber, () => oldNumber.remove());
    }
    element.append(createCountNumber(number));
}

function createCountNumber(number) {
    const numberElement = createElement('span', 'number', String(number));
    numberElement.dataset.part = 'count-number';
    return numberElement;
}

// An icon from the sprite, as Core\Icon draws one in Twig
function createIcon(name, className = '') {
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('class', `icon ${className}`.trim());
    svg.dataset.icon = name;
    svg.setAttribute('aria-hidden', 'true');
    const use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
    use.setAttribute('href', `${document.documentElement.dataset.icons}#${name}`);
    svg.append(use);
    return svg;
}

// Shows another icon from the sprite in an icon's place
function setIcon(svg, name) {
    svg.dataset.icon = name;
    const use = svg.querySelector('use');
    use.setAttribute('href', use.getAttribute('href').replace(/#.*$/, `#${name}`));
}

// Elements from a chunk of HTML the server sent
function parseHtml(html) {
    const template = document.createElement('template');
    template.innerHTML = html;
    return [...template.content.children];
}

// False for an element hidden by CSS or inside something hidden
function isShown(element) {
    return element.getClientRects().length > 0;
}

function onAnimationEnd(element, callback) {
    element.addEventListener('animationend', callback, {once: true});
}

function onTransitionEnd(element, callback) {
    element.addEventListener('transitionend', callback, {once: true});
}

// Plays a CSS animation again from the start, even if it already ran
function replayAnimation(element, className) {
    element.classList.remove(className);
    void element.offsetWidth; // makes the browser see the removal first
    element.classList.add(className);
}

// Elements that just moved in the layout start where they were and slide to
// their new place, instead of jumping. shifts maps each element to how far
// back it starts, {x, y} in px; .is-shifting in the CSS times the slide.
// translate, not transform, so an element's own transform (the tabs' flip)
// stays
function slideFromShifts(shifts) {
    shifts.forEach(({x = 0, y = 0}, element) => {
        // One still sliding from the last change starts again from where it is
        element.classList.remove('is-shifting');
        element.style.translate = `${x}px ${y}px`;
    });
    void document.body.offsetWidth; // makes the browser draw them there first

    shifts.forEach((shift, element) => {
        element.classList.add('is-shifting');
        element.style.translate = '';
        element.addEventListener('transitionend', function onSlid(event) {
            // Not a colour fading on it, or on something inside it
            if (event.target !== element || event.propertyName !== 'translate') return;
            element.removeEventListener('transitionend', onSlid);
            element.classList.remove('is-shifting');
        });
    });
}

// Plays a leaving animation, then removes the element. One that isn't
// shown (e.g. in the closed people drawer) never animates, so it goes at once
function removeWithAnimation(element, className) {
    if (!isShown(element)) {
        element.remove();
        return;
    }
    element.classList.add(className);
    onAnimationEnd(element, () => element.remove());
}

// Uploaded photos fade in over a soft circle once loaded; letter avatars
// are inline images and show at once
function fadeInPhotos(images) {
    for (const image of images) {
        if (image.complete || image.src.startsWith('data:')) continue;

        const frame = image.parentElement;
        frame.classList.add('is-loading');
        const onPhotoSettled = () => frame.classList.remove('is-loading');
        image.addEventListener('load', onPhotoSettled, {once: true});
        image.addEventListener('error', onPhotoSettled, {once: true});
    }
}

/* This browser's storage. Blocked in some private windows, where a choice
   then lasts only as long as the page */

// storageName is 'localStorage', or 'sessionStorage' for this tab only
function readStored(key, storageName = 'localStorage') {
    try {
        return window[storageName].getItem(key);
    } catch {
        return null;
    }
}

// null takes the key away
function writeStored(key, value, storageName = 'localStorage') {
    try {
        if (value === null) {
            window[storageName].removeItem(key);
        } else {
            window[storageName].setItem(key, value);
        }
    } catch {
        // Blocked: kept only for this page
    }
}

const readFlag = (key) => readStored(key) === '1';
const writeFlag = (key, isOn) => writeStored(key, isOn ? '1' : null);

// A value kept as JSON, or the fallback when there's none or it's unreadable
function readStoredJson(key, fallback) {
    try {
        return JSON.parse(readStored(key)) ?? fallback;
    } catch {
        return fallback;
    }
}

const writeStoredJson = (key, value) => writeStored(key, JSON.stringify(value));

// Puts text in a field where the cursor is, then tells the field's listeners.
// setRangeText skips maxlength, so text past it is left out
function putTextAtCursor(input, text) {
    if (input.value.length + text.length > input.maxLength) return;
    input.setRangeText(text, input.selectionStart, input.selectionEnd, 'end');
    input.dispatchEvent(new Event('input'));
}
