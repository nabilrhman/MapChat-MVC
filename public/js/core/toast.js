// Small messages in the corner: white cards with the type's icon (success,
// info or warning) in a tinted square, a close button, and a line that runs
// down the time left. Pointing at one holds it open
const TOAST_ICONS = {success: 'check-circle', info: 'info-circle', warning: 'danger-circle'};

// The corner's list of toasts, added to the page with the first one
function addToastListIfMissing() {
    let toastList = document.getElementById('toasts');
    if (!toastList) {
        toastList = createElement('div', 'toasts');
        toastList.id = 'toasts';
        document.body.append(toastList);
    }
    return toastList;
}

function showToast(text, type = 'success') {
    const toast = createElement('div', `toast ${type}`);
    toast.setAttribute('role', 'status');
    const closeButton = createButton('close');
    closeButton.setAttribute('aria-label', 'Close');
    closeButton.append(createIcon('close'));
    // The line's own animation is the toast's timer, so the pause on hover
    // (ui.css) holds both
    const timeLeft = createElement('span', 'time-left');
    toast.append(
        createIcon(TOAST_ICONS[type] ?? TOAST_ICONS.info, 'type-icon'),
        createElement('span', 'text', text),
        closeButton,
        timeLeft,
    );
    addToastListIfMissing().append(toast);

    const closeToast = () => removeWithAnimation(toast, 'is-leaving');
    // A tap anywhere on it closes it too, as the × is small on a phone
    toast.addEventListener('click', closeToast, {once: true});
    timeLeft.addEventListener('animationend', (event) => {
        // Kept from the toast, whose own leaving would take it as its end
        event.stopPropagation();
        closeToast();
    }, {once: true});
}

// The page's one-time messages (_toast_messages.html), once the page is parsed
document.addEventListener('DOMContentLoaded', () => {
    const toastMessages = document.getElementById('toast-messages');
    if (!toastMessages) return;
    JSON.parse(toastMessages.dataset.messages).forEach(({body, type}) => showToast(body, type));
});
