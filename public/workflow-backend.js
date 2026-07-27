/* Back end dashboard interactions: pending-list sorting + selection, the unified
   "send e-mail" dialog (automatic / manual, with a confirmation step) and the plain
   choice dialogs for the import mode and the data download. */
(function () {
    'use strict';

    function ready(fn) {
        if (document.readyState !== 'loading') { fn(); }
        else { document.addEventListener('DOMContentLoaded', fn); }
    }

    function rows(box) {
        return Array.prototype.slice.call(box.querySelectorAll('.wf-table tbody tr'));
    }

    function checkbox(tr) {
        return tr.querySelector('input.wf-row');
    }

    // Rows whose participant status matches, optionally restricted to checked ones.
    function matching(box, status, onlyChecked) {
        return rows(box).filter(function (tr) {
            var cb = checkbox(tr);
            if (!cb) { return false; }
            if (parseInt(cb.dataset.status, 10) !== status) { return false; }
            return !onlyChecked || cb.checked;
        });
    }

    function isManual(box) {
        var r = box.querySelector('.wf-mode[value="manual"]');
        return !!(r && r.checked);
    }

    function setupSorting(box) {
        box.querySelectorAll('.wf-table .wf-sortable').forEach(function (th) {
            th.style.cursor = 'pointer';
            th.addEventListener('click', function () {
                var key = th.dataset.key;
                var tbody = box.querySelector('.wf-table tbody');
                var asc = th.dataset.dir !== 'asc';
                box.querySelectorAll('.wf-sortable').forEach(function (o) { o.removeAttribute('data-dir'); });
                th.dataset.dir = asc ? 'asc' : 'desc';

                rows(box).sort(function (a, b) {
                    var x = (a.querySelector('[data-k="' + key + '"]').textContent || '').trim().toLowerCase();
                    var y = (b.querySelector('[data-k="' + key + '"]').textContent || '').trim().toLowerCase();
                    if (x < y) { return asc ? -1 : 1; }
                    if (x > y) { return asc ? 1 : -1; }
                    return 0;
                }).forEach(function (tr) { tbody.appendChild(tr); });
            });
        });
    }

    function setupSelection(box) {
        var setAll = function (on) {
            box.querySelectorAll('input.wf-row').forEach(function (cb) { cb.checked = on; });
            updateCounts(box);
        };
        var allBtn = box.querySelector('.wf-sel-all');
        var noneBtn = box.querySelector('.wf-sel-none');
        if (allBtn) { allBtn.addEventListener('click', function () { setAll(true); }); }
        if (noneBtn) { noneBtn.addEventListener('click', function () { setAll(false); }); }

        box.querySelectorAll('.wf-sel-status').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var status = parseInt(btn.dataset.status, 10);
                box.querySelectorAll('input.wf-row').forEach(function (cb) {
                    cb.checked = parseInt(cb.dataset.status, 10) === status;
                });
                updateCounts(box);
            });
        });

        box.querySelectorAll('input.wf-row').forEach(function (cb) {
            cb.addEventListener('change', function () { updateCounts(box); });
        });
    }

    function updateCounts(box) {
        var dialog = box.querySelector('.wf-dialog--send');
        if (!dialog) { return; }
        var manual = isManual(box);
        dialog.querySelectorAll('.wf-send').forEach(function (btn) {
            var status = parseInt(dialog.dataset[btn.dataset.type + 'Status'], 10);
            var n = matching(box, status, manual).length;
            var cnt = btn.querySelector('.wf-cnt');
            if (cnt) { cnt.textContent = n; }
        });
        var hint = dialog.querySelector('.wf-hint');
        if (hint) {
            hint.textContent = manual ? dialog.dataset.hintManual : dialog.dataset.hintAuto;
        }
    }

    // Open/close wiring shared by every dialog of a workflow box: the × button and a click
    // on the backdrop close it, the given button opens it. Returns the dialog (or null when
    // the box has none, e.g. the import dialog of a workflow that cannot run).
    function wireDialog(box, dialogSelector, openSelector, onOpen) {
        var dialog = box.querySelector(dialogSelector);
        if (!dialog) { return null; }

        var open = box.querySelector(openSelector);
        if (open) {
            open.addEventListener('click', function () {
                if (onOpen) { onOpen(); }
                dialog.hidden = false;
            });
        }

        var close = function () { dialog.hidden = true; };
        dialog.querySelector('.wf-dialog-close').addEventListener('click', close);
        dialog.addEventListener('click', function (e) { if (e.target === dialog) { close(); } });

        return dialog;
    }

    function setupDialog(box) {
        var probe = box.querySelector('.wf-dialog--send');
        if (!probe) { return; }
        var form = probe.querySelector('.wf-step2');
        var step1 = probe.querySelector('.wf-step1');
        form.action = probe.dataset.sendUrl;

        var dialog = wireDialog(box, '.wf-dialog--send', '.wf-open-dialog', function () {
            step1.hidden = false;
            form.hidden = true;
            updateCounts(box);
        });

        dialog.querySelector('.wf-back').addEventListener('click', function () {
            form.hidden = true; step1.hidden = false;
        });

        dialog.querySelectorAll('.wf-mode').forEach(function (r) {
            r.addEventListener('change', function () { updateCounts(box); });
        });

        dialog.querySelectorAll('.wf-send').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var type = btn.dataset.type;
                var status = parseInt(dialog.dataset[type + 'Status'], 10);
                var targets = matching(box, status, isManual(box));

                if (!targets.length) {
                    alert(dialog.dataset.noRecipients);
                    return;
                }

                var list = form.querySelector('.wf-confirm-list');
                var ids = form.querySelector('.wf-send-ids');
                list.innerHTML = '';
                ids.innerHTML = '';

                targets.forEach(function (tr) {
                    var cb = checkbox(tr);
                    var li = document.createElement('li');
                    li.textContent = (cb.dataset.name ? cb.dataset.name + ' – ' : '') + cb.dataset.email;
                    list.appendChild(li);
                    var input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'ids[]';
                    input.value = cb.value;
                    ids.appendChild(input);
                });

                form.querySelector('.wf-send-type').value = type;
                // confirmInvite / confirmReminder / confirmConfirmation
                var confirmTmpl = dialog.dataset['confirm' + type.charAt(0).toUpperCase() + type.slice(1)];
                form.querySelector('.wf-confirm-head').textContent = confirmTmpl.replace('%count%', targets.length);

                step1.hidden = true;
                form.hidden = false;
            });
        });

        updateCounts(box);
    }

    // The download dialog states what the selection produces, because the container follows
    // it: one spreadsheet comes plain, anything else in a ZIP. Same rule as the server
    // (WorkflowActionController::download) – if one changes, change both.
    function setupDownload(dialog) {
        if (!dialog) { return; }

        var form = dialog.querySelector('.wf-download');
        var result = dialog.querySelector('[data-download-result]');
        var submit = form.querySelector('button[type="submit"]');

        var update = function () {
            var checked = Array.prototype.slice
                .call(form.querySelectorAll('input[name="parts[]"]'))
                .filter(function (cb) { return cb.checked; });

            submit.disabled = 0 === checked.length;

            if (0 === checked.length) {
                result.textContent = dialog.dataset.hintNone;
            } else if (1 === checked.length && 'pdfs' !== checked[0].value) {
                result.textContent = dialog.dataset['hint' + ('xlsx' === checked[0].value ? 'Xlsx' : 'Csv')];
            } else {
                result.textContent = dialog.dataset.hintZip;
            }
        };

        form.querySelectorAll('input[name="parts[]"]').forEach(function (cb) {
            cb.addEventListener('change', update);
        });

        update();
    }

    ready(function () {
        document.querySelectorAll('.wf-box').forEach(function (box) {
            setupSorting(box);
            setupSelection(box);
            setupDialog(box);
            // The import dialog offers plain links, so it needs nothing beyond open/close.
            wireDialog(box, '.wf-dialog--import', '.wf-open-import');
            setupDownload(wireDialog(box, '.wf-dialog--download', '.wf-open-download'));
        });
    });
})();
