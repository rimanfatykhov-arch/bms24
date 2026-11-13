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
        // build1850: only navigate to the canonical confirmation page on an allowed drop.
        // No client-side mutation and no optimistic status changes.
        document.querySelectorAll('.bms-task1850-board').forEach(function(board) {
            var current = null;
            var routes = null;
            board.querySelectorAll('.bms-task1850-card[draggable="true"]').forEach(function(card) {
                card.addEventListener('dragstart', function(event) {
                    try { routes = JSON.parse(card.getAttribute('data-kanban-routes') || '{}'); }
                    catch (e) { routes = {}; }
                    if (!routes || !Object.keys(routes).length) { event.preventDefault(); return; }
                    current = card;
                    event.dataTransfer.effectAllowed = 'move';
                    event.dataTransfer.setData('text/plain', card.getAttribute('data-kanban-task'));
                    card.classList.add('is-dragging');
                });
                card.addEventListener('dragend', function() {
                    current = null;
                    routes = null;
                    card.classList.remove('is-dragging');
                    board.querySelectorAll('.bms-task1850-dropzone').forEach(function(zone) {
                        zone.classList.remove('is-drop-target');
                    });
                });
            });
            board.querySelectorAll('.bms-task1850-dropzone').forEach(function(zone) {
                var target = zone.getAttribute('data-kanban-target');
                zone.addEventListener('dragover', function(event) {
                    if (!current || !routes || !Object.prototype.hasOwnProperty.call(routes, target)) { return; }
                    event.preventDefault();
                    event.dataTransfer.dropEffect = 'move';
                    zone.classList.add('is-drop-target');
                });
                zone.addEventListener('dragleave', function() { zone.classList.remove('is-drop-target'); });
                zone.addEventListener('drop', function(event) {
                    zone.classList.remove('is-drop-target');
                    if (!current || !routes || !Object.prototype.hasOwnProperty.call(routes, target)) { return; }
                    event.preventDefault();
                    var path = routes[target];
                    if (typeof path === 'string' && path.indexOf('/local/kbacompany/tasks.php') >= 0) {
                        window.location.assign(path);
                    }
                });
            });
        });
        // RC29 build1840: optional debounce for GET task search; accessible form remains usable
        // without JavaScript and never changes a task or grants additional visibility.
        // 1842: client-side usability only. Server validates UUID/revision, ACL and operations.
        document.querySelectorAll('form[data-bms-task1842-batch]').forEach(function(form) {
            var checks = Array.prototype.slice.call(form.querySelectorAll('.bms-task1842-row-select'));
            var toggle = form.querySelector('[data-bms-task1842-select-all]');
            var counter = form.querySelector('[data-bms-task1842-count]');
            var operation = form.querySelector('#bms_task1842_operation');
            var duedate = form.querySelector('#bms_task1842_duedate');
            var assignee = form.querySelector('#bms_task1842_assignee');
            var submit = form.querySelector('button[type="submit"]');
            function update() {
                var chosen = checks.filter(function(c) { return c.checked; }).length;
                if (counter) { counter.textContent = chosen + ' / ' + checks.length; }
                if (toggle) { toggle.checked = chosen === checks.length && chosen > 0;
                    toggle.indeterminate = chosen > 0 && chosen < checks.length; }
                if (operation) {
                    if (duedate) { duedate.hidden = operation.value !== 'duedate';
                        duedate.required = operation.value === 'duedate'; }
                    if (assignee) { assignee.hidden = operation.value !== 'assignee';
                        assignee.required = operation.value === 'assignee'; }
                }
                if (submit) { submit.disabled = chosen === 0; }
            }
            checks.forEach(function(c) { c.addEventListener('change', update); });
            if (toggle) { toggle.addEventListener('change', function() {
                checks.forEach(function(c) { c.checked = toggle.checked; }); update();
            }); }
            if (operation) { operation.addEventListener('change', update); }
            update();
        });

        // 1852: scrolling belongs only to the table or Kanban viewport.
        // Hold the mouse over an arrow to scroll continuously; click still steps.
        document.querySelectorAll('[data-bms-task1848-shell]').forEach(function(shell) {
            var scroller = shell.querySelector('[data-bms-task1848-scroller]');
            if (!scroller) { return; }
            var left = shell.querySelector('[data-bms-task1848-scroll="left"]');
            var right = shell.querySelector('[data-bms-task1848-scroll="right"]');
            var frame = null;
            var lastFrameTime = 0;
            var hoverDirection = 0;
            var reducedMotion = window.matchMedia &&
                window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            function maxScroll() {
                return Math.max(0, scroller.scrollWidth - scroller.clientWidth);
            }
            function stopHover() {
                hoverDirection = 0;
                lastFrameTime = 0;
                if (frame !== null) {
                    window.cancelAnimationFrame(frame);
                    frame = null;
                }
                scroller.classList.remove('is-auto-scrolling');
            }
            function refresh() {
                var available = maxScroll();
                var position = Math.max(0, scroller.scrollLeft);
                shell.classList.toggle('is-scrollable', available > 8);
                if (left) {
                    left.hidden = available <= 8;
                    left.disabled = position <= 4;
                }
                if (right) {
                    right.hidden = available <= 8;
                    right.disabled = position >= available - 4;
                }
                if (hoverDirection !== 0 && (available <= 8 ||
                        (hoverDirection < 0 && position <= 4) ||
                        (hoverDirection > 0 && position >= available - 4))) {
                    stopHover();
                }
            }
            function tick(time) {
                frame = null;
                if (!hoverDirection || document.hidden) { stopHover(); return; }
                var elapsed = lastFrameTime ? Math.min(50, time - lastFrameTime) : 16;
                lastFrameTime = time;
                // 520px/s: predictable, independent of monitor refresh rate.
                scroller.scrollLeft += hoverDirection * 0.52 * elapsed;
                refresh();
                if (hoverDirection) { frame = window.requestAnimationFrame(tick); }
            }
            function startHover(direction) {
                if (reducedMotion || document.hidden || !window.requestAnimationFrame) { return; }
                var control = direction < 0 ? left : right;
                if (!control || control.hidden || control.disabled) { return; }
                stopHover();
                hoverDirection = direction;
                scroller.classList.add('is-auto-scrolling');
                frame = window.requestAnimationFrame(tick);
            }
            function nudge(direction) {
                var delta = Math.max(240, Math.round(scroller.clientWidth * 0.72));
                scroller.scrollBy({left: direction * delta,
                    behavior: hoverDirection ? 'auto' : 'smooth'});
            }
            [
                [left, -1],
                [right, 1]
            ].forEach(function(pair) {
                var control = pair[0];
                var direction = pair[1];
                if (!control) { return; }
                control.addEventListener('click', function() { nudge(direction); });
                control.addEventListener('mouseenter', function() { startHover(direction); });
                control.addEventListener('mouseleave', stopHover);
                control.addEventListener('blur', stopHover);
            });
            scroller.addEventListener('scroll', refresh, {passive:true});
            window.addEventListener('resize', refresh);
            window.addEventListener('blur', stopHover);
            window.addEventListener('pagehide', stopHover);
            document.addEventListener('visibilitychange', function() {
                if (document.hidden) { stopHover(); }
            });
            refresh();
        });
        document.querySelectorAll('form[data-bms-task-fast-search]').forEach(function(form) {
            var input = form.querySelector('input[name="q"]');
            if (!input) { return; }
            var timer = null;
            var composing = false;
            var initial = input.value;
            input.addEventListener('compositionstart', function() { composing = true; });
            input.addEventListener('compositionend', function() { composing = false; schedule(); });
            function schedule() {
                if (timer) { clearTimeout(timer); }
                if (composing || input.value === initial) { return; }
                timer = setTimeout(function() {
                    if (typeof form.requestSubmit === 'function') { form.requestSubmit(); }
                    else { form.submit(); }
                }, 650);
            }
            input.addEventListener('input', schedule);
            form.addEventListener('submit', function() { if (timer) { clearTimeout(timer); } });
        });
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
        // 1837: type-to-search existing tasks. The server remains the authority
        // for candidate visibility, revision and parent hierarchy validation.
        document.querySelectorAll('form[data-subtask-search-url]').forEach(function(searchForm) {
            var searchInput = searchForm.querySelector('#bms_subtask_search');
            var suggestions = searchForm.querySelector('#bms_subtask_suggestions');
            var status = searchForm.querySelector('#bms_subtask_search_status');
            var linkForm = document.querySelector('.bms-task-subtask-link-form');
            var select = linkForm && linkForm.querySelector('#tui_childchoice');
            var selected = linkForm && linkForm.querySelector('[data-subtask-selected]');
            var submit = linkForm && linkForm.querySelector('[data-subtask-submit]');
            if (!searchInput || !suggestions || !status || !linkForm || !select ||
                !selected || !submit || !window.fetch || !window.URL) { return; }
            var controller = null;
            var timer = null;
            var sequence = 0;
            var currentResults = [];
            var normalise = function(value) { return (value || '').trim(); };
            var message = function(name) { return searchForm.getAttribute('data-lookup-' + name) || ''; };
            var isEnhanced = function() { return searchForm.classList.contains('bms-task-subtask-enhanced'); };
            var toggleOpen = function(open) {
                suggestions.hidden = !open;
                searchInput.setAttribute('aria-expanded', open ? 'true' : 'false');
            };
            var clearSelected = function() {
                select.value = '';
                selected.textContent = '';
                selected.hidden = true;
                submit.disabled = true;
            };
            var selectTask = function(item) {
                sequence++;
                if (timer) { clearTimeout(timer); }
                if (controller) { controller.abort(); controller = null; }
                // Every selectable item is a signed-looking server candidate,
                // but is always revalidated on the canonical POST endpoint.
                select.textContent = '';
                select.add(new Option('', ''));
                select.add(new Option(item.title, item.value, true, true));
                select.value = item.value;
                searchInput.value = item.title;
                selected.textContent = message('selected') + item.title;
                selected.hidden = false;
                status.textContent = selected.textContent;
                toggleOpen(false);
                submit.disabled = false;
                searchInput.focus();
            };
            var render = function(items, more) {
                suggestions.textContent = '';
                currentResults = items;
                if (!items.length) {
                    status.textContent = message('empty');
                    toggleOpen(false);
                    return;
                }
                items.forEach(function(item, index) {
                    if (!item || typeof item.title !== 'string' || typeof item.value !== 'string' ||
                        !/^[a-f0-9-]{36}\|[0-9]+$/i.test(item.value)) { return; }
                    var button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'bms-task-subtask-suggestion';
                    button.setAttribute('role', 'option');
                    button.setAttribute('aria-selected', 'false');
                    button.id = 'bms_subtask_option_' + index;
                    var title = document.createElement('span');
                    title.className = 'bms-task-subtask-suggestion-title';
                    title.textContent = item.title;
                    var meta = document.createElement('small');
                    meta.className = 'bms-task-subtask-suggestion-meta';
                    meta.textContent = (item.due ? message('due') + item.due + ' · ' : '') +
                        (item.reference || '');
                    button.appendChild(title);
                    button.appendChild(meta);
                    button.addEventListener('click', function() { selectTask(item); });
                    button.addEventListener('keydown', function(event) {
                        var buttons = Array.prototype.slice.call(suggestions.querySelectorAll('button'));
                        var position = buttons.indexOf(button);
                        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                            event.preventDefault();
                            var next = position + (event.key === 'ArrowDown' ? 1 : -1);
                            if (buttons[next]) { buttons[next].focus(); }
                            else { searchInput.focus(); }
                        } else if (event.key === 'Escape') {
                            event.preventDefault();
                            toggleOpen(false);
                            searchInput.focus();
                        }
                    });
                    suggestions.appendChild(button);
                });
                status.textContent = more ? message('more') : '';
                toggleOpen(!!suggestions.children.length);
            };
            var search = function() {
                var query = normalise(searchInput.value);
                clearSelected();
                currentResults = [];
                suggestions.textContent = '';
                toggleOpen(false);
                sequence++;
                var id = sequence;
                if (controller) { controller.abort(); controller = null; }
                if (query.length < 2) { status.textContent = message('min'); return; }
                status.textContent = message('loading');
                timer = setTimeout(function() {
                    var url = new URL(searchForm.getAttribute('data-subtask-search-url'), window.location.href);
                    url.searchParams.set('q', query);
                    controller = window.AbortController ? new AbortController() : null;
                    var opts = {credentials:'same-origin', headers:{'Accept':'application/json'}};
                    if (controller) { opts.signal = controller.signal; }
                    fetch(url.toString(), opts)
                        .then(function(response) { if (!response.ok) { throw new Error('search'); } return response.json(); })
                        .then(function(data) {
                            if (id !== sequence || normalise(searchInput.value) !== query) { return; }
                            if (!Array.isArray(data.options)) { throw new Error('search'); }
                            render(data.options, !!data.more);
                        })
                        .catch(function(error) {
                            if (id !== sequence || error.name === 'AbortError') { return; }
                            status.textContent = message('error');
                            // Keep the original GET search + select control available if AJAX fails.
                            searchForm.classList.remove('bms-task-subtask-enhanced');
                            linkForm.classList.remove('bms-task-subtask-enhanced');
                            submit.disabled = false;
                        });
                }, 250);
            };
            searchForm.classList.add('bms-task-subtask-enhanced');
            linkForm.classList.add('bms-task-subtask-enhanced');
            submit.disabled = true;
            searchInput.addEventListener('input', function() {
                if (timer) { clearTimeout(timer); }
                if (!isEnhanced()) {
                    searchForm.classList.add('bms-task-subtask-enhanced');
                    linkForm.classList.add('bms-task-subtask-enhanced');
                }
                search();
            });
            searchInput.addEventListener('focus', function() {
                if (normalise(searchInput.value).length >= 2 && !select.value &&
                    suggestions.children.length) { toggleOpen(true); }
            });
            searchInput.addEventListener('keydown', function(event) {
                if (event.key === 'Escape') { toggleOpen(false); return; }
                if (event.key === 'ArrowDown' && !suggestions.hidden) {
                    var first = suggestions.querySelector('button');
                    if (first) { event.preventDefault(); first.focus(); }
                } else if (event.key === 'Enter') {
                    event.preventDefault();
                    var firstOption = suggestions.querySelector('button');
                    if (firstOption && !suggestions.hidden) { firstOption.click(); }
                    else if (select.value) { submit.focus(); }
                }
            });
            // GET fallback remains available when JS is disabled or AJAX breaks.
            searchForm.addEventListener('submit', function(event) {
                if (!isEnhanced()) { return; }
                event.preventDefault();
                if (suggestions.children.length && !suggestions.hidden) {
                    suggestions.querySelector('button').click();
                }
            });
            linkForm.addEventListener('submit', function(event) {
                if (isEnhanced() && !select.value) {
                    event.preventDefault();
                    status.textContent = message('min');
                    searchInput.focus();
                }
            });
            document.addEventListener('click', function(event) {
                if (!searchForm.contains(event.target)) { toggleOpen(false); }
            });
            if (normalise(searchInput.value).length >= 2) { search(); }
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
        // RC31 Pilot 02: task discussion composer — a selected file can be removed before sending.
        document.querySelectorAll('[data-bms-file-clear]').forEach(function(clear) {
            var file = document.getElementById(clear.getAttribute('data-bms-file-clear'));
            if (!file) { return; }
            var sync = function() { clear.hidden = !(file.files && file.files.length); };
            file.addEventListener('change', sync);
            clear.addEventListener('click', function() {
                file.value = '';
                sync();
                file.focus();
            });
            sync();
        });
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
}());
