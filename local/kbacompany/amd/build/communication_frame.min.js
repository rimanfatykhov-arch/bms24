/* Communication Center fixed frame: the page fits the window like a messenger; only the conversation list and the
 * messages scroll, the composer stays at the bottom. Progressive enhancement: without JavaScript, or on a window too
 * short for a usable frame, the stylesheet height and the ordinary page scroll remain. Works in the owned and the
 * legacy shell alike: it measures the real page (its scroll container, what is above and below the block) instead of
 * assuming header or footer heights. While framed, the host footer is hidden on this page only. */
define([], function() {
    'use strict';

    var MIN_HEIGHT = 360;

    function pageScroller(el) {
        for (var n = el.parentElement; n && n !== document.body && n !== document.documentElement; n = n.parentElement) {
            var oy = window.getComputedStyle(n).overflowY;
            var h = n.getBoundingClientRect().height;
            // A page-level scroll container is bounded by the window (Moodle Boost #page), not an inner wrapper.
            if ((oy === 'auto' || oy === 'scroll') && h <= window.innerHeight + 1 && h >= window.innerHeight / 2) {
                return n;
            }
        }
        return null;
    }

    function measure(layout) {
        var sc = pageScroller(layout);
        if (sc) {
            return {el: sc, view: sc.clientHeight, total: sc.scrollHeight, scroll: sc.scrollTop,
                top: layout.getBoundingClientRect().top - sc.getBoundingClientRect().top + sc.scrollTop};
        }
        var doc = document.documentElement;
        return {el: null, view: window.innerHeight, total: doc.scrollHeight, scroll: window.pageYOffset,
            top: layout.getBoundingClientRect().top + window.pageYOffset};
    }

    // Like a messenger page, the framed Center has no host footer (Moodle #page-footer) under the block.
    var PAGE_CLASS = 'bms24-comm-framed-page';

    function release(layout) {
        layout.classList.remove('bms24-comm-framed');
        document.body.classList.remove(PAGE_CLASS);
        layout.style.height = '';
    }

    // The list and the history keep their reading position across a fit; a history read to the newest message stays
    // there (its last message is not cut when the block gets shorter).
    function keepScroll(layout) {
        var panes = Array.prototype.map.call(layout.querySelectorAll('.bms24-comm-list, .bms24-comm-feed'), function(el) {
            return {el: el, top: el.scrollTop, end: el.scrollHeight - el.clientHeight - el.scrollTop <= 2};
        });
        return function() {
            panes.forEach(function(p) {
                var top = p.end ? p.el.scrollHeight : p.top;
                if (p.el.scrollTop !== top) {
                    p.el.scrollTop = top;
                }
            });
        };
    }

    // One fit gives the same height however often it runs (it is called again on every size change around the block, so
    // a fit that moved the block by even a pixel would start a loop: the open conversation shrank and grew without
    // input). The space below the block is measured with the block taller than the window, where nothing that only
    // fills the window (the owned shell's min-height: 100vh) can hide it; the overflow is taken back only when that
    // really shortens the page.
    function fit(layout) {
        var restore = keepScroll(layout);
        document.body.classList.add(PAGE_CLASS);
        layout.classList.add('bms24-comm-framed');
        var m = measure(layout);
        layout.style.height = Math.ceil(2 * m.view) + 'px';
        m = measure(layout);
        var below = Math.max(0, m.total - (m.top + layout.offsetHeight));
        var height = Math.floor(m.view - m.top - below);
        if (height < MIN_HEIGHT) {
            release(layout);
            restore();
            return;
        }
        layout.style.height = height + 'px';
        // Margins and rounding the first pass could not see: take back whatever still overflows, if it is ours.
        m = measure(layout);
        var excess = m.total - m.view;
        if (excess > 0) {
            layout.style.height = (height - excess) + 'px';
            var shorter = measure(layout);
            if (shorter.total >= m.total) {
                layout.style.height = height + 'px';
            } else if (height - excess < MIN_HEIGHT) {
                release(layout);
                restore();
                return;
            }
        }
        restore();
        if (m.scroll > 0) {
            if (m.el) {
                m.el.scrollTop = 0;
            } else {
                window.scrollTo(0, 0);
            }
        }
    }

    function init() {
        var layout = document.querySelector('#bms24-comm-root .bms24-comm-layout');
        if (!layout || layout.dataset.frame === '1') {
            return;
        }
        layout.dataset.frame = '1';
        var pending = false;
        var schedule = function() {
            if (pending) {
                return;
            }
            pending = true;
            window.requestAnimationFrame(function() {
                pending = false;
                fit(layout);
            });
        };
        fit(layout);
        window.addEventListener('resize', schedule);
        if (window.visualViewport) {
            window.visualViewport.addEventListener('resize', schedule);
        }
        // Content above the block may change after load (alerts, live notices, fonts, and the shell's own scripts:
        // the owned top bar shows its menu button only once bms24_nav_drawer runs, which may be after this module).
        // Watch the block and every ancestor up to <body>, so a change outside the block re-fits it as well; a fit
        // that keeps the same height changes no size, so this settles after one pass.
        if (window.ResizeObserver) {
            var observer = new window.ResizeObserver(schedule);
            for (var n = document.querySelector('#bms24-comm-root'); n && n !== document.documentElement; n = n.parentElement) {
                observer.observe(n);
            }
        }
        window.addEventListener('load', schedule);
    }

    return {init: init};
});
