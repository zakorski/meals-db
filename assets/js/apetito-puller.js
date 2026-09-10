/* global jQuery, mealsdbApetito, MealsDBConfirm */
(function ($) {
    'use strict';
    var cfg = window.mealsdbApetito || {};
    var esc = function (s) { return $('<div>').text(s == null ? '' : String(s)).html(); };

    // Fields the operator may correct if unreadable; others are display-only.
    var LABELS = {
        name_en: 'Name (EN)', name_fr: 'Name (FR)', category: 'Category',
        subcategory: 'Subcategory', code_on_page: 'Code on page',
        allergens: 'Allergens', diet_tags: 'Diet tags', serving_size: 'Serving size',
        pack_size: 'Pack size', portions_per_case: 'Portions per case'
    };

    function renderField(name, f) {
        var label = LABELS[name] || name;
        if (f && f.ok) {
            var val = Array.isArray(f.value) ? f.value.join(', ') : f.value;
            return '<tr><th>' + esc(label) + '</th><td>' + esc(val) + '</td></tr>';
        }
        var reason = f && f.reason ? f.reason : cfg.i18n.couldNotRead;
        return '<tr class="mealsdb-apetito-missing"><th>' + esc(label) + '</th><td>' +
            '<input type="text" class="regular-text mealsdb-apetito-manual" data-field="' + esc(name) + '" ' +
            'placeholder="' + esc(cfg.i18n.couldNotRead) + '" />' +
            ' <span class="description">' + esc(reason) + '</span></td></tr>';
    }

    function renderPreview(d) {
        var $p = $('#mealsdb-apetito-preview').empty();
        if (d.duplicate) {
            $p.append('<div class="notice notice-error"><p>' + esc(d.dupe_message) + '</p></div>');
            $p.prop('hidden', false);
            return; // BLOCK: no create UI when a duplicate exists.
        }
        var rows = '';
        Object.keys(LABELS).forEach(function (k) { rows += renderField(k, d.fields[k]); });
        var cats = (cfg.categories || []).map(function (c) {
            return '<option value="' + esc(c.id) + '">' + esc(c.name) + '</option>';
        }).join('');

        $p.append(
            '<table class="widefat striped"><tbody>' + rows + '</tbody></table>' +
            '<h3>Your inputs</h3>' +
            '<p><label><strong>Price</strong> <input type="number" step="0.01" min="0" id="mealsdb-apetito-price" class="small-text" /></label></p>' +
            '<p><label for="mealsdb-apetito-cats"><strong>Categories</strong></label><br/>' +
            '<select id="mealsdb-apetito-cats" multiple style="min-width:320px">' + cats + '</select></p>' +
            '<p class="description">Product type and tax are derived from the categories you choose. With no side category, the item is a meal.</p>' +
            '<p><button type="button" class="button button-primary" id="mealsdb-apetito-create" data-code="' + esc(d.code) + '">Create Draft product</button></p>'
        );
        $p.prop('hidden', false);
        if ($.fn.selectWoo) { $('#mealsdb-apetito-cats').selectWoo({ placeholder: 'Choose categories' }); }
    }

    function collectManual() {
        var m = {};
        $('.mealsdb-apetito-manual').each(function () {
            var v = $.trim($(this).val());
            if (v) { m[$(this).data('field')] = v; }
        });
        return m;
    }

    function doFetch() {
        var code = ($('#mealsdb-apetito-code').val() || '').trim();
        if (!code) {
            $('#mealsdb-apetito-status').text('Enter a 5-digit Apetito code.');
            return;
        }
        $('#mealsdb-apetito-status').text('Fetching…');
        $('#mealsdb-apetito-preview').prop('hidden', true).empty();
        $.post(cfg.ajaxUrl, { action: 'mealsdb_apetito_fetch', nonce: cfg.nonceFetch, code: code })
            .done(function (r) {
                if (!r || !r.success) {
                    $('#mealsdb-apetito-status').text((r && r.data && r.data.message) || 'Fetch failed.');
                    return;
                }
                $('#mealsdb-apetito-status').text(r.data.cached ? 'Loaded (cached).' : 'Loaded.');
                renderPreview(r.data);
            })
            .fail(function () { $('#mealsdb-apetito-status').text('Request failed.'); });
    }

    function doCreate() {
        var $btn = $('#mealsdb-apetito-create');
        var code = $btn.data('code');
        var price = $('#mealsdb-apetito-price').val();
        var cats = $('#mealsdb-apetito-cats').val() || [];
        MealsDBConfirm.confirm({
            title: cfg.i18n.confirmTitle,
            message: cfg.i18n.confirmBody,
            confirmLabel: 'Create',
            cancelLabel: 'Cancel'
        }).then(function (okPressed) {
            if (!okPressed) { return; }
            $btn.prop('disabled', true).text('Creating…');
            $.post(cfg.ajaxUrl, {
                action: 'mealsdb_apetito_create', nonce: cfg.nonceCreate,
                code: code, price: price, category_ids: cats, manual: collectManual()
            }).done(function (r) {
                if (!r || !r.success) {
                    $('#mealsdb-apetito-status').text((r && r.data && r.data.message) || 'Create failed.');
                    $btn.prop('disabled', false).text('Create Draft product');
                    return;
                }
                var link = r.data.edit_url ? ' <a href="' + esc(r.data.edit_url) + '">Edit it</a>' : '';
                var warn = r.data.warning ? '<div class="notice notice-warning"><p>' + esc(r.data.warning) + '</p></div>' : '';
                $('#mealsdb-apetito-preview').html(warn + '<div class="notice notice-success"><p>Draft product #' + esc(r.data.product_id) + ' created.' + link + '</p></div>');
            }).fail(function () {
                $('#mealsdb-apetito-status').text('Request failed.');
                $btn.prop('disabled', false).text('Create Draft product');
            });
        });
    }

    $(function () {
        $('#mealsdb-apetito-fetch').on('click', doFetch);
        $('#mealsdb-apetito-code').on('keydown', function (e) { if (e.key === 'Enter') { doFetch(); } });
        $('#mealsdb-apetito-preview').on('click', '#mealsdb-apetito-create', doCreate);
        $('.mealsdb-apetito .nav-tab').on('click', function (e) {
            e.preventDefault();
            var tab = $(this).data('tab');
            $('.mealsdb-apetito .nav-tab').removeClass('nav-tab-active');
            $(this).addClass('nav-tab-active');
            $('#mealsdb-apetito-pull').prop('hidden', tab !== 'pull');
            $('#mealsdb-apetito-audit').prop('hidden', tab !== 'audit');
        });
    });
}(jQuery));
