// Small helpers for building and animating the page, shared by every script

function createElement(tagName, className = '', text = '') {
    const element = document.createElement(tagName);
    element.className = className;
    element.textContent = text;
    return element;
}

// A country's name from its code. Older browsers show the code
function countryName(country) {
    if (!country) return '';
    return window.Intl?.DisplayNames ? new Intl.DisplayNames(['en'], {type: 'region'}).of(country) : country;
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
    place.append(createElement('span', 'place__name', placeName(city, country)));
    return place;
}

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
        element.replaceChildren(createElement('span', 'count-roll__number', String(target)));
        return;
    }
    let current = Number(element.dataset.count);

    const step = () => {
        const remaining = target - current;
        const shouldSnap = Math.abs(remaining) < COUNT_SNAP || matchMedia('(prefers-reduced-motion: reduce)').matches;
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
    element.classList.toggle('count-roll--up', isUp);
    element.classList.toggle('count-roll--down', !isUp);
    // Far off, numbers pass every frame or two, so each rolls quicker
    element.style.setProperty('--count-dur', distanceLeft > 3 ? '60ms' : '');

    element.querySelectorAll('.count-roll__number--leaving').forEach((old) => old.remove());
    const old = element.lastElementChild;
    if (old) {
        old.classList.add('count-roll__number--leaving');
        old.addEventListener('animationend', () => old.remove(), {once: true});
    }
    element.append(createElement('span', 'count-roll__number', String(number)));
}

// An icon from the sprite, as Core\Icon draws one in Twig
function createIcon(name, className = '') {
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('class', `icon icon--${name} ${className}`.trim());
    svg.setAttribute('aria-hidden', 'true');
    const use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
    use.setAttribute('href', `${document.documentElement.dataset.icons}#${name}`);
    svg.append(use);
    return svg;
}

// Shows another icon from the sprite in an icon's place
function setIcon(svg, name) {
    svg.classList.remove(...[...svg.classList].filter((c) => c.startsWith('icon--')));
    svg.classList.add(`icon--${name}`);
    const use = svg.querySelector('use');
    use.setAttribute('href', use.getAttribute('href').replace(/#.*$/, `#${name}`));
}

// Elements from a chunk of HTML the server sent
function parseHtml(html) {
    const template = document.createElement('template');
    template.innerHTML = html;
    return Array.from(template.content.children);
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
    images.forEach((image) => {
        if (image.complete || image.src.startsWith('data:')) return;

        const frame = image.parentElement;
        frame.classList.add('is-loading');
        const onPhotoSettled = () => frame.classList.remove('is-loading');
        image.addEventListener('load', onPhotoSettled, {once: true});
        image.addEventListener('error', onPhotoSettled, {once: true});
    });
}
