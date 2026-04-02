/* global jQuery, jmUpsellOffer */
(function ($) {
    'use strict';

    $(document).ready(function () {

        var $overlay = $('#jm-popup-overlay');
        if (!$overlay.length) return;

        var isProcessing    = false;
        var upsellHandled   = false;

        // Track the current step so we know what to do on decline.
        var currentStepId   = jmUpsellOffer.step_id;
        var currentStepType = jmUpsellOffer.step_type; // 'upsell' or 'downsell'

        // ── CLOSE BUTTON — treat same as decline ─────────────────────────────
        $(document).on('click', '#jm-close-popup', function () {
            if (isProcessing) return;
            if (currentStepType === 'downsell') {
                proceedToCheckout();
                return;
            }
            // Same logic as decline: check for downsell first.
            $('#jm-decline-offer').trigger('click');
        });

        // ── INTERCEPT PLACE ORDER ─────────────────────────────────────────────
        $(document).on('click', '#place_order', function (e) {
            if (upsellHandled) {
                return; // Decision already made — WooCommerce handles this click.
            }
            e.preventDefault();
            showPopup();
        });

        // ── ACCEPT (upsell or downsell) ─────────────────────────────────────────
        $(document).on('click', '#jm-accept-offer', function () {
            if (isProcessing) return;
            isProcessing = true;
            disableButtons();
            showLoading(jmUpsellOffer.i18n.adding);

            $.post(
                jmUpsellOffer.ajax_url,
                {
                    action:    'jm_upsell_cart_add',
                    nonce:     jmUpsellOffer.nonce,
                    step_id:   currentStepId,
                    funnel_id: jmUpsellOffer.funnel_id
                },
                function (response) {
                    if (response.success) {
                        updateLoadingText(jmUpsellOffer.i18n.added);
                        setTimeout(function () {
                            proceedToCheckout();
                        }, 800);
                    } else {
                        hideLoading();
                        alert(response.data.message || jmUpsellOffer.i18n.error);
                        enableButtons();
                        isProcessing = false;
                    }
                }
            ).fail(function () {
                hideLoading();
                alert(jmUpsellOffer.i18n.error);
                enableButtons();
                isProcessing = false;
            });
        });

        // ── DECLINE ───────────────────────────────────────────────────────────
        $(document).on('click', '#jm-decline-offer', function () {
            if (isProcessing) return;

            // Declined a downsell (or no downsell exists) — just place the order.
            if (currentStepType === 'downsell') {
                proceedToCheckout();
                return;
            }

            // Declined an upsell — check if a downsell exists for this step.
            isProcessing = true;
            disableButtons();
            showLoading(jmUpsellOffer.i18n.checking);

            $.post(
                jmUpsellOffer.ajax_url,
                {
                    action:  'jm_upsell_get_downsell',
                    nonce:   jmUpsellOffer.nonce,
                    step_id: currentStepId
                },
                function (response) {
                    if (response.success && response.data.downsell) {
                        var ds = response.data.downsell;
                        // Update tracked state to the downsell step.
                        currentStepId   = ds.step_id;
                        currentStepType = 'downsell';
                        hideLoading();
                        updatePopupContent(ds);
                        enableButtons();
                        isProcessing = false;
                    } else {
                        // No downsell configured — proceed to checkout.
                        proceedToCheckout();
                    }
                }
            ).fail(function () {
                // On network error silently proceed — never block the order.
                proceedToCheckout();
            });
        });

        // ── HELPERS ───────────────────────────────────────────────────────────

        function showPopup() {
            $('body').addClass('jm-noscroll');
            $overlay.addClass('jm-active');
        }

        function proceedToCheckout() {
            $overlay.removeClass('jm-active');
            $('body').removeClass('jm-noscroll');
            upsellHandled = true;
            setTimeout(function () {
                var btn = document.getElementById('place_order');
                if (btn) {
                    btn.click();
                }
            }, 50);
        }

        /**
         * Animate the popup card out, swap content with downsell data, animate back in.
         */
        function updatePopupContent(step) {
            var $card = $('#jm-popup-card');
            $card.addClass('jm-transitioning');

            setTimeout(function () {
                // Badge + headline
                $('#jm-popup-badge-text').text(step.badge_text);
                $('#jm-popup-headline').text(step.headline);

                // Image + title
                $('#jm-popup-img').attr('src', step.image_url).attr('alt', step.product_name);
                $('#jm-popup-title').text(step.product_name);

                // Product description
                if (step.product_short_desc) {
                    $('#jm-popup-desc').html(step.product_short_desc);
                } else {
                    $('#jm-popup-desc').html('');
                }

                // Price block
                var priceHtml = '<div class="jm-price-row">';
                if (step.discount > 0) {
                    priceHtml += '<span class="jm-original-price">' + step.price_html + '</span>';
                    priceHtml += '<span class="jm-final-price">' + step.final_price_html + '</span>';
                    priceHtml += '</div>';
                    priceHtml += '<div class="jm-save-line">Save ' + step.save_html + ' When You Add it Now</div>';
                } else {
                    priceHtml += '<span class="jm-final-price">' + step.final_price_html + '</span>';
                    priceHtml += '</div>';
                }
                priceHtml += '<div class="jm-urgency-line"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>This offer expires at checkout</div>';
                $('#jm-popup-price').html(priceHtml);

                // Update button data attributes
                $('#jm-accept-offer, #jm-decline-offer').attr('data-step-id', step.step_id);
                currentStepId = step.step_id;

                // Switch to downsell colour theme.
                $overlay.removeClass('jm-upsell jm-downsell').addClass('jm-downsell');

                $card.removeClass('jm-transitioning').addClass('jm-transitioned');
                setTimeout(function () { $card.removeClass('jm-transitioned'); }, 300);
            }, 200);
        }

        function disableButtons() {
            $('#jm-accept-offer, #jm-decline-offer').prop('disabled', true);
        }

        function enableButtons() {
            $('#jm-accept-offer, #jm-decline-offer').prop('disabled', false);
        }

        function showLoading(text) {
            $('#jm-loading-text').text(text);
            $('#jm-popup-loading').addClass('jm-loading-visible');
        }

        function updateLoadingText(text) {
            $('#jm-loading-text').text(text);
        }

        function hideLoading() {
            $('#jm-popup-loading').removeClass('jm-loading-visible');
        }
    });

})(jQuery);


