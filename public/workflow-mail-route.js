/**
 * Lets the "Versandweg" block of the workflow edit mask follow the three notification fields
 * while the user picks, without saving (see MailRouteListener).
 *
 * The server renders the route of every notification the fields offer, one element per field
 * and notification – a "choice" such as "invite:12", listed in data-wf-route-for; a warning
 * lists every choice that causes it. This script shows the elements of the selected choices,
 * hides the block when none of them has a route, and shows the "not saved yet" note while the
 * selection differs from the saved one (data-wf-route: {"fields": {kind: select id},
 * "saved": {kind: notification id}}).
 *
 * Without this script the block shows the saved selection, as rendered.
 */
(function () {
    'use strict';

    function ready(fn) {
        if (document.readyState !== 'loading') { fn(); }
        else { document.addEventListener('DOMContentLoaded', fn); }
    }

    // The empty option ("no notification") posts "", the saved record holds 0.
    function selectedId(select, saved) {
        if (!select) {
            return saved;
        }

        var value = String(select.value || '');

        return value === '' ? '0' : value;
    }

    function bind(block) {
        var cfg;

        try {
            cfg = JSON.parse(block.getAttribute('data-wf-route'));
        } catch (e) {
            return;
        }

        if (!cfg || !cfg.fields || !cfg.saved) {
            return;
        }

        var kinds = Object.keys(cfg.fields);

        function update() {
            var active = {};
            var unsaved = false;
            var anyRoute = false;

            kinds.forEach(function (kind) {
                var saved = String(cfg.saved[kind]);
                var id = selectedId(document.getElementById(cfg.fields[kind]), saved);

                active[kind + ':' + id] = true;

                if (id !== saved) {
                    unsaved = true;
                }
            });

            block.querySelectorAll('[data-wf-route-for]').forEach(function (el) {
                var on = el.getAttribute('data-wf-route-for').split(' ').some(function (choice) {
                    return active[choice] === true;
                });

                el.style.display = on ? '' : 'none';

                if (on && el.tagName === 'TBODY') {
                    anyRoute = true;
                }
            });

            block.style.display = anyRoute ? '' : 'none';

            var note = block.querySelector('[data-wf-route-unsaved]');

            if (note) {
                note.style.display = unsaved ? '' : 'none';
            }
        }

        // Bound on the selects themselves: the change event Contao's chosen widget dispatches
        // does not bubble.
        kinds.forEach(function (kind) {
            var select = document.getElementById(cfg.fields[kind]);

            if (select && !select.dataset.wfRouteBound) {
                select.dataset.wfRouteBound = '1';
                select.addEventListener('change', update);
            }
        });

        update();
    }

    function scan() {
        document.querySelectorAll('[data-wf-route]').forEach(bind);
    }

    ready(scan);
    document.addEventListener('turbo:render', scan);
})();
