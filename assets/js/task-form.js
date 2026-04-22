/**
 * Task form renderer.
 *
 * Exposes window.MealsDBTaskForm.render(selector, schema, values, payload)
 * to build inputs from a task type's form_schema, and .collect(selector,
 * schema) to harvest user input back into a plain object.
 *
 * Supports field types: text, textarea, number, date, yesno, select,
 * checkbox, repeat_group. Conditional visibility via `show_when` —
 * either { field, equals } or { field, not_equals_field }.
 */
(function(window) {
    'use strict';

    function esc(str) {
        if (str === null || typeof str === 'undefined') return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(String(str)));
        return div.innerHTML;
    }

    function resolveItemsFrom(path, payload) {
        if (!path || !payload) return [];
        // Path is dotted, e.g. "payload.expected_items" — drop the leading
        // "payload." prefix since the argument IS the payload object.
        var parts = String(path).split('.');
        var node = payload;
        for (var i = 0; i < parts.length; i++) {
            if (parts[i] === 'payload' && i === 0) continue;
            if (node && typeof node === 'object' && parts[i] in node) {
                node = node[parts[i]];
            } else {
                return [];
            }
        }
        return Array.isArray(node) ? node : [];
    }

    function buildField(field, value, ctx) {
        var label = esc(field.label || field.name);
        var name  = esc(field.name);
        var required = field.required ? ' required' : '';
        var readonly = field.readonly ? ' readonly' : '';
        var wrapper = '<div class="mealsdb-task-field" data-field-name="' + name + '"';
        if (field.show_when && field.show_when.field) {
            wrapper += ' data-show-when-field="' + esc(field.show_when.field) + '"';
            if (typeof field.show_when.equals !== 'undefined') {
                wrapper += ' data-show-when-equals="' + esc(field.show_when.equals) + '"';
            }
            if (field.show_when.not_equals_field) {
                wrapper += ' data-show-when-not-equals-field="' + esc(field.show_when.not_equals_field) + '"';
            }
        }
        wrapper += ' style="margin-bottom:12px;">';
        if (field.type !== 'repeat_group') {
            wrapper += '<label for="mealsdb-tf-' + name + '" style="display:block;font-weight:600;margin-bottom:4px;">' + label;
            if (field.required) wrapper += ' <span style="color:#c00;">*</span>';
            wrapper += '</label>';
        } else {
            wrapper += '<div style="font-weight:600;margin-bottom:4px;">' + label + '</div>';
        }

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
            case 'repeat_group':
                body = buildRepeatGroup(field, v, ctx);
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

    function buildRepeatGroup(field, savedValue, ctx) {
        var items = [];
        if (Array.isArray(savedValue) && savedValue.length) {
            items = savedValue;
        } else if (field.items_from) {
            items = resolveItemsFrom(field.items_from, ctx.payload).map(function(row) {
                return row && typeof row === 'object' ? row : {};
            });
        }

        var childFields = Array.isArray(field.fields) ? field.fields : [];

        var html = '<table class="wp-list-table widefat fixed striped mealsdb-repeat-group" data-field-name="' + esc(field.name) + '">';
        html += '<thead><tr>';
        childFields.forEach(function(cf) {
            html += '<th>' + esc(cf.label || cf.name) + '</th>';
        });
        html += '</tr></thead><tbody>';

        items.forEach(function(row, idx) {
            html += '<tr class="mealsdb-repeat-row" data-row-index="' + idx + '">';
            childFields.forEach(function(cf) {
                var cellValue = (row && typeof row === 'object' && cf.name in row) ? row[cf.name] : '';
                html += '<td data-field-name="' + esc(cf.name) + '"';
                if (cf.show_when && cf.show_when.field) {
                    html += ' data-show-when-field="' + esc(cf.show_when.field) + '"';
                    if (typeof cf.show_when.equals !== 'undefined') {
                        html += ' data-show-when-equals="' + esc(cf.show_when.equals) + '"';
                    }
                    if (cf.show_when.not_equals_field) {
                        html += ' data-show-when-not-equals-field="' + esc(cf.show_when.not_equals_field) + '"';
                    }
                }
                html += '>';
                html += buildRepeatCell(cf, cellValue);
                html += '</td>';
            });
            html += '</tr>';
        });

        html += '</tbody></table>';
        return html;
    }

    function buildRepeatCell(field, value) {
        var v = (typeof value === 'undefined' || value === null) ? '' : value;
        var required = field.required ? ' required' : '';
        var readonly = field.readonly ? ' readonly' : '';

        switch (field.type) {
            case 'textarea':
                return '<textarea name="' + esc(field.name) + '" rows="2"' + required + readonly + '>' + esc(v) + '</textarea>';
            case 'number':
                var numHtml = '<input type="number" name="' + esc(field.name) + '" value="' + esc(v) + '"';
                if (typeof field.min !== 'undefined') numHtml += ' min="' + esc(field.min) + '"';
                if (typeof field.max !== 'undefined') numHtml += ' max="' + esc(field.max) + '"';
                if (typeof field.step !== 'undefined') numHtml += ' step="' + esc(field.step) + '"';
                numHtml += ' style="width:90px;"' + required + readonly + '>';
                return numHtml;
            case 'date':
                return '<input type="date" name="' + esc(field.name) + '" value="' + esc(v) + '"' + required + readonly + '>';
            case 'yesno':
                var yesChecked = (v === 'yes' || v === true || v === 1 || v === '1') ? ' checked' : '';
                var noChecked = (v === 'no' || v === false || v === 0 || v === '0') ? ' checked' : '';
                return '<label><input type="radio" value="yes"' + yesChecked + '>Y</label> '
                     + '<label><input type="radio" value="no"' + noChecked + '>N</label>';
            case 'select':
                var options = field.options || [];
                var selHtml = '<select' + required + '>';
                if (!field.required) selHtml += '<option value="">(select)</option>';
                if (Array.isArray(options)) {
                    options.forEach(function(opt) {
                        var sel = (String(v) === String(opt)) ? ' selected' : '';
                        selHtml += '<option value="' + esc(opt) + '"' + sel + '>' + esc(opt || '(none)') + '</option>';
                    });
                } else {
                    Object.keys(options).forEach(function(key) {
                        var sel = (String(v) === String(key)) ? ' selected' : '';
                        selHtml += '<option value="' + esc(key) + '"' + sel + '>' + esc(options[key]) + '</option>';
                    });
                }
                selHtml += '</select>';
                return selHtml;
            case 'checkbox':
                var checked = (v === true || v === 1 || v === '1' || v === 'on' || v === 'yes') ? ' checked' : '';
                return '<input type="checkbox" value="1"' + checked + '>';
            case 'text':
            default:
                return '<input type="text" name="' + esc(field.name) + '" value="' + esc(v) + '"' + required + readonly + '>';
        }
    }

    function render(selector, schema, values, payload) {
        var container = typeof selector === 'string' ? document.querySelector(selector) : selector;
        if (!container || !Array.isArray(schema)) return;
        values = values || {};
        var ctx = { payload: payload || {} };

        var html = '';
        schema.forEach(function(field) {
            if (!field || !field.name) return;
            html += buildField(field, values[field.name], ctx);
        });
        container.innerHTML = html;

        wireConditionals(container, schema);
    }

    function wireConditionals(container, schema) {
        function refresh() {
            var current = collect(container, schema, { skipHidden: false });

            // Top-level fields.
            schema.forEach(function(f) {
                if (!f || !f.show_when || !f.show_when.field) return;
                var visible = evalShowWhen(f.show_when, current);
                var node = container.querySelector(':scope > [data-field-name="' + f.name + '"]');
                if (node) node.style.display = visible ? '' : 'none';
            });

            // Repeat group rows: per-row cell visibility.
            schema.forEach(function(f) {
                if (!f || f.type !== 'repeat_group') return;
                var table = container.querySelector('[data-field-name="' + f.name + '"] table.mealsdb-repeat-group, table.mealsdb-repeat-group[data-field-name="' + f.name + '"]');
                if (!table) return;
                var rows = table.querySelectorAll('tr.mealsdb-repeat-row');
                rows.forEach(function(tr, idx) {
                    var rowData = collectRow(tr, f.fields || []);
                    (f.fields || []).forEach(function(cf) {
                        if (!cf.show_when || !cf.show_when.field) return;
                        var cell = tr.querySelector('[data-field-name="' + cf.name + '"]');
                        if (!cell) return;
                        var vis = evalShowWhen(cf.show_when, rowData);
                        cell.style.visibility = vis ? '' : 'hidden';
                    });
                });
            });
        }

        container.addEventListener('change', refresh);
        container.addEventListener('input', refresh);
        refresh();
    }

    function evalShowWhen(cond, data) {
        if (!cond || !cond.field) return true;
        var actual = data[cond.field];
        if (typeof cond.equals !== 'undefined') {
            return String(actual) === String(cond.equals);
        }
        if (cond.not_equals_field) {
            var compare = data[cond.not_equals_field];
            return String(actual) !== String(compare);
        }
        return true;
    }

    function collectRow(tr, childFields) {
        var out = {};
        childFields.forEach(function(cf) {
            var cell = tr.querySelector('[data-field-name="' + cf.name + '"]');
            if (!cell) return;
            switch (cf.type) {
                case 'yesno':
                    var checked = cell.querySelector('input[type=radio]:checked');
                    out[cf.name] = checked ? checked.value : '';
                    break;
                case 'checkbox':
                    var cb = cell.querySelector('input[type=checkbox]');
                    out[cf.name] = cb && cb.checked ? 1 : 0;
                    break;
                case 'select':
                    var sel = cell.querySelector('select');
                    out[cf.name] = sel ? sel.value : '';
                    break;
                default:
                    var el = cell.querySelector('input,textarea');
                    out[cf.name] = el ? el.value : '';
                    break;
            }
        });
        return out;
    }

    function collect(selector, schema, opts) {
        opts = opts || {};
        var container = typeof selector === 'string' ? document.querySelector(selector) : selector;
        var out = {};
        if (!container || !Array.isArray(schema)) return out;

        schema.forEach(function(field) {
            if (!field || !field.name) return;

            var wrapper = container.querySelector(':scope > [data-field-name="' + field.name + '"]');
            if (!wrapper) return;

            if (!opts.skipHidden && wrapper.style.display === 'none') {
                return;
            }

            if (field.type === 'repeat_group') {
                var rows = wrapper.querySelectorAll('tr.mealsdb-repeat-row');
                var list = [];
                rows.forEach(function(tr) {
                    list.push(collectRow(tr, field.fields || []));
                });
                out[field.name] = list;
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
