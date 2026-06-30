/**
 * Template-driven editor for a master's "PDF-Variablen" (custom backend widget
 * PdfVarsWidget). Renders one labelled value field per variable the selected
 * master template declares ($GLOBALS['TL_WORKFLOW_PDF_VARS'], passed as
 * data-registry) and REBUILDS them instantly when the template select changes –
 * no save needed. Variables not declared by the template are shown in a small
 * "custom" section (free key/value).
 *
 * Inputs stay named pdfData[i][key]/[value] (reindexed on every change), so the
 * stored format is identical to the old MultiColumnWizard and the field is
 * versioned normally. Without JS the server-rendered flat rows remain editable.
 */
(function () {
    'use strict';

    function ready(fn) {
        if (document.readyState !== 'loading') { fn(); }
        else { document.addEventListener('DOMContentLoaded', fn); }
    }

    function PdfVars(box) {
        this.box = box;
        this.field = box.getAttribute('data-name');
        this.registry = {};
        try { this.registry = JSON.parse(box.getAttribute('data-registry')) || {}; } catch (e) { this.registry = {}; }
        this.rowsBox = box.querySelector('.wf-pdfvars-rows');
        this.select = document.getElementById(box.getAttribute('data-template-field'));

        this.labels = {
            template: box.getAttribute('data-label-template'),
            custom: box.getAttribute('data-label-custom'),
            key: box.getAttribute('data-label-key'),
            value: box.getAttribute('data-label-value'),
            remove: box.getAttribute('data-label-remove')
        };

        this.bind();
        this.render();
    }

    // Current rows as an ordered [{key, value}] list (reads the live inputs).
    PdfVars.prototype.collect = function () {
        var out = [];
        this.rowsBox.querySelectorAll('[data-row]').forEach(function (row) {
            var k = row.querySelector('[data-key]');
            var v = row.querySelector('[data-value]');
            out.push({ key: k ? k.value : '', value: v ? v.value : '' });
        });
        return out;
    };

    PdfVars.prototype.declaredKeys = function () {
        var tpl = this.select ? this.select.value : '';
        var declared = this.registry[tpl] || {};
        return Object.keys(declared);
    };

    // Sequentially renumber every row's inputs to pdfData[i][key]/[value].
    PdfVars.prototype.reindex = function () {
        var field = this.field;
        var i = 0;
        this.rowsBox.querySelectorAll('[data-row]').forEach(function (row) {
            var k = row.querySelector('[data-key]');
            var v = row.querySelector('[data-value]');
            if (k) { k.name = field + '[' + i + '][key]'; }
            if (v) { v.name = field + '[' + i + '][value]'; }
            i++;
        });
    };

    PdfVars.prototype.makeInput = function (type, cls, mark, value, placeholder) {
        var el = document.createElement('input');
        el.type = type;
        if (cls) { el.className = cls; }
        el.setAttribute(mark, '');
        el.value = value != null ? value : '';
        if (placeholder) { el.placeholder = placeholder; }
        return el;
    };

    // Value fields are textareas so multi-line values stay possible (as before).
    PdfVars.prototype.makeValue = function (value, placeholder) {
        var el = document.createElement('textarea');
        el.className = 'tl_textarea wf-pdfvars-v';
        el.rows = 2;
        el.setAttribute('data-value', '');
        el.value = value != null ? value : '';
        if (placeholder) { el.placeholder = placeholder; }
        return el;
    };

    PdfVars.prototype.group = function (cls, heading) {
        var g = document.createElement('div');
        g.className = 'wf-pdfvars-group ' + cls;
        var h = document.createElement('h4');
        h.className = 'wf-pdfvars-h';
        h.textContent = heading || '';
        g.appendChild(h);
        return g;
    };

    // Declared variable: fixed key (hidden) + labelled value field.
    PdfVars.prototype.templateRow = function (key, value) {
        var row = document.createElement('div');
        row.className = 'wf-pdfvars-row';
        row.setAttribute('data-row', '');

        var label = document.createElement('label');
        label.className = 'wf-pdfvars-label';
        label.textContent = key;

        row.appendChild(label);
        row.appendChild(this.makeInput('hidden', '', 'data-key', key));
        row.appendChild(this.makeValue(value));
        return row;
    };

    // Custom variable: editable key + value + remove.
    PdfVars.prototype.customRow = function (key, value) {
        var row = document.createElement('div');
        row.className = 'wf-pdfvars-row wf-pdfvars-row--custom';
        row.setAttribute('data-row', '');

        row.appendChild(this.makeInput('text', 'tl_text wf-pdfvars-k', 'data-key', key, this.labels.key));
        row.appendChild(this.makeValue(value, this.labels.value));

        var del = document.createElement('button');
        del.type = 'button';
        del.className = 'wf-pdfvars-del';
        del.title = this.labels.remove || '';
        del.innerHTML = '&times;';
        row.appendChild(del);
        return row;
    };

    // Rebuild both groups from the current values + the selected template.
    PdfVars.prototype.render = function () {
        var rows = this.collect();
        var declared = this.declaredKeys();

        var lookup = {};
        rows.forEach(function (r) {
            if (r.key !== '' && !(r.key in lookup)) { lookup[r.key] = r.value; }
        });

        var tplGroup = this.group('wf-pdfvars-template', this.labels.template);
        var self = this;
        declared.forEach(function (key) {
            var val = (key in lookup) ? lookup[key] : (self.registry[self.select.value][key] || '');
            tplGroup.appendChild(self.templateRow(key, val));
        });
        tplGroup.style.display = declared.length ? '' : 'none';

        var customGroup = this.group('wf-pdfvars-custom', this.labels.custom);
        rows.forEach(function (r) {
            if (r.key !== '' && declared.indexOf(r.key) === -1) {
                customGroup.appendChild(self.customRow(r.key, r.value));
            }
        });

        this.rowsBox.innerHTML = '';
        this.rowsBox.appendChild(tplGroup);
        this.rowsBox.appendChild(customGroup);
        this.reindex();
    };

    PdfVars.prototype.addCustom = function () {
        var group = this.rowsBox.querySelector('.wf-pdfvars-custom');
        if (!group) {
            group = this.group('wf-pdfvars-custom', this.labels.custom);
            this.rowsBox.appendChild(group);
        }
        var row = this.customRow('', '');
        group.appendChild(row);
        this.reindex();
        var k = row.querySelector('[data-key]');
        if (k) { k.focus(); }
    };

    PdfVars.prototype.bind = function () {
        var self = this;

        if (this.select) {
            this.select.addEventListener('change', function () { self.render(); });
        }

        var add = this.box.querySelector('.wf-pdfvars-add');
        if (add) {
            add.addEventListener('click', function (e) { e.preventDefault(); self.addCustom(); });
        }

        // Remove a custom row (delegated, survives re-render).
        this.rowsBox.addEventListener('click', function (e) {
            var del = e.target.closest ? e.target.closest('.wf-pdfvars-del') : null;
            if (!del) { return; }
            e.preventDefault();
            var row = del.closest('[data-row]');
            if (row) { row.parentNode.removeChild(row); self.reindex(); }
        });
    };

    ready(function () {
        document.querySelectorAll('.wf-pdfvars').forEach(function (box) {
            if (box.wfPdfVarsReady) { return; }
            box.wfPdfVarsReady = true;
            new PdfVars(box);
        });
    });
})();
