/* ADR-0009 R1: daily report form helpers. Presentation only; the server keeps the draft, revision and request key.
 * - warns before leaving the page with unsaved changes;
 * - marks the submit buttons busy after the first click (the server is idempotent anyway). */
(function() {
    'use strict';
    var form = document.querySelector('form[data-dailyreport-form]');
    if (!form) {
        return;
    }
    var initial = new FormData(form);
    var serialise = function(data) {
        var parts = [];
        data.forEach(function(value, key) {
            if (key !== 'sesskey' && key !== 'requestkey') {
                parts.push(key + '=' + value);
            }
        });
        return parts.sort().join('&');
    };
    var start = serialise(initial);
    var leaving = false;
    window.addEventListener('beforeunload', function(event) {
        if (!leaving && serialise(new FormData(form)) !== start) {
            event.preventDefault();
            event.returnValue = '';
        }
    });
    form.addEventListener('submit', function() {
        leaving = true;
        window.setTimeout(function() {
            form.querySelectorAll('button[type=submit]').forEach(function(button) {
                button.setAttribute('aria-disabled', 'true');
                button.classList.add('disabled');
            });
        }, 0);
    });
})();
