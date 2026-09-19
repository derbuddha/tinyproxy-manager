/*
 * Searchable select - turns a native <select> into a type-to-filter combobox.
 *
 * The original <select> stays in the DOM (hidden) and remains the source of
 * truth: picking an entry sets its value and fires a `change` event, so the
 * existing inline handlers (onchange="this.form.submit()", the Live Monitor's
 * filter callback) keep working untouched, and a plain form submit still sends
 * the selected value.
 */
(function () {
    'use strict';

    var instances = new WeakMap();
    var seq = 0;

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /* Renders an option label with the typed part marked, so it's obvious why
       an entry survived the filter. */
    function labelHtml(label, query) {
        if (!query) return escapeHtml(label);
        var at = label.toLowerCase().indexOf(query.toLowerCase());
        if (at === -1) return escapeHtml(label);
        return escapeHtml(label.slice(0, at)) +
            '<span class="ss-match">' + escapeHtml(label.slice(at, at + query.length)) + '</span>' +
            escapeHtml(label.slice(at + query.length));
    }

    function Combobox(select, opts) {
        opts = opts || {};
        this.select = select;
        this.id = 'ss-' + (++seq);
        this.open = false;
        this.active = -1;
        this.matches = [];

        this.wrap = document.createElement('div');
        this.wrap.className = 'ss-wrap';
        select.parentNode.insertBefore(this.wrap, select);
        this.wrap.appendChild(select);
        select.classList.add('ss-native');
        select.setAttribute('tabindex', '-1');
        select.setAttribute('aria-hidden', 'true');

        this.input = document.createElement('input');
        this.input.type = 'text';
        this.input.className = 'ss-input';
        this.input.autocomplete = 'off';
        this.input.spellcheck = false;
        this.input.setAttribute('role', 'combobox');
        this.input.setAttribute('aria-autocomplete', 'list');
        this.input.setAttribute('aria-expanded', 'false');
        this.input.setAttribute('aria-controls', this.id);
        if (select.id) {
            // Move the <label for=...> association over to the visible input.
            var label = document.querySelector('label[for="' + select.id + '"]');
            if (label) label.setAttribute('for', this.id + '-input');
        }
        this.input.id = this.id + '-input';
        this.wrap.appendChild(this.input);

        this.arrow = document.createElement('button');
        this.arrow.type = 'button';
        this.arrow.className = 'ss-arrow';
        this.arrow.tabIndex = -1;
        this.arrow.setAttribute('aria-hidden', 'true');
        this.arrow.textContent = '▾';
        this.wrap.appendChild(this.arrow);

        this.list = document.createElement('ul');
        this.list.className = 'ss-list';
        this.list.id = this.id;
        this.list.setAttribute('role', 'listbox');
        this.list.hidden = true;
        this.wrap.appendChild(this.list);

        this.readOptions();
        this.placeholder = opts.placeholder || this.emptyLabel || 'Type to filter...';
        this.sync();
        this.bind();
    }

    Combobox.prototype.readOptions = function () {
        this.options = [];
        this.emptyLabel = '';
        for (var i = 0; i < this.select.options.length; i++) {
            var o = this.select.options[i];
            if (o.value === '') {
                this.emptyLabel = o.text;
                continue; // the empty choice is offered as "clear", not as a row
            }
            this.options.push({value: o.value, label: o.text});
        }
    };

    /* Mirrors the <select>'s current value into the visible input. */
    Combobox.prototype.sync = function () {
        var value = this.select.value;
        var match = this.options.filter(function (o) { return o.value === value; })[0];
        this.input.value = match ? match.label : '';
        this.input.placeholder = this.placeholder;
    };

    Combobox.prototype.render = function (query) {
        var self = this;
        var q = (query || '').trim().toLowerCase();
        this.matches = this.options.filter(function (o) {
            return q === '' || o.label.toLowerCase().indexOf(q) !== -1;
        });

        var html = '';
        if (this.emptyLabel && (q === '' || this.emptyLabel.toLowerCase().indexOf(q) !== -1)) {
            this.matches.unshift({value: '', label: this.emptyLabel});
        }
        if (!this.matches.length) {
            html = '<li class="ss-empty">No container matches "' + escapeHtml(query) + '"</li>';
        } else {
            this.matches.forEach(function (o, i) {
                var selected = o.value === self.select.value;
                html += '<li class="ss-option' + (selected ? ' ss-selected' : '') + '"' +
                    ' id="' + self.id + '-opt-' + i + '" role="option" data-index="' + i + '"' +
                    ' aria-selected="' + (selected ? 'true' : 'false') + '">' +
                    (o.value === '' ? escapeHtml(o.label) : labelHtml(o.label, query.trim())) +
                    '</li>';
            });
        }
        this.list.innerHTML = html;

        // Pre-highlight the current selection, or the first match while filtering.
        var preferred = this.matches.findIndex(function (o) { return o.value === self.select.value; });
        this.setActive(q === '' && preferred !== -1 ? preferred : (this.matches.length ? 0 : -1));
    };

    Combobox.prototype.setActive = function (index) {
        var items = this.list.querySelectorAll('.ss-option');
        for (var i = 0; i < items.length; i++) items[i].classList.remove('ss-active');
        this.active = index;
        if (index < 0 || index >= items.length) {
            this.input.removeAttribute('aria-activedescendant');
            return;
        }
        items[index].classList.add('ss-active');
        this.input.setAttribute('aria-activedescendant', items[index].id);
        var item = items[index];
        if (item.offsetTop < this.list.scrollTop) {
            this.list.scrollTop = item.offsetTop;
        } else if (item.offsetTop + item.offsetHeight > this.list.scrollTop + this.list.clientHeight) {
            this.list.scrollTop = item.offsetTop + item.offsetHeight - this.list.clientHeight;
        }
    };

    Combobox.prototype.show = function (query) {
        this.render(query === undefined ? this.input.value : query);
        this.list.hidden = false;
        this.open = true;
        this.input.setAttribute('aria-expanded', 'true');
        this.wrap.classList.add('ss-open');
    };

    Combobox.prototype.hide = function () {
        this.list.hidden = true;
        this.open = false;
        this.active = -1;
        this.input.setAttribute('aria-expanded', 'false');
        this.input.removeAttribute('aria-activedescendant');
        this.wrap.classList.remove('ss-open');
    };

    Combobox.prototype.commit = function (index) {
        var choice = this.matches[index];
        if (!choice) return;
        this.hide();
        if (this.select.value !== choice.value) {
            this.select.value = choice.value;
            this.select.dispatchEvent(new Event('change', {bubbles: true}));
        }
        this.sync();
    };

    Combobox.prototype.bind = function () {
        var self = this;

        this.input.addEventListener('focus', function () {
            self.input.select(); // typing replaces the shown selection
            self.show('');
        });

        this.input.addEventListener('input', function () {
            self.show(self.input.value);
        });

        this.arrow.addEventListener('mousedown', function (e) {
            e.preventDefault();
            if (self.open) {
                self.hide();
            } else {
                self.input.focus();
                self.show('');
            }
        });

        this.input.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                if (!self.open) { self.show(''); return; }
                var next = self.active + (e.key === 'ArrowDown' ? 1 : -1);
                if (next < 0) next = self.matches.length - 1;
                if (next >= self.matches.length) next = 0;
                self.setActive(next);
            } else if (e.key === 'Enter') {
                if (self.open) {
                    e.preventDefault(); // don't submit the surrounding form
                    self.commit(self.active);
                }
            } else if (e.key === 'Escape') {
                if (self.open) e.stopPropagation();
                self.hide();
                self.sync();
                self.input.blur();
            } else if (e.key === 'Tab') {
                self.hide();
                self.sync();
            }
        });

        this.list.addEventListener('mousedown', function (e) {
            var item = e.target.closest('.ss-option');
            if (!item) return;
            e.preventDefault(); // keep focus so blur doesn't reset the input first
            self.commit(parseInt(item.dataset.index, 10));
        });

        this.list.addEventListener('mousemove', function (e) {
            var item = e.target.closest('.ss-option');
            if (item) self.setActive(parseInt(item.dataset.index, 10));
        });

        document.addEventListener('mousedown', function (e) {
            if (!self.wrap.contains(e.target)) {
                if (self.open) self.hide();
                self.sync();
            }
        });

        // Keep the input in step when something else changes the <select>.
        this.select.addEventListener('change', function () { self.sync(); });
    };

    window.SearchableSelect = {
        enhance: function (select, opts) {
            if (typeof select === 'string') select = document.getElementById(select);
            if (!select || instances.has(select)) return instances.get(select);
            var box = new Combobox(select, opts);
            instances.set(select, box);
            return box;
        },
        /* Call after rewriting the <select>'s options. */
        refresh: function (select) {
            if (typeof select === 'string') select = document.getElementById(select);
            var box = select && instances.get(select);
            if (!box) return;
            box.readOptions();
            box.sync();
            if (box.open) box.show(box.input.value);
        },
        /* Call after setting the <select>'s value programmatically. */
        sync: function (select) {
            if (typeof select === 'string') select = document.getElementById(select);
            var box = select && instances.get(select);
            if (box) box.sync();
        }
    };
})();
