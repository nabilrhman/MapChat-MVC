// A thin bar across the top of the page while the next page loads, so a
// slow click (the free host waking up, say) doesn't look like nothing happened.
// The old page can't finish the bar, since it's gone the moment the new one
// is ready, so the new page picks the bar up where it was and fills it.
// Pages that load quickly get no bar at all, so it doesn't just flash

// How far the bar got, kept for the next page in this tab
const LOADING_BAR_PROGRESS_KEY = 'loadingBar';
const LOADING_BAR_DELAY = 200;
// The fill and the fade after it (.loading-bar.is-finishing in ui.css)
const LOADING_BAR_FINISH_TIME = 700;

function setUpLoadingBar() {
    let isRunning = false;
    let startTimer = null;

    // Pages with the header run it along the header's yellow line instead
    const bar = createElement('div', 'loading-bar');
    bar.classList.toggle('is-under-header', document.getElementById('site-header') !== null);
    document.body.append(bar);

    function startBar() {
        clearTimeout(startTimer);
        startTimer = setTimeout(() => {
            isRunning = true;
            replayAnimation(bar, 'is-running');
        }, LOADING_BAR_DELAY);
    }

    function stopBar() {
        clearTimeout(startTimer);
        isRunning = false;
        bar.classList.remove('is-running');
    }

    // Fills the bar from where the last page left it, then fades it out
    function finishBar(progressFromLastPage) {
        bar.style.setProperty('--progress-from-last-page', progressFromLastPage);
        bar.classList.add('is-finishing');
        setTimeout(() => {
            bar.classList.remove('is-finishing');
            bar.style.removeProperty('--progress-from-last-page');
        }, LOADING_BAR_FINISH_TIME);
    }

    const progressFromLastPage = readStored(LOADING_BAR_PROGRESS_KEY, 'sessionStorage');
    if (progressFromLastPage !== null) {
        writeStored(LOADING_BAR_PROGRESS_KEY, null, 'sessionStorage');
        finishBar(parseFloat(progressFromLastPage) || 0);
    }

    // Links to another page on this site, opened in this tab
    document.addEventListener('click', (event) => {
        const link = event.target.closest('a[href]');
        const isPlainClick = event.button === 0
            && !event.metaKey && !event.ctrlKey && !event.shiftKey && !event.altKey;
        if (!link || event.defaultPrevented || !isPlainClick || link.target || link.hasAttribute('download')) {
            return;
        }

        const url = new URL(link.href, location.href);
        const isSamePage = url.pathname === location.pathname && url.search === location.search;
        if (url.origin !== location.origin || isSamePage) return;

        startBar();
    });

    // Forms that really leave the page; ones sent with JavaScript cancel the
    // event, and that has happened by the time this runs
    document.addEventListener('submit', (event) => {
        setTimeout(() => {
            if (!event.defaultPrevented && !event.target.target) startBar();
        });
    });

    // Leaving: note how far the bar got, for the next page to finish it
    window.addEventListener('pagehide', () => {
        clearTimeout(startTimer);
        if (isRunning) {
            const progress = bar.getBoundingClientRect().width / window.innerWidth;
            writeStored(LOADING_BAR_PROGRESS_KEY, String(progress), 'sessionStorage');
        }
    });

    // Back and forward can show a page from memory, with the bar still running
    window.addEventListener('pageshow', (event) => {
        if (!event.persisted) return;
        stopBar();
        writeStored(LOADING_BAR_PROGRESS_KEY, null, 'sessionStorage');
    });
}

setUpLoadingBar();
