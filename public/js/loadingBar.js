// A thin bar across the top of the page while the next page loads, so a
// slow click (the free host waking up, say) doesn't look like nothing happened.
// The old page can't finish the bar, since it's gone the moment the new one
// is ready, so the new page picks the bar up where it was and fills it.
// Pages that load quickly get no bar at all, so it doesn't just flash.
(function () {
    var KEY = 'loadingBar';
    var DELAY = 200;
    var running = false;
    var timer = null;

    var bar = document.createElement('div');
    bar.className = 'loading-bar';
    document.body.appendChild(bar);

    function remember(value) {
        try {
            if (value === null) {
                sessionStorage.removeItem(KEY);
            } else {
                sessionStorage.setItem(KEY, value);
            }
        } catch (e) {
            // Storage blocked: the bar still runs, it just won't finish on the next page
        }
    }

    function start() {
        clearTimeout(timer);
        timer = setTimeout(function () {
            running = true;
            bar.classList.remove('loading-bar--running');
            void bar.offsetWidth; // restart the animation
            bar.classList.add('loading-bar--running');
        }, DELAY);
    }

    function stop() {
        clearTimeout(timer);
        running = false;
        bar.classList.remove('loading-bar--running');
    }

    // Fill the bar from where the last page left it, then fade it out
    function finish(from) {
        bar.style.transition = 'none';
        bar.style.width = (from * 100) + '%';
        bar.style.opacity = '1';
        void bar.offsetWidth;
        bar.style.transition = 'width 0.25s ease-out, opacity 0.3s 0.25s';
        bar.style.width = '100%';
        bar.style.opacity = '0';
        setTimeout(function () {
            bar.removeAttribute('style');
        }, 600);
    }

    var from = null;
    try {
        from = sessionStorage.getItem(KEY);
    } catch (e) {}
    if (from !== null) {
        remember(null);
        finish(parseFloat(from) || 0);
    }

    // Links to another page on this site, opened in this tab
    document.addEventListener('click', function (event) {
        var link = event.target.closest('a[href]');
        if (!link || event.defaultPrevented || event.button !== 0 ||
            event.metaKey || event.ctrlKey || event.shiftKey || event.altKey ||
            link.target || link.hasAttribute('download')) {
            return;
        }

        var url = new URL(link.href, location.href);
        var samePage = url.pathname === location.pathname && url.search === location.search;
        if (url.origin !== location.origin || samePage) {
            return;
        }

        start();
    });

    // Forms that really leave the page; ones sent with JavaScript cancel the
    // event, and that has happened by the time this runs
    document.addEventListener('submit', function (event) {
        setTimeout(function () {
            if (!event.defaultPrevented && !event.target.target) {
                start();
            }
        }, 0);
    });

    // Leaving: note how far the bar got, for the next page to finish it
    window.addEventListener('pagehide', function () {
        clearTimeout(timer);
        if (running) {
            remember(bar.getBoundingClientRect().width / window.innerWidth);
        }
    });

    // Back and forward can show a page from memory, with the bar still running
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) {
            stop();
            remember(null);
        }
    });
})();
