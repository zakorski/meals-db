/**
 * Task form renderer.
 *
 * Exposes window.MealsDBTaskForm.render(selector, schema, values) to build
 * inputs from a task type's form_schema, and .collect(selector, schema) to
 * harvest user input back into a plain object.
 *
 * Supports the R1 field types: text, textarea, number, date, yesno, select,
 * checkbox. Conditional visibility via `show_when: { field, equals }`.
 */
(function(window) {
    'use strict';

    function esc(str) {
        if (str === null || typeof str === 'undefined') return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(String(str)));
        return div.innerHTML;
    }

    function buildField(field, value) {
        var label = esc(field.label || field.name);
        var name  = esc(field.name);
        var required = field.required ? ' required' : '';
        var readonly = field.readonly ? ' readonly' : '';
        var wrapper = '<div class="mealsdb-task-field" data-field-name="' + name + '"';
        if (field.show_when && field.show_when.field) {
            wrapper += ' data-show-when-field="' + esc(field.show_when.field) + '"';
            wrapper += ' data-show-when-equals="' + esc(field.show_when.equals) + '"';
        }
        wrapper += ' style="margin-bottom:12px;">';
        wrapper += '<label for="mealsdb-tf-' + name + '" style="display:block;font-weight:600;margin-bottom:4px;">' + label;
        if (field.required) wrapper += ' <span style="color:#c00;">*</span>';
        wrapper += '</label>';

        var body = '';
        var v = (typeof value === 'undefined' || value === null) ? '' : value;

        switch (field.type) {
            case 'textarea':
                body = '<textarea id="mealsdb-tf-' + name + '" name="' + name + '" rows="3" style="width:100%;max-width:600px;"' + required + readonly + '>' + esc(v) + '</textarea>';
                break;
            case 'number':
                body = '<input type="number" id="mealsdb-tf-' + name + '" name="' + name + '" value="' + esc(v) + '"';
                if (typeof field.min !== 'undefined') body += ' min="' + esc(field.min) + '"';
                if (typeof field.max !== 'undefined') body += ' max="' + esc(field.max) + '"';
                if (typeof field.step !== 'undefined') body += ' step="' + esc(field.step) + '"';
                body += required + readonly + '>';
                break;
            case 'date':
                body = '<input type="date" id="mealsdb-tf-' + name + '" name="' + name + '" value="' + esc(v) + '"' + required + readonly + '>';
                break;
            case 'yesno':
                var yesChecked = (v === 'yes' || v === true || v === 1 || v === '1') ? ' checked' : '';
                var noChecked = (v === 'no' || v === false || v === 0 || v === '0') ? ' checked' : '';
                body = '<label style="margin-right:16px;"><input type="radio" name="' + name + '" value="yes"' + yesChecked + '> Yes</label>';
                body += '<label><input type="radio" name="' + name + '" value="no"' + noChecked + '> No</label>';
                break;
            case 'select':
                body = '<select id="mealsdb-tf-' + name + '" name="' + name + '"' + required + '>';
                if (!field.required) body += '<option value="">(select)</option>';
                var options = field.options || {};
                if (Array.isArray(options)) {
                    options.forEach(function(opt) {
                        var sel = (String(v) === String(opt)) ? ' selected' : '';
                        body += '<option value="' + esc(opt) + '"' + sel + '>' + esc(opt) + '</option>';
                    });
                } else {
                    Object.keys(options).forEach(function(key) {
                        var sel = (String(v) === String(key)) ? ' selected' : '';
                        body += '<option value="' + esc(key) + '"' + sel + '>' + esc(options[key]) + '</option>';
                    });
                }
                body += '</select>';
                break;
            case 'checkbox':
                var checked = (v === true || v === 1 || v === '1' || v === 'on' || v === 'yes') ? ' checked' : '';
                body = '<input type="checkbox" id="mealsdb-tf-' + name + '" name="' + name + '" value="1"' + checked + '>';
                break;
            case 'text':
            default:
                body = '<input type="text" id="mealsdb-tf-' + name + '" name="' + name + '" value="' + esc(v) + '" style="width:100%;max-width:600px;"' + required + readonly + '>';
                break;
        }

        if (field.description) {
            body += '<p class="description">' + esc(field.description) + '</p>';
        }

        return wrapper + body + '</div>';
    }

    function render(selector, schema, values) {
        var container = typeof selector === 'string' ? document.querySelector(selector) : selector;
        if (!container || !Array.isArray(schema)) return;
        values = values || {};

        var html = '';
        schema.forEach(function(field) {
            if (!field || !field.name) return;
            html += buildField(field, values[field.name]);
        });
        container.innerHTML = html;

        wireConditionals(container, schema);
    }

    function wireConditionals(container, schema) {
        var hasConditional = schema.some(function(f) { return f && f.show_when && f.show_when.field; });
        if (!hasConditional) return;

        function refresh() {
            var current = collect(container, schema, { skipHidden: false });
            schema.forEach(function(f) {
                if (!f || !f.show_when || !f.show_when.field) return;
                var visible = String(current[f.show_when.field]) === String(f.show_when.equals);
                var node = container.querySelector('[data-field-name="' + f.name + '"]');
                if (node) node.style.display = visible ? '' : 'none';
            });
        }

        container.addEventListener('change', refresh);
        container.addEventListener('input', refresh);
        refresh();
    }

    function collect(selector, schema, opts) {
        opts = opts || {};
        var container = typeof selector === 'string' ? document.querySelector(selector) : selector;
        var out = {};
        if (!container || !Array.isArray(schema)) return out;

        schema.forEach(function(field) {
            if (!field || !field.name) return;

            var wrapper = container.querySelector('[data-field-name="' + field.name + '"]');
            if (!wrapper) return;

            if (!opts.skipHidden && wrapper.style.display === 'none') {
                return;
            }

            var value;
            switch (field.type) {
                case 'yesno':
                    var checked = wrapper.querySelector('input[type=radio]:checked');
                    value = checked ? checked.value : '';
                    break;
                case 'checkbox':
                    var cb = wrapper.querySelector('input[type=checkbox]');
                    value = cb && cb.checked ? 1 : 0;
                    break;
                case 'select':
                    var sel = wrapper.querySelector('select');
                    value = sel ? sel.value : '';
                    break;
                default:
                    var el = wrapper.querySelector('input,textarea');
                    value = el ? el.value : '';
                    break;
            }
            out[field.name] = value;
        });

        return out;
    }

    window.MealsDBTaskForm = {
        render: render,
        collect: collect
    };
})(window);
