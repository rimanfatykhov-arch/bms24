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

    function fit(layout) {
        document.body.classList.add(PAGE_CLASS);
        var m = measure(layout);
        var below = Math.max(0, m.total - (m.top + layout.offsetHeight));
        var height = Math.floor(m.view - m.top - below);
        if (height < MIN_HEIGHT) {
            release(layout);
            return;
        }
        layout.classList.add('bms24-comm-framed');
        layout.style.height = height + 'px';
        // Margins and rounding the first pass could not see: take back whatever still overflows.
        m = measure(layout);
        var excess = m.total - m.view;
        if (excess > 0) {
            if (height - excess < MIN_HEIGHT) {
                release(layout);
                return;
            }
            layout.style.height = (height - excess) + 'px';
        }
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
        // Content above the block may change after load (alerts, live notices, fonts).
        if (window.ResizeObserver) {
            var root = document.querySelector('#bms24-comm-root');
            if (root) {
                new window.ResizeObserver(schedule).observe(root);
            }
        }
        window.addEventListener('load', schedule);
    }

    return {init: init};
});
