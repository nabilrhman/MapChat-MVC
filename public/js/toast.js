// Small messages in the corner: Material 3 snackbars, with an icon for the
// type (success, info or warning). A tap closes one early
const TOAST_ICONS = {success: 'check-circle', info: 'info-circle', warning: 'danger-circle'};
const TOAST_DURATION = 4500;

function getToastList() {
    let list = document.querySelector('.toasts');
    if (!list) {
        list = createElement('div', 'toasts');
        document.body.append(list);
    }
    return list;
}

function showToast(text, type = 'success') {
    const toast = createElement('div', `toast toast--${type}`);
    toast.setAttribute('role', 'status');
    toast.append(
        createIcon(TOAST_ICONS[type] ?? TOAST_ICONS.info, 'toast__icon'),
        createElement('span', '', text),
    );
    getToastList().append(toast);

    const closeToast = () => removeWithAnimation(toast, 'is-leaving');
    toast.addEventListener('click', closeToast, {once: true});
    setTimeout(closeToast, TOAST_DURATION);
}
