<?php
/**
 * 1pay.cx for OpenCart 4 — admin settings page.
 *
 * Saving a new API key validates it against GET /v1/me and caches the webhook
 * secret it returns (payment_onepaycx_webhook_secret); the storefront uses that
 * secret to verify the signed payment notifications. The stored key is never
 * echoed back into the page.
 *
 * Layout: OpenCart 4.0.x extension folder (extension/onepaycx/admin/...),
 * namespaced Opencart\Admin\Controller\Extension\Onepaycx\Payment\Onepaycx,
 * route extension/onepaycx/payment/onepaycx.
 *
 * @author    1pay.cx
 * @copyright 2026 1pay.cx
 * @license   MIT (see LICENSE)
 */

namespace Opencart\Admin\Controller\Extension\Onepaycx\Payment;

require_once __DIR__ . '/../../../system/library/api.php';

use Opencart\System\Library\Extension\Onepaycx\Api;

class Onepaycx extends \Opencart\System\Engine\Controller {
	private const ROUTE = 'extension/onepaycx/payment/onepaycx';

	public function index(): void {
		$this->loadLanguage();

		$this->document->setTitle($this->language->get('heading_title'));

		$user_token = $this->session->data['user_token'] ?? '';

		$data['breadcrumbs'] = [];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('text_home'),
			'href' => $this->url->link('common/dashboard', 'user_token=' . $user_token)
		];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('text_extension'),
			'href' => $this->url->link('marketplace/extension', 'user_token=' . $user_token . '&type=payment')
		];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('heading_title'),
			'href' => $this->url->link(self::ROUTE, 'user_token=' . $user_token)
		];

		$data['save'] = $this->url->link($this->route('save'), 'user_token=' . $user_token);
		$data['back'] = $this->url->link('marketplace/extension', 'user_token=' . $user_token . '&type=payment');

		// Connection state only — the key and the secret never reach the page.
		$merchant_id = (string)$this->config->get('payment_onepaycx_merchant_id');
		$connected = (string)$this->config->get('payment_onepaycx_api_key') !== '' && (string)$this->config->get('payment_onepaycx_webhook_secret') !== '';
		$data['connected'] = $connected;
		$data['text_connection'] = $connected
			? sprintf($this->language->get('text_connected'), $merchant_id)
			: $this->language->get('text_not_connected');

		$data['payment_onepaycx_title'] = (string)$this->config->get('payment_onepaycx_title');
		$data['payment_onepaycx_order_status_id'] = (int)$this->config->get('payment_onepaycx_order_status_id');
		$data['payment_onepaycx_pending_status_id'] = (int)$this->config->get('payment_onepaycx_pending_status_id');
		$data['payment_onepaycx_direct'] = (bool)$this->config->get('payment_onepaycx_direct');
		$data['payment_onepaycx_status'] = (bool)$this->config->get('payment_onepaycx_status');
		$data['payment_onepaycx_sort_order'] = (string)$this->config->get('payment_onepaycx_sort_order');

		$this->load->model('localisation/order_status');

		$data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();

		// Where the storefront receives payment notifications (for firewall allow-lists).
		$catalog = defined('HTTPS_CATALOG') ? HTTPS_CATALOG : (defined('HTTP_CATALOG') ? HTTP_CATALOG : '');
		$data['callback_url'] = $catalog !== '' ? $catalog . 'index.php?route=' . $this->route('callback') : '';

		// Catch-up route for a cron job / URL monitor: 1pay.cx sends each notify once,
		// so a store that was unreachable at that moment re-polls its open payments here.
		// The token is derived from the secret, never the secret itself.
		$secret = (string)$this->config->get('payment_onepaycx_webhook_secret');
		$data['reconcile_url'] = ($catalog !== '' && $secret !== '')
			? $catalog . 'index.php?route=' . $this->route('reconcile') . '&token=' . Api::reconcileToken($secret)
			: '';

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view(self::ROUTE, $data));
	}

	public function save(): void {
		$this->loadLanguage();

		$json = [];

		if (!$this->user->hasPermission('modify', self::ROUTE)) {
			$json['error']['warning'] = $this->language->get('error_permission');
		}

		$post = $this->request->post;
		$verified = false;

		if (!$json) {
			$current_key = (string)$this->config->get('payment_onepaycx_api_key');
			$current_secret = (string)$this->config->get('payment_onepaycx_webhook_secret');
			$current_merchant = (string)$this->config->get('payment_onepaycx_merchant_id');

			$key = trim((string)($post['payment_onepaycx_api_key'] ?? ''));
			$enabling = !empty($post['payment_onepaycx_status']);

			if ($key === '') {
				// Password fields post empty when untouched — keep the stored key + secret.
				if ($current_key === '' && $enabling) {
					$json['error']['api_key'] = $this->language->get('error_api_key_missing');
				}
				$post['payment_onepaycx_api_key'] = $current_key;
				$post['payment_onepaycx_webhook_secret'] = $current_secret;
				$post['payment_onepaycx_merchant_id'] = $current_merchant;
			} elseif (!preg_match('/^[A-Za-z0-9_.\-]{8,200}$/', $key)) {
				$json['error']['api_key'] = $this->language->get('error_api_key_format');
			} elseif ($key === $current_key && $current_secret !== '') {
				// Same key re-entered: nothing to verify.
				$post['payment_onepaycx_api_key'] = $current_key;
				$post['payment_onepaycx_webhook_secret'] = $current_secret;
				$post['payment_onepaycx_merchant_id'] = $current_merchant;
			} else {
				$res = $this->api($key)->me();

				if (!$res['ok'] || !is_array($res['data']) || empty($res['data']['webhookSecret']) || !is_string($res['data']['webhookSecret'])) {
					$json['error']['api_key'] = sprintf(
						$this->language->get('error_api_key_verify'),
						$res['error'] !== '' ? $res['error'] : $this->language->get('error_unknown')
					);
				} else {
					$post['payment_onepaycx_api_key'] = $key;
					$post['payment_onepaycx_webhook_secret'] = $res['data']['webhookSecret'];
					$post['payment_onepaycx_merchant_id'] = isset($res['data']['id']) ? (string)$res['data']['id'] : '';
					$verified = true;
				}
			}
		}

		if (!$json) {
			$post['payment_onepaycx_title'] = trim((string)($post['payment_onepaycx_title'] ?? ''));
			$post['payment_onepaycx_order_status_id'] = (int)($post['payment_onepaycx_order_status_id'] ?? 0);
			$post['payment_onepaycx_pending_status_id'] = (int)($post['payment_onepaycx_pending_status_id'] ?? 0);
			$post['payment_onepaycx_direct'] = empty($post['payment_onepaycx_direct']) ? 0 : 1;
			$post['payment_onepaycx_status'] = empty($post['payment_onepaycx_status']) ? 0 : 1;
			$post['payment_onepaycx_sort_order'] = (int)($post['payment_onepaycx_sort_order'] ?? 0);

			$this->load->model('setting/setting');

			$this->model_setting_setting->editSetting('payment_onepaycx', $post);

			$json['success'] = $this->language->get($verified ? 'text_success_verified' : 'text_success');
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	/** Called by admin/controller/extension/payment.php when the extension is installed. */
	public function install(): void {
		if ($this->user->hasPermission('modify', 'extension/payment')) {
			$this->load->model(self::ROUTE);

			$this->model_extension_onepaycx_payment_onepaycx->install();

			// Sensible defaults on a fresh install only: editSetting() replaces the whole
			// payment_onepaycx group, so never run it over a merchant's saved key.
			if ((string)$this->config->get('payment_onepaycx_api_key') === '' && (string)$this->config->get('payment_onepaycx_title') === '') {
				$this->load->model('setting/setting');

				$this->model_setting_setting->editSetting('payment_onepaycx', [
					'payment_onepaycx_status'            => 0,
					'payment_onepaycx_title'             => 'Pay by card or crypto',
					'payment_onepaycx_order_status_id'   => 2, // Processing
					'payment_onepaycx_pending_status_id' => 0, // leave as a missing order until paid
					'payment_onepaycx_direct'            => 0,
					'payment_onepaycx_sort_order'        => 1,
				]);
			}
		}
	}

	/**
	 * Called by admin/controller/extension/payment.php when the extension is
	 * uninstalled (core deletes the settings). The `onepaycx_order` table is
	 * kept on purpose: it is the only map from a payment id to an order, and a
	 * reinstall must still be able to complete payments that are in flight.
	 */
	public function uninstall(): void {
		if ($this->user->hasPermission('modify', 'extension/payment')) {
			$this->load->model(self::ROUTE);

			$this->model_extension_onepaycx_payment_onepaycx->uninstall();
		}
	}

	/**
	 * OpenCart loads only the admin user's language file and has no en-gb
	 * fallback, so a non-English admin would see raw keys. Load the shipped
	 * en-gb strings first; a translation, when present, overrides them.
	 */
	private function loadLanguage(): void {
		$this->load->language(self::ROUTE, '', 'en-gb');
		$this->load->language(self::ROUTE);
	}

	/**
	 * Route for one of this controller's methods. OpenCart 4.0.2 switched the
	 * method separator from "|" to ".".
	 */
	private function route(string $method): string {
		$sep = (defined('VERSION') && version_compare(VERSION, '4.0.2.0', '<')) ? '|' : '.';

		return self::ROUTE . $sep . $method;
	}

	/** Factory so the compile harness can substitute a fake client. */
	protected function api(string $key): Api {
		return new Api($key);
	}
}
