// The header's two menus: the hamburger on phones, which opens the links
// under the header, and the account menu, which opens Profile and Log out.
// Each closes on a second tap, a tap anywhere outside it or Escape
(function () {
    function menu(root, button, openClass, onToggle) {
        if (!root || !button) return;

        function setOpen(open) {
            root.classList.toggle(openClass, open);
            button.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (onToggle) onToggle(open);
        }

        button.addEventListener('click', function () {
            setOpen(button.getAttribute('aria-expanded') !== 'true');
        });

        document.addEventListener('click', function (event) {
            if (!root.contains(event.target)) setOpen(false);
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') setOpen(false);
        });
    }

    var menuButton = document.getElementById('menu-button');
    menu(document.querySelector('.site-header'), menuButton, 'is-menu-open', function (open) {
        setIcon(menuButton.querySelector('.icon'), open ? 'close' : 'hamburger-menu');
    });

    menu(document.getElementById('account-menu'), document.getElementById('account-menu-button'), 'is-open');

    // Logging out first stops this browser's Web Push (notifications.js), so
    // whoever signs in on it next doesn't get your messages. Gives up after
    // a moment rather than keep you waiting
    var LOGOUT_PUSH_WAIT = 2000;
    var logoutForm = document.querySelector('form[action="/logout"]');
    if (!logoutForm || !navigator.serviceWorker) return;

    logoutForm.addEventListener('submit', function (event) {
        event.preventDefault();
        try {
            localStorage.removeItem('pushOn');
        } catch (e) {
            // No storage: nothing was kept
        }

        var unsubscribe = navigator.serviceWorker.getRegistration()
            .then(function (registration) {
                return registration && registration.pushManager.getSubscription();
            })
            .then(function (subscription) {
                if (!subscription) return undefined;
                return postForm('/push/unsubscribe', {endpoint: subscription.endpoint})
                    .catch(function () {})
                    .then(function () {
                        return subscription.unsubscribe();
                    });
            });
        var timeout = new Promise(function (resolve) {
            setTimeout(resolve, LOGOUT_PUSH_WAIT);
        });

        Promise.race([unsubscribe, timeout]).catch(function () {}).then(function () {
            logoutForm.submit();
        });
    }, {once: true});
})();
