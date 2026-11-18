// Small messages in the corner: Material 3 snackbars, with an icon for the
// type (success, info or warning). A tap closes one early
const TOAST_ICONS = {success: 'check-circle', info: 'info-circle', warning: 'danger-circle'};
const TOAST_DURATION = 4500;

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
    toast.append(
        createIcon(TOAST_ICONS[type] ?? TOAST_ICONS.info),
        createElement('span', '', text),
    );
    addToastListIfMissing().append(toast);

    const closeToast = () => removeWithAnimation(toast, 'is-leaving');
    toast.addEventListener('click', closeToast, {once: true});
    setTimeout(closeToast, TOAST_DURATION);
}

// The page's one-time messages (_flash.html), once the page is parsed
document.addEventListener('DOMContentLoaded', () => {
    const flashMessages = document.getElementById('flash-messages');
    if (!flashMessages) return;
    JSON.parse(flashMessages.dataset.messages).forEach(({body, type}) => showToast(body, type));
});
