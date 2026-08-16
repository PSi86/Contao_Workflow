/**
 * The "Vergleichswert" column of a visibility condition (answer-field mask, MultiColumnWizard
 * "conditions"): offers the trigger field's answers as a dropdown, and greys the column out
 * for the operators that do not use a value at all.
 *
 * What a condition compares is the STORED value of an option ("ja"), not the option text the
 * participant sees ("Einverstanden"). Typed by hand that is easy to get wrong – and wrong in a
 * way nothing complains about, because a value that matches no option is a legitimate thing to
 * write. Picking from the list removes the question; the list therefore shows both halves.
 *
 * A small switch next to the control moves between list and free text – in BOTH directions.
 * The free-text option deliberately does not live inside the list: a list entry can only work
 * one way, because choosing it removes the list that would carry the way back. Free text is
 * needed for values the option list does not contain, which is a real case for prefilled or
 * read-only trigger fields (their column holds imported data) and for "enthält" with a partial
 * value.
 *
 * The options come from AnswerConfigListener::loadConditionValueOptions() as JSON in
 * #wf-condition-options, because which options belong in a row only follows from the field
 * chosen IN that row – a question the wizard's fixed column configuration cannot answer
 * server-side.
 *
 * Nothing is lost in any direction: a value that matches no option survives as a marked entry
 * in the list, and the value of an unused ("ist leer") comparison is kept and still saved.
 */
