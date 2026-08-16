/**
 * Dependency display of the answer-field list (conditional form fields).
 *
 * The markers themselves are rendered server-side (AnswerConfigListener::dependencyMarkers);
 * this adds the two things that only make sense live:
 *
 *   1. Hovering (or focusing) a row highlights the rows it is connected to – in both
 *      directions: a trigger highlights everything that depends on it, a dependent field
 *      highlights its triggers. Rows are ordered freely and a field may have several
 *      triggers, so the connection cannot be shown by indentation.
 *   2. After a drag&drop reorder, a field that ends up ABOVE one of its triggers is marked
 *      red at once. Such an order is refused when the workflow is saved
 *      (AnswerConfigListener::saveQuestionOrder) – this is the early warning, so nobody
 *      arranges twenty fields before finding out.
 *
 * Rows carry data-wf-col (own storage column) and data-wf-depends (comma-separated columns
 * this field's conditions read). Event delegation plus a MutationObserver, because dcaWizard
 * replaces the whole widget after a row modal is closed.
 */
(function () {
    'use strict';

    function ready(fn) {
        if (document.readyState !== 'loading') { fn(); }
        else { document.addEventListener('DOMContentLoaded', fn); }
    }

    function rows() {
        return Array.prototype.slice.call(document.querySelectorAll('[data-question-sort] tr[data-question-id]'));
    }

    function dependsOf(row) {
        var value = row.getAttribute('data-wf-depends');

        return value ? value.split(',') : [];
    }

    function clearHighlight() {
        rows().forEach(function (row) { row.classList.remove('tw-dep-related'); });
    }

    function highlight(row) {
        var column = row.getAttribute('data-wf-col');
        var depends = dependsOf(row);

        clearHighlight();

        if (!column && !depends.length) {
            return;
        }

        row.classList.add('tw-dep-related');

        rows().forEach(function (other) {
            if (other === row) {
                return;
            }

            // Fields depending on this row's column …
            if (column && dependsOf(other).indexOf(column) !== -1) {
                other.classList.add('tw-dep-related');
            }

            // … and the fields this row depends on.
            if (depends.indexOf(other.getAttribute('data-wf-col')) !== -1) {
                other.classList.add('tw-dep-related');
            }
        });
    }

    /**
     * Walks the list top-down and marks every field whose trigger column has not appeared yet.
     * A column that no field writes at all is left alone: that is a broken configuration, not
     * a wrong order, and the workflow overview reports it as such.
     */
    function checkOrder() {
        var all = rows();
        var known = {};
        var available = {};
        var box = document.querySelector('[data-question-sort]');
        var message = (box && box.getAttribute('data-wf-order-error')) || '';

        all.forEach(function (row) {
            var column = row.getAttribute('data-wf-col');

            if (column) {
                known[column] = true;
            }
        });

        all.forEach(function (row) {
            var broken = dependsOf(row).filter(function (column) {
                return known[column] && !available[column];
            });

            row.classList.toggle('tw-dep-error', broken.length > 0);

            if (broken.length) {
                row.setAttribute('title', message);
            } else {
                row.removeAttribute('title');
            }

            var column = row.getAttribute('data-wf-col');

            if (column) {
                available[column] = true;
            }
        });
    }

    // Document-level listeners survive every re-render, so they are bound exactly once.
    document.addEventListener('mouseover', function (e) {
        var row = e.target && e.target.closest ? e.target.closest('[data-question-sort] tr[data-question-id]') : null;

        if (row) {
            highlight(row);
        }
    });

    document.addEventListener('mouseleave', function (e) {
        if (e.target && e.target.matches && e.target.matches('[data-question-sort]')) {
            clearHighlight();
        }
    }, true);

    // Re-run after a drop (workflow-question-sort.js).
    document.addEventListener('wf-question-order-changed', checkOrder);

    function boot() {
        var box = document.querySelector('[data-question-sort]');
        // The dcaWizard replaces its widget's innerHTML, so observe the surrounding control
        // (which survives) rather than the list itself – same anchor workflow-question-sort.js
        // uses for re-applying a pending order.
        var container = box ? (box.closest('[id^="ctrl_"]') || box.parentNode) : null;

        if (container && !container.wfDepsObserved) {
            container.wfDepsObserved = true;
            new MutationObserver(checkOrder).observe(container, { childList: true, subtree: true });
        }

        checkOrder();
    }

    ready(boot);
    // The back end navigates with Turbo, so the mask can arrive without a page load.
    document.addEventListener('turbo:render', boot);
})();
