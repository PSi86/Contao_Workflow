/**
 * Drag&drop reordering of the lists embedded in the workflow edit mask: the answer
 * fields (AnswerConfigListener::renderQuestionsList) and the document texts
 * (AnswerConfigListener::renderRulesList).
 *
 * A list is marked with data-wf-sort="<field>", its rows with data-sort-id="<id>".
 * Reordering does NOT write to the server immediately. The new order is kept in the
 * hidden workflow field #ctrl_<field> (questionOrder / ruleOrder – real tl_workflow
 * columns, rendered OUTSIDE the dcaWizard widget so they survive the widget's partial
 * AJAX refresh) and is only persisted when the workflow is saved: the field's
 * save_callback renumbers the child sorting, so the reordering is also versioned.
 *
 * The dcaWizard re-renders just its own widget after a row modal is closed
 * (reloadDcaWizard → replaces #ctrl_<widget> innerHTML); that re-render comes back
 * in stored (DB) order. A MutationObserver therefore re-applies the pending order
 * to the freshly rendered rows, so an in-progress reorder is not lost when the
 * user adds/edits/deletes a single row before saving.
 *
 * A list with data-wf-default-last keeps its warning ([data-wf-default-warning]) in
 * step with the order: the row marked data-wf-default (the document text without
 * conditions, which always matches) has to come last, or every row below it is never
 * reached.
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

    function lists() {
        return Array.prototype.slice.call(document.querySelectorAll('[data-wf-sort]'));
    }

    function rowsIn(list) {
        return list ? Array.prototype.slice.call(list.querySelectorAll('tr[data-sort-id]')) : [];
    }

    // The real tl_workflow field behind a list (rendered outside the dcaWizard widget,
    // so it survives the widget's partial refresh). Always present on the edit mask.
    function pendingField(list) {
        return document.getElementById('ctrl_' + list.getAttribute('data-wf-sort'));
    }

    function checkDefaultLast(list) {
        var warning = list.hasAttribute('data-wf-default-last') ? list.querySelector('[data-wf-default-warning]') : null;

        if (!warning) { return; }

        var rows = rowsIn(list);
        var misplaced = rows.some(function (row, i) {
            return row.hasAttribute('data-wf-default') && i !== rows.length - 1;
        });

        warning.hidden = !misplaced;
    }

    // Store the current visible order into the hidden field.
    function sync(list) {
        if (!list) { return; }

        var field = pendingField(list);

        if (field) {
            field.value = rowsIn(list).map(function (r) { return r.getAttribute('data-sort-id'); }).join(',');
        }

        checkDefaultLast(list);

        // The answer-field order decides whether the visibility conditions can still be
        // evaluated (they may only reference PRECEDING fields). workflow-question-deps.js
        // listens and marks a violation immediately; the save refuses it as well, but only
        // after the round trip.
        if ('questionOrder' === list.getAttribute('data-wf-sort')) {
            document.dispatchEvent(new CustomEvent('wf-question-order-changed'));
        }
    }

    // Reorder freshly rendered rows to match the pending order; ids not in it
    // (e.g. a just-added row) keep their place after the known ones. Then
    // refresh the hidden field so it always mirrors the visible order.
    function reapply(list) {
        var field = pendingField(list);

        if (!field || !field.value) {
            checkDefaultLast(list);
            return;
        }

        var tbody = list.querySelector('tbody');
        if (!tbody) { return; }

        var byId = {};
        rowsIn(list).forEach(function (r) { byId[r.getAttribute('data-sort-id')] = r; });

        field.value.split(',').forEach(function (id) {
            if (byId[id]) { tbody.appendChild(byId[id]); }
        });

        sync(list);
    }

    document.addEventListener('dragstart', function (e) {
        var handle = e.target && e.target.closest ? e.target.closest('[data-wf-sort] .tw-drag-handle') : null;
        if (!handle) { return; }

        dragRow = handle.closest('tr[data-sort-id]');
        dragRow.classList.add('tw-dragging');
        e.dataTransfer.effectAllowed = 'move';

        try {
            e.dataTransfer.setData('text/plain', dragRow.getAttribute('data-sort-id'));
        } catch (err) {
            // IE11 quirk – the payload is not used anyway
        }
    });

    document.addEventListener('dragover', function (e) {
        if (!dragRow) { return; }

        // Only within the list the drag started in: the same tbody.
        var row = e.target && e.target.closest ? e.target.closest('[data-wf-sort] tr[data-sort-id]') : null;
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
        sync(row.closest('[data-wf-sort]'));
    });

    // Re-apply the pending order whenever the dcaWizard RE-RENDERS a list (modal close →
    // reloadDcaWizard replaces the widget HTML, so the list element is a NEW node). We
    // must NOT react to our own row moves during a drag (same list element) – otherwise
    // the stored order would be re-applied mid-drag and snap the row back, blocking any
    // further reordering until a refresh/save.
    function observe() {
        lists().forEach(function (list) {
            checkDefaultLast(list);

            var container = list.closest('[id^="ctrl_"]') || list.parentNode;
            if (!container || container.wfSortObserved) { return; }
            container.wfSortObserved = true;
            container.wfSortList = list;

            new MutationObserver(function () {
                var current = container.querySelector('[data-wf-sort]');

                // Same list element → just a row move from dragging → ignore.
                if (!current || current === container.wfSortList) { return; }

                container.wfSortList = current;
                reapply(current);
            }).observe(container, { childList: true, subtree: true });
        });
    }

    ready(observe);
    // The back end navigates with Turbo (Contao 5.4+): the mask can arrive without a page
    // load. The drag listeners above sit on the document and need no re-binding.
    document.addEventListener('turbo:render', observe);
})();
