/* Synthetic function check for amd/src/notification_bell.js (no Moodle, no browser): late claim answers.
 *
 *   node tools/ci/browser/notification_bell_race_harness.js [path/to/notification_bell.js]
 *
 * Runs the real AMD module against a minimal fake DOM, fetch and Audio, and controls when each claim answer arrives.
 * Prints one JSON line per scenario and exits 1 if any scenario fails. This is evidence about the module's logic only;
 * the real-browser proof is notification_bell.py step "late_claim" and the audible check is manual.
 */
'use strict';
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const file = process.argv[2] || path.join(__dirname, '../../../local/kbacompany/amd/src/notification_bell.js');
const source = fs.readFileSync(file, 'utf8');

function el(name) {
    const e = {name, dataset: {}, hidden: false, children: [], listeners: {}, attrs: {}, style: {}, textContent: '',
        className: ''};
    e.setAttribute = (k, v) => { e.attrs[k] = String(v); };
    e.getAttribute = (k) => (k in e.attrs ? e.attrs[k] : null);
    e.addEventListener = (t, f) => { (e.listeners[t] = e.listeners[t] || []).push(f); };
    e.appendChild = (c) => { e.children.push(c); return c; };
    e.removeChild = (c) => { e.children.splice(e.children.indexOf(c), 1); };
    Object.defineProperty(e, 'firstChild', {get: () => e.children[0] || null});
    e.focus = () => {};
    e.contains = () => true;
    e.getBoundingClientRect = () => ({left: 0, right: 40, top: 0, bottom: 40});
    e.click = () => (e.listeners.click || []).forEach((f) => f({target: e}));
    return e;
}

function scenario(name, steps) {
    const root = el('root');
    Object.assign(root.dataset, {user: '5', company: 'A', endpoint: '/feed', sound: '0'});
    const parts = {};
    for (const sel of ['toggle', 'badge', 'panel', 'list', 'count', 'retry', 'sound-enable', 'sound-test', 'sound-disable']) {
        parts[sel] = el(sel);
    }
    root.querySelector = (sel) => {
        const m = /data-bms24-bell-([a-z-]+)\]/.exec(sel);
        return m ? parts[m[1]] : null;
    };
    root.querySelectorAll = () => [];
    const timers = [];
    const pending = [];
    const plays = {n: 0};
    let feed = {items: [], sound: false, now: 1000};
    let soundStored = false;
    const document = {hidden: false, listeners: {}, querySelector: () => root, createElement: el,
        addEventListener: (t, f) => { (document.listeners[t] = document.listeners[t] || []).push(f); }};
    const window = {
        setTimeout: (f) => { timers.push(f); return timers.length; }, clearTimeout: () => {},
        addEventListener: () => {}, innerWidth: 1440, innerHeight: 900, M: {cfg: {sesskey: 'k'}},
    };
    function respond(body) {
        return Promise.resolve({ok: true, status: 200, json: () => Promise.resolve(body)});
    }
    const fetch = (url, opts) => {
        if (!opts || !opts.method) {
            return respond({ok: true, user: 5, company: 'A', unread: feed.items.length, label: 'x',
                items: feed.items, sound: soundStored, now: feed.now});
        }
        const action = opts.body.get('action');
        if (action === 'sound') {
            soundStored = opts.body.get('enabled') === '1';
            return respond({ok: true, user: 5, sound: soundStored});
        }
        // Claim: answered by the test (the server would have said play=true when it was sent).
        return new Promise((resolve) => pending.push(() => resolve({ok: true, status: 200,
            json: () => Promise.resolve({ok: true, user: 5, play: true})})));
    };
    class Audio { play() { plays.n++; return Promise.resolve(); } }
    let factory;
    const ctx = {define: (deps, f) => { factory = f; }, window, document, fetch, Audio, navigator: {onLine: true},
        URLSearchParams, Promise, Math, Date, String, parseInt, ArrayBuffer, DataView, Uint8Array, btoa: (s) =>
            Buffer.from(s, 'binary').toString('base64'), Blob: class {}, URL: {createObjectURL: () => 'blob:x'}};
    ctx.window.document = document;
    vm.createContext(ctx);
    vm.runInContext(source, ctx);
    factory().init();
    const flush = () => new Promise((r) => setImmediate(r)).then(() => new Promise((r) => setImmediate(r)));
    const api = {
        root, parts, plays, pending,
        setFeed(items, now) { feed = {items, now}; },
        storeSound(v) { soundStored = v; },
        async tick() { const f = timers.shift(); if (f) { f(); } await flush(); await flush(); },
        async click(part) { parts[part].click(); await flush(); await flush(); },
        async answerClaims() { while (pending.length) { pending.shift()(); } await flush(); await flush(); await flush(); },
        flush,
    };
    return steps(api).then((res) => ({name, ...res}));
}

const item = (id, created) => ({id, read: false, created, type: 't', title: id, subtitle: '', time: '', url: '/x', linklabel: ''});

(async () => {
    const results = [];
    // Baseline with sound on (stored), then a new notification whose claim answer is held.
    async function start(t) {
        t.storeSound(true);
        t.setFeed([item('n0', 900)], 1000);
        await t.flush(); await t.flush(); await t.flush();
        t.setFeed([item('n1', 1001), item('n0', 900)], 1002);
        await t.tick();
        return t.pending.length;
    }
    results.push(await scenario('late claim after turning sound off', async (t) => {
        const sent = await start(t);
        await t.click('sound-disable');
        const before = t.plays.n;
        await t.answerClaims();
        return {claim_sent: sent, automatic_plays_after_late_answer: t.plays.n - before, expect: 0};
    }));
    results.push(await scenario('late claim after turning sound off and on again', async (t) => {
        const sent = await start(t);
        await t.click('sound-disable');
        await t.click('sound-enable');           // confirmation tone: one play() by the button itself
        const before = t.plays.n;
        await t.answerClaims();
        return {claim_sent: sent, automatic_plays_after_late_answer: t.plays.n - before, expect: 0};
    }));
    results.push(await scenario('normal new notification with sound on', async (t) => {
        const sent = await start(t);
        const before = t.plays.n;
        await t.answerClaims();
        return {claim_sent: sent, automatic_plays_after_late_answer: t.plays.n - before, expect: 1};
    }));
    let failed = 0;
    for (const r of results) {
        r.status = r.claim_sent === 1 && r.automatic_plays_after_late_answer === r.expect ? 'PASS' : 'FAIL';
        failed += r.status === 'FAIL';
        console.log(JSON.stringify(r));
    }
    process.exit(failed ? 1 : 0);
})();
