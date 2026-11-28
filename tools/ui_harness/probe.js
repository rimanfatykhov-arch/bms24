// BMS24 UI harness geometry probe (#160, task B). Measures what the browser draws, not what is in the DOM.
// Same measurements as the BROWSER producer for #152 (communication_ux.py GEOMETRY_JS), plus the harness extras.
// Exposes window.BMS24_PROBE(); returns plain JSON. Read-only: no clicks, no requests.
window.BMS24_PROBE = function () {
    const root = document.querySelector('#bms24-comm-root') || document.querySelector('.bms24-comm');
    const vis = (e) => !!e && e.checkVisibility() && e.getClientRects().length > 0;
    const box = (e) => e.getBoundingClientRect();
    const out = {
        root: !!root,
        root_id: !!document.querySelector('#bms24-comm-root'),
        width: innerWidth,
        overflow: document.documentElement.scrollWidth > innerWidth + 1,
    };
    if (!root) {
        return out;
    }
    out.css = getComputedStyle(root).getPropertyValue('--cc-emerald').trim() !== '';
    out.missing_stylesheets = [...document.querySelectorAll('link[rel="stylesheet"]')]
        .filter((link) => !link.sheet).map((link) => link.getAttribute('href'));
    const triggers = [...document.querySelectorAll('summary.bms-comm2-new-trigger')].filter(vis);
    const head = root.querySelector('.bms24-cardheader');
    const sections = root.querySelector('.bms-comm-ux-sections');
    const t = triggers.length ? triggers[0] : null;
    if (t && head && sections) {
        const b = box(t), h = box(head), tabs = box(sections);
        const details = t.closest('details');
        const form = details && details.querySelector('form[method=post]');
        out.action = {
            count: triggers.length,
            label: t.innerText.replace(/\s+/g, ' ').trim(),
            width: Math.round(b.width), height: Math.round(b.height),
            scroll_width: t.scrollWidth, client_width: t.clientWidth,
            in_header: head.contains(t) && b.left >= h.left - 0.5 && b.right <= h.right + 0.5
                && b.top >= h.top - 0.5 && b.bottom <= h.bottom + 0.5,
            overlaps_tabs: !(b.right <= tabs.left || b.left >= tabs.right || b.bottom <= tabs.top || b.top >= tabs.bottom),
            in_form: !!form && !!form.querySelector('input[name=sesskey]'),
        };
    } else {
        out.action = null;
    }
    const controls = root.querySelectorAll('.bms-comm-ux-sections a, .bms-comm-ux-sections a > span, '
        + '.bms-comm-ux-filters a, .bms24-comm-ntools a, .bms24-comm-btn, summary.bms-comm2-new-trigger');
    out.clipped = [...controls].filter((e) => vis(e) && e.clientWidth > 1 && e.scrollWidth > e.clientWidth + 1)
        .map((e) => e.innerText.replace(/\s+/g, ' ').trim().slice(0, 40));
    const s = document.querySelector('#bms_comm2_search');
    out.search = s && vis(s) ? {
        width: Math.round(box(s.closest('form')).width),
        side_width: Math.round(box(s.closest('.bms-comm2-sidebar')).width),
        input_width: Math.round(box(s).width),
    } : null;
    // Harness extras: elements wider than the viewport (names the culprit of an overflow) and tiny tap targets.
    out.wide = [...root.querySelectorAll('*')].filter((e) => vis(e) && box(e).right > innerWidth + 1)
        .slice(0, 5).map((e) => e.tagName.toLowerCase() + '.' + [...e.classList].slice(0, 2).join('.'));
    out.small_targets = [...root.querySelectorAll('a[href], button, summary, input:not([type=hidden]), select, textarea')]
        .filter((e) => vis(e) && e.clientWidth > 1 && (box(e).width < 24 || box(e).height < 24))
        .slice(0, 8).map((e) => (e.getAttribute('aria-label') || e.innerText || e.tagName).replace(/\s+/g, ' ').trim().slice(0, 30));
    return out;
};
