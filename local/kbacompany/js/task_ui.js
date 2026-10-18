/* BMS task presentation only: legacy create link and company-scoped relation lookup. */
(function() {
    'use strict';
    function init() {
        var root = document.querySelector('[data-task-create-url]');
        function oldCreate() {
            if (root && window.location.hash === '#task-create-modal') {
                window.location.replace(root.getAttribute('data-task-create-url'));
            }
        }
        oldCreate();
        window.addEventListener('hashchange', oldCreate);
        document.querySelectorAll('form[data-duty-positions-url]').forEach(function(form) {
            var assignee = form.querySelector('#tui_assigneeid');
            var responsibility = form.querySelector('#tui_dutypositionuuid');
            var status = form.querySelector('[data-duty-status]');
            var empty = form.querySelector('[data-duty-no-positions]');
            var submit = form.querySelector('button[type="submit"]');
            var confirm = form.querySelector('[data-duty-confirm]');
            function updateConfirmation() {
                if (!confirm) { return; }
                var original = form.getAttribute('data-duty-original') || '';
                var originalAssignee = form.getAttribute('data-assignee-original') || '';
                var mustConfirm = responsibility.value !== '' &&
                    (responsibility.value !== original || assignee.value !== originalAssignee);
                confirm.required = mustConfirm;
                if (!mustConfirm) { confirm.checked = false; }
            }
            responsibility.addEventListener('change', updateConfirmation);
            updateConfirmation();
            if (!assignee || !responsibility || !window.fetch) { return; }
            var requestId = 0;
            assignee.addEventListener('change', function() {
                var current = ++requestId;
                var url = new URL(form.getAttribute('data-duty-positions-url'), window.location.href);
                url.searchParams.set('assigneeid', assignee.value);
                // Never retain a previous assignee's position after their assignment changes.
                var first = responsibility.options[0];
                var label = first ? first.textContent : '';
                responsibility.textContent = '';
                responsibility.add(new Option(label, ''));
                responsibility.disabled = true;
                if (submit) { submit.disabled = true; }
                if (status) { status.textContent = ''; }
                if (empty) { empty.hidden = true; }
                updateConfirmation();
                fetch(url.toString(), {credentials:'same-origin', headers:{'Accept':'application/json'}})
                    .then(function(response) { if (!response.ok) { throw new Error('positions'); } return response.json(); })
                    .then(function(data) {
                        if (current !== requestId) { return; }
                        if (!Array.isArray(data.options)) { throw new Error('positions'); }
                        var added = 0;
                        data.options.forEach(function(item) {
                            if (item.value) {
                                responsibility.add(new Option(item.label, item.value));
                                added++;
                            }
                        });
                        responsibility.disabled = false;
                        if (submit) { submit.disabled = false; }
                        if (empty) { empty.hidden = added > 0; }
                        updateConfirmation();
                    })
                    .catch(function() {
                        if (current !== requestId) { return; }
                        if (status) { status.textContent = form.getAttribute('data-duty-options-error') || ''; }
                        // Do not save while positions have not been authoritatively checked.
                        responsibility.disabled = true;
                        if (submit) { submit.disabled = true; }
                    });
            });
        });
        document.querySelectorAll('[data-relation-url]').forEach(function(box) {
            var input = box.querySelector('input');
            var button = box.querySelector('[data-relation-button]');
            var status = box.querySelector('[data-relation-status]');
            var select = document.getElementById('tui_relationref');
            if (!input || !button || !status || !select || !window.fetch) { return; }
            box.hidden = false;
            button.addEventListener('click', function() {
                var url = new URL(box.getAttribute('data-relation-url'), window.location.href);
                url.searchParams.set('q', input.value);
                button.disabled = true;
                status.textContent = '';
                fetch(url.toString(), {credentials: 'same-origin', headers: {'Accept': 'application/json'}})
                    .then(function(response) { if (!response.ok) { throw new Error('lookup'); } return response.json(); })
                    .then(function(data) {
                        if (!Array.isArray(data.options)) { throw new Error('lookup'); }
                        var value = select.value;
                        var chosen = select.options[select.selectedIndex];
                        var label = chosen ? chosen.textContent : '';
                        var empty = select.options[0].textContent;
                        select.textContent = '';
                        select.add(new Option(empty, ''));
                        data.options.forEach(function(item) { select.add(new Option(item.label, item.value)); });
                        if (value && !Array.from(select.options).some(function(option) { return option.value === value; })) {
                            select.add(new Option(label, value));
                        }
                        select.value = value;
                        status.textContent = box.getAttribute('data-found') + ': ' + data.options.length;
                    })
                    .catch(function() { status.textContent = box.getAttribute('data-error'); })
                    .then(function() { button.disabled = false; });
            });
            input.addEventListener('keydown', function(event) {
                if (event.key === 'Enter') { event.preventDefault(); button.click(); }
            });
        });
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
}());
