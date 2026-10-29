/* BMS Process CRUD: progressive enhancement only; server validation is authoritative. */
(function() {
    'use strict';
    function init() {
        var root = document.querySelector('.bms-pru');
        if (!root || root.dataset.pruReady) { return; }
        root.dataset.pruReady = '1';
        var dirty = new Set();
        var navigating = false;
        root.querySelectorAll('form[data-pru-dirty]').forEach(function(form) {
            var state = form.querySelector('[data-pru-form-state]');
            function changed() { dirty.add(form); if (state) { state.textContent = state.dataset.dirty; } }
            if (form.dataset.pruInitialDirty === '1') { changed(); }
            form.addEventListener('input', changed);
            form.addEventListener('change', changed);
            form.addEventListener('reset', function() { dirty.delete(form); });
        });
        root.querySelectorAll('form[method="post"]').forEach(function(form) {
            var submitted = false;
            form.addEventListener('submit', function(e) {
                if (submitted) { e.preventDefault(); return; }
                submitted = true;
                navigating = true;
                // Do not disable named submitters: Moodle needs their action value.
                form.setAttribute('aria-busy', 'true');
                var state = form.querySelector('[data-pru-form-state]');
                if (state) { state.textContent = state.dataset.saving; }
            });
        });
        window.addEventListener('beforeunload', function(e) {
            if (dirty.size && !navigating) { e.preventDefault(); e.returnValue = ''; }
        });
        window.addEventListener('pageshow', function(e) {
            if (e.persisted) { navigating = false; window.location.reload(); }
        });
        var sipocForm = root.querySelector('form[data-sipoc-form]');
        if (sipocForm) {
            var orderInput = sipocForm.querySelector('input[name="ordering"]');
            var dragged = null;
            function sipocOrder() {
                var result = {input: [], task: [], output: []};
                Object.keys(result).forEach(function(type) {
                    var list = sipocForm.querySelector('[data-sipoc-list="' + type + '"]');
                    if (!list) { return; }
                    list.querySelectorAll('[data-sipoc-item][data-sipoc-type="' + type + '"]').forEach(function(card) {
                        result[type].push(card.dataset.sipocItem);
                    });
                });
                if (orderInput) {
                    orderInput.value = JSON.stringify(result);
                    orderInput.dispatchEvent(new Event('input', {bubbles: true}));
                }
            }
            sipocForm.querySelectorAll('[data-sipoc-item]').forEach(function(card) {
                card.addEventListener('dragstart', function(e) {
                    dragged = card;
                    card.classList.add('is-dragging');
                    if (e.dataTransfer) { e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', card.dataset.sipocItem); }
                });
                card.addEventListener('dragend', function() { card.classList.remove('is-dragging'); dragged = null; sipocOrder(); });
            });
            sipocForm.querySelectorAll('[data-sipoc-list="input"], [data-sipoc-list="task"], [data-sipoc-list="output"]').forEach(function(list) {
                list.addEventListener('dragover', function(e) {
                    if (!dragged || dragged.dataset.sipocType !== list.dataset.sipocList) { return; }
                    e.preventDefault();
                    var cards = Array.prototype.slice.call(list.querySelectorAll('[data-sipoc-item]:not(.is-dragging)'));
                    var next = cards.find(function(card) {
                        var r = card.getBoundingClientRect();
                        return e.clientY < r.top + r.height / 2;
                    });
                    if (next) { list.insertBefore(dragged, next); } else { list.appendChild(dragged); }
                });
            });
            sipocForm.addEventListener('click', function(e) {
                var button = e.target.closest('[data-sipoc-move]');
                if (!button) { return; }
                var card = button.closest('[data-sipoc-item]');
                if (!card) { return; }
                e.preventDefault();
                var direction = button.dataset.sipocMove;
                if (direction === 'up') {
                    var prev = card.previousElementSibling;
                    while (prev && !prev.hasAttribute('data-sipoc-item')) { prev = prev.previousElementSibling; }
                    if (prev) { card.parentNode.insertBefore(card, prev); }
                } else {
                    var next = card.nextElementSibling;
                    while (next && !next.hasAttribute('data-sipoc-item')) { next = next.nextElementSibling; }
                    if (next) { card.parentNode.insertBefore(next, card); }
                }
                sipocOrder();
                card.focus();
            });
        }
        root.addEventListener('keydown', function(e) {
            if (e.key !== 'Escape') { return; }
            var menu = e.target.closest('.bms-pdu-menu[open]');
            if (menu) { menu.removeAttribute('open'); menu.querySelector('summary').focus(); }
        });
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); }
    else { init(); }
}());
