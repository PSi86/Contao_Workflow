/**
 * The participant's form link, shown in the token field's help text
 * (EntryFormLinkListener). Click it to copy the URL.
 *
 * It also removes the two tooltips that would otherwise sit on top of it:
 *
 *  - Contao attaches a tooltip to every "p.tl_tip" and fills it with the paragraph's own
 *    innerHTML (tips.js, useContent). For this help that produced a second, dead copy of the
 *    link box floating next to the real one, which vanished again the moment the pointer moved
 *    towards it. The paragraph is therefore swapped for a clone without that class: a clone
 *    carries no event listeners, so this works whether or not tips.js has already bound, and
 *    the MutationObserver in tips.js skips the clone because it no longer matches.
 *  - The link itself carries no title attribute, which tips.js would pick up as well (and
 *    which is useless on touch devices anyway) – the visible label says what a click does.
 *
 * Removing them helps touch operation in particular: tips.js also opens on "touchend", so on a
 * tablet the tooltip appeared instead of the link being copied.
 */
(function () {
    'use strict';

    function detachTooltip(help) {
        var clone = help.cloneNode(true);
        clone.classList.remove('tl_tip');
        help.replaceWith(clone);

        return clone;
    }

    function copy(link) {
        var text = link.textContent.trim();
        var range = document.createRange();
        var selection = window.getSelection();

        // Select it either way: even when writing to the clipboard is refused (it needs a
        // secure context), the URL is then ready for a manual copy.
        range.selectNodeContents(link);
        selection.removeAllRanges();
        selection.addRange(range);

        if (navigator.clipboard) {
            navigator.clipboard.writeText(text).catch(function () {});
        } else {
            try {
                document.execCommand('copy');
            } catch (e) {
                return;
            }
        }

        link.classList.add('wf-copied');
        setTimeout(function () {
            link.classList.remove('wf-copied');
        }, 1500);
    }

    function init() {
        document.querySelectorAll('.tw-linkhelp p.tl_tip').forEach(detachTooltip);

        document.querySelectorAll('.wf-copylink').forEach(function (link) {
            if (link.dataset.wfCopyBound) {
                return;
            }

            link.dataset.wfCopyBound = '1';
            link.addEventListener('click', function () {
                copy(link);
            });
        });
    }

    if ('loading' === document.readyState) {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
