/* Communication Center 3.2: progressive enhancement only; native Moodle forms remain functional. */
define([], function() {
    'use strict';

    function init() {
        var workspace = document.querySelector('.bms-comm2');
        if (!workspace || workspace.dataset.liveUi === '1') { return; }
        workspace.dataset.liveUi = '1';

        var search = workspace.querySelector('#bms_comm2_search');
        var list = workspace.querySelector('.bms-comm2-thread-list');
        if (search && list && search.form) {
            var timer, controller, requestId = 0;
            search.setAttribute('autocomplete', 'off');
            search.addEventListener('input', function() {
                var query = search.value.trim().toLocaleLowerCase();
                Array.prototype.forEach.call(list.querySelectorAll('.bms-comm2-thread'), function(thread) {
                    thread.hidden = !!query && thread.textContent.toLocaleLowerCase().indexOf(query) < 0;
                });
                clearTimeout(timer);
                var id = ++requestId;
                if (controller) { controller.abort(); }
                timer = setTimeout(function() {
                    var url = new URL(search.form.action || window.location.href, window.location.href);
                    url.searchParams.set('uuid', search.form.elements.uuid.value);
                    url.searchParams.set('tab', search.form.elements.tab.value);
                    url.searchParams.set('q', search.value.trim());
                    url.searchParams.delete('thread');
                    controller = new AbortController();
                    fetch(url.toString(), {credentials: 'same-origin', signal: controller.signal})
                        .then(function(response) { if (!response.ok) { throw new Error('Search'); } return response.text(); })
                        .then(function(html) {
                            if (id !== requestId) { return; }
                            var doc = new DOMParser().parseFromString(html, 'text/html');
                            var updated = doc.querySelector('.bms-comm2-thread-list');
                            if (updated) { list.innerHTML = updated.innerHTML; }
                        })
                        .catch(function(error) {
                            if (error.name !== 'AbortError') {
                                var button = search.form.querySelector('button[type="submit"]');
                                if (button) { button.hidden = false; }
                            }
                        });
                }, 350);
            });
            var button = search.form.querySelector('button[type="submit"]');
            if (button) { button.hidden = true; }

            // Keep sidebar position when opening dialogs; no message contents are stored.
            try {
                var params = new URL(window.location.href).searchParams;
                var scrollKey = 'bms_comm3_list_scroll_' + (params.get('uuid') || '');
                var stored = window.sessionStorage.getItem(scrollKey);
                if (stored !== null && !search.value) {
                    list.scrollTop = Math.max(0, Number(stored) || 0);
                }
                list.addEventListener('scroll', function() {
                    window.sessionStorage.setItem(scrollKey, String(list.scrollTop));
                }, {passive: true});
            } catch (ignored) { /* No persistent state needed for private browsing. */ }
        }

        var messages = workspace.querySelector('#bms-comm2-history');
        if (messages) {
            var url = new URL(window.location.href);
            if (!url.searchParams.has('mpage') && !url.searchParams.has('mq')) {
                // Latest page is server-sorted in chronological order.
                messages.scrollTop = messages.scrollHeight;
            }
        }

        var searchPanel = workspace.querySelector('#bms-comm3-search-panel');
        var searchToggle = workspace.querySelector('[data-bms-comm-search-toggle]');
        if (searchPanel && searchToggle) {
            var opened = searchPanel.dataset.hasQuery === '1';
            searchPanel.classList.toggle('is-collapsed', !opened);
            searchToggle.setAttribute('aria-expanded', opened ? 'true' : 'false');
            searchToggle.addEventListener('click', function() {
                opened = !opened;
                searchPanel.classList.toggle('is-collapsed', !opened);
                searchToggle.setAttribute('aria-expanded', opened ? 'true' : 'false');
                if (opened) { var input = searchPanel.querySelector('input[type="search"]'); if (input) { input.focus(); } }
            });
        }
        var context = workspace.querySelector('#bms-comm3-context-details');
        var contextToggle = workspace.querySelector('[data-bms-comm-context-toggle]');
        if (context && contextToggle) {
            contextToggle.setAttribute('aria-expanded', context.open ? 'true' : 'false');
            contextToggle.addEventListener('click', function() {
                context.open = !context.open;
                contextToggle.setAttribute('aria-expanded', context.open ? 'true' : 'false');
            });
            context.addEventListener('toggle', function() {
                contextToggle.setAttribute('aria-expanded', context.open ? 'true' : 'false');
            });
            var contextClose = workspace.querySelector('[data-bms-comm-context-close]');
            if (contextClose) {
                contextClose.addEventListener('click', function() { context.open = false; });
            }
        }

        // Read-only change probe: no long polling and no background requests in hidden tabs.
        // A heavy page projection is fetched only after a version change.
        var liveUrl = workspace.dataset.liveUrl || '';
        var currentRevision = workspace.dataset.liveRevision || '';
        // The counter comes from the already-authorized page projection, not from
        // an additional request. Do not infer a global unread total from a bounded poll.
        var originalTitle = document.title.replace(/^\(\d+\+?\)\s+/, '');
        function renderUnread() {
            var count = Math.max(0, Math.min(9999, Number(workspace.dataset.liveUnread) || 0));
            var shortCount = count > 99 ? '99+' : String(count);
            var badge = workspace.querySelector('.bms-comm4-unread-indicator');
            if (badge) {
                badge.textContent = shortCount;
                badge.hidden = count === 0;
            }
            document.title = count ? '(' + shortCount + ') ' + originalTitle : originalTitle;
        }
        renderUnread();
        if (liveUrl && currentRevision && window.fetch && window.DOMParser) {
            var liveTimer = null, liveBusy = false, liveFailures = 0, liveStopped = false;
            // Spread clients across the interval; reduce polling for inactive staff.
            var lastActivityAt = Date.now();
            var activeInterval = 25000, idleInterval = 60000, idleAfter = 300000;
            var markActive = function() { lastActivityAt = Date.now(); };
            workspace.addEventListener('pointerdown', markActive, {passive: true});
            workspace.addEventListener('keydown', markActive);
            workspace.addEventListener('wheel', markActive, {passive: true});
            function nextLiveDelay() {
                var base = Date.now() - lastActivityAt >= idleAfter ? idleInterval : activeInterval;
                var delayed = liveFailures ? Math.min(180000, base * Math.pow(2, liveFailures)) : base;
                return Math.round(delayed * (0.87 + Math.random() * 0.26));
            }
            var historyNode = workspace.querySelector('#bms-comm2-history');
            var sidebarNode = workspace.querySelector('.bms-comm2-thread-list');
            var liveNotice = null;
            function hasDraftOrEditing() {
                var compose = workspace.querySelector('[data-bms-comm-compose]');
                if (compose) {
                    var body = compose.querySelector('textarea[name="body"]');
                    var attachment = compose.querySelector('input[type="file"]');
                    if ((body && body.value.trim()) || (attachment && attachment.files && attachment.files.length)) {
                        return true;
                    }
                }
                var active = document.activeElement;
                return !!(active && workspace.contains(active) && (
                    active.matches('input, textarea, select') || active.isContentEditable));
            }
            function noticeNewMessages() {
                if (!historyNode || liveNotice) { return; }
                liveNotice = document.createElement('button');
                liveNotice.type = 'button';
                liveNotice.className = 'bms-comm4-new-messages';
                liveNotice.textContent = workspace.dataset.liveNew || 'New messages';
                liveNotice.addEventListener('click', function() {
                    historyNode.scrollTop = historyNode.scrollHeight;
                    liveNotice.remove();
                    liveNotice = null;
                });
                var feed = workspace.querySelector('.bms-comm2-feed');
                if (feed) { feed.appendChild(liveNotice); }
            }
            function refreshProjection() {
                return fetch(window.location.href, {credentials: 'same-origin', cache: 'no-store'})
                    .then(function(response) {
                        if (!response.ok || response.redirected &&
                                new URL(response.url, window.location.href).pathname.indexOf(
                                    '/local/kbacompany/communication_center.php') === -1) {
                            throw new Error('Projection unavailable');
                        }
                        return response.text();
                    })
                    .then(function(html) {
                        var doc = new DOMParser().parseFromString(html, 'text/html');
                        var newWorkspace = doc.querySelector('.bms-comm2[data-live-revision]');
                        if (!newWorkspace) { throw new Error('Invalid projection'); }
                        var newList = newWorkspace.querySelector('.bms-comm2-thread-list');
                        if (sidebarNode && newList && sidebarNode.innerHTML !== newList.innerHTML) {
                            var listScroll = sidebarNode.scrollTop;
                            sidebarNode.innerHTML = newList.innerHTML;
                            sidebarNode.scrollTop = listScroll;
                        }
                        ['.bms-comm2-inline-stats', '.bms-comm2-rail'].forEach(function(selector) {
                            var current = workspace.querySelector(selector);
                            var next = newWorkspace.querySelector(selector);
                            if (current && next && current.innerHTML !== next.innerHTML) {
                                current.innerHTML = next.innerHTML;
                            }
                        });
                        // Keep deliberate history search and older-history pages stable.
                        var url = new URL(window.location.href);
                        if (!url.searchParams.has('mpage') && !url.searchParams.has('mq')) {
                            var nextMessages = newWorkspace.querySelector('#bms-comm2-history');
                            if (historyNode && nextMessages &&
                                    historyNode.innerHTML !== nextMessages.innerHTML) {
                                var distance = historyNode.scrollHeight - historyNode.scrollTop -
                                    historyNode.clientHeight;
                                var closeToBottom = distance <= 85;
                                var previousScroll = historyNode.scrollTop;
                                historyNode.innerHTML = nextMessages.innerHTML;
                                if (closeToBottom) {
                                    historyNode.scrollTop = historyNode.scrollHeight;
                                    if (liveNotice) { liveNotice.remove(); liveNotice = null; }
                                } else {
                                    historyNode.scrollTop = previousScroll;
                                    noticeNewMessages();
                                }
                            }
                        }
                        // Notification-only screens contain no composer or message history.
                        // Their content can refresh without recreating the entire App Shell.
                        var compose = workspace.querySelector('[data-bms-comm-compose]');
                        if (!historyNode && !compose) {
                            var currentFeed = workspace.querySelector('.bms-comm2-feed');
                            var nextFeed = newWorkspace.querySelector('.bms-comm2-feed');
                            if (currentFeed && nextFeed && currentFeed.innerHTML !== nextFeed.innerHTML) {
                                currentFeed.innerHTML = nextFeed.innerHTML;
                            }
                        }
                        // Server may update read-state during authorized projection rendering.
                        workspace.dataset.liveUnread = newWorkspace.dataset.liveUnread || '0';
                        workspace.dataset.liveNotifications = newWorkspace.dataset.liveNotifications || '0';
                        renderUnread();
                        currentRevision = newWorkspace.dataset.liveRevision || currentRevision;
                    });
            }
            function scheduleLive(delay) {
                if (liveTimer) { clearTimeout(liveTimer); liveTimer = null; }
                // Hidden/offline tabs must not enqueue timers or network operations.
                if (!liveStopped && !document.hidden && navigator.onLine !== false) {
                    liveTimer = setTimeout(pollLive, delay);
                }
            }
            function pollLive() {
                if (liveStopped || document.hidden || navigator.onLine === false) { return; }
                if (liveBusy) { scheduleLive(nextLiveDelay()); return; }
                if (hasDraftOrEditing()) { scheduleLive(nextLiveDelay()); return; }
                liveBusy = true;
                fetch(liveUrl, {credentials: 'same-origin', cache: 'no-store',
                    headers: {'Accept': 'application/json'}})
                    .then(function(response) {
                        if (response.status === 401 || response.status === 403) {
                            liveStopped = true;
                            throw new Error('Access expired');
                        }
                        if (!response.ok) { throw new Error('Poll failed'); }
                        return response.json();
                    }).then(function(state) {
                        if (!state || typeof state.revision !== 'string' ||
                                !/^[a-f0-9]{64}$/.test(state.revision)) {
                            throw new Error('Invalid poll response');
                        }
                        if (state.revision !== currentRevision) {
                            // User may have started typing while the request was pending.
                            if (!document.hidden && !hasDraftOrEditing()) {
                                return refreshProjection();
                            }
                        }
                    }).then(function() { liveFailures = 0; })
                    .catch(function() { liveFailures = Math.min(liveFailures + 1, 4); })
                    .finally(function() {
                        liveBusy = false;
                        if (!liveStopped) {
                            scheduleLive(nextLiveDelay());
                        }
                    });
            }
            scheduleLive(nextLiveDelay());
            document.addEventListener('visibilitychange', function() {
                if (document.hidden) { scheduleLive(nextLiveDelay()); }
                else if (!liveBusy && !liveStopped) { scheduleLive(1200); }
            });
            window.addEventListener('online', function() {
                if (!liveBusy && !liveStopped) { scheduleLive(1200); }
            });
            window.addEventListener('pagehide', function() {
                liveStopped = true;
                if (liveTimer) { clearTimeout(liveTimer); liveTimer = null; }
            });
            // A page restored from the browser's back-forward cache must resume polling.
            window.addEventListener('pageshow', function(event) {
                if (event.persisted) {
                    liveStopped = false;
                    liveFailures = 0;
                    scheduleLive(1200);
                }
            });
        }

        var form = workspace.querySelector('[data-bms-comm-compose]');
        if (!form) { return; }
        var input = form.querySelector('textarea[name="body"]');
        var submit = form.querySelector('button[type="submit"]');
        if (!input || !submit) { return; }
        var submitting = false;
        input.addEventListener('keydown', function(event) {
            if (event.key !== 'Enter' || event.shiftKey || event.ctrlKey || event.altKey ||
                    event.metaKey || event.isComposing || event.keyCode === 229) { return; }
            event.preventDefault();
            if (!submitting && input.value.trim()) {
                if (form.requestSubmit) { form.requestSubmit(submit); }
                else { submit.click(); }
            }
        });
        // Native Moodle POST/redirect is more reliable here than fetch interception,
        // especially with sesskey redirects, attachment uploads and browser-specific
        // redirect handling. Progressive enhancement stops at keyboard convenience.
        form.addEventListener('submit', function(event) {
            if (submitting) {
                event.preventDefault();
                return;
            }
            if (!input.value.trim() || !form.checkValidity()) {
                event.preventDefault();
                form.reportValidity();
                return;
            }
            submitting = true;
            submit.disabled = true;
            form.dataset.submitting = '1';
        });
    }
    return {init: init};
});
