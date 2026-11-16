/* Records "user opened the task" once the task card is shown in an active, visible tab.
   The marker is rendered only after the card itself rendered successfully. One POST per page
   load, never retried; the server deduplicates (15 min per user + task) and re-checks access.
   A hidden or prerendered tab waits until it becomes visible. Failure never affects the card. */
(function() {
    'use strict';
    function init() {
        var marker = document.querySelector('[data-bms-task-open]');
        if (!marker || !window.fetch || !window.FormData) { return; }
        var sent = false;
        function send() {
            if (sent || document.visibilityState !== 'visible' || document.prerendering) { return; }
            sent = true;
            document.removeEventListener('visibilitychange', send);
            var body = new FormData();
            body.append('sesskey', marker.getAttribute('data-sesskey') || '');
            body.append('uuid', marker.getAttribute('data-company') || '');
            body.append('task', marker.getAttribute('data-task') || '');
            try {
                window.fetch(marker.getAttribute('data-endpoint'), {
                    method: 'POST', body: body, credentials: 'same-origin', redirect: 'error', cache: 'no-store'
                }).then(function(response) {
                    // Consume the body so the request completes (an unread stream stays open in the browser).
                    return response.text().then(function() {
                        marker.setAttribute('data-bms-task-open-status', String(response.status));
                    });
                }).then(null, function() {
                    marker.setAttribute('data-bms-task-open-status', 'failed');
                });
            } catch (e) {
                marker.setAttribute('data-bms-task-open-status', 'failed');
            }
        }
        document.addEventListener('visibilitychange', send);
        if (document.prerendering) { document.addEventListener('prerenderingchange', send, {once: true}); }
        send();
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
})();
