/* BMS24 Information Centre charts on Apache ECharts (#190, #192).
 *
 * Progressive enhancement over server-rendered tiles. Each tile carries its already company-scoped, read-only
 * series (data-bmsic-chart, and data-bmsic-mini for the mini chart); nothing is fetched or written here. One local
 * ECharts module (local_kbacompany/echarts-lazy, loaded once per page) draws both.
 * - Mini charts: initialised when the tile comes near the viewport (IntersectionObserver, immediate fallback), real
 *   dots, line broken at missing periods, no interaction inside the tile (the tile button opens the large chart),
 *   resized with their column.
 * - Large chart: fact with real dots, broken at missing periods (connectNulls false); plan when a plan exists; dashed
 *   least-squares trend over the visible facts (3+ facts) as a separate series. Window 13/26/52; inside data zoom
 *   (mouse wheel, drag, touch pinch and drag) plus buttons and keyboard (←/→ select date, Shift+←/→ pan, +/− zoom,
 *   0 reset, Home/End). The value axis fits the visible range, keeps a real zero visible as a value and supports
 *   negative values; a flat series gets padding. The instance is disposed when the dialog closes.
 * - Machine-readable view state (data-* on the chart container and on each mini chart) for keyboard/assistive QA and
 *   the browser acceptance; it carries nothing beyond the tile's own data.
 */
