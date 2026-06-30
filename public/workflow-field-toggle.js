/**
 * Client-side show/hide of the conditional fields in the workflow and PDF-rule
 * edit masks. Replaces Contao's subpalette mechanism (submitOnChange /
 * toggleSubpalette), which persisted the record before the user clicked "save".
 *
 * A selector field carries its configuration as JSON in data-wf-toggle:
 *   checkbox: {"mode":"checkbox","on":[<fields shown when checked>],
 *                                "off":[<fields shown when unchecked>]}
 *   select:   {"mode":"select","map":{"<value>":[<fields shown for that value>]}}
 *
 * Hidden fields are also disabled, so they are neither submitted nor validated –
 * nothing is written until the user saves. If the asset fails to load, every field
 * stays visible (it is part of the palette): a benign degradation, no auto-save.
 */
(function () {
    'use strict';

    function ready(fn) {
        if (document.readyState !== 'loading') { fn(); }
        else { document.addEventListener('DOMContentLoaded', fn); }
    }

    // The Contao back end wraps every field in its own ".widget" container; fall
    // back to the closest div, then to the control itself.
    function wrapperOf(field) {
        var ctrl = document.getElementById('ctrl_' + field) || document.querySelector('[id^="ctrl_' + field + '"]');

        if (!ctrl) {
            return null;
        }

        return ctrl.closest('.widget') || ctrl.closest('div') || ctrl;
    }

    function setVisible(field, visible) {
        var wrap = wrapperOf(field);

        if (!wrap) {
            return;
        }

        wrap.style.display = visible ? '' : 'none';

        // Disable hidden inputs so they are not posted/validated (and re-enable
        // them when shown again).
        wrap.querySelectorAll('input, select, textarea').forEach(function (el) {
            el.disabled = !visible;
        });
    }

    // All fields the selector governs (union over the config), so the ones not
    // currently visible can be hidden.
    function governedFields(cfg) {
        var all = [];

        if (cfg.mode === 'checkbox') {
            all = (cfg.on || []).concat(cfg.off || []);
        } else if (cfg.mode === 'select' && cfg.map) {
            Object.keys(cfg.map).forEach(function (key) {
                all = all.concat(cfg.map[key] || []);
            });
        }

        // De-duplicate.
        return all.filter(function (f, i) { return all.indexOf(f) === i; });
    }

    function visibleFields(cfg, selector) {
        if (cfg.mode === 'checkbox') {
            return (selector.checked ? cfg.on : cfg.off) || [];
        }

        if (cfg.mode === 'select' && cfg.map) {
            return cfg.map[selector.value] || [];
        }

        return [];
    }

    function apply(selector, cfg) {
        var visible = visibleFields(cfg, selector);

        governedFields(cfg).forEach(function (field) {
            setVisible(field, visible.indexOf(field) !== -1);
        });
    }

    function init(selector) {
        if (selector.dataset.wfToggleReady) {
            return;
        }

        var cfg;
        try {
            cfg = JSON.parse(selector.getAttribute('data-wf-toggle'));
        } catch (e) {
            return;
        }

        if (!cfg || !cfg.mode) {
            return;
        }

        selector.dataset.wfToggleReady = '1';
        selector.addEventListener('change', function () { apply(selector, cfg); });
        apply(selector, cfg);
    }

    function scan() {
        document.querySelectorAll('[data-wf-toggle]').forEach(init);
    }

    ready(scan);
    document.addEventListener('turbo:render', scan);
})();
