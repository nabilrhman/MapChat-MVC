// The login page's guest button: one account at a time, with a spinner
// while it's made

// Making a guest account and finding its location takes a few seconds
const guestForm = document.getElementById('guest-form');
const guestButton = document.getElementById('guest-button');
let isMakingGuest = false;

function setMakingGuest(isMaking) {
    isMakingGuest = isMaking;
    guestButton.classList.toggle('is-loading', isMaking);
    if (isMaking) {
        guestButton.setAttribute('aria-busy', 'true');
    } else {
        guestButton.removeAttribute('aria-busy');
    }
}

guestForm.addEventListener('submit', (event) => {
    if (isMakingGuest) {
        event.preventDefault();
        return;
    }
    setMakingGuest(true);
});

// Coming back with the Back button shows the page as it was left
window.addEventListener('pageshow', () => setMakingGuest(false));
