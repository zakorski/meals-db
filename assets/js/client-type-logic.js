(function ($) {
    'use strict';

    const parseClientTypes = (value) => {
        if (!value) {
            return [];
        }

        return value
            .toString()
            .split(',')
            .map((item) => item.trim().toLowerCase())
            .filter(Boolean);
    };

    $(function () {
        const $form = $('#mealsdb-client-form');
        if ($form.length === 0) {
            return;
        }

        const $clientType = $form.find('#customer_type');
        if ($clientType.length === 0) {
            return;
        }

        const findSectionForField = (selector) => {
            const $field = $form.find(selector).first();
            if ($field.length === 0) {
                return $();
            }

            return $field.closest('.mealsdb-section');
        };

        const collectSectionsForType = (type) => {
            const normalizedType = (type || '').toString().trim().toLowerCase();
            if (!normalizedType) {
                return $();
            }

            return $form.find('.mealsdb-section[data-client-type]').filter(function () {
                const $section = $(this);
                const types = parseClientTypes($section.attr('data-client-type'));
                return types.length > 0 && types.includes(normalizedType) && types.every((item) => item === normalizedType);
            });
        };

        const toggleSection = ($elements, shouldShow) => {
            if (!$elements || $elements.length === 0) {
                return;
            }

            if (shouldShow) {
                $elements.show();
            } else {
                $elements.hide();
            }
        };

        const normalizeType = (value) => (value || '').toString().trim().toLowerCase();

        const $addressSection = findSectionForField('#address_street_number');
        const $initialsSection = findSectionForField('#delivery_initials');
        const $sdnbSections = collectSectionsForType('sdnb');
        const $veteranSections = collectSectionsForType('veteran');

        const applyVisibility = () => {
            const selectedType = normalizeType($clientType.val());
            const isStaff = selectedType === 'staff';
            const isSdnb = selectedType === 'sdnb';
            const isVeteran = selectedType === 'veteran';

            toggleSection($addressSection, !isStaff);
            toggleSection($sdnbSections, isSdnb);
            toggleSection($veteranSections, isVeteran);
            toggleSection($initialsSection, !isStaff);
        };

        $clientType.on('change', applyVisibility);
        applyVisibility();
    });
})(jQuery);
