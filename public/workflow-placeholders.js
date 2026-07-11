/* Placeholder helper for the workflow settings: a small autocomplete that lists
   the available ##tokens## for the field and filters them as you type. The token
   list is provided per field as JSON in the data-wf-autosuggest attribute; an
   optional data-wf-insert-tags attribute adds Contao insert tags ("{{…}}"),
   triggered by typing "{".

   Unlike a whitespace-based trigger, the helper recognises a ##…## token (or a
   {{…}} insert tag) fragment directly at the caret even when it is glued to
   surrounding text (e.g. the PDF file-name pattern "Verzicht_##data_name##"), so it
   also pops up while editing an existing placeholder – not only when starting a
   fresh one. */
(function () {
    'use strict';

    function ready(fn) {
        if (document.readyState !== 'loading') { fn(); }
        else { document.addEventListener('DOMContentLoaded', fn); }
    }

    // Token fragment immediately BEFORE the caret: one or two "#", optional name
    // characters, optional up to two closing "#". Anchored at the end of the text.
    var OPEN = /#{1,2}[A-Za-z0-9_]*#{0,2}$/;
    // Remainder of the token AFTER the caret: name characters up to the closing "##".
    var TAIL = /^[A-Za-z0-9_]*##/;
    // Insert-tag fragment before the caret: "{" or "{{" followed by tag characters
    // (anything but a brace). Anchored at the end of the text.
    var OPEN_TAG = /\{\{?[^{}]*$/;
    // Remainder of an insert tag AFTER the caret: tag characters up to the "}}".
    var TAIL_TAG = /^[^{}]*\}\}/;

    function Autosuggest(el, tokens, insertTags) {
        this.el = el;
        this.tokens = (tokens || []).map(function (t) {
            return { name: '##' + t.name + '##', label: t.label || '' };
        }).concat((insertTags || []).map(function (t) {
            return { name: '{{' + t.name + '}}', label: t.label || '' };
        }));
        this.names = this.tokens.map(function (t) { return t.name; });
        this.items = [];
        this.visible = [];     // token indexes currently shown
        this.active = -1;      // token index highlighted
        this.open = false;
        this.filter = '';
        this.fragStart = 0;
        this.fragEnd = 0;

        this.build();
        this.bind();
    }

    Autosuggest.prototype.build = function () {
        var self = this;

        this.box = document.createElement('div');
        this.box.className = 'wf-ph-box';

        var list = document.createElement('ul');
        list.className = 'wf-ph-list';

        this.tokens.forEach(function (token, i) {
            var li = document.createElement('li');
            li.className = 'wf-ph-item';
            li.innerHTML = '<span class="wf-ph-value"></span><span class="wf-ph-label"></span>';
            li.querySelector('.wf-ph-value').textContent = token.name;
            li.querySelector('.wf-ph-label').textContent = token.label;

            li.addEventListener('mouseenter', function () { self.highlight(i); });
            // mousedown (not click) so the field does not blur before we insert.
            li.addEventListener('mousedown', function (e) {
                e.preventDefault();
                self.active = i;
                self.select();
            });

            self.items[i] = li;
            list.appendChild(li);
        });

        this.box.appendChild(list);
        document.body.appendChild(this.box);
    };

    Autosuggest.prototype.bind = function () {
        var self = this;

        this.el.addEventListener('input', function () { self.update(); });
        this.el.addEventListener('click', function () { self.update(); });
        this.el.addEventListener('keyup', function (e) {
            // Caret moved without changing the text → re-evaluate.
            if (e.key === 'ArrowLeft' || e.key === 'ArrowRight' || e.key === 'Home' || e.key === 'End') {
                self.update();
            }
        });
        this.el.addEventListener('keydown', function (e) { self.onKeyDown(e); });
        this.el.addEventListener('blur', function () {
            // Delay so a mousedown on an item still registers.
            window.setTimeout(function () { self.hide(); }, 150);
        });

        document.addEventListener('mousedown', function (e) {
            if (self.open && e.target !== self.el && !self.box.contains(e.target)) {
                self.hide();
            }
        });
    };

    // Returns the token/insert-tag fragment around the caret, or null if none.
    Autosuggest.prototype.fragment = function () {
        var pos = this.el.selectionStart;
        if (pos === null || pos === undefined) { return null; }

        var before = this.el.value.slice(0, pos);
        var after = this.el.value.slice(pos);

        // Contao insert-tag fragment ("{{ … ") at the caret. Its filter starts with
        // "{", so only the {{…}} entries match it (## tokens can never).
        var tagMatch = before.match(OPEN_TAG);
        if (tagMatch) {
            var openTag = tagMatch[0];
            var closeTag = '';
            // Inside an insert tag, absorb the "}}" (with any tag characters up to it)
            // so a mid-tag edit replaces the whole tag; adjacent tags are not swallowed
            // (TAIL_TAG stops at the first "}}").
            var tailTag = after.match(TAIL_TAG);
            if (tailTag) { closeTag = tailTag[0]; }

            return { filter: openTag, start: pos - openTag.length, end: pos + closeTag.length };
        }

        var openMatch = before.match(OPEN);
        if (!openMatch) { return null; }

        var open = openMatch[0];

        // Optionally absorb the rest of the token AFTER the caret, but only when it
        // reconstructs a KNOWN token. This lets a mid-token edit replace the whole
        // token, while never swallowing the "_" separator between two glued tokens.
        var close = '';
        if (!/#$/.test(open)) {
            var tail = after.match(TAIL);
            if (tail && this.names.indexOf(open + tail[0]) !== -1) {
                close = tail[0];
            }
        }

        return {
            filter: open,
            start: pos - open.length,
            end: pos + close.length,
        };
    };

    Autosuggest.prototype.update = function () {
        var frag = this.fragment();
        if (!frag) { this.hide(); return; }

        this.filter = frag.filter;
        this.fragStart = frag.start;
        this.fragEnd = frag.end;

        if (this.applyFilter() === 0) { this.hide(); return; }
        this.show();
    };

    // Shows the tokens whose name starts with the (partial) fragment and is longer
    // than it – a fully typed token suggests nothing. Returns the visible count.
    Autosuggest.prototype.applyFilter = function () {
        var self = this;
        this.visible = [];

        this.tokens.forEach(function (token, i) {
            var match = token.name.length > self.filter.length && token.name.indexOf(self.filter) === 0;
            self.items[i].classList.toggle('wf-ph-hidden', !match);
            if (match) { self.visible.push(i); }
        });

        this.active = -1;
        return this.visible.length;
    };

    Autosuggest.prototype.onKeyDown = function (e) {
        if (!this.open) { return; }

        switch (e.key) {
            case 'ArrowDown':
                e.preventDefault();
                this.move(1);
                break;
            case 'ArrowUp':
                e.preventDefault();
                this.move(-1);
                break;
            case 'Enter':
                if (this.active >= 0) { e.preventDefault(); this.select(); }
                break;
            case 'Escape':
                e.preventDefault();
                this.hide();
                break;
            default:
                // ignore
        }
    };

    Autosuggest.prototype.move = function (dir) {
        if (this.visible.length === 0) { return; }

        var cur = this.visible.indexOf(this.active);
        var next;

        if (cur === -1) {
            next = dir > 0 ? 0 : this.visible.length - 1;
        } else {
            next = Math.min(Math.max(cur + dir, 0), this.visible.length - 1);
        }

        this.highlight(this.visible[next]);
    };

    Autosuggest.prototype.highlight = function (i) {
        if (this.active >= 0 && this.items[this.active]) {
            this.items[this.active].classList.remove('wf-ph-active');
        }

        this.active = i;
        var li = this.items[i];
        li.classList.add('wf-ph-active');

        // Keep the highlighted item in view.
        var top = li.offsetTop;
        var bottom = top + li.offsetHeight;
        if (bottom > this.box.scrollTop + this.box.clientHeight) {
            this.box.scrollTop = bottom - this.box.clientHeight;
        } else if (top < this.box.scrollTop) {
            this.box.scrollTop = top;
        }
    };

    Autosuggest.prototype.select = function () {
        if (this.active < 0) { return; }

        var token = this.tokens[this.active].name;
        var value = this.el.value;
        this.el.value = value.slice(0, this.fragStart) + token + value.slice(this.fragEnd);

        var caret = this.fragStart + token.length;
        this.hide();
        this.el.focus();
        this.el.setSelectionRange(caret, caret);

        // Let Contao's change tracking notice the programmatic edit.
        this.el.dispatchEvent(new Event('input', { bubbles: true }));
    };

    Autosuggest.prototype.show = function () {
        var rect = this.el.getBoundingClientRect();
        this.box.style.left = (rect.left + window.scrollX) + 'px';
        this.box.style.top = (rect.bottom + window.scrollY) + 'px';
        this.box.style.minWidth = Math.min(rect.width, 420) + 'px';
        this.box.style.display = 'block';
        this.box.scrollTop = 0;
        this.open = true;
    };

    Autosuggest.prototype.hide = function () {
        if (this.active >= 0 && this.items[this.active]) {
            this.items[this.active].classList.remove('wf-ph-active');
        }
        this.active = -1;
        this.box.style.display = 'none';
        this.open = false;
    };

    function scan() {
        document.querySelectorAll('[data-wf-autosuggest]').forEach(function (el) {
            if (el.dataset.wfPhReady) { return; }

            var tokens;
            try {
                tokens = JSON.parse(el.getAttribute('data-wf-autosuggest'));
            } catch (e) {
                return;
            }

            var insertTags = [];
            if (el.hasAttribute('data-wf-insert-tags')) {
                try {
                    insertTags = JSON.parse(el.getAttribute('data-wf-insert-tags')) || [];
                } catch (e) {
                    insertTags = [];
                }
            }

            if ((!tokens || !tokens.length) && !insertTags.length) { return; }

            el.dataset.wfPhReady = '1';
            new Autosuggest(el, tokens || [], insertTags);
        });
    }

    ready(scan);
    // Re-scan after a Turbo navigation, if Turbo is in use (guarded by wfPhReady).
    document.addEventListener('turbo:render', scan);
})();
