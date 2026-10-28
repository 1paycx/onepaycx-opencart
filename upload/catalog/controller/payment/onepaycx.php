<?php
/**
 * 1pay.cx for OpenCart 4 — storefront side.
 *
 * Flow: checkout confirm step -> index() renders the "Continue to payment"
 * button -> confirm() creates the payment on 1pay.cx and answers with the
 * hosted-checkout URL -> the buyer pays by card, Bitcoin or Monero ->
 * 1pay.cx POSTs a signed notify to callback(), which moves the order to the
 * paid status (idempotently) -> the buyer lands on return(), which polls
 * GET /v1/payments/{id} as a fallback so a lost notify never strands them.
 * The notify is sent exactly once (no redelivery), so reconcile() offers a
 * cron-driven catch-up that re-polls every open payment of the last 7 days.
 *
 * All outbound traffic goes to https://api.1pay.cx and https://1pay.cx only.
 *
 * Layout: OpenCart 4.0.x extension folder (extension/onepaycx/catalog/...),
 * namespaced Opencart\Catalog\Controller\Extension\Onepaycx\Payment\Onepaycx,
 * routes extension/onepaycx/payment/onepaycx{.confirm,.callback,.return,.reconcile}.
 *
 * @author    1pay.cx
 * @copyright 2026 1pay.cx
 * @license   MIT (see LICENSE)
 */

namespace Opencart\Catalog\Controller\Extension\Onepaycx\Payment;

require_once __DIR__ . '/../../../system/library/api.php';

use Opencart\System\Library\Extension\Onepaycx\Api;

class Onepaycx extends \Opencart\System\Engine\Controller {
	private const ROUTE = 'extension/onepaycx/payment/onepaycx';

	/** Seconds between return-page refreshes while the payment is pending. */
	private const REFRESH_SECONDS = 5;

	/** reconcile(): how far back to re-poll open payments, and how many per run. */
	private const RECONCILE_DAYS = 7;
	private const RECONCILE_LIMIT = 20;

	/**
	 * The payment panel on the checkout confirm step.
	 */
	public function index(): string {
		$this->loadLanguage();

		$data['language'] = $this->config->get('config_language');
		$data['confirm'] = $this->url->link($this->route('confirm'), 'language=' . $this->config->get('config_language'), true);

		return $this->load->view(self::ROUTE, $data);
	}

