/**
 * Drag&drop reordering of the answer-field list embedded in the workflow edit
 * mask (rendered by AnswerConfigListener::renderQuestionsList).
 *
 * Rows are moved by their handle cell ([draggable] .tw-drag-handle); on drop
 * the new order is POSTed to the workflow_question_sort route (URL + request
 * token come from the [data-question-sort] wrapper). Uses event delegation so
 * it keeps working after the dcaWizard refreshes the list via ajax.
 */
(function () {
    'use strict';

    var dragRow = null;

    function rowOf(target) {
        return target && target.closest ? target.closest('[data-question-sort] tr[data-question-id]') : null;
    }

    document.addEventListener('dragstart', function (e) {
        var handle = e.target && e.target.closest ? e.target.closest('[data-question-sort] .tw-drag-handle') : null;

        if (!handle) {
            return;
        }

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
        if (!dragRow) {
            return;
        }

        var row = rowOf(e.target);

        if (!row || row === dragRow || row.parentNode !== dragRow.parentNode) {
            return;
        }

        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';

        var rect = row.getBoundingClientRect();
        var after = (e.clientY - rect.top) > rect.height / 2;
        row.parentNode.insertBefore(dragRow, after ? row.nextSibling : row);
    });

    document.addEventListener('drop', function (e) {
        if (dragRow) {
            e.preventDefault();
        }
    });

    document.addEventListener('dragend', function () {
        if (!dragRow) {
            return;
        }

        var row = dragRow;
        dragRow = null;
        row.classList.remove('tw-dragging');
        persist(row.closest('[data-question-sort]'));
    });

    function persist(box) {
        if (!box) {
            return;
        }

        var body = new URLSearchParams();
        body.append('REQUEST_TOKEN', box.getAttribute('data-rt'));

        box.querySelectorAll('tr[data-question-id]').forEach(function (row) {
            body.append('ids[]', row.getAttribute('data-question-id'));
        });

        fetch(box.getAttribute('data-sort-url'), {
            method: 'POST',
            body: body,
            credentials: 'same-origin'
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }

            box.classList.add('tw-sort-saved');
            setTimeout(function () {
                box.classList.remove('tw-sort-saved');
            }, 800);
        }).catch(function () {
            alert('Die neue Reihenfolge konnte nicht gespeichert werden.');
            window.location.reload();
        });
    }
})();
