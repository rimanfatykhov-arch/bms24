/* BMS Visual BPMN 0.9.4: bpmn-js is a layout editor over the canonical structural model. */
(function() {
    'use strict';
    var VERSION = '18.30.1';
    var BASE = 'https://unpkg.com/bpmn-js@' + VERSION + '/dist/';
    function style(url) {
        if (document.querySelector('link[data-bms-bpmn-css="' + url + '"]')) { return; }
        var link = document.createElement('link');
        link.rel = 'stylesheet'; link.href = url; link.dataset.bmsBpmnCss = url;
        document.head.appendChild(link);
    }
    function library() {
        if (window.BpmnJS) { return Promise.resolve(window.BpmnJS); }
        if (window.__bmsBpmnLoader) { return window.__bmsBpmnLoader; }
        style(BASE + 'assets/bpmn-js.css');
        style(BASE + 'assets/diagram-js.css');
        window.__bmsBpmnLoader = new Promise(function(resolve, reject) {
            var script = document.createElement('script');
            script.src = BASE + 'bpmn-modeler.production.min.js';
            script.async = true;
            script.onload = function() { window.BpmnJS ? resolve(window.BpmnJS) : reject(new Error('BpmnJS global missing')); };
            script.onerror = function() { reject(new Error('bpmn-js load failed')); };
            document.head.appendChild(script);
        });
        return window.__bmsBpmnLoader;
    }
    function download(name, xml) {
        var blob = new Blob([xml], {type: 'application/xml;charset=utf-8'});
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url; a.download = name; document.body.appendChild(a); a.click(); a.remove();
        setTimeout(function() { URL.revokeObjectURL(url); }, 1000);
    }
    function initCanvas(canvas) {
        var shell = canvas.closest('.pru-bpmn-shell');
        var source = shell && shell.querySelector('[data-bpmn-source]');
        var status = shell && shell.querySelector('[data-bpmn-status]');
        var form = canvas.closest('form[data-bpmn-form]');
        var submitXml = form && form.querySelector('[data-bpmn-submit]');
        var save = form && form.querySelector('[data-bpmn-save]');
        if (!source || !status) { return; }
        status.textContent = status.dataset.loading || status.textContent;
        library().then(function(Bpmn) {
            var modeler = new Bpmn({container: canvas, keyboard: {bindTo: null}});
            canvas._bmsBpmn = modeler;
            return modeler.importXML(source.value).then(function(result) {
                try { modeler.get('canvas').zoom('fit-viewport'); } catch (ignore) {}
                status.textContent = result && result.warnings && result.warnings.length ? (status.dataset.warning || 'BPMN warnings') : (status.dataset.ready || 'Ready');
                if (save) { save.disabled = false; }
                var bus = modeler.get('eventBus');
                if (form && bus) {
                    bus.on('commandStack.changed', function() {
                        form.dataset.bpmnChanged = '1';
                        submitXml.dispatchEvent(new Event('input', {bubbles: true}));
                    });
                }
            }).then(function() { return modeler; });
        }).then(function(modeler) {
            var fit = shell.querySelector('[data-bpmn-fit]');
            var dl = shell.querySelector('[data-bpmn-download]');
            var importer = form && form.querySelector('[data-bpmn-import]');
            if (importer && window.FileReader) {
                importer.addEventListener('change', function() {
                    var file = importer.files && importer.files[0];
                    if (!file) { return; }
                    if (file.size > 3000000) { status.textContent = status.dataset.error || 'BPMN file too large'; importer.value = ''; return; }
                    var reader = new FileReader();
                    reader.onload = function() {
                        var xml = String(reader.result || '');
                        modeler.importXML(xml).then(function(result) {
                            source.value = xml; submitXml.value = xml; form.dataset.bpmnChanged = '1';
                            submitXml.dispatchEvent(new Event('input', {bubbles: true}));
                            try { modeler.get('canvas').zoom('fit-viewport'); } catch (ignore) {}
                            status.textContent = result && result.warnings && result.warnings.length ? (status.dataset.warning || 'BPMN warnings') : (status.dataset.ready || 'Ready');
                            if (save) { save.disabled = false; }
                        }).catch(function() { status.textContent = status.dataset.error || 'Invalid BPMN XML'; });
                    };
                    reader.readAsText(file);
                });
            }
            if (fit) { fit.addEventListener('click', function() { try { modeler.get('canvas').zoom('fit-viewport'); } catch (ignore) {} }); }
            if (dl) { dl.addEventListener('click', function() {
                modeler.saveXML({format: true}).then(function(r) { download('process.bpmn', r.xml); });
            }); }
            if (form) {
                var saving = false;
                form.addEventListener('submit', function(e) {
                    e.preventDefault(); e.stopImmediatePropagation();
                    if (saving) { return; }
                    saving = true; form.setAttribute('aria-busy', 'true');
                    status.textContent = status.dataset.saving || 'Saving…';
                    modeler.saveXML({format: true}).then(function(r) {
                        submitXml.value = r.xml; source.value = r.xml;
                        HTMLFormElement.prototype.submit.call(form);
                    }).catch(function() {
                        saving = false; form.removeAttribute('aria-busy');
                        status.textContent = status.dataset.error || 'Unable to export BPMN XML';
                    });
                }, true);
            }
        }).catch(function() {
            status.textContent = status.dataset.error || 'BPMN editor unavailable';
            canvas.classList.add('is-unavailable');
            if (save) { save.disabled = true; }
        });
    }
    function init() {
        document.querySelectorAll('[data-bpmn-canvas]').forEach(function(canvas) {
            if (canvas.dataset.bpmnReady) { return; }
            canvas.dataset.bpmnReady = '1'; initCanvas(canvas);
        });
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
}());
