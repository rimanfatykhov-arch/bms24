'use strict';
// Unit control for the bounded Task Center host-viewport fallback.
// Real geometry remains the responsibility of test_task_menu_overlay_browser.py.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../../local/kbacompany/js/task_ui.js'), 'utf8');
function run(existing) {
    const metas = existing ? [existing] : [];
    const document = {
        head: {
            querySelector: () => metas[0] || null,
            appendChild: element => metas.push(element)
        },
        createElement: tag => ({tagName: tag}),
        querySelector: () => null,
        querySelectorAll: () => [],
        addEventListener: () => {},
        readyState: 'complete'
    };
    const sandbox = {document, window: {location: {hash: ''}, addEventListener: () => {}}};
    vm.runInNewContext(source, sandbox);
    vm.runInNewContext(source, sandbox);
    return metas;
}
const inserted = run(null);
assert.equal(inserted.length, 1, 'fallback must not duplicate meta on reinitialization');
assert.equal(inserted[0].name, 'viewport');
assert.equal(inserted[0].content, 'width=device-width, initial-scale=1');
assert.equal(/maximum-scale|user-scalable/.test(inserted[0].content), false, 'zoom must not be restricted');
const existing = {name: 'viewport', content: 'width=device-width, initial-scale=1, viewport-fit=cover'};
const preserved = run(existing);
assert.equal(preserved.length, 1);
assert.equal(preserved[0], existing, 'host viewport element must not be replaced');
assert.equal(preserved[0].content, 'width=device-width, initial-scale=1, viewport-fit=cover');
console.log('TASK_VIEWPORT_FALLBACK_PASS: missing, existing, repeat-init, zoom');
