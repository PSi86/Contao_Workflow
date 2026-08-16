/**
 * Conditional form fields: shows and hides a field while the form is being filled in,
 * following the rules configured on it in the back end ("Sichtbarkeit").
 *
 * Markup rendered by mod_workflow_form.html5:
 *   - .tw-field[data-wf-field="<storage column>"]  a field that can act as a trigger
 *   - .tw-field[data-wf-cond='{"mode":"show|hide","logic":"and|or",
 *                              "rules":[{"f":"<column>","o":"<operator>","v":"<value>"}]}']
 *   - the "hidden" attribute carries the initial state the SERVER computed
 *
 * This script only MIRRORS the server's decision (see PHP FieldVisibility): the submission
 * evaluates the same rules again, so a field that is hidden here is neither validated nor
 * stored, and one that is shown here is. Nothing depends on the browser being honest.
 *
 * Operators are limited to the string comparisons the back end offers for form conditions
 * (eq, neq, contains, empty, notempty) – exactly so this mirror stays a few lines and cannot
 * drift from PHP. The ordering operators of the PDF rules would need the German number
 * parsing and the date normalisation here, which is why they are not offered; for a number or
 * date trigger the back end hints that only "ist (nicht) leer" is dependable.
 */
(function () {
    'use strict';

    var OPERATORS = {
        eq: function (actual, expected) { return actual === expected; },
        neq: function (actual, expected) { return actual !== expected; },
        contains: function (actual, expected) {
            return expected !== '' && actual.toLowerCase().indexOf(expected.toLowerCase()) !== -1;
        },
        empty: function (actual) { return actual === ''; },
        notempty: function (actual) { return actual !== ''; }
    };

    function config(field) {
        if (field.wfCond === undefined) {
            try {
                field.wfCond = JSON.parse(field.getAttribute('data-wf-cond'));
            } catch (e) {
                // Unreadable configuration: the field stays visible (same fail-open rule as
                // the server) – a field nobody can see is the failure nobody notices.
                field.wfCond = null;
            }
        }

        return field.wfCond;
    }

    /**
     * The value of the field storing into a column, in the same spelling the answer will be
     * stored in (checkboxes as a ", "-joined list). A HIDDEN field counts as empty – that is
     * the cascade: a chain of conditions must come apart when one of its links disappears.
     */
    function valueOf(form, column) {
        var fields = form.querySelectorAll('[data-wf-field="' + column.replace(/"/g, '\\"') + '"]');
        var value = '';

        // Two fields may write the same column; the last visible one wins, mirroring the
        // submission, where the later answer overwrites the earlier one.
        fields.forEach(function (field) {
            if (field.hidden) {
                return;
            }

            var select = field.querySelector('select');

            if (select) {
                value = (select.value || '').trim();

                return;
            }

            var checked = field.querySelectorAll('input[type=radio]:checked, input[type=checkbox]:checked');

            if (field.querySelector('input[type=radio], input[type=checkbox]')) {
                var parts = [];

                checked.forEach(function (box) { parts.push(box.value); });
                value = parts.join(', ');

                return;
            }

            var input = field.querySelector('input:not([type=hidden]), textarea');

            value = input ? (input.value || '').trim() : '';
        });

        return value;
    }

    function matches(form, cfg) {
        var isOr = cfg.logic === 'or';
        var rules = cfg.rules || [];
        var i;

        for (i = 0; i < rules.length; i++) {
            var operator = OPERATORS[rules[i].o];
            var hit = operator ? operator(valueOf(form, rules[i].f), rules[i].v || '') : false;

            if (isOr && hit) {
                return true;
            }

            if (!isOr && !hit) {
                return false;
            }
        }

        return rules.length > 0 && !isOr;
    }

    /**
     * Hidden fields are disabled as well, so they are neither posted nor validated – the same
     * contract the back-end toggle uses. The original disabled state is remembered: read-only
     * choice fields and read-only date inputs are rendered disabled by the server and must
     * stay that way when the field is revealed again.
     */
    function setVisible(field, visible) {
        field.hidden = !visible;

        field.querySelectorAll('input, select, textarea').forEach(function (el) {
            if (!visible) {
                if (el.dataset.wfWasDisabled === undefined) {
                    el.dataset.wfWasDisabled = el.disabled ? '1' : '0';
                }

                el.disabled = true;

                return;
            }

            if (el.dataset.wfWasDisabled !== undefined) {
                el.disabled = el.dataset.wfWasDisabled === '1';
                delete el.dataset.wfWasDisabled;
            }
        });
    }

    // In document order, so a field is evaluated after every field it may depend on – the
    // back end only allows conditions on PRECEDING fields, which is what makes one pass enough.
    function update(form) {
        form.querySelectorAll('[data-wf-cond]').forEach(function (field) {
            var cfg = config(field);

            if (!cfg) {
                return;
            }

            var hit = matches(form, cfg);

            setVisible(field, cfg.mode === 'hide' ? !hit : hit);
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.tw-form').forEach(function (form) {
            if (!form.querySelector('[data-wf-cond]')) {
                return;
            }

            ['input', 'change'].forEach(function (event) {
                form.addEventListener(event, function () { update(form); });
            });

            update(form);
        });
    });
})();
