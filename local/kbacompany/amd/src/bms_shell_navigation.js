define([], function() {
    'use strict';
    var storageKey = 'bms24_sidebar_compact_v1';
    function init() {
        var shell = document.querySelector('.bms24-app, .kba-bms');
        if (!shell || shell.getAttribute('data-bms-shell-nav-init') === '1') { return; }
        var owned = shell.classList.contains('bms24-app');
        var sidebar = shell.querySelector(owned ? '.bms24-sidebar' : '.kba-bms-sidebar');
        if (!sidebar) { return; }
        // Theme shell topology is not owned by the plugin. Do not alter unrelated grid containers.
        // Only support a KBA sidebar that is a DIRECT shell child; otherwise leave theme unchanged.
        if (!owned && sidebar.parentElement !== shell) { return; }
        var style = window.getComputedStyle(shell);
        if (!owned && style.display !== 'grid' && style.display !== 'flex') { return; }
        shell.setAttribute('data-bms-shell-nav-init', '1');
        var links = Array.prototype.slice.call(sidebar.querySelectorAll('nav a, .bms24-navitem, .nav-link'));
        links.forEach(function(a) {
            var label = (a.getAttribute('aria-label') || a.textContent || '').replace(/\s+/g, ' ').trim();
            if (label) { a.setAttribute('title', label); a.setAttribute('aria-label', label); }
        });
        var toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'bms-shell-nav-toggle';
        toggle.setAttribute('aria-controls', 'bms-shell-navigation');
        toggle.innerHTML = '<span aria-hidden="true">☰</span>';
        sidebar.id = sidebar.id || 'bms-shell-navigation';
        toggle.setAttribute('aria-controls', sidebar.id);
        // The shared heading keeps the navigation toggle and the BMS24 brand on one row.
        // KBA Theme may supply a different brand class; do not move unrelated page nodes.
        var brand = sidebar.querySelector(owned ? '.bms24-brand' :
            '.kba-bms-sidebar__brand, .kba-bms-brand, .kba-bms-sidebar-brand, [data-kba-bms-brand]');
        var header = document.createElement('div');
        header.className = 'bms-shell-nav-header';
        sidebar.insertBefore(header, sidebar.firstChild);
        header.appendChild(toggle);
        if (brand) { brand.classList.add('bms-shell-nav-brand'); header.appendChild(brand); }
        function apply(compact) {
            shell.classList.toggle('bms-shell-nav-compact', compact);
            toggle.setAttribute('aria-expanded', compact ? 'false' : 'true');
            toggle.setAttribute('aria-label', compact ? 'Развернуть меню' : 'Свернуть меню');
            toggle.title = compact ? 'Развернуть меню' : 'Свернуть меню';
            // Only adjust a known, direct-child sidebar. Never set a 0px column.
            if (!owned && sidebar.parentElement === shell) {
                sidebar.style.width = compact ? '76px' : '';
                sidebar.style.minWidth = compact ? '76px' : '';
                sidebar.style.maxWidth = compact ? '76px' : '';
                // Restore inline grid/flex values on expand, do not assume original columns.
                if (!shell.hasAttribute('data-bms-layout-original')) {
                    shell.dataset.bmsLayoutOriginal = shell.style.gridTemplateColumns || '';
                }
                if (style.display === 'grid') {
                    shell.style.gridTemplateColumns = compact ? '76px minmax(0, 1fr)' : shell.dataset.bmsLayoutOriginal;
                }
            }
            Array.prototype.forEach.call(sidebar.querySelectorAll('details'), function(group) {
                if (compact) {
                    group.dataset.bmsPriorOpen = group.open ? '1' : '0';
                    group.open = true;
                } else if (group.hasAttribute('data-bms-prior-open')) {
                    group.open = group.dataset.bmsPriorOpen === '1';
                    delete group.dataset.bmsPriorOpen;
                }
            });
        }
        var initial = false;
        try { initial = window.localStorage.getItem(storageKey) === '1'; } catch (e) { /* private mode */ }
        apply(initial);
        toggle.addEventListener('click', function() {
            var compact = !shell.classList.contains('bms-shell-nav-compact');
            apply(compact);
            try { window.localStorage.setItem(storageKey, compact ? '1' : '0'); } catch (e) { /* private mode */ }
        });
    }
    return {init: init};
});
