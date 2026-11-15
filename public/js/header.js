// The menu button on phones: opens the links under the header, and closes
// them on a second tap, a tap anywhere else or Escape
(function () {
    var header = document.querySelector('.site-header');
    var button = document.getElementById('menu-button');
    if (!header || !button) return;

    function setOpen(open) {
        header.classList.toggle('is-menu-open', open);
        button.setAttribute('aria-expanded', open ? 'true' : 'false');
        button.querySelector('.material-icons').textContent = open ? 'close' : 'menu';
    }

    button.addEventListener('click', function () {
        setOpen(!header.classList.contains('is-menu-open'));
    });

    document.addEventListener('click', function (event) {
        if (!header.contains(event.target)) setOpen(false);
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') setOpen(false);
    });
})();
