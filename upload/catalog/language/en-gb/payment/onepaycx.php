<?php
/**
 * 1pay.cx for OpenCart 4 — storefront language strings (en-gb).
 *
 * @license MIT (see LICENSE)
 */

// Heading
$_['heading_title']          = 'Pay by card or crypto';

// Text
$_['text_info']              = 'Pay securely by card, Bitcoin or Monero. You will be redirected to complete your payment.';
$_['text_product']           = '%s order #%s';
$_['text_history_pending']   = '1pay.cx: buyer sent to the hosted checkout (payment %s).';
$_['text_history_paid']      = '1pay.cx: payment confirmed (payment %s, %s %s, via %s).';
$_['text_history_mismatch']  = ' WARNING: the amount paid (%s %s) does not match the current order total (%s %s). The order status was NOT changed - check before fulfilling.';
$_['text_history_double']    = ' WARNING: this order was already paid or fulfilled - possible double payment, check before fulfilling.';
$_['text_return_title']      = 'Your payment';
$_['text_return_pending']    = 'We are waiting for your payment to be confirmed. This page refreshes automatically.';
$_['text_return_pending_hint'] = 'Card payments confirm within a few seconds; crypto payments confirm once the network does.';
$_['text_return_failed']     = 'Your payment could not be completed. You can try again from the checkout.';
$_['text_return_expired']    = 'This payment has expired. You can start a new one from the checkout.';
$_['text_return_error']      = 'We could not check your payment right now. Please try again in a moment.';
$_['text_home']              = 'Home';
$_['text_basket']            = 'Shopping Cart';
$_['text_checkout']          = 'Checkout';

// Button
$_['button_confirm']         = 'Continue to payment';
$_['button_refresh']         = 'Check again';
$_['button_retry']           = 'Back to checkout';

// Error
$_['error_order']            = 'No order found in the session!';
$_['error_payment_method']   = 'Payment method is incorrect!';
$_['error_not_configured']   = '1pay.cx is not configured yet. Please choose another payment method.';
$_['error_amount']           = 'This order cannot be paid with 1pay.cx (unsupported currency or zero total).';
$_['error_api']              = 'The payment could not be started (%s). Please try again.';
