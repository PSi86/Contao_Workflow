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
            content: box.getAttribute('data-label-content'),
            layout: box.getAttribute('data-label-layout'),
            custom: box.getAttribute('data-label-custom'),
            key: box.getAttribute('data-label-key'),
            value: box.getAttribute('data-label-value'),
            remove: box.getAttribute('data-label-remove')
        };

        this.bind();
        this.render();
    }

    // A declared variable is either a plain default (content var, label = key) or
    // {default, label, group}. Normalise to {def, label, group}.
    function meta(entry, key) {
        if (entry && typeof entry === 'object') {
            return {
                def: entry.default != null ? String(entry.default) : '',
                label: entry.label || key,
                group: entry.group || 'content'
            };
        }
        return { def: entry != null ? String(entry) : '', label: key, group: 'content' };
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

    // Declared variable: fixed key (hidden) + labelled value field. Layout metrics
    // are single-line number-ish inputs; content values are multi-line textareas.
    PdfVars.prototype.templateRow = function (key, labelText, value, isLayout) {
        var row = document.createElement('div');
        row.className = 'wf-pdfvars-row' + (isLayout ? ' wf-pdfvars-row--metric' : '');
        row.setAttribute('data-row', '');

        var label = document.createElement('label');
        label.className = 'wf-pdfvars-label';
        label.textContent = labelText || key;

        row.appendChild(label);
        row.appendChild(this.makeInput('hidden', '', 'data-key', key));
        row.appendChild(isLayout
            ? this.makeInput('text', 'tl_text wf-pdfvars-v wf-pdfvars-num', 'data-value', value)
            : this.makeValue(value));
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

    // Rebuild the groups from the current values + the selected template: declared
    // "content" vars, declared "layout" (metrics) vars, then free custom vars.
    PdfVars.prototype.render = function () {
        var self = this;
        var rows = this.collect();
        var declared = this.registry[this.select ? this.select.value : ''] || {};
        var keys = Object.keys(declared);

        var lookup = {};
        rows.forEach(function (r) {
            if (r.key !== '' && !(r.key in lookup)) { lookup[r.key] = r.value; }
        });

        var contentGroup = this.group('wf-pdfvars-content', this.labels.content);
        var layoutGroup = this.group('wf-pdfvars-layout', this.labels.layout);
        var contentCount = 0;
        var layoutCount = 0;

        keys.forEach(function (key) {
            var m = meta(declared[key], key);
            var val = (key in lookup) ? lookup[key] : m.def;
            var isLayout = m.group === 'layout';
            var row = self.templateRow(key, m.label, val, isLayout);

            if (isLayout) {
                layoutGroup.appendChild(row);
                layoutCount++;
            } else {
                contentGroup.appendChild(row);
                contentCount++;
            }
        });

        contentGroup.style.display = contentCount ? '' : 'none';
        layoutGroup.style.display = layoutCount ? '' : 'none';

        var customGroup = this.group('wf-pdfvars-custom', this.labels.custom);
        rows.forEach(function (r) {
            if (r.key !== '' && keys.indexOf(r.key) === -1) {
                customGroup.appendChild(self.customRow(r.key, r.value));
            }
        });

        this.rowsBox.innerHTML = '';
        this.rowsBox.appendChild(contentGroup);
        this.rowsBox.appendChild(layoutGroup);
        this.rowsBox.appendChild(customGroup);
        this.reindex();
        this.fitAll();
    };

    // Height that fits the content without a scrollbar. scrollHeight excludes the
    // border, but box-sizing:border-box counts it into height() – so add it, else
    // the content is a few px too tall and a scrollbar appears.
    PdfVars.prototype.autoHeight = function (el) {
        el.style.height = 'auto';
        var cs = window.getComputedStyle(el);
        var border = (parseFloat(cs.borderTopWidth) || 0) + (parseFloat(cs.borderBottomWidth) || 0);
        el.style.height = (el.scrollHeight + border) + 'px';
    };

    // Size a value field to its content: height fits all lines, width grows with
    // the longest line (from the CSS default up to the row width). min-height +
    // max-width in CSS keep the default/upper bounds; the field stays resizable.
    PdfVars.prototype.fit = function (el) {
        this.autoHeight(el);

        var lines = (el.value || '').split('\n');
        var longest = 0;
        for (var i = 0; i < lines.length; i++) {
            if (lines[i].length > longest) { longest = lines[i].length; }
        }
        el.style.width = Math.max(longest + 3, 34) + 'ch';
    };

    PdfVars.prototype.fitAll = function () {
        var self = this;
        this.rowsBox.querySelectorAll('textarea.wf-pdfvars-v').forEach(function (el) { self.fit(el); });
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

        // Grow the height while typing (grow-only, so a manual resize is kept).
        this.rowsBox.addEventListener('input', function (e) {
            var t = e.target;
            if (t && t.classList && t.classList.contains('wf-pdfvars-v') && t.scrollHeight > t.clientHeight) {
                self.autoHeight(t);
            }
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
