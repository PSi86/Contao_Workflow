/**
 * Live document-statement hints for the workflow form.
 *
 * Each editable question with an explicitly configured document text
 * ("Textbaustein") carries data attributes rendered by mod_workflow_form.html5:
 *   - fieldset[data-tw-question]                 the question container
 *   - fieldset[data-statement-template]          per-question template with ##value##
 *   - input/option[data-statement]               choice questions: resolved option statement
 *   - [data-statement-hint] > [data-statement-text]  the hint output
 *
 * For choice questions ##value## in the template stands for the selected
 * option statement(s) (joined with ", "), mirroring the server-side
 * DocumentBodyComposer. Read-only/auto-filled fields keep their
 * server-rendered hint (no data-tw-question attribute).
 */
(function () {
    'use strict';

    function formatDate(value) {
        // HTML date inputs yield Y-m-d; the document prints d.m.Y.
        var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);

        return m ? m[3] + '.' + m[2] + '.' + m[1] : value;
    }

    function selectedStatements(fieldset) {
        var select = fieldset.querySelector('select');

        if (select) {
            var option = select.options[select.selectedIndex];

            if (option && option.value !== '' && option.getAttribute('data-statement')) {
                return [option.getAttribute('data-statement')];
            }

            return [];
        }

        var parts = [];

        fieldset.querySelectorAll('input[data-statement]:checked').forEach(function (box) {
            if (box.getAttribute('data-statement') !== '') {
                parts.push(box.getAttribute('data-statement'));
            }
        });

        return parts;
    }

    function update(fieldset) {
        var hint = fieldset.querySelector('[data-statement-hint]');
        var text = fieldset.querySelector('[data-statement-text]');

        if (!hint || !text) {
            return;
        }

        var template = fieldset.getAttribute('data-statement-template');
        var result = '';

        if (fieldset.querySelector('[data-statement]')) {
            var parts = selectedStatements(fieldset);

            if (parts.length) {
                result = template !== null
                    ? template.split('##value##').join(parts.join(', '))
                    : parts.join('\n');
            }
        } else if (template !== null) {
            var input = fieldset.querySelector('input:not([type=hidden]), textarea');
            var value = input ? input.value.trim() : '';

            if (value !== '') {
                if (input && input.type === 'date') {
                    value = formatDate(value);
                }
                result = template.split('##value##').join(value);
            }
        }

        text.textContent = result;
        hint.hidden = result === '';
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.tw-form fieldset[data-tw-question]').forEach(function (fieldset) {
            ['input', 'change'].forEach(function (event) {
                fieldset.addEventListener(event, function () {
                    update(fieldset);
                });
            });

            update(fieldset);
        });
    });
})();
