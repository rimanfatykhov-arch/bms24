define([], function() {
    'use strict';

    function init() {
        var form = document.querySelector('.bms-calendar-move-form');
        if (!form) {
            return;
        }
        var dragged = null;
        Array.prototype.forEach.call(document.querySelectorAll('[data-calendar-draggable="1"]'), function(card) {
            card.addEventListener('dragstart', function(event) {
                dragged = card;
                card.classList.add('is-dragging');
                if (event.dataTransfer) {
                    event.dataTransfer.effectAllowed = 'move';
                    event.dataTransfer.setData('text/plain', card.getAttribute('data-calendar-event-id') || '');
                }
            });
            card.addEventListener('dragend', function() {
                card.classList.remove('is-dragging');
                dragged = null;
                Array.prototype.forEach.call(document.querySelectorAll('[data-calendar-drop-date]'), function(day) {
                    day.classList.remove('is-drop-target');
                });
            });
        });

        Array.prototype.forEach.call(document.querySelectorAll('[data-calendar-drop-date]'), function(day) {
            day.addEventListener('dragover', function(event) {
                if (!dragged) {
                    return;
                }
                event.preventDefault();
                day.classList.add('is-drop-target');
                if (event.dataTransfer) {
                    event.dataTransfer.dropEffect = 'move';
                }
            });
            day.addEventListener('dragleave', function() {
                day.classList.remove('is-drop-target');
            });
            day.addEventListener('drop', function(event) {
                if (!dragged) {
                    return;
                }
                event.preventDefault();
                day.classList.remove('is-drop-target');
                var eventid = dragged.getAttribute('data-calendar-event-id');
                var targetdate = day.getAttribute('data-calendar-drop-date');
                if (!eventid || !targetdate) {
                    return;
                }
                var message = form.getAttribute('data-confirm') || '';
                if (message && !window.confirm(message)) {
                    return;
                }
                form.querySelector('input[name="eventid"]').value = eventid;
                form.querySelector('input[name="targetdate"]').value = targetdate;
                form.submit();
            });
        });
    }

    return {init: init};
});
