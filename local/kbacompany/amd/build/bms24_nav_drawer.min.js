/**
 * BMS24 owned shell: modal navigation drawer for narrow windows (docs/ui/ADAPTIVE_NAVIGATION.md).
 *
 * Progressive enhancement of the server-rendered <details class="bms24-mobilemenu">: without this module the summary
 * still opens the same list. Presentation only: no request, no storage, no change of links, rights or counters.
 */
define([], function() {
    'use strict';
    var QUERY = '(max-width: 860px)';
    var FOCUSABLE = 'a[href],button:not([disabled]),summary,input:not([disabled]),select:not([disabled]),' +
        'textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';

    function visible(el) {
        // Content of a closed <details> keeps a layout box in current Chromium: ask the renderer first.
        if (el.checkVisibility && !el.checkVisibility({visibilityProperty: true})) {
            return false;
        }
        var r = el.getBoundingClientRect();
        return r.width > 0 && r.height > 0 && window.getComputedStyle(el).visibility !== 'hidden';
    }

    function init() {
        var shell = document.querySelector('.bms24-app[data-bms24-owned-shell]');
        if (!shell || shell.getAttribute('data-bms24-drawer') === '1') {
            return;
        }
        var details = shell.querySelector('.bms24-mobilemenu');
        var drawer = shell.querySelector('#bms24-drawer');
        var button = shell.querySelector('.bms24-menubutton');
        var close = drawer && drawer.querySelector('[data-bms24-drawer-close]');
        var backdrop = details && details.querySelector('[data-bms24-drawer-backdrop]');
        if (!details || !drawer || !button || !close || !backdrop || !window.matchMedia) {
            return;
        }
        var narrow = window.matchMedia(QUERY);
        var root = document.documentElement;
        var isopen = false;
        var savedy = 0;
        var inerted = [];
        var prior = null;

        shell.setAttribute('data-bms24-drawer', '1');
        drawer.setAttribute('role', 'dialog');
        drawer.setAttribute('aria-modal', 'true');
        drawer.setAttribute('aria-label', drawer.getAttribute('data-bms24-drawer-label') || button.textContent.trim());
        button.hidden = false;
        close.hidden = false;
        backdrop.hidden = false;
        details.open = false;

        function focusables() {
            return Array.prototype.filter.call(drawer.querySelectorAll(FOCUSABLE), visible);
        }

        function setInert(on) {
            if (on) {
                var topbar = shell.querySelector('.bms24-topbar');
                var nodes = Array.prototype.slice.call(shell.querySelectorAll('.bms24-skip, .bms24-sidebar, .bms24-main'));
                if (topbar) {
                    Array.prototype.forEach.call(topbar.children, function(child) {
                        if (child !== details) {
                            nodes.push(child);
                        }
                    });
                }
                inerted = nodes.filter(function(node) {
                    return !node.hasAttribute('inert');
                });
                inerted.forEach(function(node) {
                    node.setAttribute('inert', '');
                });
            } else {
                inerted.forEach(function(node) {
                    node.removeAttribute('inert');
                });
                inerted = [];
            }
        }

        function open() {
            if (isopen || !narrow.matches) {
                return;
            }
            isopen = true;
            savedy = window.pageYOffset || root.scrollTop || 0;
            var gutter = window.innerWidth - root.clientWidth > 0;
            details.open = true;
            // The group of the current page is open, so its item is visible at once.
            Array.prototype.forEach.call(drawer.querySelectorAll('a[aria-current="page"]'), function(link) {
                var group = link.closest('details');
                if (group) {
                    group.open = true;
                }
            });
            // Lock the page behind the modal; keep the scrollbar gutter so the page neither shifts nor widens.
            prior = {overflow: root.style.overflow, gutter: root.style.scrollbarGutter};
            root.style.overflow = 'hidden';
            if (gutter) {
                root.style.scrollbarGutter = 'stable';
            }
            shell.classList.add('bms24-drawer-open');
            button.setAttribute('aria-expanded', 'true');
            setInert(true);
            var current = drawer.querySelector('a[aria-current="page"]');
            var target = current && visible(current) ? current : close;
            target.focus({preventScroll: true});
            if (target === current) {
                var body = drawer.querySelector('.bms24-drawerbody');
                if (body) {
                    var br = body.getBoundingClientRect();
                    var cr = current.getBoundingClientRect();
                    if (cr.bottom > br.bottom || cr.top < br.top) {
                        body.scrollTop += cr.top - br.top - br.height / 3;
                    }
                }
            }
        }

        function hide(restorefocus) {
            if (!isopen) {
                return;
            }
            isopen = false;
            var hadfocus = drawer.contains(document.activeElement);
            setInert(false);
            details.open = false;
            shell.classList.remove('bms24-drawer-open');
            if (prior) {
                root.style.overflow = prior.overflow;
                root.style.scrollbarGutter = prior.gutter;
                prior = null;
            }
            button.setAttribute('aria-expanded', 'false');
            if ((window.pageYOffset || 0) !== savedy) {
                window.scrollTo(window.pageXOffset || 0, savedy);
            }
            if (restorefocus && visible(button)) {
                button.focus({preventScroll: true});
            } else if (hadfocus) {
                // The button disappeared (wide window): continue from the current item of the sidebar.
                var side = shell.querySelector('.bms24-sidebar a[aria-current="page"]');
                if (side && visible(side)) {
                    side.focus({preventScroll: true});
                }
            }
        }

        button.addEventListener('click', function() {
            if (isopen) {
                hide(true);
            } else {
                open();
            }
        });
        close.addEventListener('click', function() {
            hide(true);
        });
        backdrop.addEventListener('click', function() {
            hide(true);
        });
        document.addEventListener('keydown', function(e) {
            if (!isopen) {
                return;
            }
            if (e.key === 'Escape' || e.key === 'Esc') {
                e.preventDefault();
                hide(true);
                return;
            }
            if (e.key !== 'Tab') {
                return;
            }
            var items = focusables();
            if (!items.length) {
                e.preventDefault();
                return;
            }
            var first = items[0];
            var last = items[items.length - 1];
            var inside = drawer.contains(document.activeElement);
            if (e.shiftKey && (!inside || document.activeElement === first)) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && (!inside || document.activeElement === last)) {
                e.preventDefault();
                first.focus();
            }
        });
        // Find-in-page or other agents may toggle the <details>: keep the modal state in step with it.
        details.addEventListener('toggle', function() {
            if (details.open && !isopen) {
                if (narrow.matches) {
                    open();
                } else {
                    details.open = false;
                }
            } else if (!details.open && isopen) {
                hide(true);
            }
        });
        var onchange = function() {
            if (!narrow.matches) {
                hide(false);
            }
        };
        if (narrow.addEventListener) {
            narrow.addEventListener('change', onchange);
        } else if (narrow.addListener) {
            narrow.addListener(onchange);
        }
        window.addEventListener('pageshow', function() {
            hide(false);
        });
    }

    return {init: init};
});
