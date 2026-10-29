define([], function() {
    'use strict';

    function wrapTables(root) {
        Array.prototype.forEach.call(root.querySelectorAll('.generaltable, table.table'), function(table) {
            if (table.closest('.bms-table-scroll')) {
                return;
            }
            var wrapper = document.createElement('div');
            wrapper.className = 'bms-table-scroll';
            wrapper.setAttribute('tabindex', '0');
            wrapper.setAttribute('role', 'region');
            wrapper.setAttribute('aria-label', table.getAttribute('aria-label') || 'Scrollable table');
            table.parentNode.insertBefore(wrapper, table);
            wrapper.appendChild(table);
        });
    }

    function normaliseForms(root) {
        Array.prototype.forEach.call(root.querySelectorAll('.bms-record-editview, .kbacompany-form'), function(form) {
            form.classList.add('bms-ux-form');
        });
        Array.prototype.forEach.call(root.querySelectorAll('.bms-record-editview .card, .kbacompany-form .card'), function(card) {
            card.classList.add('bms-ux-card');
        });
    }

    function normaliseActions(root) {
        Array.prototype.forEach.call(root.querySelectorAll('.bms-workspace-header-actions, .bms-workspace-actionbar-main'), function(bar) {
            bar.setAttribute('data-bms-actions', '1');
        });
    }

    function init() {
        var root = document.querySelector('.kba-bms');
        if (!root || root.getAttribute('data-bms-ux-stabilized') === '1') {
            return;
        }
        root.setAttribute('data-bms-ux-stabilized', '1');
        wrapTables(root);
        normaliseForms(root);
        normaliseActions(root);
    }

    return {init: init};
});
