(function() {
    'use strict';

    function normalise(value) {
        return (value || '').toString().toLowerCase().replace(/\s+/g, ' ').trim();
    }

    function setSectionOpen(section, open) {
        if (!section) {
            return;
        }
        section.classList.toggle('is-open', open);
        var toggle = section.querySelector('[data-kba-toggle]');
        if (toggle) {
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
    }

    function boot() {
        var root = document.querySelector('[data-kba-modules-page]');
        if (!root) {
            return;
        }

        var cards = Array.prototype.slice.call(root.querySelectorAll('[data-kba-open]'));
        var sections = Array.prototype.slice.call(root.querySelectorAll('[data-kba-section]'));
        var search = root.querySelector('[data-kba-search]');

        cards.forEach(function(card) {
            card.addEventListener('click', function() {
                var key = card.getAttribute('data-kba-open');
                var section = root.querySelector('[data-kba-section="' + key + '"]');
                if (!section) {
                    return;
                }
                setSectionOpen(section, true);
                section.scrollIntoView({behavior: 'smooth', block: 'start'});
            });
        });

        sections.forEach(function(section) {
            var toggle = section.querySelector('[data-kba-toggle]');
            if (!toggle) {
                return;
            }
            toggle.addEventListener('click', function() {
                setSectionOpen(section, !section.classList.contains('is-open'));
            });
        });

        if (search) {
            search.addEventListener('input', function() {
                var query = normalise(search.value);

                cards.forEach(function(card) {
                    var haystack = normalise(card.getAttribute('data-kba-search-text'));
                    card.hidden = query && haystack.indexOf(query) === -1;
                });

                sections.forEach(function(section) {
                    var nativeText = normalise(section.textContent);
                    var haystack = normalise(section.getAttribute('data-kba-search-text') + ' ' + nativeText);
                    var matches = !query || haystack.indexOf(query) !== -1;
                    section.hidden = !matches;
                    if (query && matches) {
                        setSectionOpen(section, true);
                    }
                });
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
}());
