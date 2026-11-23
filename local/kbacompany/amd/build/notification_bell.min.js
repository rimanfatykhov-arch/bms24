/* BMS24 top bar bell (owned shell): unread badge, panel of recent notifications and an opt-in sound.
 *
 * Progressive enhancement over a server-rendered button and a link to the Communication Center.
 * - The feed is read-only and already filtered on the server (company, participant, object access). Nothing is
 *   filtered or hidden here, and opening the panel marks nothing read.
 * - Polling: every 30 s while the tab is visible and online; paused while hidden or offline; exponential backoff
 *   (60 s .. 5 min) after errors; one request in flight at most.
 * - Sound: only when the account opted in, only for notification ids that were not present when observation
 *   started, are unread and were created after observation started (server clock) and within the last 10 minutes.
 *   One signal per batch, one signal across tabs (localStorage claim), no queued signals after reconnect.
 */
define([], function() {
    'use strict';

    var POLL_MS = 30000;
    var MAX_BACKOFF_MS = 300000;
    var CLAIM_WAIT_MS = 150;
    var SOUND_GAP_MS = 5000;
    var FRESH_SECONDS = 600;
    var KEEP_IDS = 200;

    function storageGet(key) {
        try {
            return JSON.parse(window.localStorage.getItem(key) || 'null');
        } catch (e) {
            return null;
        }
    }

    function storageSet(key, value) {
        try {
            window.localStorage.setItem(key, JSON.stringify(value));
            return true;
        } catch (e) {
            return false;
        }
    }

    /** A short two-tone WAV built in memory: no file, no external request. */
    function toneUrl() {
        var rate = 22050, length = Math.floor(rate * 0.36), buffer = new ArrayBuffer(44 + length * 2);
        var view = new DataView(buffer);
        var text = function(offset, value) {
            for (var i = 0; i < value.length; i++) {
                view.setUint8(offset + i, value.charCodeAt(i));
            }
        };
        text(0, 'RIFF');
        view.setUint32(4, 36 + length * 2, true);
        text(8, 'WAVE');
        text(12, 'fmt ');
        view.setUint32(16, 16, true);
        view.setUint16(20, 1, true);
        view.setUint16(22, 1, true);
        view.setUint32(24, rate, true);
        view.setUint32(28, rate * 2, true);
        view.setUint16(32, 2, true);
        view.setUint16(34, 16, true);
        text(36, 'data');
        view.setUint32(40, length * 2, true);
        for (var i = 0; i < length; i++) {
            var t = i / rate;
            var frequency = i < length / 2 ? 880 : 1175;
            var envelope = Math.min(1, i / 300, (length - i) / 900);
            view.setInt16(44 + i * 2, Math.round(Math.sin(2 * Math.PI * frequency * t) * 0.3 * envelope * 32767), true);
        }
        return URL.createObjectURL(new Blob([buffer], {type: 'audio/wav'}));
    }

    function Bell(root) {
        this.root = root;
        this.user = String(root.dataset.user || '');
        this.company = String(root.dataset.company || '');
        this.endpoint = String(root.dataset.endpoint || '');
        this.sound = root.dataset.sound === '1';
        this.toggle = root.querySelector('[data-bms24-bell-toggle]');
        this.badge = root.querySelector('[data-bms24-bell-badge]');
        this.panel = root.querySelector('[data-bms24-bell-panel]');
        this.list = root.querySelector('[data-bms24-bell-list]');
        this.count = root.querySelector('[data-bms24-bell-count]');
        this.key = 'bms24-bell-sound:' + this.user + ':' + this.company;
        this.seen = {};
        this.started = false;
        this.since = 0;
        this.loaded = false;
        this.failures = 0;
        this.timer = null;
        this.inflight = false;
        this.lastpoll = 0;
        this.stopped = false;
        this.blocked = false;
        this.audio = null;
    }

    Bell.prototype.setState = function(state) {
        this.root.dataset.state = state;
        var rows = this.root.querySelectorAll('[data-bms24-bell-state]');
        for (var i = 0; i < rows.length; i++) {
            rows[i].hidden = rows[i].getAttribute('data-bms24-bell-state') !== state;
        }
        this.list.hidden = state !== 'ready';
    };

    Bell.prototype.setBadge = function(unread, label) {
        var n = Math.max(0, parseInt(unread, 10) || 0);
        this.badge.textContent = n > 99 ? '99+' : String(n);
        this.badge.hidden = n === 0;
        this.root.dataset.unread = String(n);
        if (label) {
            this.toggle.setAttribute('aria-label', label);
            this.toggle.setAttribute('title', label);
            this.count.textContent = label;
        }
    };

    Bell.prototype.renderItems = function(items) {
        while (this.list.firstChild) {
            this.list.removeChild(this.list.firstChild);
        }
        items.forEach(function(item) {
            var li = document.createElement('li');
            li.className = 'bms24-bell-item' + (item.read ? '' : ' bms24-bell-item-unread');
            li.dataset.id = item.id;
            li.dataset.read = item.read ? '1' : '0';
            var link = document.createElement('a');
            link.className = 'bms24-bell-link';
            link.href = item.url;
            var type = document.createElement('span');
            type.className = 'bms24-bell-type bms24-bell-type-' + String(item.variant || 'sky').replace(/[^a-z]/g, '');
            type.textContent = (item.icon ? item.icon + ' ' : '') + item.type;
            var title = document.createElement('span');
            title.className = 'bms24-bell-title';
            title.textContent = item.title;
            var subtitle = document.createElement('span');
            subtitle.className = 'bms24-bell-subtitle';
            subtitle.textContent = item.subtitle;
            var meta = document.createElement('span');
            meta.className = 'bms24-bell-meta';
            var time = document.createElement('time');
            time.className = 'bms24-bell-time';
            time.dateTime = new Date(item.created * 1000).toISOString();
            time.textContent = item.time;
            var target = document.createElement('span');
            target.className = 'bms24-bell-target';
            target.textContent = item.linklabel;
            meta.appendChild(time);
            meta.appendChild(target);
            link.appendChild(type);
            link.appendChild(title);
            if (item.subtitle) {
                link.appendChild(subtitle);
            }
            link.appendChild(meta);
            li.appendChild(link);
            this.list.appendChild(li);
        }, this);
    };

    Bell.prototype.renderSound = function() {
        var state = this.blocked ? 'blocked' : (this.sound ? 'on' : 'off');
        this.root.dataset.soundState = state;
        this.root.dataset.sound = this.sound ? '1' : '0';
        var spans = this.root.querySelectorAll('[data-sound-state]');
        for (var i = 0; i < spans.length; i++) {
            spans[i].hidden = spans[i].getAttribute('data-sound-state') !== state;
        }
        this.root.querySelector('[data-bms24-bell-sound-enable]').hidden = this.sound;
        this.root.querySelector('[data-bms24-bell-sound-disable]').hidden = !this.sound;
    };

    /** Stop observing: another account or no access. Clears everything shown; never plays a sound. */
    Bell.prototype.unavailable = function() {
        this.setBadge(0, null);
        this.renderItems([]);
        this.setState('unavailable');
        // If access comes back, observation starts again: rows visible at that moment never sound.
        this.started = false;
        this.seen = {};
    };

    Bell.prototype.done = function() {
        this.polls = (this.polls || 0) + 1;
        this.root.dataset.polls = String(this.polls);
    };

    Bell.prototype.apply = function(data) {
        if (String(data.user) !== this.user || String(data.company) !== this.company) {
            // The Moodle session now belongs to another account or the answer is for another company.
            this.stopped = true;
            this.unavailable();
            return;
        }
        this.loaded = true;
        this.sound = !!data.sound;
        this.setBadge(data.unread, data.label);
        this.renderItems(data.items || []);
        this.setState((data.items || []).length ? 'ready' : 'empty');
        this.renderSound();
        var fresh = [];
        (data.items || []).forEach(function(item) {
            if (this.seen[item.id]) {
                return;
            }
            this.seen[item.id] = true;
            if (this.started && !item.read && item.created >= this.since && item.created >= data.now - FRESH_SECONDS) {
                fresh.push(item.id);
            }
        }, this);
        if (!this.started) {
            // Start of observation: everything present now is the baseline and never sounds (initial load, reload).
            this.started = true;
            this.since = data.now;
            return;
        }
        if (fresh.length && this.sound) {
            this.announce(fresh);
        }
    };

    /** One signal per batch and across tabs of this account and company. */
    Bell.prototype.announce = function(ids) {
        var self = this;
        var state = storageGet(this.key);
        if (state === null && !storageSet(this.key, {ids: [], at: 0})) {
            // No shared storage (private mode or blocked): this tab cannot coordinate, signal once for the batch.
            this.play();
            return;
        }
        state = state || {ids: [], at: 0};
        var known = Array.isArray(state.ids) ? state.ids : [];
        var pending = ids.filter(function(id) {
            return known.indexOf(id) === -1;
        });
        if (!pending.length) {
            return;
        }
        var token = Math.random().toString(36).slice(2) + Date.now().toString(36);
        storageSet(this.key, {ids: known.concat(pending).slice(-KEEP_IDS), at: state.at || 0, claim: token});
        window.setTimeout(function() {
            var now = storageGet(self.key) || {};
            if (now.claim !== token) {
                return; // Another tab claimed this batch.
            }
            var recent = Date.now() - (now.at || 0) < SOUND_GAP_MS;
            storageSet(self.key, {ids: now.ids || [], at: recent ? now.at : Date.now(), claim: null});
            if (!recent && !self.stopped) {
                self.play();
            }
        }, CLAIM_WAIT_MS);
    };

    Bell.prototype.play = function() {
        var self = this;
        try {
            if (!this.audio) {
                this.audio = new Audio(toneUrl());
            }
            this.audio.currentTime = 0;
            var result = this.audio.play();
            if (result && typeof result.then === 'function') {
                result.then(function() {
                    self.blocked = false;
                    self.renderSound();
                }, function() {
                    self.blocked = true;
                    self.renderSound();
                });
            }
        } catch (e) {
            this.blocked = true;
            this.renderSound();
        }
    };

    Bell.prototype.saveSound = function(enabled) {
        var self = this;
        var body = new URLSearchParams();
        body.set('uuid', this.company);
        body.set('action', 'sound');
        body.set('enabled', enabled ? '1' : '0');
        body.set('sesskey', (window.M && window.M.cfg && window.M.cfg.sesskey) || '');
        return fetch(this.endpoint, {method: 'POST', credentials: 'same-origin', cache: 'no-store', body: body,
                headers: {'Accept': 'application/json'}})
            .then(function(response) {
                return response.ok ? response.json() : {ok: false};
            })
            .then(function(data) {
                if (data && data.ok && String(data.user) === self.user) {
                    self.sound = !!data.sound;
                }
                self.renderSound();
            })
            .catch(function() {
                self.renderSound();
            });
    };

    Bell.prototype.schedule = function(delay) {
        var self = this;
        window.clearTimeout(this.timer);
        this.timer = null;
        if (this.stopped || document.hidden) {
            return;
        }
        this.timer = window.setTimeout(function() {
            self.poll();
        }, delay);
    };

    Bell.prototype.backoff = function() {
        return Math.min(MAX_BACKOFF_MS, POLL_MS * Math.pow(2, Math.min(this.failures, 4)));
    };

    Bell.prototype.poll = function() {
        var self = this;
        if (this.stopped || this.inflight) {
            return;
        }
        if (navigator.onLine === false) {
            return; // Resumed by the 'online' event.
        }
        this.inflight = true;
        this.lastpoll = Date.now();
        var url = this.endpoint + '?uuid=' + encodeURIComponent(this.company);
        fetch(url, {credentials: 'same-origin', cache: 'no-store', headers: {'Accept': 'application/json'}})
            .then(function(response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
                return response.json();
            })
            .then(function(data) {
                self.inflight = false;
                if (!data || data.ok !== true) {
                    self.failures++;
                    if (data && data.user !== undefined && String(data.user) !== self.user) {
                        self.stopped = true;
                    }
                    self.unavailable();
                    self.done();
                    self.schedule(self.backoff());
                    return;
                }
                self.failures = 0;
                self.apply(data);
                self.done();
                self.schedule(POLL_MS);
            })
            .catch(function() {
                self.inflight = false;
                self.failures++;
                if (!self.loaded) {
                    self.setState('error');
                }
                self.done();
                self.schedule(self.backoff());
            });
    };

    Bell.prototype.place = function() {
        var rect = this.toggle.getBoundingClientRect();
        var width = Math.min(380, window.innerWidth - 16);
        var left = Math.min(Math.max(8, rect.right - width), window.innerWidth - width - 8);
        var top = Math.max(8, rect.bottom + 8);
        this.panel.style.width = width + 'px';
        this.panel.style.left = left + 'px';
        this.panel.style.top = top + 'px';
        this.panel.style.maxHeight = Math.max(160, window.innerHeight - top - 8) + 'px';
    };

    Bell.prototype.open = function() {
        this.panel.hidden = false;
        this.toggle.setAttribute('aria-expanded', 'true');
        this.root.dataset.open = '1';
        this.place();
        this.panel.focus();
        // Refresh the list on open; reading the feed never marks anything read.
        if (!this.stopped && Date.now() - this.lastpoll > 2000) {
            this.poll();
        }
    };

    Bell.prototype.close = function(restore) {
        if (this.panel.hidden) {
            return;
        }
        this.panel.hidden = true;
        this.toggle.setAttribute('aria-expanded', 'false');
        this.root.dataset.open = '0';
        if (restore) {
            this.toggle.focus();
        }
    };

    Bell.prototype.bind = function() {
        var self = this;
        this.toggle.addEventListener('click', function() {
            if (self.panel.hidden) {
                self.open();
            } else {
                self.close(true);
            }
        });
        this.root.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && !self.panel.hidden) {
                e.preventDefault();
                self.close(true);
            }
        });
        document.addEventListener('click', function(e) {
            if (!self.panel.hidden && !self.root.contains(e.target)) {
                self.close(false);
            }
        });
        this.root.addEventListener('focusout', function(e) {
            if (!self.panel.hidden && e.relatedTarget && !self.root.contains(e.relatedTarget)) {
                self.close(false);
            }
        });
        window.addEventListener('resize', function() {
            if (!self.panel.hidden) {
                self.place();
            }
        });
        this.root.querySelector('[data-bms24-bell-retry]').addEventListener('click', function() {
            self.failures = 0;
            self.setState('loading');
            self.poll();
        });
        this.root.querySelector('[data-bms24-bell-sound-enable]').addEventListener('click', function() {
            // The click is the explicit user action: unlock and confirm playback now, then store the preference.
            self.play();
            self.saveSound(true).then(function() {
                if (self.sound) {
                    self.root.querySelector('[data-bms24-bell-sound-disable]').focus();
                }
            });
        });
        this.root.querySelector('[data-bms24-bell-sound-test]').addEventListener('click', function() {
            self.play();
        });
        this.root.querySelector('[data-bms24-bell-sound-disable]').addEventListener('click', function() {
            self.blocked = false;
            self.saveSound(false).then(function() {
                if (!self.sound) {
                    self.root.querySelector('[data-bms24-bell-sound-enable]').focus();
                }
            });
        });
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                window.clearTimeout(self.timer);
                self.timer = null;
            } else if (Date.now() - self.lastpoll >= POLL_MS) {
                self.poll();
            } else {
                self.schedule(POLL_MS - (Date.now() - self.lastpoll));
            }
        });
        window.addEventListener('online', function() {
            self.failures = 0;
            self.poll();
        });
        window.addEventListener('offline', function() {
            window.clearTimeout(self.timer);
            self.timer = null;
        });
    };

    function init() {
        var root = document.querySelector('[data-bms24-bell]');
        if (!root || root.dataset.ready === '1') {
            return;
        }
        root.dataset.ready = '1';
        var bell = new Bell(root);
        bell.renderSound();
        bell.bind();
        bell.poll();
    }

    return {init: init};
});