define(['local_kbacompany/echarts-lazy'], function(echarts) {
    'use strict';

    var COLORS = {fact: '#0C6C52', plan: '#1777B5', trend: '#6B43B5', grid: '#e3ebe6', minigrid: '#dfe9e3', zero: '#0B2B41',
        gap: '#FDF6ED', gapline: '#e8d9c3', text: '#40564a', select: '#0B2B41'};
    var NS = 'http://www.w3.org/2000/svg';
    var GRID = {right: 20, top: 16, bottom: 34};

    var S = {};
    var state = null;
    var opener = null;
    var nodes = {};
    var chart = null;
    var layout = null;
    var pointers = {};
    var gesture = null;
    var clickStart = null;
    var minis = [];
    var touchEvents = typeof window !== 'undefined' && 'ontouchstart' in window;

    function fmt(value) {
        if (value === null || value === undefined) { return ''; }
        if (Math.abs(value) < 1e-9) { value = 0; }
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

    function esc(text) {
        return String(text).replace(/[&<>"']/g, function(c) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
        });
    }

    function isFact(p) { return !!p && !p.gap && p.fact !== null && p.fact !== undefined; }
    function isPlan(p) { return !!p && !p.gap && p.plan !== null && p.plan !== undefined; }

    /** Number of drawn line pieces: runs of 2+ consecutive values (a missing value breaks the line). */
    function runs(values) {
        var count = 0, run = 0;
        values.forEach(function(v) {
            if (v === null) { if (run > 1) { count++; } run = 0; } else { run++; }
        });
        return count + (run > 1 ? 1 : 0);
    }

    /** "Nice" ticks for a value range; a flat range gets padding instead of a zero-height axis. */
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
        return {lo: out[0], hi: out[out.length - 1], step: stepv, count: out.length};
    }

    // ---------------------------------------------------------------- Mini charts.

    function miniOption(points) {
        var values = points.filter(isFact).map(function(p) { return p.fact; });
        if (!values.length) { return null; }
        var low = Math.min.apply(null, values), high = Math.max.apply(null, values);
        if (high === low) {
            var pad = low === 0 ? 1 : Math.abs(low) * 0.1;
            low -= pad; high += pad;
        }
        var data = points.map(function(p) { return isFact(p) ? p.fact : null; });
        return {
            option: {
                animation: false,
                grid: {left: 8, right: 8, top: 8, bottom: 8},
                xAxis: {type: 'category', show: false, boundaryGap: points.length === 1,
                    data: points.map(function(p) { return p.period; })},
                yAxis: {type: 'value', min: low, max: high, interval: (high - low) / 2, axisLabel: {show: false},
                    axisLine: {show: false}, axisTick: {show: false},
                    splitLine: {show: true, lineStyle: {color: COLORS.minigrid, width: 1}}},
                series: [{id: 'fact', type: 'line', data: data, connectNulls: false, showSymbol: true, showAllSymbol: true,
                    symbol: 'circle', symbolSize: 6, clip: false, silent: true, animation: false,
                    lineStyle: {color: COLORS.fact, width: 2}, itemStyle: {color: COLORS.fact}}]
            },
            dots: values.length, lines: runs(data), gaps: points.filter(function(p) { return p.gap; }).length, grid: 3
        };
    }

    function initMini(el) {
        if (el.getAttribute('data-engine')) { return; }
        var points;
        try { points = JSON.parse(el.getAttribute('data-bmsic-mini')) || []; } catch (e) { return; }
        var built = miniOption(points);
        if (!built) { return; }
        var instance = echarts.init(el, null, {renderer: 'svg'});
        instance.setOption(built.option);
        minis.push(instance);
        el.setAttribute('data-engine', 'echarts');
        el.setAttribute('data-dots', String(built.dots));
        el.setAttribute('data-lines', String(built.lines));
        el.setAttribute('data-gaps', String(built.gaps));
        el.setAttribute('data-grid', String(built.grid));
        observeSize(el, function() { instance.resize(); });
    }

    function initMinis() {
        var els = Array.prototype.slice.call(document.querySelectorAll('[data-bmsic-mini]'));
        if (typeof window.IntersectionObserver !== 'function') {
            els.forEach(initMini);
            return;
        }
        var observer = new window.IntersectionObserver(function(entries) {
            entries.forEach(function(entry) {
                if (entry.isIntersecting) {
                    observer.unobserve(entry.target);
                    initMini(entry.target);
                }
            });
        }, {rootMargin: '100px 0px'});
        els.forEach(function(el) { observer.observe(el); });
    }

    /** Resize callback for an element: ResizeObserver when available, window resize otherwise. */
    function observeSize(el, callback) {
        if (typeof window.ResizeObserver === 'function') {
            var last = el.clientWidth + 'x' + el.clientHeight;
            new window.ResizeObserver(function() {
                var size = el.clientWidth + 'x' + el.clientHeight;
                if (size !== last) { last = size; callback(); }
            }).observe(el);
        } else {
            window.addEventListener('resize', callback);
        }
    }

    // ---------------------------------------------------------------- Large chart: view state.

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
            if (isFact(state.points[i])) { return i; }
        }
        return -1;
    }

    function span() { return state.end - state.start; }

    function minSpan() { return Math.min(2, Math.max(0, state.points.length - 1)); }

    function clampView() {
        var max = state.points.length - 1;
        var width = Math.min(Math.max(span(), minSpan()), Math.max(0, max));
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
        next = Math.max(minSpan(), Math.min(max, next));
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

    function trendLine(from, to) {
        var xs = [], ys = [];
        for (var i = from; i <= to; i++) {
            if (isFact(state.points[i])) { xs.push(i); ys.push(state.points[i].fact); }
        }
        if (xs.length < 3) { return null; }
        var n = xs.length, sx = 0, sy = 0, sxy = 0, sxx = 0;
        for (var k = 0; k < n; k++) { sx += xs[k]; sy += ys[k]; sxy += xs[k] * ys[k]; sxx += xs[k] * xs[k]; }
        var den = n * sxx - sx * sx;
        if (den === 0) { return null; }
        var slope = (n * sxy - sx * sy) / den;
        return {from: xs[0], to: xs[n - 1], slope: slope, icept: (sy - slope * sx) / n};
    }

    // ---------------------------------------------------------------- Large chart: drawing.

    function setAttrs(values) {
        Object.keys(values).forEach(function(key) {
            if (values[key] === null || values[key] === undefined) {
                nodes.chart.removeAttribute('data-' + key);
            } else {
                nodes.chart.setAttribute('data-' + key, String(values[key]));
            }
        });
    }

    function showEmpty(empty) {
        nodes.empty.hidden = !empty;
        nodes.canvas.style.visibility = empty ? 'hidden' : '';
    }

    function ensureChart() {
        if (chart) { return chart; }
        chart = echarts.init(nodes.canvas, null, {renderer: 'svg'});
        chart.on('datazoom', onDataZoom);
        var zr = chart.getZr();
        zr.on('mousedown', function(e) { clickStart = {x: e.offsetX, y: e.offsetY}; });
        zr.on('click', function(e) {
            var moved = !clickStart || Math.abs(clickStart.x - e.offsetX) > 4 || Math.abs(clickStart.y - e.offsetY) > 4;
            clickStart = null;
            if (!state || moved || !chart.containPixel('grid', [e.offsetX, e.offsetY])) { return; }
            var index = indexAt(e.offsetX);
            if (index >= 0) { select(index); }
        });
        return chart;
    }

    function disposeChart() {
        if (chart) {
            chart.dispose();
            chart = null;
        }
        layout = null;
        pointers = {};
        gesture = null;
    }

    /** Native inside data zoom (wheel, drag, pinch) moved the window: adopt it and redraw the dependent parts. */
    function onDataZoom() {
        if (!state || !chart) { return; }
        var option = chart.getOption();
        var dz = option.dataZoom && option.dataZoom[0];
        if (!dz) { return; }
        var max = state.points.length - 1;
        var start = typeof dz.startValue === 'number' ? dz.startValue : Math.round((dz.start || 0) * max / 100);
        var end = typeof dz.endValue === 'number' ? dz.endValue : Math.round((dz.end === undefined ? 100 : dz.end) * max / 100);
        start = Math.max(0, Math.min(max, Math.round(start)));
        end = Math.max(start, Math.min(max, Math.round(end)));
        if (start === state.start && end === state.end) { return; }
        state.start = start;
        state.end = end;
        render();
    }

    function labelInterval(start, end, width) {
        var count = end - start + 1;
        var max = Math.max(2, Math.floor(width / 96));
        var every = Math.max(1, Math.ceil(count / max));
        return function(index) {
            if (index < start || index > end) { return false; }
            if (index === start || index === end) { return true; }
            return (index - start) % every === 0 && end - index >= Math.ceil(every * 0.75);
        };
    }

    function render() {
        var pts = state.points;
        var visible = [];
        for (var i = state.start; i <= state.end && i < pts.length; i++) { visible.push(i); }
        var real = visible.filter(function(k) { return !pts[k].gap; }).length;
        nodes.visible.textContent = S.visible.replace('{$a}', String(real));
        var factsVisible = visible.filter(function(k) { return isFact(pts[k]); });
        var selected = state.selected >= state.start && state.selected <= state.end && pts[state.selected] ?
            pts[state.selected].period : null;
        if (!factsVisible.length) {
            setAttrs({engine: 'echarts', start: visible.length ? pts[visible[0]].period : null,
                end: visible.length ? pts[visible[visible.length - 1]].period : null, span: span(), selected: null,
                lo: null, hi: null, empty: '1', gaps: 0, factlines: 0, planlines: 0, trendlines: 0, dots: 0,
                window: state.windowN});
            showEmpty(true);
            nodes.readout.textContent = S.nodata;
            renderLegend(false, false, false);
            if (chart) { chart.clear(); }
            return;
        }
        showEmpty(false);
        var instance = ensureChart();
        var trend = trendLine(state.start, state.end);
        var hasPlan = visible.some(function(k) { return isPlan(pts[k]); });
        var values = [];
        visible.forEach(function(k) {
            if (isFact(pts[k])) { values.push(pts[k].fact); }
            if (isPlan(pts[k])) { values.push(pts[k].plan); }
        });
        if (trend) { values.push(trend.icept + trend.slope * trend.from, trend.icept + trend.slope * trend.to); }
        var axis = ticks(Math.min.apply(null, values), Math.max.apply(null, values));

        var factData = pts.map(function(p, k) {
            if (!isFact(p)) { return null; }
            return k === state.selected ? {value: p.fact, symbolSize: 13,
                itemStyle: {color: COLORS.fact, borderColor: COLORS.select, borderWidth: 3}} : p.fact;
        });
        var planData = pts.map(function(p) { return isPlan(p) ? p.plan : null; });
        var trendData = pts.map(function(p, k) {
            return trend && k >= trend.from && k <= trend.to ? trend.icept + trend.slope * k : null;
        });
        var gapAreas = [];
        pts.forEach(function(p, k) {
            if (p.gap && k > 0 && k < pts.length - 1) {
                gapAreas.push([{xAxis: pts[k - 1].period}, {xAxis: pts[k + 1].period}]);
            }
        });
        var gapsVisible = visible.filter(function(k) { return pts[k].gap; }).length;

        // Left margin fits the widest value label plus the rotated axis name.
        var widest = 0;
        for (var t = 0; t < axis.count; t++) { widest = Math.max(widest, fmt(axis.lo + t * axis.step).length); }
        var left = 34 + widest * 7.5;
        var width = instance.getWidth();
        layout = {left: left, width: Math.max(1, width - left - GRID.right)};
        var unitSuffix = state.unit ? ', ' + state.unit : '';

        var markLines = selected ? [{xAxis: selected}] : [];
        if (axis.lo < 0 && axis.hi > 0) {
            // A real zero inside the range is emphasised as a value line.
            markLines.push({yAxis: 0, lineStyle: {color: COLORS.zero, type: 'solid', width: 1.5}});
        }
        var series = [{
            id: 'fact', name: S.legend_fact, type: 'line', data: factData, connectNulls: false, showSymbol: true,
            showAllSymbol: true, symbol: 'circle', symbolSize: 8, z: 4, clip: false, lineStyle: {color: COLORS.fact, width: 3},
            itemStyle: {color: COLORS.fact}, emphasis: {disabled: true},
            markLine: {silent: true, symbol: 'none', animation: false, label: {show: false},
                lineStyle: {color: COLORS.select, type: 'dotted', width: 1.5},
                data: markLines},
            markArea: {silent: true, animation: false, label: {show: false},
                itemStyle: {color: COLORS.gap, borderColor: COLORS.gapline, borderWidth: 1, borderType: 'dashed'},
                data: gapAreas}
        }, {
            id: 'plan', name: S.legend_plan, type: 'line', data: planData, connectNulls: false, showSymbol: true,
            showAllSymbol: true, symbol: 'rect', symbolSize: 7, z: 3, clip: false, lineStyle: {color: COLORS.plan, width: 2},
            itemStyle: {color: COLORS.plan}, emphasis: {disabled: true}
        }, {
            id: 'trend', name: S.legend_trend, type: 'line', data: trendData, connectNulls: false, showSymbol: false,
            z: 2, silent: true, lineStyle: {color: COLORS.trend, width: 2, type: 'dashed'}, emphasis: {disabled: true},
            tooltip: {show: false}
        }];
        instance.setOption({
            animation: false,
            grid: {left: left, right: GRID.right, top: GRID.top, bottom: GRID.bottom},
            tooltip: {trigger: 'axis', confine: true, axisPointer: {type: 'line', lineStyle: {color: COLORS.select}},
                formatter: tooltip},
            xAxis: {type: 'category', boundaryGap: pts.length === 1, data: pts.map(function(p) { return p.period; }),
                axisLine: {lineStyle: {color: COLORS.text}}, axisTick: {alignWithLabel: true},
                axisLabel: {color: COLORS.text, fontSize: 12, hideOverlap: false,
                    interval: labelInterval(state.start, state.end, layout.width)}},
            yAxis: {type: 'value', min: axis.lo, max: axis.hi, interval: axis.step,
                name: S.axis_value + unitSuffix, nameLocation: 'middle', nameGap: left - 20, nameRotate: 90,
                nameTextStyle: {color: COLORS.text, fontSize: 13},
                axisLabel: {color: COLORS.text, fontSize: 12, formatter: function(v) { return fmt(v); }},
                splitLine: {lineStyle: {color: COLORS.grid}}},
            dataZoom: [{type: 'inside', id: 'window', xAxisIndex: 0, filterMode: 'filter', startValue: state.start,
                endValue: state.end, minValueSpan: minSpan(), zoomOnMouseWheel: true, moveOnMouseMove: true,
                moveOnMouseWheel: false, preventDefaultMouseMove: true, throttle: 0}],
            series: series
        }, {replaceMerge: ['series']});
        setAttrs({engine: 'echarts', start: pts[visible[0]].period, end: pts[visible[visible.length - 1]].period,
            span: span(), selected: selected, lo: axis.lo, hi: axis.hi, empty: null, gaps: gapsVisible,
            factlines: runs(visible.map(function(k) { return isFact(pts[k]) ? 1 : null; })),
            planlines: runs(visible.map(function(k) { return isPlan(pts[k]) ? 1 : null; })),
            trendlines: trend ? 1 : 0, dots: factsVisible.length, window: state.windowN});
        renderReadout();
        renderLegend(hasPlan, !!trend, gapsVisible > 0);
        if (factsVisible.length === 1) {
            nodes.readout.textContent += ' ' + S.single;
        }
    }

    function tooltip(params) {
        var list = Array.isArray(params) ? params : [params];
        if (!list.length || !state) { return ''; }
        var p = state.points[list[0].dataIndex];
        if (!p) { return ''; }
        if (p.gap) { return esc(p.period + ' — ' + S.legend_gap); }
        return esc(p.period) + '<br>' + esc(S.legend_fact + ': ' + (isFact(p) ? withUnit(p.fact) : S.nofact)) +
            '<br>' + esc(S.legend_plan + ': ' + (isPlan(p) ? withUnit(p.plan) : S.noplan));
    }

    function renderReadout() {
        var p = state.points[state.selected];
        if (!p) { nodes.readout.textContent = ''; return; }
        if (p.gap) { nodes.readout.textContent = p.period + ' — ' + S.legend_gap; return; }
        nodes.readout.textContent = p.period + ' — ' + S.legend_fact + ': ' +
            (isFact(p) ? withUnit(p.fact) : S.nofact) + ' · ' + S.legend_plan + ': ' +
            (isPlan(p) ? withUnit(p.plan) : S.noplan);
    }

    function svgEl(name, attrs, parent) {
        var node = document.createElementNS(NS, name);
        Object.keys(attrs || {}).forEach(function(key) { node.setAttribute(key, attrs[key]); });
        if (parent) { parent.appendChild(node); }
        return node;
    }

    function swatch(kind) {
        var svg = svgEl('svg', {width: 28, height: 12, viewBox: '0 0 28 12', 'aria-hidden': 'true'});
        if (kind === 'gap') {
            svgEl('rect', {x: 1, y: 1, width: 26, height: 10, fill: COLORS.gap, stroke: COLORS.gapline, 'stroke-dasharray': '3 3'}, svg);
        } else {
            var attrs = {x1: 1, x2: 27, y1: 6, y2: 6, stroke: COLORS[kind], 'stroke-width': kind === 'fact' ? 3 : 2};
            if (kind === 'trend') { attrs['stroke-dasharray'] = '6 4'; }
            svgEl('line', attrs, svg);
            if (kind === 'fact') { svgEl('circle', {cx: 14, cy: 6, r: 3.5, fill: COLORS.fact}, svg); }
            if (kind === 'plan') { svgEl('rect', {x: 11, y: 3, width: 6, height: 6, fill: COLORS.plan}, svg); }
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

    /** Nearest visible period under an x offset inside the chart. */
    function indexAt(offsetX) {
        if (!chart || !layout) { return -1; }
        var raw = chart.convertFromPixel({xAxisIndex: 0}, offsetX);
        if (typeof raw !== 'number' || isNaN(raw)) { return -1; }
        return Math.max(state.start, Math.min(state.end, Math.round(raw)));
    }

    function unitsPerPixel() {
        return layout ? Math.max(1, span()) / layout.width : 0;
    }

    /**
     * Touch and pen pointers where the browser has no touch events (e.g. desktop Firefox on a touch screen): ECharts
     * listens to touch events only, so one-pointer drag pans and two-pointer pinch zooms here. Where touch events
     * exist, ECharts' inside data zoom handles drag and pinch itself and this stays idle.
     */
    function bindPointerFallback() {
        var box = nodes.chart;
        var tracked = function(e) { return !touchEvents && state && chart && e.pointerType !== 'mouse'; };
        box.addEventListener('pointerdown', function(e) {
            if (!tracked(e)) { return; }
            pointers[e.pointerId] = e.clientX;
            try { box.setPointerCapture(e.pointerId); } catch (err) { /* Pointer already released: gesture still works. */ }
            var ids = Object.keys(pointers);
            gesture = ids.length === 2 ?
                {pinch: true, distance: Math.abs(pointers[ids[0]] - pointers[ids[1]]) || 1, span: span()} :
                {pinch: false, x: e.clientX, start: state.start, moved: false};
        });
        box.addEventListener('pointermove', function(e) {
            if (!tracked(e) || pointers[e.pointerId] === undefined || !gesture) { return; }
            pointers[e.pointerId] = e.clientX;
            var ids = Object.keys(pointers);
            if (gesture.pinch && ids.length === 2) {
                var distance = Math.abs(pointers[ids[0]] - pointers[ids[1]]) || 1;
                var target = Math.round(gesture.span * gesture.distance / distance);
                if (target !== span()) { zoom(target / Math.max(1, span())); }
            } else if (!gesture.pinch) {
                var delta = Math.round((gesture.x - e.clientX) * unitsPerPixel());
                if (Math.abs(gesture.x - e.clientX) > 4) { gesture.moved = true; }
                if (gesture.moved && gesture.start + delta !== state.start) { pan(gesture.start + delta - state.start); }
            }
        });
        var end = function(e) {
            delete pointers[e.pointerId];
            if (!Object.keys(pointers).length) { gesture = null; } else if (gesture && gesture.pinch) { gesture = null; }
        };
        box.addEventListener('pointerup', end);
        box.addEventListener('pointercancel', end);
    }

    function bindKeyboard() {
        nodes.chart.addEventListener('keydown', function(e) {
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
        nodes.canvas.setAttribute('aria-label', data.name);
        if (typeof nodes.dialog.showModal === 'function') {
            nodes.dialog.showModal();
        } else {
            nodes.dialog.setAttribute('open', 'open');
        }
        // The dialog is laid out now, so the chart gets its real size.
        setWindow(13);
        nodes.chart.focus();
    }

    function close() {
        if (typeof nodes.dialog.close === 'function') { nodes.dialog.close(); } else { nodes.dialog.removeAttribute('open'); }
    }

    function onResize() {
        if (chart && state) {
            chart.resize();
            render();
        }
    }

    return {
        init: function(strings) {
            S = strings || {};
            initMinis();
            var dialog = document.getElementById('bmsic-dialog');
            if (!dialog) { return; }
            nodes.dialog = dialog;
            nodes.title = document.getElementById('bmsic-dlg-title');
            ['meta', 'close', 'window', 'zoomin', 'zoomout', 'panleft', 'panright', 'reset', 'visible', 'chart',
                'readout', 'legend'].forEach(function(key) {
                nodes[key] = dialog.querySelector('[data-bmsic="' + key + '"]');
            });
            nodes.canvas = document.createElement('div');
            nodes.canvas.className = 'bmsic-canvas';
            nodes.canvas.setAttribute('role', 'img');
            nodes.chart.appendChild(nodes.canvas);
            nodes.empty = document.createElement('div');
            nodes.empty.className = 'bmsic-empty';
            nodes.empty.hidden = true;
            nodes.empty.textContent = S.nodata;
            nodes.chart.appendChild(nodes.empty);
            bindKeyboard();
            bindPointerFallback();
            observeSize(nodes.chart, onResize);
            nodes.close.addEventListener('click', close);
            nodes.window.addEventListener('change', function() { setWindow(parseInt(nodes.window.value, 10) || 13); });
            nodes.zoomin.addEventListener('click', function() { zoom(0.75); });
            nodes.zoomout.addEventListener('click', function() { zoom(1.35); });
            nodes.panleft.addEventListener('click', function() { pan(-Math.max(1, Math.round(span() / 4))); });
            nodes.panright.addEventListener('click', function() { pan(Math.max(1, Math.round(span() / 4))); });
            nodes.reset.addEventListener('click', resetView);
            dialog.addEventListener('close', function() {
                state = null;
                disposeChart();
                showEmpty(false);
                setAttrs({engine: null, start: null, end: null, span: null, selected: null, lo: null, hi: null, empty: null,
                    gaps: null, factlines: null, planlines: null, trendlines: null, dots: null, window: null});
                if (opener) { opener.focus(); }
            });
            document.addEventListener('click', function(e) {
                var tile = e.target.closest ? e.target.closest('[data-bmsic-chart]') : null;
                if (tile) { open(tile); }
            });
        }
    };
});
