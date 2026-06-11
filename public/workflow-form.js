/**
 * Live document-statement hints for the workflow form.
 *
 * Each editable question with an explicitly configured document text
 * ("Textbaustein") carries data attributes rendered by mod_workflow_form.html5:
 *   - .tw-field[data-tw-question]                the question container
 *   - [data-statement-template]                  per-question template with ##value##
 *   - input/option[data-statement]               choice questions: resolved option statement
 *   - [data-statement-hint] > [data-statement-text]  the hint output
 *
 * Choice questions carry their document text per option (no per-question
 * template), value questions use the ##value## template – mirroring the
 * server-side DocumentBodyComposer. Read-only/auto-filled fields keep their
 * server-rendered hint (no data-tw-question attribute).
 */
(function () {
    'use strict';

    function formatDate(value) {
        // HTML date inputs yield Y-m-d; the document prints d.m.Y.
        var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);

        return m ? m[3] + '.' + m[2] + '.' + m[1] : value;
    }

    function selectedStatements(field) {
        var select = field.querySelector('select');

        if (select) {
            var option = select.options[select.selectedIndex];

            if (option && option.value !== '' && option.getAttribute('data-statement')) {
                return [option.getAttribute('data-statement')];
            }

            return [];
        }

        var parts = [];

        field.querySelectorAll('input[data-statement]:checked').forEach(function (box) {
            if (box.getAttribute('data-statement') !== '') {
                parts.push(box.getAttribute('data-statement'));
            }
        });

        return parts;
    }

    function update(field) {
        var hint = field.querySelector('[data-statement-hint]');
        var text = field.querySelector('[data-statement-text]');

        if (!hint || !text) {
            return;
        }

        var template = field.getAttribute('data-statement-template');
        var result = '';

        if (field.querySelector('[data-statement]')) {
            var parts = selectedStatements(field);

            if (parts.length) {
                result = template !== null
                    ? template.split('##value##').join(parts.join(', '))
                    : parts.join('\n');
            }
        } else if (template !== null) {
            var input = field.querySelector('input:not([type=hidden]), textarea');
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
        document.querySelectorAll('.tw-form [data-tw-question]').forEach(function (field) {
            ['input', 'change'].forEach(function (event) {
                field.addEventListener(event, function () {
                    update(field);
                });
            });

            update(field);
        });
    });
})();
