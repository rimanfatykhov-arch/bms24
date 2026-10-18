/* Company-bound reference pickers. No state changes, domain writes or AI calls. */
(function () {
    'use strict';
    function init() {
        document.querySelectorAll('.bms-dvu-lookup').forEach(function (box) {
            if (box.dataset.bound) { return; } box.dataset.bound = '1';
            var input = box.querySelector('input'), select = document.getElementById(box.dataset.dvuSelect);
            var status = box.querySelector('[data-dvu-result]'), page = 0, query = '', seq = 0;
            var find = box.querySelector('[data-dvu-find]'), more = box.querySelector('[data-dvu-more]');
            function load(next) {
                var serial = ++seq, q = input.value.trim(), requested = next && q === query ? page + 1 : 0;
                var url = new URL(box.dataset.dvuUrl, window.location.href);
                if (url.origin !== window.location.origin) { status.textContent = box.dataset.dvuError; return; }
                url.searchParams.set('q', q); url.searchParams.set('page', requested);
                find.disabled = more.disabled = true;
                fetch(url.toString(), {credentials: 'same-origin', headers: {'Accept': 'application/json'}})
                    .then(function (response) { if (!response.ok) { throw new Error('lookup'); } return response.json(); })
                    .then(function (data) {
                        if (serial !== seq) { return; }
                        if (!Array.isArray(data.options) || !data.options.every(function (o) { return o && typeof o.value === 'string' && typeof o.label === 'string'; })) { throw new Error('options'); }
                        var selected = select.value, text = select.options[select.selectedIndex] ? select.options[select.selectedIndex].text : '';
                        var first = select.options[0].cloneNode(true); select.replaceChildren(first);
                        if (selected && !data.options.some(function (o) { return o.value === selected; })) { select.add(new Option(text, selected)); }
                        data.options.forEach(function (o) { select.add(new Option(o.label, o.value)); }); select.value = selected;
                        page = requested; query = q; status.textContent = box.dataset.dvuFound + data.options.length;
                    }).catch(function () { if (serial === seq) { status.textContent = box.dataset.dvuError; } })
                    .finally(function () { if (serial === seq) { find.disabled = more.disabled = false; } });
            }
            find.addEventListener('click', function () { load(false); }); more.addEventListener('click', function () { load(true); });
            input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); load(false); } });
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { document.querySelectorAll('.bms-dvu-menu[open]').forEach(function (menu) { menu.open = false; menu.querySelector('summary').focus(); }); }
        });
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
}());
