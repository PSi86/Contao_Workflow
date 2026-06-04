/* Back end dashboard interactions: pending-list sorting + selection and the
   unified "send e-mail" dialog (automatic / manual, with a confirmation step). */
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
        var dialog = box.querySelector('.wf-dialog');
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
            hint.textContent = manual
                ? 'Es werden nur die markierten Teilnehmer mit passendem Status berücksichtigt.'
                : 'Die Adressaten werden automatisch nach Status gewählt.';
        }
    }

    function setupDialog(box) {
        var dialog = box.querySelector('.wf-dialog');
        if (!dialog) { return; }
        var form = dialog.querySelector('.wf-step2');
        var step1 = dialog.querySelector('.wf-step1');
        form.action = dialog.dataset.sendUrl;

        var open = box.querySelector('.wf-open-dialog');
        if (open) {
            open.addEventListener('click', function () {
                step1.hidden = false;
                form.hidden = true;
                dialog.hidden = false;
                updateCounts(box);
            });
        }

        var close = function () { dialog.hidden = true; };
        dialog.querySelector('.wf-dialog-close').addEventListener('click', close);
        dialog.addEventListener('click', function (e) { if (e.target === dialog) { close(); } });
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
                    alert('Es gibt keine passenden Empfänger für diese Aktion.');
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
                form.querySelector('.wf-confirm-head').textContent =
                    'Folgende ' + targets.length + ' Empfänger erhalten die '
                    + (type === 'invite' ? 'Einladung' : 'Erinnerung') + ':';

                step1.hidden = true;
                form.hidden = false;
            });
        });

        updateCounts(box);
    }

    ready(function () {
        document.querySelectorAll('.wf-box').forEach(function (box) {
            setupSorting(box);
            setupSelection(box);
            setupDialog(box);
        });
    });
})();
