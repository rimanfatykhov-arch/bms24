/* BMS24 native onboarding, no route or authorization logic on the client. */
(function () {
    'use strict';
    function openIntro() {
        var dialog = document.getElementById('bms-main-intro');
        if (dialog && typeof dialog.showModal === 'function' && !dialog.open) {
            dialog.showModal();
        }
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', openIntro);
    } else {
        openIntro();
    }
}());
