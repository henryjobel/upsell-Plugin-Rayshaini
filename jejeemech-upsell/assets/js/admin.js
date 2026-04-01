/* global jQuery, jmUpsellAdmin */
(function ($) {
    'use strict';

    var maxSteps = 3;
    var searchTimer = null;

    /**
     * Initialize.
     */
    $(document).ready(function () {
        initProductSearch();
        initStepManagement();
        initFunnelForm();
        initFunnelList();
    });

    /**
     * Product search autocomplete.
     */
    function initProductSearch() {
        $(document).on('input', '.jm-product-search-input', function () {
            var $input = $(this);
            var term = $input.val().trim();
            var $results = $input.closest('.jm-product-search').find('.jm-search-results');

            // Clear previous timer.
            if (searchTimer) {
                clearTimeout(searchTimer);
            }

            if (term.length < 2) {
                $results.removeClass('active').empty();
                return;
            }

            searchTimer = setTimeout(function () {
                $.ajax({
                    url: jmUpsellAdmin.ajax_url,
                    method: 'GET',
                    data: {
                        action: 'jm_upsell_search_products',
                        nonce: jmUpsellAdmin.nonce,
                        term: term,
                        context: $input.data('context') || 'step'
                    },
                    success: function (response) {
                        if (response.success && response.data.length > 0) {
                            var html = '';
                            $.each(response.data, function (i, item) {
                                var variationId = item.variation_id || 0;
                                html += '<div class="jm-search-result-item" data-id="' + item.id + '" data-variation-id="' + variationId + '" data-name="' + $('<span>').text(item.text).html() + '">';
                                html += '<span class="product-name">' + $('<span>').text(item.text).html() + '</span>';
                                html += '<span class="product-price">' + item.price + '</span>';
                                html += '</div>';
                            });
                            $results.html(html).addClass('active');
                        } else {
                            $results.html('<div class="jm-search-result-item" style="color:#999;">No products found</div>').addClass('active');
                        }
                    }
                });
            }, 300);
        });

        // Select a product from results.
        $(document).on('click', '.jm-search-result-item[data-id]', function () {
            var $item = $(this);
            var $wrapper = $item.closest('.jm-product-search');
            var $input = $wrapper.find('.jm-product-search-input');
            var $hidden = $wrapper.find('.jm-product-id-hidden');
            var $variationHidden = $wrapper.find('.jm-variation-id-hidden');
            var $results = $wrapper.find('.jm-search-results');

            // If no data-id, it's the "no products found" message.
            if (!$item.data('id')) {
                return;
            }

            $input.val($item.data('name'));
            $hidden.val($item.data('id'));
            if ($variationHidden.length) {
                $variationHidden.val($item.data('variation-id') || 0);
            }
            $results.removeClass('active').empty();
        });

        // Close results when clicking outside.
        $(document).on('click', function (e) {
            if (!$(e.target).closest('.jm-product-search').length) {
                $('.jm-search-results').removeClass('active').empty();
            }
        });
    }

    /**
     * Step management (add/remove).
     */
    function initStepManagement() {
        // Add step.
        $('#jm-add-step').on('click', function () {
            var $container = $('#jm-steps-container');
            var currentSteps = $container.find('.jm-step-block').length;

            if (currentSteps >= maxSteps) {
                alert('Maximum ' + maxSteps + ' upsell steps allowed.');
                return;
            }

            var stepNum = currentSteps + 1;
            var template = $('#jm-step-template').html();
            template = template.replace(/\{\{step_num\}\}/g, stepNum);

            $container.append(template);
        });

        // Remove step.
        $(document).on('click', '.jm-remove-step', function () {
            $(this).closest('.jm-step-block').remove();
            renumberSteps();
        });

        // Toggle downsell.
        $(document).on('change', '.jm-enable-downsell', function () {
            var $fields = $(this).closest('.jm-downsell-section').find('.jm-downsell-fields');
            if ($(this).is(':checked')) {
                $fields.slideDown(200);
            } else {
                $fields.slideUp(200);
            }
        });
    }

    /**
     * Renumber steps after removal.
     */
    function renumberSteps() {
        $('#jm-steps-container .jm-step-block').each(function (index) {
            var newNum = index + 1;
            var $block = $(this);

            $block.attr('data-step', newNum);
            $block.find('.jm-step-header h3').text('Step ' + newNum + ' – Upsell');

            // Update input names.
            $block.find('input, select, textarea').each(function () {
                var name = $(this).attr('name');
                if (name) {
                    name = name.replace(/steps\[\d+\]/, 'steps[' + newNum + ']');
                    $(this).attr('name', name);
                }

                var target = $(this).data('target');
                if (target) {
                    target = target.replace(/steps\[\d+\]/, 'steps[' + newNum + ']');
                    $(this).data('target', target);
                }
            });
        });
    }

    /**
     * Funnel form submission.
     */
    function initFunnelForm() {
        $('#jm-funnel-form').on('submit', function (e) {
            e.preventDefault();

            var $form = $(this);
            var $btn = $('#jm-save-funnel');
            var $notice = $('#jm-upsell-notice');

            // Validate.
            var funnelName = $form.find('[name="funnel_name"]').val().trim();
            var triggerId = $form.find('[name="trigger_product_id"]').val();

            if (!funnelName) {
                showNotice($notice, 'error', 'Please enter a funnel name.');
                return;
            }

            if (!triggerId) {
                showNotice($notice, 'error', 'Please select a trigger product.');
                return;
            }

            $btn.prop('disabled', true).text(jmUpsellAdmin.i18n.saving);

            var formData = $form.serialize();
            formData += '&action=jm_upsell_save_funnel&nonce=' + jmUpsellAdmin.nonce;

            $.post(jmUpsellAdmin.ajax_url, formData, function (response) {
                if (response.success) {
                    showNotice($notice, 'success', response.data.message);
                    if (response.data.redirect) {
                        setTimeout(function () {
                            window.location.href = response.data.redirect;
                        }, 800);
                    }
                } else {
                    showNotice($notice, 'error', response.data.message || jmUpsellAdmin.i18n.error);
                    $btn.prop('disabled', false).text('Save Funnel');
                }
            }).fail(function () {
                showNotice($notice, 'error', jmUpsellAdmin.i18n.error);
                $btn.prop('disabled', false).text('Save Funnel');
            });
        });
    }

    /**
     * Funnel list actions.
     */
    function initFunnelList() {
        // Toggle status.
        $(document).on('change', '.jm-toggle-funnel', function () {
            var $toggle = $(this);
            var funnelId = $toggle.data('id');
            var status = $toggle.is(':checked') ? 'active' : 'inactive';
            var $label = $toggle.closest('td').find('.jm-status-label');

            $.post(jmUpsellAdmin.ajax_url, {
                action: 'jm_upsell_toggle_funnel',
                nonce: jmUpsellAdmin.nonce,
                funnel_id: funnelId,
                status: status
            }, function (response) {
                if (response.success) {
                    $label.text(status === 'active' ? 'Active' : 'Inactive');
                }
            });
        });

        // Delete funnel.
        $(document).on('click', '.jm-delete-funnel', function () {
            if (!confirm(jmUpsellAdmin.i18n.confirm_delete)) {
                return;
            }

            var $btn = $(this);
            var funnelId = $btn.data('id');
            var $row = $btn.closest('tr');
            var $notice = $('#jm-upsell-notice');

            $.post(jmUpsellAdmin.ajax_url, {
                action: 'jm_upsell_delete_funnel',
                nonce: jmUpsellAdmin.nonce,
                funnel_id: funnelId
            }, function (response) {
                if (response.success) {
                    $row.fadeOut(300, function () {
                        $(this).remove();
                    });
                    showNotice($notice, 'success', response.data.message);
                } else {
                    showNotice($notice, 'error', response.data.message);
                }
            });
        });
    }

    /**
     * Show admin notice.
     */
    function showNotice($notice, type, message) {
        $notice.removeClass('notice-success notice-error notice-warning')
            .addClass('notice-' + type)
            .find('p').text(message);
        $notice.slideDown(200);

        setTimeout(function () {
            $notice.slideUp(200);
        }, 4000);
    }

})(jQuery);
