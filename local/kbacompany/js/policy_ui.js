/* Policies: presentation-only company-scoped relation lookup. No writes or permission decisions. */
(function() {
    'use strict';
    function init() {
        document.querySelectorAll('[data-policy-url]').forEach(function(box) {
            var input = box.querySelector('input');
            var button = box.querySelector('[data-policy-find]');
            var status = box.querySelector('[data-policy-result]');
            var select = document.getElementById(box.getAttribute('data-policy-select'));
            if (!input || !button || !status || !select || !window.fetch) { return; }
            button.addEventListener('click', function() {
                var url = new URL(box.getAttribute('data-policy-url'), window.location.href);
                url.searchParams.set('q', input.value);
                button.disabled = true;
                status.textContent = '';
                fetch(url.toString(), {credentials: 'same-origin', headers: {'Accept': 'application/json'}})
                    .then(function(response) { if (!response.ok) { throw new Error('lookup'); } return response.json(); })
                    .then(function(data) {
                        if (!Array.isArray(data.options) || data.options.some(function(item) {
                            return !item || typeof item.value !== 'string' || typeof item.label !== 'string';
                        })) { throw new Error('lookup'); }
                        var value = select.value;
                        var selected = select.options[select.selectedIndex];
                        var label = selected ? selected.textContent : '';
                        var first = select.options[0];
                        var firstValue = first.value;
                        var firstLabel = first.textContent;
                        select.textContent = '';
                        select.add(new Option(firstLabel, firstValue));
                        data.options.forEach(function(item) {
                            if (typeof item.value !== 'string' || typeof item.label !== 'string') { throw new Error('lookup'); }
                            select.add(new Option(item.label, item.value));
                        });
                        if (value && !Array.from(select.options).some(function(option) { return option.value === value; })) {
                            select.add(new Option(label, value));
                        }
                        select.value = value;
                        status.textContent = box.getAttribute('data-policy-found') + ': ' + data.options.length;
                    })
                    .catch(function() { status.textContent = box.getAttribute('data-policy-error'); })
                    .then(function() { button.disabled = false; });
            });
            input.addEventListener('keydown', function(event) {
                if (event.key === 'Enter') { event.preventDefault(); button.click(); }
            });
        });
        document.querySelectorAll('.bms-pcu-menu').forEach(function(menu) {
            menu.addEventListener('keydown', function(event) {
                if (event.key === 'Escape') { menu.open = false; menu.querySelector('summary').focus(); }
            });
        });
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
}());