(function () {
    'use strict';

    // Operators that ignore the comparison value (see PHP ConditionMatcher).
    var VALUELESS = ['empty', 'notempty'];

    var data = null;
    var observer = null;

    function ready(fn) {
        if (document.readyState !== 'loading') { fn(); }
        else { document.addEventListener('DOMContentLoaded', fn); }
    }

    function options() {
        if (null === data) {
            var node = document.getElementById('wf-condition-options');

            try {
                data = node ? JSON.parse(node.textContent) : { columns: {}, labels: {} };
            } catch (e) {
                data = { columns: {}, labels: {} };
            }
        }

        return data;
    }

    function table() {
        return document.getElementById('ctrl_conditions');
    }

    function cellControl(row, suffix) {
        return row.querySelector('[name$="[' + suffix + ']"]');
    }

    /**
     * Replaces a control with one of the other kind, carrying over everything the wizard and
     * the back end rely on (name, id, class, width) plus the value.
     */
    function replace(oldEl, newEl, value) {
        newEl.name = oldEl.name;
        newEl.id = oldEl.id;
        newEl.className = oldEl.className.replace(/tl_(text|select)/, 'SELECT' === newEl.tagName ? 'tl_select' : 'tl_text');
        newEl.setAttribute('style', oldEl.getAttribute('style') || '');
        newEl.setAttribute('data-action', 'focus->contao--scroll-offset#store');
        newEl.value = value;

        oldEl.parentNode.replaceChild(newEl, oldEl);

        return newEl;
    }

    function buildInput() {
        var input = document.createElement('input');

        input.type = 'text';

        return input;
    }

    function buildSelect(list, value) {
        var labels = options().labels || {};
        var select = document.createElement('select');
        var known = false;

        select.appendChild(new Option(labels.blank || '-', ''));

        list.forEach(function (option) {
            // Both halves matter: the label is what the participant sees, the value is what
            // the condition compares – showing only one of them would hide the distinction
            // this dropdown exists to protect.
            var text = option.l === option.v ? option.v : option.l + ' (' + option.v + ')';

            select.appendChild(new Option(text, option.v));

            if (option.v === value) {
                known = true;
            }
        });

        // A value from outside the list (an imported one, or one written before the options
        // changed) stays selectable instead of being silently dropped on the next save.
        if ('' !== value && !known) {
            select.appendChild(new Option((labels.unknown || '%s').replace('%s', value), value));
        }

        select.value = value;

        return select;
    }

    /**
     * The switch between list and free text. Created once per row and then only relabelled –
     * a button that is added and removed on every keystroke would fight the MutationObserver.
     */
    function modeButton(control) {
        var cell = control.parentNode;
        var button = cell.querySelector('.tw-cond-mode');

        if (!button) {
            button = document.createElement('button');
            button.type = 'button';
            button.className = 'tw-cond-mode';
            cell.appendChild(button);
        }

        return button;
    }

    function updateButton(button, mode, available) {
        var labels = options().labels || {};

        button.hidden = !available;

        // Only when it actually changed: writing innerHTML unconditionally would be a DOM
        // mutation on every sync, and sync runs FROM the MutationObserver – that is an endless
        // loop. Attributes are safe (the observer watches childList only), the content is not.
        if (button.dataset.wfMode === mode) {
            return;
        }

        button.dataset.wfMode = mode;
        // Entities rather than literal glyphs: this file is served as-is, and the symbols
        // would be its only non-ASCII content.
        button.innerHTML = 'list' === mode ? '&#9998;' : '&#9776;';
        button.title = 'list' === mode ? (labels.free || '') : (labels.list || '');
        button.setAttribute('aria-label', button.title);
    }

    function syncRow(row) {
        var field = cellControl(row, 'field');
        var operator = cellControl(row, 'operator');
        var control = cellControl(row, 'value');

        if (!field || !control) {
            return;
        }

        var labels = options().labels || {};
        var list = (options().columns || {})[field.value];
        var valueless = operator && -1 !== VALUELESS.indexOf(operator.value);
        // Free text unless a list exists and the row has not been switched over. The mode is
        // per row and not stored: reopening the mask starts from the list again, with an
        // off-list value shown as a marked entry – one click away from being edited freely.
        var mode = !list || 'free' === row.dataset.wfMode ? 'free' : 'list';
        var kind = valueless ? 'inert' : mode;

        // Swap only when the control is of the wrong kind (or the list belongs to another
        // field): every needless replacement costs the focus and wakes the observer again.
        var needsSwap = 'list' === kind
            ? 'SELECT' !== control.tagName || control.dataset.wfColumn !== field.value
            : 'INPUT' !== control.tagName;

        if (needsSwap) {
            var value = control.value;

            control = 'list' === kind
                ? replace(control, buildSelect(list, value), value)
                : replace(control, buildInput(), value);
        }

        control.dataset.wfKind = kind;
        control.dataset.wfColumn = 'list' === kind ? field.value : '';

        // The value of a valueless operator is kept and still saved – it only stops being
        // offered, and says why. Read-only rather than disabled: a disabled field is not
        // posted, and switching the operator back would find the value gone.
        control.readOnly = 'inert' === kind;
        control.classList.toggle('tw-cond-inert', 'inert' === kind);
        control.title = 'inert' === kind ? (labels.inert || '') : '';

        updateButton(modeButton(control), mode, !!list && !valueless);
    }

    /**
     * The observer is paused while we work: sync() is what the observer CALLS, and it changes
     * the DOM itself. Pausing takes the whole class of feedback loop off the table instead of
     * relying on every single write being idempotent.
     */
    function sync() {
        var wizard = table();

        if (!wizard) {
            return;
        }

        if (observer) {
            observer.disconnect();
        }

        wizard.querySelectorAll('tr[data-rowId]').forEach(syncRow);

        if (observer) {
            observer.observe(wizard, { childList: true, subtree: true });
        }
    }

    function boot() {
        var wizard = table();

        // Re-read the payload: after a Turbo navigation this is a different page, and the
        // options belong to the field being edited there.
        data = null;

        if (!wizard || wizard.wfValueBound) {
            return;
        }

        wizard.wfValueBound = true;

        wizard.addEventListener('click', function (e) {
            var button = e.target.closest ? e.target.closest('.tw-cond-mode') : null;
            var row = button ? button.closest('tr[data-rowId]') : null;

            if (!row) {
                return;
            }

            e.preventDefault();
            row.dataset.wfMode = 'free' === row.dataset.wfMode ? 'list' : 'free';
            syncRow(row);
        });

        wizard.addEventListener('change', sync);

        // The wizard adds and removes rows in the browser (its +/- buttons clone the markup),
        // so a new row has to be picked up as it appears.
        observer = new MutationObserver(sync);

        sync();
    }

    ready(boot);
    // The back end navigates with Turbo, so the mask can arrive without a page load.
    document.addEventListener('turbo:render', boot);
})();
