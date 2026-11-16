// Small messages in the corner: Material 3 snackbars, with an icon for the
// type (success, info or warning). A tap closes one early
const TOAST_ICONS = {success: 'check-circle', info: 'info-circle', warning: 'danger-circle'};
const TOAST_DURATION = 4500;

function getToastList() {
    let list = document.getElementById('toasts');
    if (!list) {
        list = createElement('div', 'toasts');
        list.id = 'toasts';
        document.body.append(list);
    }
    return list;
}

function showToast(text, type = 'success') {
    const toast = createElement('div', `toast ${type}`);
    toast.setAttribute('role', 'status');
    toast.append(
        createIcon(TOAST_ICONS[type] ?? TOAST_ICONS.info),
        createElement('span', '', text),
    );
    getToastList().append(toast);

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
