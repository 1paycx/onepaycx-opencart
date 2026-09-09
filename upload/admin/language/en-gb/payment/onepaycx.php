<?php
/**
 * 1pay.cx for OpenCart 4 — admin language strings (en-gb).
 *
 * @license MIT (see LICENSE)
 */

// Heading
$_['heading_title']            = '1pay.cx - pay by card or crypto';

// Text
$_['text_extension']           = 'Extensions';
$_['text_success']             = 'Success: 1pay.cx settings saved.';
$_['text_success_verified']    = 'Success: API key verified and settings saved. You are ready to accept payments.';
$_['text_edit']                = 'Edit 1pay.cx';
$_['text_intro']               = 'Accept card, Bitcoin and Monero payments. Buyers pay on a hosted checkout; orders are confirmed automatically by a signed webhook. Paste your API key to go live.';
$_['text_connected']           = 'Connected (merchant %s).';
$_['text_not_connected']       = 'Not connected yet - enter your API key and save.';
$_['text_none']                = ' --- None --- ';
$_['text_leave_missing']       = 'Leave as a missing order until paid';
$_['text_callback_url']        = 'Payment notifications are delivered to:';
$_['text_reconcile_url']       = 'Reconciliation URL (optional, for a cron job or URL monitor):';

// Entry
$_['entry_status']             = 'Status';
$_['entry_api_key']            = 'API key';
$_['entry_title']              = 'Title at checkout';
$_['entry_order_status']       = 'Paid order status';
$_['entry_pending_status']     = 'Awaiting-payment order status';
$_['entry_direct']             = 'Direct to card processor';
$_['entry_sort_order']         = 'Sort Order';

// Help
$_['help_api_key']             = 'From your 1pay.cx dashboard: Settings -> API keys. Looks like pk_xxx.yyy. Leave blank to keep the current key.';
$_['help_title']               = 'What buyers see as the payment option.';
$_['help_order_status']        = 'Status applied when 1pay.cx confirms the payment.';
$_['help_pending_status']      = 'Status applied when the buyer is sent to the hosted checkout. "Leave as a missing order" keeps abandoned checkouts out of your order list.';
$_['help_direct']              = 'Skip the 1pay.cx payment page and send buyers straight to the card processor. Uses the order email. Buyers will not be offered Bitcoin or Monero in this mode.';
$_['help_callback_url']        = 'Your store must be reachable from the internet over HTTPS at this address, and must not be in maintenance mode, or paid orders will only be confirmed when the buyer returns to your store.';
$_['help_reconcile_url']       = '1pay.cx sends each payment notification once. If your store was unreachable at that moment (maintenance mode, downtime, extension disabled), the order is only confirmed when the buyer returns to your store - or when this URL is requested. Fetch it every 5-15 minutes from a cron job or an uptime monitor and it re-checks every open payment from the last 7 days.';

// Error
$_['error_permission']         = 'Warning: You do not have permission to modify the 1pay.cx payment extension!';
$_['error_api_key_missing']    = 'Enter your 1pay.cx API key (from the dashboard: Settings -> API keys) before enabling the extension.';
$_['error_api_key_format']     = 'That does not look like a 1pay.cx API key. It looks like pk_xxx.yyy.';
$_['error_api_key_verify']     = 'Could not verify the API key (%s). Double-check it in your 1pay.cx dashboard.';
$_['error_unknown']            = 'unknown error';
