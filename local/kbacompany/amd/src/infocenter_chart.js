/* BMS24 Information Centre drill-down chart (#190).
 *
 * Progressive enhancement over server-rendered tiles. Each tile carries its already company-scoped, read-only
 * series in data-bmsic-chart; nothing is fetched or written here.
 * - Fact line with real dots, broken at missing periods; plan line when a plan exists; dashed least-squares trend
 *   over the visible facts (3+ facts), drawn and labelled separately from fact and plan.
 * - Time window 13/26/52, zoom in/out and pan on the time axis, reset. Mouse wheel and drag, touch drag and pinch,
 *   buttons and keyboard (←/→ select date, Shift+←/→ pan, +/− zoom, 0 reset, Home/End).
 * - The value axis fits the visible range, keeps a real zero visible as a value and supports negative values; a
 *   flat series gets padding instead of a zero-height axis.
 */
define([], function() {
    'use strict';

    var NS = 'http://www.w3.org/2000/svg';
    var W = 1000, H = 420, ML = 76, MR = 24, MT = 20, MB = 56;
    var COLORS = {fact: '#0C6C52', plan: '#1777B5', trend: '#6B43B5', grid: '#e3ebe6', zero: '#0B2B41', gap: '#FDF6ED',
        text: '#40564a', select: '#0B2B41'};

    var S = {};
    var state = null;
    var opener = null;
    var nodes = {};
    var pointers = {};
    var drag = null;
    var pinch = null;

    function el(name, attrs, parent) {
        var node = document.createElementNS(NS, name);
        Object.keys(attrs || {}).forEach(function(key) { node.setAttribute(key, attrs[key]); });
        if (parent) { parent.appendChild(node); }
        return node;
    }

    function fmt(value) {
        if (value === null || value === undefined) { return ''; }
        var lang = document.documentElement.lang || undefined;
        try {
            return new Intl.NumberFormat(lang, {maximumFractionDigits: 2}).format(value);
        } catch (e) {
            return String(Math.round(value * 100) / 100);
        }
    }

    function withUnit(value) {
        return fmt(value) + (state && state.unit ? ' ' + state.unit : '');
    }

    /** Last n real periods, keeping gap markers inside the window (mirrors information_center_service::tail). */
    function tail(points, n) {
        var real = 0;
        for (var i = points.length - 1; i >= 0; i--) {
            if (!points[i].gap) { real++; }
            if (real === n) { return points.slice(i); }
        }
        return points.slice();
    }

    function setWindow(n) {
        state.windowN = n;
        state.points = tail(state.all, n);
        resetView();
    }

    function resetView() {
        state.start = 0;
        state.end = Math.max(0, state.points.length - 1);
        state.selected = state.points.length ? lastFact(state.start, state.end) : -1;
        render();
    }

    function lastFact(from, to) {
        for (var i = to; i >= from; i--) {
            if (!state.points[i].gap && state.points[i].fact !== null) { return i; }
        }
        return -1;
    }

    function span() { return state.end - state.start; }

    function clampView() {
        var max = state.points.length - 1;
        var width = Math.min(Math.max(span(), Math.min(2, max)), max);
        if (state.start < 0) { state.start = 0; }
        if (state.start + width > max) { state.start = Math.max(0, max - width); }
        state.end = state.start + width;
    }

    function zoom(factor, anchor) {
        var max = state.points.length - 1;
        if (max < 1) { return; }
        var current = span();
        var next = Math.round(current * factor);
        if (factor < 1 && next === current) { next = current - 1; }
        if (factor > 1 && next === current) { next = current + 1; }
        next = Math.max(Math.min(2, max), Math.min(max, next));
        var center = anchor === undefined ? (state.start + state.end) / 2 : anchor;
        var ratio = current === 0 ? 0.5 : (center - state.start) / current;
        state.start = Math.round(center - ratio * next);
        state.end = state.start + next;
        clampView();
        render();
    }

    function pan(delta) {
        if (!delta) { return; }
        var width = span();
        state.start += delta;
        state.end = state.start + width;
        clampView();
        render();
    }

    function select(index) {
        if (index < 0 || index >= state.points.length) { return; }
        state.selected = index;
        if (index < state.start) { pan(index - state.start); return; }
        if (index > state.end) { pan(index - state.end); return; }
        render();
    }

    function step(direction) {
        var i = state.selected < 0 ? (direction > 0 ? state.start - 1 : state.end + 1) : state.selected;
        do { i += direction; } while (i >= 0 && i < state.points.length && state.points[i].gap);
        select(i);
    }

    /** "Nice" ticks for the visible value range. */
    function ticks(low, high) {
        if (high === low) {
            var pad = low === 0 ? 1 : Math.abs(low) * 0.1;
            low -= pad; high += pad;
        }
        var raw = (high - low) / 5;
        var mag = Math.pow(10, Math.floor(Math.log(raw) / Math.LN10));
        var stepv = [1, 2, 2.5, 5, 10].map(function(m) { return m * mag; }).filter(function(v) { return v >= raw; })[0];
        var first = Math.floor(low / stepv) * stepv;
        var last = Math.ceil(high / stepv) * stepv;
        var out = [];
        for (var v = first; v <= last + stepv / 2; v += stepv) { out.push(Math.round(v / stepv) * stepv); }
        return out;
    }

    function trendLine(visible) {
        var xs = [], ys = [];
        visible.forEach(function(p) {
            if (!p.point.gap && p.point.fact !== null) { xs.push(p.index); ys.push(p.point.fact); }
        });
        if (xs.length < 3) { return null; }
        var n = xs.length, sx = 0, sy = 0, sxy = 0, sxx = 0;
        for (var i = 0; i < n; i++) { sx += xs[i]; sy += ys[i]; sxy += xs[i] * ys[i]; sxx += xs[i] * xs[i]; }
        var den = n * sxx - sx * sx;
        if (den === 0) { return null; }
        var slope = (n * sxy - sx * sy) / den;
        var icept = (sy - slope * sx) / n;
        return {x1: xs[0], y1: icept + slope * xs[0], x2: xs[n - 1], y2: icept + slope * xs[n - 1]};
    }

    function render() {
        var box = nodes.chart;
        box.innerHTML = '';
        var svg = el('svg', {viewBox: '0 0 ' + W + ' ' + H, role: 'img', 'aria-label': state.name}, box);
        var visible = [];
        for (var i = state.start; i <= state.end && i < state.points.length; i++) {
            visible.push({index: i, point: state.points[i]});
        }
        var facts = visible.filter(function(p) { return !p.point.gap && p.point.fact !== null; });
        nodes.visible.textContent = S.visible.replace('{$a}', String(visible.filter(function(p) {
            return !p.point.gap;
        }).length));
        // Machine-readable view state for keyboard/assistive QA and the browser acceptance (no data beyond the tile's).
        svg.setAttribute('data-span', String(span()));
        if (visible.length) {
            svg.setAttribute('data-start', visible[0].point.period);
            svg.setAttribute('data-end', visible[visible.length - 1].point.period);
        }
        if (!facts.length) {
            svg.setAttribute('data-empty', '1');
            el('text', {x: W / 2, y: H / 2, 'text-anchor': 'middle', fill: COLORS.text, 'font-size': 22}, svg)
                .textContent = S.nodata;
            nodes.readout.textContent = S.nodata;
            renderLegend(false, false, false);
            return;
        }
        var trend = trendLine(visible);
        var values = [];
        visible.forEach(function(p) {
            if (p.point.fact !== null) { values.push(p.point.fact); }
            if (p.point.plan !== null) { values.push(p.point.plan); }
        });
        if (trend) { values.push(trend.y1, trend.y2); }
        var yt = ticks(Math.min.apply(null, values), Math.max.apply(null, values));
        var lo = yt[0], hi = yt[yt.length - 1];
        svg.setAttribute('data-lo', String(lo));
        svg.setAttribute('data-hi', String(hi));
        var count = Math.max(1, state.end - state.start);
        var x = function(index) {
            return visible.length === 1 ? ML + (W - ML - MR) / 2 : ML + (index - state.start) * (W - ML - MR) / count;
        };
        var y = function(v) { return H - MB - (v - lo) * (H - MT - MB) / (hi - lo); };
        state.geometry = {x: x, count: count};

        // Gaps first, as background bands.
        visible.forEach(function(p) {
            if (p.point.gap) {
                var half = (W - ML - MR) / count / 2;
                el('rect', {'class': 'bmsic-gap', x: x(p.index) - half, y: MT, width: half * 2, height: H - MT - MB, fill: COLORS.gap,
                    stroke: '#e8d9c3', 'stroke-dasharray': '3 3'}, svg);
            }
        });
        // Value grid and axis.
        yt.forEach(function(v) {
            el('line', {x1: ML, x2: W - MR, y1: y(v), y2: y(v), stroke: v === 0 ? COLORS.zero : COLORS.grid,
                'stroke-width': v === 0 ? 1.5 : 1}, svg);
            el('text', {x: ML - 8, y: y(v) + 4, 'text-anchor': 'end', fill: COLORS.text, 'font-size': 13}, svg)
                .textContent = fmt(v);
        });
        el('text', {x: 14, y: MT + (H - MT - MB) / 2, fill: COLORS.text, 'font-size': 13, 'text-anchor': 'middle',
            transform: 'rotate(-90 14 ' + (MT + (H - MT - MB) / 2) + ')'}, svg)
            .textContent = state.unit ? S.axis_value + ', ' + state.unit : S.axis_value;
        // Period labels: at most 8, evenly spread, always first and last.
        var every = Math.max(1, Math.ceil(visible.length / 8));
        visible.forEach(function(p, k) {
            if (k % every !== 0 && k !== visible.length - 1) { return; }
            el('line', {x1: x(p.index), x2: x(p.index), y1: H - MB, y2: H - MB + 5, stroke: COLORS.text}, svg);
            el('text', {x: x(p.index), y: H - MB + 22, 'text-anchor': 'middle', fill: COLORS.text, 'font-size': 12}, svg)
                .textContent = p.point.period;
        });
        el('line', {x1: ML, x2: W - MR, y1: H - MB, y2: H - MB, stroke: COLORS.text}, svg);

        var hasPlan = false;
        var drawSeries = function(key, color, dash, width) {
            var current = [];
            var flush = function() {
                if (current.length > 1) {
                    var attrs = {'class': 'bmsic-' + key + 'line', points: current.join(' '), fill: 'none', stroke: color,
                        'stroke-width': width};
                    if (dash) { attrs['stroke-dasharray'] = dash; }
                    el('polyline', attrs, svg);
                }
                current = [];
            };
            visible.forEach(function(p) {
                if (p.point.gap || p.point[key] === null) { flush(); return; }
                current.push(x(p.index).toFixed(1) + ',' + y(p.point[key]).toFixed(1));
            });
            flush();
        };
        visible.forEach(function(p) { if (!p.point.gap && p.point.plan !== null) { hasPlan = true; } });
        if (hasPlan) {
            drawSeries('plan', COLORS.plan, '', 2);
            visible.forEach(function(p) {
                if (!p.point.gap && p.point.plan !== null) {
                    el('rect', {x: x(p.index) - 3.5, y: y(p.point.plan) - 3.5, width: 7, height: 7, fill: COLORS.plan}, svg);
                }
            });
        }
        if (trend) {
            el('line', {'class': 'bmsic-trendline', x1: x(trend.x1), y1: y(trend.y1), x2: x(trend.x2), y2: y(trend.y2), stroke: COLORS.trend,
                'stroke-width': 2, 'stroke-dasharray': '9 6'}, svg);
        }
        drawSeries('fact', COLORS.fact, '', 3);
        facts.forEach(function(p) {
            el('circle', {'class': 'bmsic-dot', cx: x(p.index), cy: y(p.point.fact), r: 4.5, fill: COLORS.fact}, svg);
        });
        // Selection.
        if (state.selected >= state.start && state.selected <= state.end && state.points[state.selected]) {
            var sp = state.points[state.selected];
            svg.setAttribute('data-selected', sp.period);
            el('line', {x1: x(state.selected), x2: x(state.selected), y1: MT, y2: H - MB, stroke: COLORS.select,
                'stroke-dasharray': '2 3'}, svg);
            if (!sp.gap && sp.fact !== null) {
                el('circle', {cx: x(state.selected), cy: y(sp.fact), r: 8, fill: 'none', stroke: COLORS.select,
                    'stroke-width': 2}, svg);
            }
        }
        renderReadout();
        renderLegend(hasPlan, !!trend, visible.some(function(p) { return p.point.gap; }));
        if (facts.length === 1) {
            nodes.readout.textContent += ' ' + S.single;
        }
    }

    function renderReadout() {
        var p = state.points[state.selected];
        if (!p) { nodes.readout.textContent = ''; return; }
        if (p.gap) { nodes.readout.textContent = p.period + ' — ' + S.legend_gap; return; }
        nodes.readout.textContent = p.period + ' — ' + S.legend_fact + ': ' +
            (p.fact === null ? S.nofact : withUnit(p.fact)) + ' · ' + S.legend_plan + ': ' +
            (p.plan === null ? S.noplan : withUnit(p.plan));
    }

    function swatch(kind) {
        var svg = el('svg', {width: 28, height: 12, viewBox: '0 0 28 12', 'aria-hidden': 'true'});
        if (kind === 'gap') {
            el('rect', {x: 1, y: 1, width: 26, height: 10, fill: COLORS.gap, stroke: '#e8d9c3', 'stroke-dasharray': '3 3'}, svg);
        } else {
            var attrs = {x1: 1, x2: 27, y1: 6, y2: 6, stroke: COLORS[kind], 'stroke-width': kind === 'fact' ? 3 : 2};
            if (kind === 'trend') { attrs['stroke-dasharray'] = '6 4'; }
            el('line', attrs, svg);
            if (kind === 'fact') { el('circle', {cx: 14, cy: 6, r: 3.5, fill: COLORS.fact}, svg); }
            if (kind === 'plan') { el('rect', {x: 11, y: 3, width: 6, height: 6, fill: COLORS.plan}, svg); }
        }
        return svg;
    }

    function renderLegend(plan, trend, gap) {
        var legend = nodes.legend;
        legend.innerHTML = '';
        var add = function(kind, text) {
            var item = document.createElement('span');
            item.appendChild(swatch(kind));
            item.appendChild(document.createTextNode(' ' + text));
            legend.appendChild(item);
        };
        add('fact', S.legend_fact);
        if (plan) { add('plan', S.legend_plan); }
        if (trend) { add('trend', S.legend_trend); }
        if (gap) { add('gap', S.legend_gap); }
    }

    /** Index under a client X coordinate (nearest visible period). */
    function indexAt(clientX) {
        var svg = nodes.chart.querySelector('svg');
        if (!svg || !state.geometry) { return -1; }
        var rect = svg.getBoundingClientRect();
        var vx = (clientX - rect.left) * W / rect.width;
        var raw = state.start + (vx - ML) * state.geometry.count / (W - ML - MR);
        return Math.max(state.start, Math.min(state.end, Math.round(raw)));
    }

    function unitsPerPixel() {
        var svg = nodes.chart.querySelector('svg');
        if (!svg || !state.geometry) { return 0; }
        return state.geometry.count / ((W - ML - MR) * svg.getBoundingClientRect().width / W);
    }

    function bindChart() {
        var box = nodes.chart;
        box.addEventListener('wheel', function(e) {
            if (!state) { return; }
            e.preventDefault();
            zoom(e.deltaY < 0 ? 0.75 : 1.35, indexAt(e.clientX));
        }, {passive: false});
        box.addEventListener('pointerdown', function(e) {
            if (!state) { return; }
            pointers[e.pointerId] = e.clientX;
            try { box.setPointerCapture(e.pointerId); } catch (err) { /* Pointer already released: drag still works. */ }
            var ids = Object.keys(pointers);
            if (ids.length === 2) {
                pinch = {distance: Math.abs(pointers[ids[0]] - pointers[ids[1]]) || 1, span: span()};
                drag = null;
            } else {
                drag = {x: e.clientX, start: state.start, moved: false};
            }
        });
        box.addEventListener('pointermove', function(e) {
            if (!state) { return; }
            if (pointers[e.pointerId] !== undefined) { pointers[e.pointerId] = e.clientX; }
            var ids = Object.keys(pointers);
            if (pinch && ids.length === 2) {
                var distance = Math.abs(pointers[ids[0]] - pointers[ids[1]]) || 1;
                var target = Math.round(pinch.span * pinch.distance / distance);
                if (target !== span()) { zoom(target / Math.max(1, span())); }
                return;
            }
            if (drag) {
                var delta = Math.round((drag.x - e.clientX) * unitsPerPixel());
                if (Math.abs(drag.x - e.clientX) > 4) { drag.moved = true; }
                if (drag.moved && drag.start + delta !== state.start) { pan(drag.start + delta - state.start); }
                return;
            }
            if (e.pointerType === 'mouse') {
                var i = indexAt(e.clientX);
                if (i >= 0 && i !== state.selected) { state.selected = i; render(); }
            }
        });
        var end = function(e) {
            if (drag && !drag.moved && state) { select(indexAt(e.clientX)); }
            delete pointers[e.pointerId];
            if (Object.keys(pointers).length < 2) { pinch = null; }
            drag = null;
        };
        box.addEventListener('pointerup', end);
        box.addEventListener('pointercancel', function(e) { delete pointers[e.pointerId]; pinch = null; drag = null; });
        box.addEventListener('keydown', function(e) {
            if (!state) { return; }
            var handled = true;
            if (e.key === 'ArrowLeft' && e.shiftKey) { pan(-Math.max(1, Math.round(span() / 4))); }
            else if (e.key === 'ArrowRight' && e.shiftKey) { pan(Math.max(1, Math.round(span() / 4))); }
            else if (e.key === 'ArrowLeft') { step(-1); }
            else if (e.key === 'ArrowRight') { step(1); }
            else if (e.key === '+' || e.key === '=') { zoom(0.75, state.selected >= 0 ? state.selected : undefined); }
            else if (e.key === '-' || e.key === '_') { zoom(1.35, state.selected >= 0 ? state.selected : undefined); }
            else if (e.key === '0') { resetView(); }
            else if (e.key === 'Home') { select(0); }
            else if (e.key === 'End') { select(state.points.length - 1); }
            else { handled = false; }
            if (handled) { e.preventDefault(); }
        });
    }

    function open(tile) {
        var data;
        try { data = JSON.parse(tile.getAttribute('data-bmsic-chart')); } catch (e) { return; }
        opener = tile;
        state = {name: data.name, unit: data.unit || '', all: data.points || [], windowN: 13};
        nodes.title.textContent = data.name;
        nodes.meta.textContent = (data.unit ? S.unit.replace('{$a}', data.unit) + ' · ' : '') + data.trend;
        nodes.window.value = '13';
        setWindow(13);
        if (typeof nodes.dialog.showModal === 'function') {
            nodes.dialog.showModal();
        } else {
            nodes.dialog.setAttribute('open', 'open');
        }
        nodes.chart.focus();
    }

    function close() {
        if (typeof nodes.dialog.close === 'function') { nodes.dialog.close(); } else { nodes.dialog.removeAttribute('open'); }
    }

    return {
        init: function(strings) {
            S = strings || {};
            var dialog = document.getElementById('bmsic-dialog');
            if (!dialog) { return; }
            nodes.dialog = dialog;
            nodes.title = document.getElementById('bmsic-dlg-title');
            ['meta', 'close', 'window', 'zoomin', 'zoomout', 'panleft', 'panright', 'reset', 'visible', 'chart',
                'readout', 'legend'].forEach(function(key) {
                nodes[key] = dialog.querySelector('[data-bmsic="' + key + '"]');
            });
            bindChart();
            nodes.close.addEventListener('click', close);
            nodes.window.addEventListener('change', function() { setWindow(parseInt(nodes.window.value, 10) || 13); });
            nodes.zoomin.addEventListener('click', function() { zoom(0.75); });
            nodes.zoomout.addEventListener('click', function() { zoom(1.35); });
            nodes.panleft.addEventListener('click', function() { pan(-Math.max(1, Math.round(span() / 4))); });
            nodes.panright.addEventListener('click', function() { pan(Math.max(1, Math.round(span() / 4))); });
            nodes.reset.addEventListener('click', resetView);
            dialog.addEventListener('close', function() {
                state = null;
                if (opener) { opener.focus(); }
            });
            document.addEventListener('click', function(e) {
                var tile = e.target.closest ? e.target.closest('[data-bmsic-chart]') : null;
                if (tile) { open(tile); }
            });
        }
    };
});
