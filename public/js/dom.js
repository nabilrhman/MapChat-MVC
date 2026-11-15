// Small helpers for building and animating the page, shared by every script

function createElement(tagName, className = '', text = '') {
    const element = document.createElement(tagName);
    element.className = className;
    element.textContent = text;
    return element;
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
