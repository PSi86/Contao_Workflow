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
     * Renders a number the way ValueFormatter does: grouping ".", decimal ",", and the
     * column's currency symbol appended after a space.
     *
     * The currency is not decoration: the stored value carries it (the server puts it back on
     * submission), so a preview without it would promise something the document does not show.
     * Like ValueFormatter, the symbol is only appended when the format fixes the decimals –
     * a "General" column has no currency to begin with.
     */
    function formatNumber(value, decimals, grouping, currency) {
        // decimals === null is "General": keep the decimals the value carries instead of
        // forcing a count. Mirrors ValueFormatter, where a null decimals prints the value
        // as-is – forcing 0 here would round 11,56 to 12.
        var general = null === decimals || undefined === decimals;
        var fixed = general ? String(Math.abs(value)) : Math.abs(value).toFixed(decimals);
        var parts = fixed.split('.');
        var integer = parts[0];

        if (grouping) {
            integer = integer.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        }

        var out = (value < 0 ? '-' : '') + integer + (parts[1] ? ',' + parts[1] : '');

        return !general && currency ? out + ' ' + currency : out;
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

        // No data-wf-decimals means "General" – see the template. "|| 0" would have turned a
        // missing attribute into "no decimals" and silently rounded the input.
        var raw10 = input.getAttribute('data-wf-decimals');
        var decimals = null === raw10 || '' === raw10 ? null : parseInt(raw10, 10);

        return formatNumber(
            number,
            isNaN(decimals) ? null : decimals,
            input.getAttribute('data-wf-grouping') === '1',
            input.getAttribute('data-wf-currency') || ''
        );
    }

    /**
     * Whether what is currently typed can be read as a number. Empty counts as valid – whether
     * a field may be left empty is the "mandatory" flag's business, not this one's.
     */
    function isAcceptable(input) {
        var raw = input.value.trim();

        return raw === '' || parseNumber(raw) !== null;
    }

    window.WorkflowNumber = {
        parse: parseNumber,
        format: formatNumber,
        formatInput: formatInput,
        isAcceptable: isAcceptable
    };
})();
