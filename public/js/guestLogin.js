// The login page's guest button: one account at a time, with a spinner
// while it's made

// Making a guest account and finding its location takes a few seconds
const guestForm = document.getElementById('guestForm');
const guestButton = guestForm.querySelector('button');

guestForm.addEventListener('submit', (event) => {
    if (guestButton.classList.contains('is-loading')) {
        event.preventDefault();
        return;
    }
    guestButton.classList.add('is-loading');
    guestButton.setAttribute('aria-busy', 'true');
});

// Coming back with the Back button shows the page as it was left
window.addEventListener('pageshow', () => {
    guestButton.classList.remove('is-loading');
    guestButton.removeAttribute('aria-busy');
});
