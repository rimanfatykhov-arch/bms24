/* RC29/1804 progressive enhancement. Server validates and persists every change. */
(function () {
    'use strict';
    function setup() {
        var list = document.getElementById('bms-widget-settings-list');
        if (!list) { return; }
        function sync() {
            Array.prototype.forEach.call(list.children, function (li, i) {
                var select = li.querySelector('.bms-widget-position');
                if (select) { select.value = String(i + 1); }
            });
        }
        list.addEventListener('click', function (e) {
            var button = e.target.closest('button');
            if (!button) { return; }
            var li = button.closest('li');
            if (!li || li.parentNode !== list) { return; }
            if (button.classList.contains('bms-widget-move-up') && li.previousElementSibling) {
                list.insertBefore(li, li.previousElementSibling);
            } else if (button.classList.contains('bms-widget-move-down') && li.nextElementSibling) {
                list.insertBefore(li.nextElementSibling, li);
            } else { return; }
            sync();
            button.focus();
        });
        // Native selects remain fully functional without JavaScript.
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setup);
    } else { setup(); }
}());
