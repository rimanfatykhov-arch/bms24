/* Task discussion only: retained drafts and atomic multi-file submission. */
(function() {
    'use strict';
    function init() {
        document.querySelectorAll('form[data-bms-discussion-composer]').forEach(function(form) {
            var input = form.querySelector('#tui_discussionattachment');
            var list = form.querySelector('[data-bms-attachment-list]');
            var error = form.querySelector('[data-bms-discussion-error]');
            var text = form.querySelector('[name="message"]');
            var submit = form.querySelector('[type="submit"]');
            if (!input || !list || !error || !text || !submit || !window.fetch || !window.DataTransfer) { return; }
            // Native fallback stays single-file: PHP can silently truncate an over-limit array.
            input.multiple = true;
            var files = [], busy = false;
            function message(key) { return form.getAttribute('data-error-' + key) || form.getAttribute('data-error-send'); }
            function showError(value) { error.textContent = value || ''; error.hidden = !value; }
            function validate() {
                var maxfiles = Number(form.getAttribute('data-upload-maxfiles'));
                var maxbytes = Number(form.getAttribute('data-upload-maxbytes'));
                var postmax = Number(form.getAttribute('data-upload-postmaxbytes'));
                if (maxfiles > 0 && files.length > maxfiles) { return message('count'); }
                var total = new Blob([text.value]).size;
                for (var i = 0; i < files.length; i++) {
                    if (maxbytes > 0 && files[i].size > maxbytes) { return message('size'); }
                    total += files[i].size;
                }
                if (postmax > 0 && total > postmax) { return message('size'); }
                return '';
            }
            function sync() {
                var transfer = new DataTransfer();
                files.forEach(function(file) { transfer.items.add(file); });
                input.files = transfer.files;
                list.textContent = '';
                files.forEach(function(file, index) {
                    var row = document.createElement('li');
                    var label = document.createElement('span');
                    label.textContent = file.name + ' (' + file.size.toLocaleString() + ' B)';
                    var remove = document.createElement('button');
                    remove.type = 'button'; remove.className = 'btn btn-sm btn-link';
                    remove.textContent = form.getAttribute('data-remove-file');
                    remove.setAttribute('aria-label', remove.textContent + ': ' + file.name);
                    remove.disabled = busy;
                    remove.addEventListener('click', function() {
                        if (busy) { return; }
                        files.splice(index, 1); sync(); showError(validate()); input.focus();
                    });
                    row.appendChild(label); row.appendChild(remove); list.appendChild(row);
                });
            }
            input.addEventListener('change', function() {
                Array.from(input.files || []).forEach(function(file) {
                    // Equal metadata does not prove equal contents (e.g. two directories).
                    // Keep every selected item; the message receipt prevents duplicate sends.
                    files.push(file);
                });
                sync(); showError(validate());
            });
            text.addEventListener('keydown', function(event) {
                if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
                    event.preventDefault();
                    if (!busy) { if (form.requestSubmit) { form.requestSubmit(submit); } else { submit.click(); } }
                }
            });
            form.addEventListener('submit', function(event) {
                event.preventDefault();
                if (busy) { return; }
                var invalid = validate();
                if (invalid) { showError(invalid); return; }
                var target = new URL(form.action, window.location.href);
                if (target.origin !== window.location.origin || target.pathname !== window.location.pathname) {
                    showError(message('send')); return;
                }
                var data = new FormData(form);
                data.set('discussionajax', '1'); data.set('discussionfilecount', String(files.length));
                var requestkey = data.get('requestkey');
                busy = true; submit.disabled = true; input.disabled = true; text.readOnly = true;
                sync(); showError('');
                // No automatic redirects, retry, or draft clearing: an ambiguous reply is never PASS.
                fetch(target.href, {method: 'POST', body: data, credentials: 'same-origin', redirect: 'error'})
                    .then(function(response) {
                        if (response.redirected || !/^application\/json(?:;|$)/i.test(response.headers.get('content-type') || '')) {
                            throw new Error(message('send'));
                        }
                        return response.json().then(function(result) {
                            if (!result || result.contract !== 'bms_task_discussion_v1' || result.requestkey !== requestkey) {
                                throw new Error(message('send'));
                            }
                            if (!response.ok || result.ok !== true) {
                                throw new Error(typeof result.error === 'string' && result.error ? result.error : message('send'));
                            }
                            var next = new URL(result.url, window.location.href);
                            if (next.origin !== target.origin || next.pathname !== target.pathname ||
                                    next.searchParams.get('uuid') !== data.get('uuid') || next.searchParams.get('task') !== data.get('task')) {
                                throw new Error(message('send'));
                            }
                            window.location.assign(next.href);
                        });
                    }).catch(function(reason) {
                        busy = false; submit.disabled = false; input.disabled = false; text.readOnly = false;
                        sync(); showError(reason.message || message('send'));
                    });
            });
            sync();
        });
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
}());
