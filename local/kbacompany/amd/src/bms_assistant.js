/**
 * Unified proposal-only BMS Assistant drawer.
 * @module local_kbacompany/bms_assistant
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/str'], function(Str) {
    'use strict';

    var operations = ['explain', 'interview', 'draft', 'review', 'evidence_check'];
    var keys = ['title', 'loading', 'use', 'unavailable', 'mode', 'run', 'close', 'questions',
        'issues', 'warnings', 'evidence', 'rationale', 'emptyproposal', 'applied', 'changed',
        'requestfailed', 'invalidresponse', 'proposalnotice', 'mode_explain', 'mode_interview',
        'mode_draft', 'mode_review', 'mode_evidence_check', 'mockwarning', 'confirmreplace'];

    function node(tag, text, cls) {
        var el = document.createElement(tag);
        if (text !== undefined) { el.textContent = text; }
        if (cls) { el.className = cls; }
        return el;
    }

    function addList(container, title, items) {
        if (!Array.isArray(items) || !items.length) { return; }
        container.appendChild(node('h4', title, 'h6 mt-3'));
        var list = node('ul', undefined, 'bms-ai-drawer-list');
        items.forEach(function(item) {
            if (typeof item === 'string' && item) { list.appendChild(node('li', item)); }
        });
        container.appendChild(list);
    }

    function init(config) {
        var panel = document.getElementById('bms-ai-panel');
        var buttons = Array.prototype.slice.call(document.querySelectorAll('.bms-ai-help'));
        if (!panel || panel.dataset.initialised) { return Promise.resolve(); }
        panel.dataset.initialised = '1';
        document.body.appendChild(panel);

        var backdrop = node('div', undefined, 'bms-ai-drawer-backdrop d-none');
        backdrop.setAttribute('aria-hidden', 'true');
        document.body.appendChild(backdrop);

        if (!config.available) {
            buttons.forEach(function(button) { button.disabled = true; });
            return Promise.resolve();
        }

        return Str.get_strings(keys.map(function(key) {
            return {key: 'bmsai_' + key, component: 'local_kbacompany'};
        })).then(function(values) {
            var text = {};
            keys.forEach(function(key, index) { text[key] = values[index]; });
            var sequence = 0;
            var controller = null;
            var activeButton = null;

            function closeDrawer() {
                sequence++;
                if (controller) { controller.abort(); controller = null; }
                panel.classList.add('d-none');
                backdrop.classList.add('d-none');
                document.body.classList.remove('bms-ai-drawer-open');
                if (activeButton) { activeButton.focus(); }
                activeButton = null;
            }

            backdrop.addEventListener('click', closeDrawer);
            document.addEventListener('keydown', function(event) {
                if (event.key === 'Escape' && !panel.classList.contains('d-none')) { closeDrawer(); }
            });

            buttons.forEach(function(button) {
                button.disabled = config.fields.indexOf(button.dataset.field) === -1;
                if (button.disabled) { return; }
                button.addEventListener('click', function() {
                    var target = document.getElementById(button.dataset.target);
                    if (!target) { return; }
                    sequence++;
                    if (controller) { controller.abort(); controller = null; }
                    activeButton = button;
                    panel.textContent = '';
                    panel.classList.remove('d-none');
                    backdrop.classList.remove('d-none');
                    document.body.classList.add('bms-ai-drawer-open');

                    var header = node('div', undefined, 'bms-ai-drawer-header');
                    var heading = node('div');
                    heading.appendChild(node('strong', '✨ ' + text.title, 'bms-ai-drawer-title'));
                    var fieldLabel = button.closest('.bms-ai-field') ? button.closest('.bms-ai-field').querySelector('label') : null;
                    var readableLabel = button.dataset.label || (fieldLabel && fieldLabel.textContent.trim()) || '';
                    if (readableLabel) {
                        heading.appendChild(node('div', readableLabel, 'bms-ai-drawer-context'));
                    }
                    header.appendChild(heading);
                    var close = node('button', '×', 'bms-ai-drawer-close');
                    close.type = 'button';
                    close.setAttribute('aria-label', text.close);
                    header.appendChild(close);
                    panel.appendChild(header);

                    var body = node('div', undefined, 'bms-ai-drawer-body');
                    body.appendChild(node('p', text.proposalnotice, 'small text-muted'));
                    body.appendChild(node('label', text.mode, 'font-weight-bold'));
                    var select = node('select', undefined, 'form-control mb-2');
                    operations.forEach(function(operation) {
                        var option = node('option', text['mode_' + operation]);
                        option.value = operation;
                        select.appendChild(option);
                    });
                    select.value = (button.dataset.field === 'evidencesummary' || button.dataset.field === 'businessevidence') ?
                        'evidence_check' : 'draft';
                    body.appendChild(select);
                    if (config.testmode) { body.appendChild(node('p', text.mockwarning, 'alert alert-warning py-2')); }
                    var run = node('button', text.run, 'btn btn-primary mb-3');
                    run.type = 'button';
                    body.appendChild(run);
                    var result = node('div', undefined, 'bms-ai-drawer-result');
                    result.setAttribute('aria-live', 'polite');
                    body.appendChild(result);
                    panel.appendChild(body);
                    close.addEventListener('click', closeDrawer);
                    select.focus();

                    run.addEventListener('click', function() {
                        var requestId = ++sequence;
                        var operation = select.value;
                        var originalValue = target.value || '';
                        var expectedRecord = button.dataset.record || config.recorduuid || '';
                        var expectedRevision = Number(button.dataset.revision || config.revision || 0);
                        var section = button.dataset.section || config.section || '';
                        result.textContent = text.loading;
                        run.disabled = true;
                        select.disabled = true;
                        buttons.forEach(function(b) { b.disabled = true; });
                        controller = new AbortController();
                        var active = controller;
                        var timeout = setTimeout(function() { active.abort(); }, 90000);
                        var params = new URLSearchParams({
                            sesskey: M.cfg.sesskey,
                            uuid: config.companyuuid,
                            domain: config.domain,
                            field: button.dataset.field,
                            operation: operation,
                            record: expectedRecord,
                            revision: String(expectedRevision),
                            section: section,
                            draftvalue: originalValue
                        });
                        fetch(config.endpoint, {
                            method: 'POST', credentials: 'same-origin',
                            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                            body: params, signal: active.signal
                        }).then(function(response) { return response.json(); }).then(function(json) {
                            if (requestId !== sequence) { return; }
                            if (!json.ok) { throw new Error(typeof json.error === 'string' ? json.error : text.requestfailed); }
                            var p = json.proposal;
                            if (!p || p.company_uuid !== config.companyuuid || p.domain !== config.domain ||
                                    p.record_uuid !== expectedRecord || Number(p.record_revision) !== expectedRevision ||
                                    p.field !== button.dataset.field || p.operation !== operation ||
                                    p.write_policy !== 'proposal_only' || typeof p.suggested_value !== 'string') {
                                throw new Error(text.invalidresponse);
                            }
                            result.textContent = '';
                            var proposal = node('div', p.suggested_value || text.emptyproposal, 'bms-ai-drawer-proposal');
                            result.appendChild(proposal);
                            if (typeof p.rationale === 'string' && p.rationale) {
                                result.appendChild(node('h4', text.rationale, 'h6 mt-3'));
                                result.appendChild(node('p', p.rationale));
                            }
                            addList(result, text.questions, p.questions);
                            addList(result, text.issues, p.issues);
                            addList(result, text.warnings, p.warnings);
                            addList(result, text.evidence, p.evidence_suggestions);
                            if (operation === 'draft' && p.can_apply === true && p.suggested_value.trim() !== '') {
                                var apply = node('button', text.use, 'btn btn-primary mt-3');
                                apply.type = 'button';
                                var note = node('p', '', 'small mt-2');
                                result.appendChild(apply);
                                result.appendChild(note);
                                apply.addEventListener('click', function() {
                                    if ((target.value || '') !== originalValue) {
                                        apply.disabled = true; note.textContent = text.changed; return;
                                    }
                                    if (originalValue.trim() !== '' && originalValue !== p.suggested_value &&
                                            !window.confirm(text.confirmreplace)) { return; }
                                    target.value = p.suggested_value;
                                    target.dispatchEvent(new Event('input', {bubbles: true}));
                                    target.dispatchEvent(new Event('change', {bubbles: true}));
                                    apply.disabled = true;
                                    note.textContent = text.applied;
                                });
                            }
                        }).catch(function(error) {
                            if (requestId === sequence) {
                                result.textContent = error && error.message ? error.message : text.requestfailed;
                            }
                        }).then(function() {
                            clearTimeout(timeout);
                            if (controller === active) { controller = null; }
                            if (requestId === sequence) {
                                run.disabled = false;
                                select.disabled = false;
                                buttons.forEach(function(b) { b.disabled = config.fields.indexOf(b.dataset.field) === -1; });
                            }
                        });
                    });
                });
            });
        });
    }

    return {init: init};
});
