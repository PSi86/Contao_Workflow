/**
 * Drag&drop reordering of the answer-field list embedded in the workflow edit
 * mask (rendered by AnswerConfigListener::renderQuestionsList).
 *
 * Reordering NO LONGER writes to the server immediately. The new order is kept
 * in a hidden field ("wfQuestionOrder") appended to the edit FORM (outside the
 * dcaWizard widget, so it survives the widget's partial AJAX refresh) and is only
 * persisted when the workflow is saved (AnswerConfigListener::persistQuestionOrder).
 *
 * The dcaWizard re-renders just its own widget after a row modal is closed
 * (reloadDcaWizard → replaces #ctrl_<field> innerHTML); that re-render comes back
 * in stored (DB) order. A MutationObserver therefore re-applies the pending order
 * to the freshly rendered rows, so an in-progress reorder is not lost when the
 * user adds/edits/deletes a single answer field before saving.
 *
 * Uses event delegation, so it keeps working after every dcaWizard refresh.
 */
(function () {
    'use strict';

    var FIELD = 'wfQuestionOrder';
    var dragRow = null;

    function ready(fn) {
        if (document.readyState !== 'loading') { fn(); }
        else { document.addEventListener('DOMContentLoaded', fn); }
    }

    function box() {
        return document.querySelector('[data-question-sort]');
    }

    function rowsIn(b) {
        return b ? Array.prototype.slice.call(b.querySelectorAll('tr[data-question-id]')) : [];
    }

    // Hidden field lives on the FORM (not inside the widget), so the dcaWizard's
    // partial refresh of the widget does not wipe the pending order.
    function pendingField(b, create) {
        var form = b ? b.closest('form') : null;
        if (!form) { return null; }

        var field = form.querySelector('input[name="' + FIELD + '"]');
        if (!field && create) {
            field = document.createElement('input');
            field.type = 'hidden';
            field.name = FIELD;
            form.appendChild(field);
        }
        return field;
    }

    // Store the current visible order into the hidden field.
    function sync(b) {
        if (!b) { return; }
        var field = pendingField(b, true);
        if (field) {
            field.value = rowsIn(b).map(function (r) { return r.getAttribute('data-question-id'); }).join(',');
        }
    }

    // Reorder freshly rendered rows to match the pending order; ids not in it
    // (e.g. a just-added field) keep their place after the known ones. Then
    // refresh the hidden field so it always mirrors the visible order.
    function reapply(b) {
        var field = pendingField(b, false);
        if (!field || !field.value) { return; }

        var tbody = b.querySelector('tbody');
        if (!tbody) { return; }

        var byId = {};
        rowsIn(b).forEach(function (r) { byId[r.getAttribute('data-question-id')] = r; });

        field.value.split(',').forEach(function (id) {
            if (byId[id]) { tbody.appendChild(byId[id]); }
        });

        sync(b);
    }

    document.addEventListener('dragstart', function (e) {
        var handle = e.target && e.target.closest ? e.target.closest('[data-question-sort] .tw-drag-handle') : null;
        if (!handle) { return; }

        dragRow = handle.closest('tr[data-question-id]');
        dragRow.classList.add('tw-dragging');
        e.dataTransfer.effectAllowed = 'move';

        try {
            e.dataTransfer.setData('text/plain', dragRow.getAttribute('data-question-id'));
        } catch (err) {
            // IE11 quirk – the payload is not used anyway
        }
    });

    document.addEventListener('dragover', function (e) {
        if (!dragRow) { return; }

        var row = e.target && e.target.closest ? e.target.closest('[data-question-sort] tr[data-question-id]') : null;
        if (!row || row === dragRow || row.parentNode !== dragRow.parentNode) { return; }

        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';

        var rect = row.getBoundingClientRect();
        var after = (e.clientY - rect.top) > rect.height / 2;
        row.parentNode.insertBefore(dragRow, after ? row.nextSibling : row);
    });

    document.addEventListener('drop', function (e) {
        if (dragRow) { e.preventDefault(); }
    });

    document.addEventListener('dragend', function () {
        if (!dragRow) { return; }

        var row = dragRow;
        dragRow = null;
        row.classList.remove('tw-dragging');
        sync(row.closest('[data-question-sort]'));
    });

    // Re-apply the pending order whenever the dcaWizard re-renders its list.
    function observe() {
        var b = box();
        if (!b) { return; }

        var container = b.closest('[id^="ctrl_"]') || b.parentNode;
        if (!container || container.wfQSObserved) { return; }
        container.wfQSObserved = true;

        var obs = new MutationObserver(function () {
            var nb = container.querySelector('[data-question-sort]');
            if (!nb) { return; }

            // Pause observing while we move rows, so our own changes don't re-fire.
            obs.disconnect();
            reapply(nb);
            obs.observe(container, { childList: true, subtree: true });
        });

        obs.observe(container, { childList: true, subtree: true });
    }

    ready(observe);
})();
