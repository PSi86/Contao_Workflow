/**
 * Live document-statement hints for the workflow form.
 *
 * Each editable question with an explicitly configured document text
 * ("Textbaustein") carries data attributes rendered by mod_workflow_form.html5:
 *   - .tw-field[data-tw-question]                the question container
 *   - [data-statement-template]                  per-question template with ##answer##
 *   - input/option[data-statement]               choice questions: resolved option statement
 *   - [data-statement-hint] > [data-statement-text]  the hint output
 *
 * Choice questions carry their document text per option (no per-question
 * template), value questions use the ##answer## template – mirroring the
 * server-side DocumentBodyComposer. Read-only/auto-filled fields keep their
 * server-rendered hint (no data-tw-question attribute).
 *
 * The statement template and the option statements are SAFE HTML (server-escaped,
 * with the whitelisted [b]/[i]/[u] formatting turned into <strong>/<em>/<u>). They
 * are written via innerHTML so the formatting shows; the live answer value the user
 * types is HTML-escaped before it is inserted into that HTML.
 */
(function () {
    'use strict';

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

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
            // Choice question: the option statements are already safe HTML.
            var parts = selectedStatements(field);

            if (parts.length) {
                result = template !== null
                    ? template.split('##answer##').join(parts.join(', '))
                    : parts.join('<br>');
            }
        } else if (template !== null) {
            // Value question: the typed value is user input – escape it before it
            // enters the safe-HTML template (the template already holds <strong> etc.).
            var input = field.querySelector('input:not([type=hidden]), textarea');
            var value = input ? input.value.trim() : '';

            if (value !== '') {
                if (input && input.type === 'date') {
                    value = formatDate(value);
                }
                result = template.split('##answer##').join(escapeHtml(value));
            }
        }

        text.innerHTML = result;
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
