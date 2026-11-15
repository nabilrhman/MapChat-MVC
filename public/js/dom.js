// Small helpers for building and animating the page, shared by every script

function createElement(tagName, className = '', text = '') {
    const element = document.createElement(tagName);
    element.className = className;
    element.textContent = text;
    return element;
}

// An icon from the sprite, as Core\Icon draws one in Twig
function createIcon(name, className = '') {
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('class', `icon ${className}`.trim());
    svg.setAttribute('aria-hidden', 'true');
    const use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
    use.setAttribute('href', `${document.documentElement.dataset.icons}#${name}`);
    svg.append(use);
    return svg;
}

// Shows another icon from the sprite in an icon's place
function setIcon(svg, name) {
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
