// Tooltips in the app's style instead of the browser's grey box. Anything
// with a title gets one: on hover the title moves to data-tip (so the
// browser's own doesn't show too) and a small dark label appears above it,
// or below when there's no room. Touch screens keep no tooltips, as before.
(function () {
    if (!window.matchMedia('(hover: hover)').matches) return;

    var DELAY = 400;
    var tip = document.createElement('div');
    tip.className = 'tooltip';
    tip.setAttribute('role', 'tooltip');
    document.body.appendChild(tip);

    var target = null;
    var timer = null;

    function textOf(el) {
        var title = el.getAttribute('title');
        if (title) {
            el.dataset.tip = title;
            el.removeAttribute('title');
            // Keep a name for screen readers on things with no text of their own
            if (!el.getAttribute('aria-label') && !el.textContent.trim()) {
                el.setAttribute('aria-label', title);
            }
        } else if (title === '') {
            delete el.dataset.tip; // the title was cleared, e.g. your own row
        }
        return el.dataset.tip || '';
    }

    // Where the thing's text actually is: a name spans its whole row, but
    // the tooltip should sit over the name itself
    function boxOf(el) {
        if (el.textContent.trim() && !el.querySelector('img, i, svg')) {
            var range = document.createRange();
            range.selectNodeContents(el);
            var rect = range.getBoundingClientRect();
            if (rect.width) return rect;
        }
        return el.getBoundingClientRect();
    }

    function show() {
        var text = target && textOf(target);
        if (!text) return;

        tip.textContent = text;
        tip.classList.remove('tooltip--below');
        var box = boxOf(target);
        var width = tip.offsetWidth;
        var height = tip.offsetHeight;
        var left = Math.min(Math.max(8, box.left + box.width / 2 - width / 2), window.innerWidth - width - 8);
        var top = box.top - height - 6;
        if (top < 8) {
            top = box.bottom + 6;
            tip.classList.add('tooltip--below');
        }

        // The arrow points at the middle of the thing, even when the label is
        // pushed in from the screen's edge
        tip.style.setProperty('--arrow-x', (box.left + box.width / 2 - left) + 'px');
        tip.style.left = left + 'px';
        tip.style.top = top + 'px';
        tip.classList.add('is-shown');
    }

    function hide() {
        clearTimeout(timer);
        target = null;
        tip.classList.remove('is-shown');
    }

    document.addEventListener('mouseover', function (event) {
        var el = event.target.closest('[title], [data-tip]');
        if (el === target) return;
        hide();
        if (!el) return;

        target = el;
        textOf(el); // take the title away now, before the browser shows its own
        timer = setTimeout(show, DELAY);
    });

    document.addEventListener('mousedown', hide);
    document.addEventListener('scroll', hide, true);
    window.addEventListener('blur', hide);
})();
