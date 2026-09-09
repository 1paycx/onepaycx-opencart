<?php
/**
 * 1pay.cx for OpenCart 4 — storefront model.
 *
 * Offers the payment method at checkout and keeps the mapping between a
 * 1pay.cx payment id and the order it pays for (table `onepaycx_order`,
 * created by the admin model on install). One row per payment attempt; the
 * `status` column is the idempotency latch for the paid event.
 *
 * @author    1pay.cx
 * @copyright 2026 1pay.cx
 * @license   MIT (see LICENSE)
 */

namespace Opencart\Catalog\Model\Extension\Onepaycx\Payment;

class Onepaycx extends \Opencart\System\Engine\Model {
	/**
	 * OpenCart 4.0.2+ payment-method discovery.
	 *
	 * @param array $address the payment address (unused: no geo-zone restriction)
	 */
	public function getMethods(array $address = []): array {
		if (!$this->isAvailable()) {
			return [];
		}

		$name = $this->title();

		return [
			'code'       => 'onepaycx',
			'name'       => $name,
			'option'     => [
				'onepaycx' => [
					'code' => 'onepaycx.onepaycx',
					'name' => $name,
				],
			],
			'sort_order' => $this->config->get('payment_onepaycx_sort_order'),
		];
	}

	/**
	 * OpenCart 4.0.0 / 4.0.1 payment-method discovery (single option per extension).
	 *
	 * @param array $address the payment address (unused)
	 * @param float $total   the cart total (unused)
	 */
	public function getMethod(array $address, float $total = 0): array {
		if (!$this->isAvailable()) {
			return [];
		}

		return [
			'code'       => 'onepaycx',
			'title'      => $this->title(),
			'terms'      => '',
			'sort_order' => $this->config->get('payment_onepaycx_sort_order'),
		];
	}

	/** True when the extension is enabled, connected, and the cart holds nothing we cannot charge once. */
	private function isAvailable(): bool {
		if (!$this->config->get('payment_onepaycx_status')) {
			return false;
		}
		if ((string)$this->config->get('payment_onepaycx_api_key') === '' || (string)$this->config->get('payment_onepaycx_webhook_secret') === '') {
			return false;
		}
		// Subscriptions need a stored card; a hosted one-off checkout cannot renew them.
		if ($this->registry->has('cart') && method_exists($this->cart, 'hasSubscription') && $this->cart->hasSubscription()) {
			return false;
		}

		return true;
	}

	private function title(): string {
		// OpenCart loads only the store language and has no fallback, so load the
		// shipped en-gb file first; a translation, when present, overrides it.
		$this->load->language('extension/onepaycx/payment/onepaycx', '', 'en-gb');
		$this->load->language('extension/onepaycx/payment/onepaycx');

		$title = trim((string)$this->config->get('payment_onepaycx_title'));

		return $title !== '' ? $title : $this->language->get('heading_title');
	}

	/**
	 * Remember which 1pay.cx payment belongs to an order.
	 *
	 * @param string $amount   two-decimal string ("12.50")
	 * @param string $currency upper-case ISO code
	 */
	public function addPayment(string $payment_id, int $order_id, string $amount, string $currency, string $return_key): void {
		$this->db->query("INSERT INTO `" . DB_PREFIX . "onepaycx_order` SET"
			. " `payment_id` = '" . $this->db->escape($payment_id) . "',"
			. " `order_id` = '" . (int)$order_id . "',"
			. " `amount` = '" . $this->db->escape($amount) . "',"
			. " `currency` = '" . $this->db->escape(strtoupper($currency)) . "',"
			. " `status` = 'pending',"
			. " `return_key` = '" . $this->db->escape($return_key) . "',"
			. " `date_added` = NOW(), `date_modified` = NOW()");
	}

	/**
	 * @return array|null row with payment_id, order_id, amount, currency, status, return_key
	 */
	public function getPayment(string $payment_id): ?array {
		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "onepaycx_order` WHERE `payment_id` = '" . $this->db->escape($payment_id) . "'");

		return $query->num_rows ? $query->row : null;
	}

	/**
	 * The most recent payment attempt for an order (a buyer may retry after an expiry).
	 *
	 * @return array|null
	 */
	public function getLatestPaymentByOrder(int $order_id): ?array {
		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "onepaycx_order` WHERE `order_id` = '" . (int)$order_id . "' ORDER BY `date_added` DESC, `payment_id` DESC LIMIT 1");

		return $query->num_rows ? $query->row : null;
	}

	/**
	 * Payments that may still settle: not yet paid (pending, or expired but
	 * still payable late) and younger than $max_age_days. Pending rows first so
	 * a backlog of expired attempts cannot starve a live one.
	 *
	 * @return array[] rows, oldest first within each status
	 */
	public function getUnsettled(int $max_age_days, int $limit): array {
		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "onepaycx_order` WHERE `status` IN ('pending', 'expired') AND `date_added` > DATE_SUB(NOW(), INTERVAL " . (int)$max_age_days . " DAY) ORDER BY (`status` = 'pending') DESC, `date_added` ASC LIMIT " . max(1, (int)$limit));

		return $query->rows;
	}

	/**
	 * Atomically claim the paid transition. Returns true exactly once per
	 * payment: a redelivered webhook or a concurrent return-page poll gets
	 * false and must not touch the order again.
	 */
	public function markPaid(string $payment_id): bool {
		$this->db->query("UPDATE `" . DB_PREFIX . "onepaycx_order` SET `status` = 'paid', `date_modified` = NOW() WHERE `payment_id` = '" . $this->db->escape($payment_id) . "' AND `status` <> 'paid'");

		return $this->db->countAffected() > 0;
	}

	/**
	 * @param string $status pending | failed | expired
	 */
	public function setStatus(string $payment_id, string $status): void {
		$status = preg_replace('/[^a-z]/', '', strtolower($status));

		$this->db->query("UPDATE `" . DB_PREFIX . "onepaycx_order` SET `status` = '" . $this->db->escape($status) . "', `date_modified` = NOW() WHERE `payment_id` = '" . $this->db->escape($payment_id) . "'");
	}
}
