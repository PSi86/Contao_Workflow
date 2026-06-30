/**
 * Drag&drop reordering of the answer-field list embedded in the workflow edit
 * mask (rendered by AnswerConfigListener::renderQuestionsList).
 *
 * Reordering NO LONGER writes to the server immediately. The new order is kept
 * in the hidden workflow field #ctrl_questionOrder (a real tl_workflow column,
 * rendered OUTSIDE the dcaWizard widget so it survives the widget's partial AJAX
 * refresh) and is only persisted when the workflow is saved – its save_callback
 * renumbers the answer-field sorting, so the reordering is also versioned.
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

    // The real tl_workflow field (rendered outside the dcaWizard widget, so it
    // survives the widget's partial refresh). Always present on the edit mask.
    function pendingField() {
        return document.getElementById('ctrl_questionOrder');
    }

    // Store the current visible order into the hidden field.
    function sync(b) {
        if (!b) { return; }
        var field = pendingField();
        if (field) {
            field.value = rowsIn(b).map(function (r) { return r.getAttribute('data-question-id'); }).join(',');
        }
    }

    // Reorder freshly rendered rows to match the pending order; ids not in it
    // (e.g. a just-added field) keep their place after the known ones. Then
    // refresh the hidden field so it always mirrors the visible order.
    function reapply(b) {
        var field = pendingField();
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

    // Re-apply the pending order whenever the dcaWizard RE-RENDERS its list (modal
    // close → reloadDcaWizard replaces the widget HTML, so the wrapper element is a
    // NEW node). We must NOT react to our own row moves during a drag (same wrapper
    // element) – otherwise the stored order would be re-applied mid-drag and snap the
    // row back, blocking any further reordering until a refresh/save.
    function observe() {
        var b = box();
        if (!b) { return; }

        var container = b.closest('[id^="ctrl_"]') || b.parentNode;
        if (!container || container.wfQSObserved) { return; }
        container.wfQSObserved = true;

        var lastBox = b;

        var obs = new MutationObserver(function () {
            var nb = container.querySelector('[data-question-sort]');

            // Same wrapper element → just a row move from dragging → ignore.
            if (!nb || nb === lastBox) { return; }

            lastBox = nb;
            reapply(nb);
        });

        obs.observe(container, { childList: true, subtree: true });
    }

    ready(observe);
})();
