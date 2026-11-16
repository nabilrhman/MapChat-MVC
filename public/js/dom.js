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

// Flag and place (.place in chat.css). With slidesToCountry and a city,
// hovering it slides to the country's name, like the place under a message
function createPlace(city, country, slidesToCountry = false) {
    const flag = () => (country ? [createElement('span', `fi fi-${country.toLowerCase()}`)] : []);
    const nameOfCountry = countryName(country);
    const place = createElement('span', 'place');
    const cityLine = createElement('span', 'swap__line');
    cityLine.append(...flag(), createElement('span', 'place__name', placeName(city, country)));

    // Plain without a city, or for a code the browser can't name, like MapChat's Null Island
    if (!slidesToCountry || !city || nameOfCountry === country) {
        place.append(...cityLine.childNodes);
        return place;
    }
    // Two lines in a one-line window (.swap in chat.css)
    const countryLine = createElement('span', 'swap__line');
    countryLine.append(...flag(), createElement('span', 'place__name', nameOfCountry));
    const track = createElement('span', 'swap__track');
    track.append(cityLine, countryLine);
    place.classList.add('swap');
    place.append(track);
    return place;
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
