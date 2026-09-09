<?php
/**
 * 1pay.cx for OpenCart 4 — tiny HTTP client for the 1pay.cx REST API.
 *
 * All outbound traffic goes to https://api.1pay.cx only; buyers are only ever
 * sent to https://1pay.cx. The API key travels as a bearer header and is never
 * logged, echoed or included in an error string.
 *
 * On OpenCart 4.0.2+ this file is autoloaded as
 * Opencart\System\Library\Extension\Onepaycx\Api (startup/extension registers
 * extension/onepaycx/system/library/); the controllers also require_once it by
 * path so older 4.0.x releases work the same way.
 *
 * @author    1pay.cx
 * @copyright 2026 1pay.cx
 * @license   MIT (see LICENSE)
 */

namespace Opencart\System\Library\Extension\Onepaycx;

class Api {
	public const VERSION = '1.0.0';
	public const API_BASE = 'https://api.1pay.cx';
	public const CHECKOUT_BASE = 'https://1pay.cx';
	public const USER_AGENT = 'onepaycx-opencart/1.0.0';

	/** @var string the merchant's API key (pk_xxx.yyy) */
	private string $key;

	/** @var int seconds */
	private int $timeout = 20;

	public function __construct(string $key) {
		$this->key = $key;
	}

	/**
	 * GET /v1/me — validates the key and returns { id, email, webhookSecret, ... }.
	 *
	 * @return array{ok:bool,status:int,data:mixed,error:string}
	 */
	public function me(): array {
		return $this->request('GET', '/v1/me');
	}

	/**
	 * POST /v1/payments — create a hosted-checkout payment.
	 * Returns { id, checkoutUrl } on success; checkoutUrl is a path on https://1pay.cx.
	 *
	 * @return array{ok:bool,status:int,data:mixed,error:string}
	 */
	public function createPayment(array $body): array {
		return $this->request('POST', '/v1/payments', $body);
	}

	/**
	 * GET /v1/payments/{id} — status is one of pending | paid | failed | expired.
	 *
	 * @return array{ok:bool,status:int,data:mixed,error:string}
	 */
	public function getPayment(string $id): array {
		if (!self::isPaymentId($id)) {
			return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'invalid_payment_id'];
		}

		return $this->request('GET', '/v1/payments/' . rawurlencode($id));
	}

	/** Payment ids are short URL-safe tokens; anything else is rejected before it reaches a URL or SQL. */
	public static function isPaymentId(string $id): bool {
		return (bool)preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id);
	}

	/**
	 * Turn the checkoutUrl returned by the API into an absolute URL on 1pay.cx.
	 * Anything that is not a relative path on 1pay.cx is rejected so a buyer can
	 * never be redirected anywhere but the hosted checkout.
	 *
	 * @return string|null absolute https://1pay.cx/... URL, or null when unusable
	 */
	public static function absoluteCheckoutUrl(string $checkoutUrl): ?string {
		if ($checkoutUrl === '') {
			return null;
		}
		if (strpos($checkoutUrl, '/') === 0 && strpos($checkoutUrl, '//') !== 0) {
			return self::CHECKOUT_BASE . $checkoutUrl;
		}
		if (strpos($checkoutUrl, self::CHECKOUT_BASE . '/') === 0) {
			return $checkoutUrl;
		}

		return null;
	}

	/**
	 * Format an amount as a two-decimal string ("12.50").
	 *
	 * @param float|int|string $amount
	 */
	public static function formatAmount($amount): string {
		return number_format(round((float)$amount, 2), 2, '.', '');
	}

	/**
	 * Constant-time check of X-1pay-Signature: hex HMAC-SHA256 of the RAW body
	 * under the merchant's webhook secret (from GET /v1/me).
	 */
	public static function verifySignature(string $rawBody, string $signature, string $secret): bool {
		$signature = strtolower(trim($signature));
		if ($secret === '' || !preg_match('/^[0-9a-f]{64}$/', $signature)) {
			return false;
		}
		$expected = hash_hmac('sha256', $rawBody, $secret);

		return hash_equals($expected, $signature);
	}

	/**
	 * Token that guards the storefront reconcile route. Derived from the webhook
	 * secret (never the secret itself) so it can be shown in the admin panel
	 * and pasted into a cron job without exposing what signs the notifies.
	 */
	public static function reconcileToken(string $secret): string {
		return hash_hmac('sha256', 'onepaycx-reconcile', $secret);
	}

	/**
	 * @return array{ok:bool,status:int,data:mixed,error:string}
	 */
	private function request(string $method, string $path, ?array $body = null): array {
		if (!function_exists('curl_init')) {
			return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'curl_missing'];
		}

		$headers = [
			'Authorization: Bearer ' . $this->key,
			'Accept: application/json',
			'User-Agent: ' . self::USER_AGENT,
		];

		$ch = curl_init(self::API_BASE . $path);
		$opts = [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CUSTOMREQUEST  => $method,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_TIMEOUT        => $this->timeout,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
		];
		if ($body !== null) {
			$headers[] = 'Content-Type: application/json';
			$opts[CURLOPT_POSTFIELDS] = json_encode($body);
		}
		$opts[CURLOPT_HTTPHEADER] = $headers;
		curl_setopt_array($ch, $opts);

		$raw = curl_exec($ch);
		$errno = curl_errno($ch);
		$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($raw === false || $errno !== 0) {
			// Deliberately generic: curl's own message is never surfaced to a page.
			return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'network_error'];
		}

		$data = json_decode((string)$raw, true);
		$ok = $status >= 200 && $status < 300;
		$error = '';
		if (!$ok) {
			$error = (is_array($data) && isset($data['error']) && is_string($data['error']))
				? $data['error']
				: ('HTTP ' . $status);
		}

		return ['ok' => $ok, 'status' => $status, 'data' => $data, 'error' => $error];
	}
}