	/**
	 * Create the payment on 1pay.cx for the order in the session and answer
	 * with the hosted-checkout URL (JSON: { redirect } or { error }).
	 */
	public function confirm(): void {
		$this->loadLanguage();

		$json = [];
		$order_info = [];

		if (!isset($this->session->data['order_id'])) {
			$json['error'] = $this->language->get('error_order');
		} elseif (!$this->isSelectedMethod() || !$this->config->get('payment_onepaycx_status')) {
			$json['error'] = $this->language->get('error_payment_method');
		}

		$key = (string)$this->config->get('payment_onepaycx_api_key');

		if (!$json && $key === '') {
			$json['error'] = $this->language->get('error_not_configured');
		}

		if (!$json) {
			$this->load->model('checkout/order');

			$order_info = $this->model_checkout_order->getOrder((int)$this->session->data['order_id']);

			if (!$order_info) {
				$json['error'] = $this->language->get('error_order');
			}
		}

		if (!$json) {
			$order_id = (int)$order_info['order_id'];
			$currency = strtoupper((string)$order_info['currency_code']);
			// Order totals are stored in the default currency; convert to the buyer's.
			$amount = Api::formatAmount($this->currency->format((float)$order_info['total'], (string)$order_info['currency_code'], (float)$order_info['currency_value'], false));

			if (!preg_match('/^[A-Z]{3}$/', $currency) || (float)$amount <= 0) {
				$json['error'] = $this->language->get('error_amount');
			}
		}

		if (!$json) {
			$language = 'language=' . $this->config->get('config_language');
			$return_key = bin2hex(random_bytes(16));
			$store = html_entity_decode((string)$this->config->get('config_name'), ENT_QUOTES, 'UTF-8');

			$body = [
				'amount'      => (float)$amount,
				'currency'    => $currency,
				'reference'   => (string)$order_id,
				'productName' => sprintf($this->language->get('text_product'), $store, $order_id),
				'notifyUrl'   => $this->url->link($this->route('callback'), $language, true),
				'redirectUrl' => $this->url->link($this->route('return'), $language . '&order_id=' . $order_id . '&key=' . $return_key, true),
			];

			$email = trim(html_entity_decode((string)($order_info['email'] ?? ''), ENT_QUOTES, 'UTF-8'));

			if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
				$body['email'] = $email;
			}

			$res = $this->api($key)->createPayment($body);

			$payment_id = (is_array($res['data']) && isset($res['data']['id'])) ? (string)$res['data']['id'] : '';
			$redirect = (is_array($res['data']) && isset($res['data']['checkoutUrl']) && is_string($res['data']['checkoutUrl']))
				? Api::absoluteCheckoutUrl($res['data']['checkoutUrl'])
				: null;

			if (!$res['ok'] || !Api::isPaymentId($payment_id) || $redirect === null) {
				$json['error'] = sprintf($this->language->get('error_api'), $res['error'] !== '' ? $res['error'] : 'HTTP ' . (int)$res['status']);
			} else {
				$this->load->model(self::ROUTE);

				$this->model_extension_onepaycx_payment_onepaycx->addPayment($payment_id, $order_id, $amount, $currency, $return_key);

				// Optionally surface the order as "awaiting payment" right away.
				$pending_status_id = (int)$this->config->get('payment_onepaycx_pending_status_id');

				if ($pending_status_id > 0 && (int)$order_info['order_status_id'] === 0) {
					$this->model_checkout_order->addHistory($order_id, $pending_status_id, sprintf($this->language->get('text_history_pending'), $payment_id));
				}

				// Direct mode: skip the 1pay.cx page and go straight to the card processor
				// (only valid when the payment carries an email).
				if ($this->config->get('payment_onepaycx_direct') && isset($body['email'])) {
					$redirect .= (strpos($redirect, '?') === false ? '?' : '&') . 'direct=1';
				}

				$json['redirect'] = $redirect;
			}
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	/**
	 * Signed notify from 1pay.cx: { event, data, at }. Signed with
	 * X-1pay-Signature-V2 ("t=<unix>,v1=<hex HMAC-SHA256 of '<t>.<raw body>'>",
	 * timestamp within 5 minutes) or, from older senders, X-1pay-Signature (hex
	 * HMAC-SHA256 of the raw body). When the V2 header is present it alone decides.
	 *
	 * A verified "payment.paid" event only touches an order after every check:
	 * - the payment id is one this store created for that order (local row);
	 * - the notify's amount/currency cover the quoted amount (surcharge on top is fine);
	 * - a test payment (livemode:false) is refused while a live key is configured;
	 * - GET /v1/payments/{id} with the configured key confirms status "paid" with
	 *   a matching amount/currency and mode.
	 * Any failed check refuses without marking anything; return() or reconcile()
	 * completes a genuine payment later. The row is claimed atomically, so a
	 * repeat (or a concurrent return-page poll) answers 200 without doing anything.
	 */
	public function callback(): void {
		$this->loadLanguage();

		$raw = $this->rawBody();
		$server = $this->request->server;
		$signature = isset($server['HTTP_X_1PAY_SIGNATURE']) ? (string)$server['HTTP_X_1PAY_SIGNATURE'] : '';
		$signature_v2 = isset($server['HTTP_X_1PAY_SIGNATURE_V2']) ? (string)$server['HTTP_X_1PAY_SIGNATURE_V2'] : '';
		$secret = (string)$this->config->get('payment_onepaycx_webhook_secret');
		$api_key = (string)$this->config->get('payment_onepaycx_api_key');

		if (!$this->config->get('payment_onepaycx_status')) {
			// The notify is not redelivered; reconcile() picks the payment up once re-enabled.
			$this->respond(503, 'extension disabled');

			return;
		}

		if ($secret === '' || ($signature === '' && $signature_v2 === '') || $raw === '') {
			$this->respond(400, 'missing signature');

			return;
		}

		if (!Api::verifyWebhook($raw, $signature_v2, $signature, $secret, $this->now())) {
			$this->respond(401, 'bad signature');

			return;
		}

		$payload = json_decode($raw, true);

		if (!is_array($payload)) {
			$this->respond(400, 'bad json');

			return;
		}

		$event = isset($payload['event']) ? (string)$payload['event'] : '';
		$data = (isset($payload['data']) && is_array($payload['data'])) ? $payload['data'] : [];

		if ($event !== 'payment.paid' || (isset($data['status']) && (string)$data['status'] !== 'paid')) {
			$this->respond(200, 'ignored');

			return;
		}

		$reference = isset($data['reference']) ? (string)$data['reference'] : '';
		$payment_id = isset($data['paymentId']) ? (string)$data['paymentId'] : '';

		if (!preg_match('/^[1-9][0-9]{0,11}$/', $reference) || !Api::isPaymentId($payment_id)) {
			$this->respond(400, 'bad reference');

			return;
		}

		// A test payment must never complete an order in a live store.
		$live_key = !Api::isTestKey($api_key);

		if ($live_key && array_key_exists('livemode', $data) && $data['livemode'] === false) {
			$this->respond(400, 'test payment');

			return;
		}

		$this->load->model(self::ROUTE);

		// Only honour notifies for a payment this store actually started for this order.
		$row = $this->model_extension_onepaycx_payment_onepaycx->getPayment($payment_id);

		if ($row === null || (int)$row['order_id'] !== (int)$reference) {
			$this->respond(404, 'unknown payment');

			return;
		}

		if ((string)$row['status'] === 'paid') {
			$this->respond(200, 'already paid');

			return;
		}

		if (!Api::coversQuote($data['amount'] ?? null, $data['currency'] ?? null, (string)$row['amount'], (string)$row['currency'])) {
			$this->respond(400, 'amount mismatch');

			return;
		}

		// Trust, but verify: ask 1pay.cx directly with this store's own key.
		if ($api_key === '') {
			$this->respond(503, 'not configured');

			return;
		}

		$res = $this->api($api_key)->getPayment($payment_id);

		if (!$res['ok'] || !is_array($res['data'])) {
			// Transient; return() or reconcile() finishes the order later.
			$this->respond(503, 'verification unavailable');

			return;
		}

		$remote = $res['data'];

		if ((string)($remote['status'] ?? '') !== 'paid') {
			$this->respond(409, 'not paid');

			return;
		}

		if (!Api::coversQuote($remote['amount'] ?? null, $remote['currency'] ?? null, (string)$row['amount'], (string)$row['currency'])) {
			$this->respond(400, 'amount mismatch');

			return;
		}

		if ($live_key && array_key_exists('livemode', $remote) && $remote['livemode'] === false) {
			$this->respond(400, 'test payment');

			return;
		}

		$paid_amount = Api::formatAmount($remote['amount']);

		switch ($this->completeOrder($row, 'webhook', $paid_amount)) {
			case 'ok':
				$this->respond(200, 'ok');
				break;
			case 'already paid':
				$this->respond(200, 'already paid');
				break;
			case 'order not found':
				$this->respond(404, 'order not found');
				break;
			default:
				// Nothing was recorded and the claim was released. 1pay.cx does not
				// retry; return() or reconcile() completes the order later.
				$this->respond(500, 'order update failed');
				break;
		}
	}

	/**
	 * The page the buyer lands on after the hosted checkout. Paid -> the
	 * standard success page; pending -> a waiting page that refreshes;
	 * failed/expired -> offer to retry from the checkout.
	 */
	public function return(): void {
		$this->loadLanguage();

		$language = 'language=' . $this->config->get('config_language');

		$order_id = (int)($this->request->get['order_id'] ?? 0);
		$key = (string)($this->request->get['key'] ?? '');

		$this->load->model(self::ROUTE);

		$row = $order_id > 0 ? $this->model_extension_onepaycx_payment_onepaycx->getLatestPaymentByOrder($order_id) : null;

		// The key in the return URL proves the visitor started this payment.
		if ($row === null || !preg_match('/^[0-9a-f]{32}$/', $key) || !hash_equals((string)$row['return_key'], $key)) {
			$this->response->redirect($this->url->link('checkout/cart', $language, true));

			return;
		}

		if ((string)$row['status'] === 'paid') {
			$this->response->redirect($this->url->link('checkout/success', $language, true));

			return;
		}

		$state = 'pending';
		$api_key = (string)$this->config->get('payment_onepaycx_api_key');

		if ($api_key === '') {
			$state = 'error';
		} else {
			$res = $this->api($api_key)->getPayment((string)$row['payment_id']);

			if ($res['ok'] && is_array($res['data']) && isset($res['data']['status'])) {
				$status = (string)$res['data']['status'];

				if ($status === 'paid') {
					$paid_amount = (isset($res['data']['amount']) && is_numeric($res['data']['amount'])) ? Api::formatAmount($res['data']['amount']) : null;
					$result = $this->completeOrder($row, 'return', $paid_amount);

					if ($result === 'ok' || $result === 'already paid') {
						$this->response->redirect($this->url->link('checkout/success', $language, true));

						return;
					}

					$state = 'error';
				} elseif ($status === 'failed' || $status === 'expired') {
					// Late money can still pay an expired link, so the row stays pollable.
					$this->model_extension_onepaycx_payment_onepaycx->setStatus((string)$row['payment_id'], $status);
					$state = $status;
				}
			}
			// A transient API error leaves the page in "pending"; the refresh retries.
		}

		$this->document->setTitle($this->language->get('text_return_title'));

		$data['breadcrumbs'] = [];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('text_home'),
			'href' => $this->url->link('common/home', $language)
		];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('text_basket'),
			'href' => $this->url->link('checkout/cart', $language)
		];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('text_checkout'),
			'href' => $this->url->link('checkout/checkout', $language)
		];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('text_return_title'),
			'href' => $this->url->link($this->route('return'), $language . '&order_id=' . $order_id . '&key=' . $key)
		];

		$data['heading_title'] = $this->language->get('text_return_title');
		$data['state'] = $state;
		$data['refresh_seconds'] = $state === 'pending' ? self::REFRESH_SECONDS : 0;
		$data['refresh'] = $this->url->link($this->route('return'), $language . '&order_id=' . $order_id . '&key=' . $key, true);
		$data['retry'] = $this->url->link('checkout/checkout', $language);

		$data['column_left'] = $this->load->controller('common/column_left');
		$data['column_right'] = $this->load->controller('common/column_right');
		$data['content_top'] = $this->load->controller('common/content_top');
		$data['content_bottom'] = $this->load->controller('common/content_bottom');
		$data['footer'] = $this->load->controller('common/footer');
		$data['header'] = $this->load->controller('common/header');

		$this->response->addHeader('Cache-Control: no-store');
		$this->response->setOutput($this->load->view('extension/onepaycx/payment/onepaycx_return', $data));
	}

	/**
	 * Catch-up for notifies that never arrived (the store was down, in
	 * maintenance mode, or the extension was disabled at that moment): re-polls
	 * GET /v1/payments/{id} for every payment still pending/expired from the last
	 * RECONCILE_DAYS days and completes the paid ones through the same idempotent
	 * path as callback()/return().
	 *
	 * GET index.php?route=extension/onepaycx/payment/onepaycx.reconcile&token=...
	 * The token (shown on the settings page) is derived from the webhook secret
	 * and compared with hash_equals(). Meant for a cron job or a URL monitor
	 * every 5-15 minutes; safe to call as often as you like.
	 */
	public function reconcile(): void {
		$this->loadLanguage();

		$api_key = (string)$this->config->get('payment_onepaycx_api_key');
		$secret = (string)$this->config->get('payment_onepaycx_webhook_secret');
		$token = (string)($this->request->get['token'] ?? '');

		if ($api_key === '' || $secret === '') {
			$this->respond(503, 'not configured');

			return;
		}

		if (!preg_match('/^[0-9a-f]{64}$/', $token) || !hash_equals(Api::reconcileToken($secret), $token)) {
			$this->respond(401, 'bad token');

			return;
		}

		$this->load->model(self::ROUTE);

		$model = $this->model_extension_onepaycx_payment_onepaycx;
		$api = $this->api($api_key);
		$counts = ['checked' => 0, 'paid' => 0, 'pending' => 0, 'expired' => 0, 'failed' => 0, 'errors' => 0];

		foreach ($model->getUnsettled(self::RECONCILE_DAYS, self::RECONCILE_LIMIT) as $row) {
			$counts['checked']++;

			$res = $api->getPayment((string)$row['payment_id']);

			if (!$res['ok'] || !is_array($res['data']) || !isset($res['data']['status'])) {
				$counts['errors']++;

				continue;
			}

			$status = (string)$res['data']['status'];

			if ($status === 'paid') {
				$paid_amount = (isset($res['data']['amount']) && is_numeric($res['data']['amount'])) ? Api::formatAmount($res['data']['amount']) : null;
				$result = $this->completeOrder($row, 'reconcile', $paid_amount);

				$counts[($result === 'ok' || $result === 'already paid') ? 'paid' : 'errors']++;
			} elseif ($status === 'failed' || $status === 'expired') {
				if ((string)$row['status'] !== $status) {
					$model->setStatus((string)$row['payment_id'], $status);
				}
				$counts[$status]++;
			} else {
				$counts['pending']++;
			}
		}

		$summary = [];

		foreach ($counts as $k => $v) {
			$summary[] = $k . '=' . $v;
		}

		$this->respond(200, implode(' ', $summary));
	}

	/**
	 * Move an order to the paid status exactly once.
	 *
	 * The payment row is claimed atomically first (UPDATE ... WHERE status <>
	 * 'paid'), so a concurrent notify, return-page poll and reconcile run cannot
	 * all add history. If the order update then fails, the claim is released so
	 * a later return()/reconcile() can finish the job.
	 *
	 * Two cases keep the claim but never apply the paid status:
	 * - the order is already paid/fulfilled (paid status, or any status the
	 *   store counts as processing/complete): a second attempt paid late, so
	 *   the current status is kept and a double-payment note is added;
	 * - the payment does not match the order as it is NOW. OpenCart edits an
	 *   unpaid order in place (checkout/confirm calls editOrder() while the
	 *   status is 0), so a buyer can open a $1 payment link, grow the cart to
	 *   $500 and pay the old link. The row's amount/currency are compared with
	 *   the current order total (converted exactly as confirm() did) and with
	 *   the amount 1pay.cx reports; on a mismatch the order only gets a WARNING
	 *   note (made visible if it was still a "missing" order) for the merchant
	 *   to check before fulfilling.
	 *
	 * @param array       $row         onepaycx_order row
	 * @param string      $source      'webhook' | 'return' | 'reconcile' (for the history comment)
	 * @param string|null $paid_amount two-decimal amount reported by 1pay.cx, if any
	 *
	 * @return string 'ok' | 'already paid' | 'order not found' | 'failed'
	 */
	private function completeOrder(array $row, string $source, ?string $paid_amount = null): string {
		$this->load->model('checkout/order');
		$this->load->model(self::ROUTE);

		$model = $this->model_extension_onepaycx_payment_onepaycx;
		$order_id = (int)$row['order_id'];
		$payment_id = (string)$row['payment_id'];
		$amount = (string)$row['amount'];
		$currency = (string)$row['currency'];

		$order_info = $this->model_checkout_order->getOrder($order_id);

		if (!$order_info) {
			return 'order not found';
		}

		$paid_status_id = (int)$this->config->get('payment_onepaycx_order_status_id');

		if ($paid_status_id <= 0) {
			$paid_status_id = (int)$this->config->get('config_order_status_id');
		}

		if ($paid_status_id <= 0) {
			return 'failed';
		}

		if (!$model->markPaid($payment_id)) {
			return 'already paid';
		}

		$current_status_id = (int)$order_info['order_status_id'];
		$comment = sprintf($this->language->get('text_history_paid'), $payment_id, $paid_amount ?? $amount, $currency, $source);

		// Already paid or fulfilled (another attempt paid first, or the merchant moved
		// the order on): never regress it, just record the extra payment.
		$already_paid = $current_status_id === $paid_status_id
			|| in_array($current_status_id, $this->statusList('config_processing_status'), true)
			|| in_array($current_status_id, $this->statusList('config_complete_status'), true);

		if ($already_paid) {
			$comment .= $this->language->get('text_history_double');

			return $this->addHistory($model, $payment_id, $order_id, $current_status_id, $comment) ? 'already paid' : 'failed';
		}

		// The payment must match the order as it is NOW, not as it was when the
		// link was created (same conversion as confirm()).
		$expected_amount = Api::formatAmount($this->currency->format((float)$order_info['total'], (string)$order_info['currency_code'], (float)$order_info['currency_value'], false));
		$expected_currency = strtoupper((string)$order_info['currency_code']);

		// 1pay.cx reports the amount the buyer was charged, which includes the
		// merchant's optional customer surcharge, so it may exceed what we asked for.
		// Underpayment is a mismatch; a surcharge on top is not.
		$mismatch = $expected_amount !== $amount
			|| $expected_currency !== $currency
			|| ($paid_amount !== null && (int)round((float)$paid_amount * 100) < (int)round((float)$amount * 100));

		if ($mismatch) {
			$comment .= sprintf($this->language->get('text_history_mismatch'), $paid_amount ?? $amount, $currency, $expected_amount, $expected_currency);

			// Keep the current status; a still-"missing" order becomes visible so the note is seen.
			$status_id = $current_status_id;

			if ($status_id <= 0) {
				$status_id = (int)$this->config->get('payment_onepaycx_pending_status_id');
			}

			if ($status_id <= 0) {
				$status_id = (int)$this->config->get('config_order_status_id');
			}

			return $this->addHistory($model, $payment_id, $order_id, $status_id, $comment) ? 'ok' : 'failed';
		}

		return $this->addHistory($model, $payment_id, $order_id, $paid_status_id, $comment) ? 'ok' : 'failed';
	}

	/**
	 * Add the history entry for a claimed payment; on failure release the claim
	 * so return()/reconcile() can finish the job later.
	 *
	 * @param object $model the extension's catalog model
	 */
	private function addHistory(object $model, string $payment_id, int $order_id, int $status_id, string $comment): bool {
		try {
			$this->model_checkout_order->addHistory($order_id, $status_id, $comment, false);
		} catch (\Throwable $e) {
			$model->setStatus($payment_id, 'pending');

			return false;
		}

		return true;
	}

	/**
	 * Order-status ids behind a store setting such as config_processing_status
	 * (an array once OpenCart decodes the setting; a JSON string on some setups).
	 *
	 * @return int[]
	 */
	private function statusList(string $key): array {
		$value = $this->config->get($key);

		if (is_string($value)) {
			$decoded = json_decode($value, true);
			$value = is_array($decoded) ? $decoded : [];
		}

		if (!is_array($value)) {
			return [];
		}

		return array_values(array_filter(array_map('intval', $value), fn ($id) => $id > 0));
	}

	/**
	 * OpenCart loads only the store language file and has no en-gb fallback, so
	 * a de-de/ru-ru/... storefront would render raw keys. Load the shipped en-gb
	 * strings first; a translation, when present, overrides them.
	 */
	private function loadLanguage(): void {
		$this->load->language(self::ROUTE, '', 'en-gb');
		$this->load->language(self::ROUTE);
	}

	/**
	 * Whether the buyer picked this method. 4.0.2+ stores an option array
	 * ('onepaycx.onepaycx'); 4.0.0/4.0.1 stored the bare code string.
	 */
	private function isSelectedMethod(): bool {
		if (!isset($this->session->data['payment_method'])) {
			return false;
		}

		$method = $this->session->data['payment_method'];

		if (is_array($method)) {
			return isset($method['code']) && in_array((string)$method['code'], ['onepaycx', 'onepaycx.onepaycx'], true);
		}

		return (string)$method === 'onepaycx';
	}

	/**
	 * Route for one of this controller's methods. OpenCart 4.0.2 switched the
	 * method separator from "|" to ".".
	 */
	private function route(string $method): string {
		$sep = (defined('VERSION') && version_compare(VERSION, '4.0.2.0', '<')) ? '|' : '.';

		return self::ROUTE . $sep . $method;
	}

	/**
	 * Plain-text response with an HTTP status; the notify sender only looks at the status.
	 */
	protected function respond(int $status, string $text): void {
		$reasons = [200 => 'OK', 400 => 'Bad Request', 401 => 'Unauthorized', 404 => 'Not Found', 409 => 'Conflict', 500 => 'Internal Server Error', 503 => 'Service Unavailable'];
		$protocol = isset($this->request->server['SERVER_PROTOCOL']) && is_string($this->request->server['SERVER_PROTOCOL'])
			? $this->request->server['SERVER_PROTOCOL']
			: 'HTTP/1.1';

		$this->response->addHeader($protocol . ' ' . $status . ' ' . ($reasons[$status] ?? 'OK'));
		$this->response->addHeader('Content-Type: text/plain; charset=utf-8');
		$this->response->addHeader('Cache-Control: no-store');
		$this->response->setOutput($text);
	}

	/** The raw request body — the signature is computed over these exact bytes. */
	protected function rawBody(): string {
		return (string)file_get_contents('php://input');
	}

	/** Current unix time (overridable by the compile harness). */
	protected function now(): int {
		return time();
	}

	/** Factory so the compile harness can substitute a fake client. */
	protected function api(string $key): Api {
		return new Api($key);
	}
}
