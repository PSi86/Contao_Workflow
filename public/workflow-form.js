/**
 * Live document-statement hints for the workflow form.
 *
 * Each question with an explicitly configured document text ("Textbaustein")
 * carries data attributes rendered by mod_workflow_form.html5:
 *   - fieldset[data-tw-question]                 the question container
 *   - fieldset[data-statement-template]          value questions: template with ##value##
 *   - input/option[data-statement]               choice questions: resolved statement
 *   - [data-statement-hint] > [data-statement-text]  the hint output
 *
 * The hint always shows exactly the text the generated document will contain –
 * the server composes the PDF from the same statements.
 */
(function () {
    'use strict';

    function formatDate(value) {
        // HTML date inputs yield Y-m-d; the document prints d.m.Y.
        var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);

        return m ? m[3] + '.' + m[2] + '.' + m[1] : value;
    }

    function update(fieldset) {
        var hint = fieldset.querySelector('[data-statement-hint]');
        var text = fieldset.querySelector('[data-statement-text]');

        if (!hint || !text) {
            return;
        }

        var parts = [];
        var template = fieldset.getAttribute('data-statement-template');

        if (template !== null) {
            var input = fieldset.querySelector('input:not([type=hidden]), textarea');
            var value = input ? input.value.trim() : '';

            if (value !== '') {
                if (input && input.type === 'date') {
                    value = formatDate(value);
                }
                parts.push(template.split('##value##').join(value));
            }
        } else {
            var select = fieldset.querySelector('select');

            if (select) {
                var option = select.options[select.selectedIndex];

                if (option && option.value !== '' && option.hasAttribute('data-statement')) {
                    parts.push(option.getAttribute('data-statement'));
                }
            } else {
                fieldset.querySelectorAll('input[data-statement]:checked').forEach(function (box) {
                    if (box.getAttribute('data-statement') !== '') {
                        parts.push(box.getAttribute('data-statement'));
                    }
                });
            }
        }

        if (parts.length) {
            text.textContent = parts.join('\n');
            hint.hidden = false;
        } else {
            text.textContent = '';
            hint.hidden = true;
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.tw-form fieldset[data-tw-question]').forEach(function (fieldset) {
            // Read-only fields (currentTime) keep their server-rendered hint.
            if (!fieldset.querySelector('input:not([readonly]):not([type=hidden]), textarea, select')) {
                return;
            }

            ['input', 'change'].forEach(function (event) {
                fieldset.addEventListener(event, function () {
                    update(fieldset);
                });
            });

            update(fieldset);
        });
    });
})();
