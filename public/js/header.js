// The header's two menus: the hamburger on phones, which opens the links
// under the header, and the account menu, which opens Profile and Log out.
// Each closes on a second tap, a tap anywhere outside it or Escape. Logging
// out lets go of this browser's Web Push first

// How long logging out waits on the browser's push service at each step
// before it goes on without it
const LOGOUT_PUSH_WAIT = 1000;

function setUpMenu(menu, button, openClass) {
    if (!menu || !button) return;

    function setOpen(isOpen) {
        menu.classList.toggle(openClass, isOpen);
        button.setAttribute('aria-expanded', String(isOpen));
    }

    button.addEventListener('click', () => {
        setOpen(button.getAttribute('aria-expanded') !== 'true');
    });

    document.addEventListener('click', (event) => {
        if (!menu.contains(event.target)) setOpen(false);
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') setOpen(false);
    });
}

function setUpMenus() {
    // Its three lines turn into an X with the open class (header.css)
    setUpMenu(document.getElementById('site-header'), document.getElementById('menu-button'), 'is-menu-open');

    setUpMenu(document.getElementById('account-menu'), document.getElementById('account-menu-button'), 'is-open');
}

// The form takes this browser's push endpoint along, so the server deletes
// that subscription and whoever signs in here next doesn't get your
// messages. The browser lets go of its own copy too. A push service that
// doesn't answer in time doesn't keep you waiting
function setUpLogout() {
    const logoutForm = document.querySelector('form[action="/logout"]');
    if (!logoutForm) return;
    let isLoggingOut = false;

    logoutForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (isLoggingOut) return;
        isLoggingOut = true;
        writeFlag(PUSH_ON_KEY, false);

        const subscription = await withTimeLimit(getPushSubscription(), LOGOUT_PUSH_WAIT).catch(() => null);
        if (subscription) {
            const endpointField = createElement('input');
            endpointField.type = 'hidden';
            endpointField.name = 'push_endpoint';
            endpointField.value = subscription.endpoint;
            logoutForm.append(endpointField);
            await withTimeLimit(subscription.unsubscribe(), LOGOUT_PUSH_WAIT).catch(() => {});
        }
        logoutForm.submit();
    });
}

setUpMenus();
setUpLogout();
