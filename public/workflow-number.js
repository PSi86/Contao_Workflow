/**
 * German number formatting/parsing for the form – the browser-side mirror of the PHP
 * Excel module (Psimandl\WorkflowBundle\Excel\ValueFormatter / ValueParser).
 *
 * The live preview ("So erscheint dies im Dokument") must show exactly what the PDF will
 * contain. It cannot ask the server on every keystroke, so the rules exist twice – but the
 * *parameters* do not: decimals and grouping come from the column's format snapshot via
 * data-wf-decimals / data-wf-grouping. Nothing here decides how a column looks; it only
 * applies what PHP already worked out.
 *
 * Keep in sync with ValueFormatter/ValueParser. The rules are covered by their PHPUnit
 * tests (tests/Excel/); this file deliberately holds no rule of its own.
 */
(function () {
    'use strict';

    // A German number with grouping and no decimals ("1.234"): every dot followed by
    // exactly three digits. Mirrors ValueParser::GROUPED.
    var GROUPED = /^[+-]?\d{1,3}(\.\d{3})+$/;

    /**
     * The number in a string, or null. German is unambiguous: a dot is never a decimal
     * separator, so "1.234" is 1234 – reading it as 1.234 is the factor-1000 error this
     * whole exercise is about.
     */
    function parseNumber(value) {
        var digits = String(value).trim().replace(/[^\d,.\-+]/g, '');

        if (digits === '') {
            return null;
        }

        var hasComma = digits.indexOf(',') !== -1;
        var hasDot = digits.indexOf('.') !== -1;

        if (hasComma && hasDot) {
            // Whichever separator comes last is the decimal one.
            digits = digits.lastIndexOf(',') > digits.lastIndexOf('.')
                ? digits.replace(/\./g, '').replace(',', '.')
                : digits.replace(/,/g, '');
        } else if (hasComma) {
            digits = (digits.match(/,/g) || []).length > 1
                ? digits.replace(/,/g, '')
                : digits.replace(',', '.');
        } else if (hasDot && GROUPED.test(digits)) {
            digits = digits.replace(/\./g, '');
        }

        var number = Number(digits);

        return digits !== '' && isFinite(number) ? number : null;
    }

    /**
     * Renders a number the way ValueFormatter does: grouping ".", decimal ",".
     */
    function formatNumber(value, decimals, grouping) {
        var fixed = Math.abs(value).toFixed(decimals);
        var parts = fixed.split('.');
        var integer = parts[0];

        if (grouping) {
            integer = integer.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        }

        return (value < 0 ? '-' : '') + integer + (parts[1] ? ',' + parts[1] : '');
    }

    /**
     * The German rendering of what is currently typed into a number input, or the raw
     * input when it holds no number (so the preview shows the user their own text rather
     * than silently blanking).
     */
    function formatInput(input) {
        var raw = input.value.trim();

        if (raw === '') {
            return '';
        }

        var number = parseNumber(raw);

        if (number === null) {
            return raw;
        }

        return formatNumber(
            number,
            parseInt(input.getAttribute('data-wf-decimals'), 10) || 0,
            input.getAttribute('data-wf-grouping') === '1'
        );
    }

    window.WorkflowNumber = {
        parse: parseNumber,
        format: formatNumber,
        formatInput: formatInput
    };
})();
