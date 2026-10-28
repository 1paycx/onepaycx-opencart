<?php
/**
 * Stub-parent compile harness for the 1pay.cx OpenCart 4 extension.
 *
 * Loads minimal OpenCart 4.0.2-shaped stand-ins (stubs.php), then requires the
 * API client, both controllers and both models, instantiates every class and
 * drives the admin save/install flow and the storefront confirm / callback /
 * return flow with fake requests. Anything that would be a compile-time fatal
 * on a real store (abstract mismatch, visibility narrowing, bad signature)
 * fails here; so does a regression in signature verification or idempotency.
 *
 * Usage: php test/compile-check.php   (exit code 0 = pass)
 *
 * @license MIT
 */

declare(strict_types=0);

error_reporting(E_ALL);
ini_set('display_errors', '1');

if (!defined('VERSION')) {
	define('VERSION', '4.0.2.3');
}
if (!defined('DB_PREFIX')) {
	define('DB_PREFIX', 'oc_');
}
if (!defined('HTTP_CATALOG')) {
	define('HTTP_CATALOG', 'https://shop.example/');
}

require_once __DIR__ . '/stubs.php';

use Opencart\System\Engine\Registry;
use Opencart\System\Engine\Loader;
use Opencart\System\Library\Request;
use Opencart\System\Library\Response;
use Opencart\System\Library\Session;
use Opencart\System\Library\Config;
use Opencart\System\Library\Url;
use Opencart\System\Library\Language;
use Opencart\System\Library\Document;
use Opencart\System\Library\Log;
use Opencart\System\Library\DB;
use Opencart\System\Library\User;
use Opencart\System\Library\StubRedirect;
use Opencart\System\Library\Cart\Cart;
use Opencart\System\Library\Cart\Currency;
use Opencart\System\Library\Extension\Onepaycx\Api;

$upload = dirname(__DIR__) . '/upload';

$failures = 0;
function check(string $label, bool $cond): void {
	global $failures;
	if ($cond) {
		echo "  ok   $label\n";
	} else {
		++$failures;
		echo "  FAIL $label\n";
	}
}

/* ------------------------------------------------------------------ */
echo "1. Loading the extension against stub parents\n";
/* ------------------------------------------------------------------ */

require_once $upload . '/system/library/api.php';
require_once $upload . '/admin/controller/payment/onepaycx.php';
require_once $upload . '/admin/model/payment/onepaycx.php';
require_once $upload . '/catalog/controller/payment/onepaycx.php';
require_once $upload . '/catalog/model/payment/onepaycx.php';

$classes = [
	'Opencart\System\Library\Extension\Onepaycx\Api',
	'Opencart\Admin\Controller\Extension\Onepaycx\Payment\Onepaycx',
	'Opencart\Admin\Model\Extension\Onepaycx\Payment\Onepaycx',
	'Opencart\Catalog\Controller\Extension\Onepaycx\Payment\Onepaycx',
	'Opencart\Catalog\Model\Extension\Onepaycx\Payment\Onepaycx',
];
foreach ($classes as $cls) {
	check("$cls declared", class_exists($cls, false));
	$ref = new ReflectionClass($cls);
	check("$cls is concrete", !$ref->isAbstract());
}
check('admin controller extends Opencart\System\Engine\Controller', is_subclass_of('Opencart\Admin\Controller\Extension\Onepaycx\Payment\Onepaycx', 'Opencart\System\Engine\Controller'));
check('catalog controller extends Opencart\System\Engine\Controller', is_subclass_of('Opencart\Catalog\Controller\Extension\Onepaycx\Payment\Onepaycx', 'Opencart\System\Engine\Controller'));
check('models extend Opencart\System\Engine\Model', is_subclass_of('Opencart\Admin\Model\Extension\Onepaycx\Payment\Onepaycx', 'Opencart\System\Engine\Model') && is_subclass_of('Opencart\Catalog\Model\Extension\Onepaycx\Payment\Onepaycx', 'Opencart\System\Engine\Model'));

foreach (['index', 'save', 'install', 'uninstall'] as $m) {
	check("admin controller has public $m()", (new ReflectionMethod('Opencart\Admin\Controller\Extension\Onepaycx\Payment\Onepaycx', $m))->isPublic());
}
foreach (['index', 'confirm', 'callback', 'return', 'reconcile'] as $m) {
	check("catalog controller has public $m()", (new ReflectionMethod('Opencart\Catalog\Controller\Extension\Onepaycx\Payment\Onepaycx', $m))->isPublic());
}
foreach (['getMethods', 'getMethod', 'addPayment', 'getPayment', 'getLatestPaymentByOrder', 'getUnsettled', 'markPaid', 'setStatus'] as $m) {
	check("catalog model has public $m()", (new ReflectionMethod('Opencart\Catalog\Model\Extension\Onepaycx\Payment\Onepaycx', $m))->isPublic());
}

/* ------------------------------------------------------------------ */
echo "2. API client helpers\n";
/* ------------------------------------------------------------------ */

$secret = 'whsec_test';
$raw = json_encode(['event' => 'payment.paid', 'data' => ['paymentId' => 'k3Xm9Qp2', 'reference' => '7', 'amount' => 12.5, 'valueForwarded' => 12.5, 'status' => 'paid', 'currency' => 'USD', 'livemode' => true], 'at' => '2026-09-08T00:00:00.000Z']);
$sig = hash_hmac('sha256', $raw, $secret);
check('valid signature accepted', Api::verifySignature($raw, $sig, $secret));
check('upper-case hex accepted', Api::verifySignature($raw, strtoupper($sig), $secret));
check('tampered body rejected', !Api::verifySignature($raw . ' ', $sig, $secret));
check('wrong secret rejected', !Api::verifySignature($raw, $sig, 'other'));
check('malformed signature rejected', !Api::verifySignature($raw, 'nope', $secret));
check('empty secret rejected', !Api::verifySignature($raw, $sig, ''));
check('formatAmount gives two decimals', Api::formatAmount(12.5) === '12.50' && Api::formatAmount('3') === '3.00' && Api::formatAmount(0.105) === '0.11');
check('absoluteCheckoutUrl only allows 1pay.cx', Api::absoluteCheckoutUrl('/pay/abc') === 'https://1pay.cx/pay/abc'
	&& Api::absoluteCheckoutUrl('https://1pay.cx/pay/abc') === 'https://1pay.cx/pay/abc'
	&& Api::absoluteCheckoutUrl('https://evil.example/pay') === null
	&& Api::absoluteCheckoutUrl('//evil.example/pay') === null
	&& Api::absoluteCheckoutUrl('') === null);
check('isPaymentId', Api::isPaymentId('k3Xm9Qp2') && !Api::isPaymentId('') && !Api::isPaymentId('a/b') && !Api::isPaymentId(str_repeat('a', 65)));
check('user agent identifies the platform', str_contains(Api::USER_AGENT, 'onepaycx-opencart'));
check('reconcileToken is a 64-hex value derived from (not equal to) the secret', (bool)preg_match('/^[0-9a-f]{64}$/', Api::reconcileToken('whsec_test')) && Api::reconcileToken('whsec_test') !== Api::reconcileToken('other') && !str_contains(Api::reconcileToken('whsec_test'), 'whsec'));

/* ------------------------------------------------------------------ */
/* Harness doubles                                                     */
/* ------------------------------------------------------------------ */

/** Same method surface as Api, canned answers. */
class FakeApi extends Api {
	public static array $payment = ['id' => 'k3Xm9Qp2', 'checkoutUrl' => '/pay/k3Xm9Qp2'];
	public static string $status = 'pending';
	/** @var array<string,string> per-payment status overrides for reconcile runs */
	public static array $statusById = [];
	public static float $amount = 12.5;
	public static bool $createOk = true;
	public static bool $meOk = true;
	public static ?array $lastBody = null;
	public static array $calls = [];

	public function me(): array {
		self::$calls[] = 'me';
		if (!self::$meOk) {
			return ['ok' => false, 'status' => 401, 'data' => ['error' => 'unauthorized'], 'error' => 'unauthorized'];
		}

		return ['ok' => true, 'status' => 200, 'data' => ['id' => 'm_1', 'email' => 'shop@example.com', 'webhookSecret' => 'whsec_test'], 'error' => ''];
	}

	public function createPayment(array $body): array {
		self::$calls[] = 'create';
		self::$lastBody = $body;
		if (!self::$createOk) {
			return ['ok' => false, 'status' => 400, 'data' => ['error' => 'invalid'], 'error' => 'invalid'];
		}

		return ['ok' => true, 'status' => 200, 'data' => self::$payment, 'error' => ''];
	}

	/** @var array<string,float> per-payment amount overrides */
	public static array $amountById = [];
	public static string $currency = 'USD';
	public static bool $livemode = true;
	public static bool $getOk = true;

	public function getPayment(string $id): array {
		self::$calls[] = 'get:' . $id;
		if (!self::$getOk) {
			return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'network_error'];
		}

		return ['ok' => true, 'status' => 200, 'data' => ['id' => $id, 'status' => self::$statusById[$id] ?? self::$status, 'amount' => self::$amountById[$id] ?? self::$amount, 'currency' => self::$currency, 'livemode' => self::$livemode], 'error' => ''];
	}
}

class TestAdminController extends Opencart\Admin\Controller\Extension\Onepaycx\Payment\Onepaycx {
	protected function api(string $key): Api {
		return new FakeApi($key);
	}
}

class TestCatalogController extends Opencart\Catalog\Controller\Extension\Onepaycx\Payment\Onepaycx {
	public string $body = '';

	protected function rawBody(): string {
		return $this->body;
	}

	protected function api(string $key): Api {
		return new FakeApi($key);
	}
}

/** admin model setting/setting */
class FakeSettingModel {
	public array $settings = [];

	public function editSetting(string $code, array $data, int $store_id = 0): void {
		$this->settings = [];
		foreach ($data as $k => $v) {
			if (str_starts_with($k, $code)) {
				$this->settings[$k] = $v;
			}
		}
	}
}

/** admin model localisation/order_status */
class FakeOrderStatusModel {
	public function getOrderStatuses(array $data = []): array {
		return [['order_status_id' => 1, 'name' => 'Pending'], ['order_status_id' => 2, 'name' => 'Processing'], ['order_status_id' => 5, 'name' => 'Complete']];
	}
}

/** catalog model checkout/order */
class FakeOrderModel {
	public array $orders = [];
	public array $history = [];
	public bool $throwOnHistory = false;

	public function getOrder(int $order_id): array {
		return $this->orders[$order_id] ?? [];
	}

	public function addHistory(int $order_id, int $order_status_id, string $comment = '', bool $notify = false, bool $override = false): void {
		if ($this->throwOnHistory) {
			throw new RuntimeException('db down');
		}
		$this->history[] = ['order_id' => $order_id, 'order_status_id' => $order_status_id, 'comment' => $comment, 'notify' => $notify];
		$this->orders[$order_id]['order_status_id'] = $order_status_id;
	}
}

/** In-memory version of the extension's catalog model (same surface). */
class FakeOnepaycxModel {
	public array $rows = [];

	public function addPayment(string $payment_id, int $order_id, string $amount, string $currency, string $return_key): void {
		$this->rows[$payment_id] = ['payment_id' => $payment_id, 'order_id' => $order_id, 'amount' => $amount, 'currency' => $currency, 'status' => 'pending', 'return_key' => $return_key, 'seq' => count($this->rows)];
	}

	public function getPayment(string $payment_id): ?array {
		return $this->rows[$payment_id] ?? null;
	}

	public function getLatestPaymentByOrder(int $order_id): ?array {
		$best = null;
		foreach ($this->rows as $r) {
			if ((int)$r['order_id'] === $order_id && ($best === null || $r['seq'] > $best['seq'])) {
				$best = $r;
			}
		}

		return $best;
	}

	public function getUnsettled(int $max_age_days, int $limit): array {
		$out = array_values(array_filter($this->rows, fn ($r) => in_array($r['status'], ['pending', 'expired'], true)));
		usort($out, fn ($x, $y) => [$x['status'] !== 'pending', $x['seq']] <=> [$y['status'] !== 'pending', $y['seq']]);

		return array_slice($out, 0, $limit);
	}

	public function markPaid(string $payment_id): bool {
		if (!isset($this->rows[$payment_id]) || $this->rows[$payment_id]['status'] === 'paid') {
			return false;
		}
		$this->rows[$payment_id]['status'] = 'paid';

		return true;
	}

	public function setStatus(string $payment_id, string $status): void {
		if (isset($this->rows[$payment_id])) {
			$this->rows[$payment_id]['status'] = $status;
		}
	}
}

function registry(string $app, string $upload): Registry {
	$r = new Registry();
	$r->set('load', new Loader($r, $app, $upload));
	$r->set('request', new Request());
	$r->set('response', new Response());
	$r->set('session', new Session());
	$r->set('config', new Config());
	$r->set('url', new Url('https://shop.example/'));
	$r->set('language', new Language());
	$r->set('document', new Document());
	$r->set('log', new Log());
	$r->set('db', new DB());
	$r->set('cart', new Cart());
	$r->set('currency', new Currency());
	$r->set('user', new User());
	$r->config->set('config_language', 'en-gb');
	$r->config->set('config_name', 'Demo &amp; Co');
	$r->config->set('config_order_status_id', 1);
	$r->config->set('config_processing_status', [2, 3]);
	$r->config->set('config_complete_status', [5]);

	return $r;
}

/** True if $needle appears anywhere inside a nested array/string. */
function contains_deep(mixed $haystack, string $needle): bool {
	if (is_array($haystack)) {
		foreach ($haystack as $v) {
			if (contains_deep($v, $needle)) {
				return true;
			}
		}

		return false;
	}

	return is_string($haystack) && str_contains($haystack, $needle);
}

/* ------------------------------------------------------------------ */
echo "3. Admin controller\n";
/* ------------------------------------------------------------------ */

$a = registry('admin', $upload);
$a->session->data['user_token'] = 'tok';
$settings = new FakeSettingModel();
$a->load->models['setting/setting'] = $settings;
$a->load->models['localisation/order_status'] = new FakeOrderStatusModel();
$admin = new TestAdminController($a);

$admin->index();
$view = $a->load->views['extension/onepaycx/payment/onepaycx'] ?? null;
check('index() renders the settings view', $view !== null && str_contains($a->response->getOutput(), '<view:extension/onepaycx/payment/onepaycx>'));
check('index() save/back links use the "." method separator', str_contains($view['save'], 'extension/onepaycx/payment/onepaycx.save') && str_contains($view['back'], 'marketplace/extension'));
check('index() reports "not connected" before a key is saved', $view['connected'] === false);
check('index() lists order statuses and the callback URL', count($view['order_statuses']) === 3 && $view['callback_url'] === 'https://shop.example/index.php?route=extension/onepaycx/payment/onepaycx.callback');
check('language file loaded (heading_title resolved)', $a->language->get('heading_title') === '1pay.cx - pay by card or crypto');

// save(): permission
$a->response->reset();
$admin->save();
$json = json_decode($a->response->getOutput(), true);
check('save() without permission -> error.warning', isset($json['error']['warning']));

$a->user->permissions[] = 'modify:extension/onepaycx/payment/onepaycx';
$a->user->permissions[] = 'modify:extension/payment';

// save(): enabling without a key
$a->request->post = ['payment_onepaycx_status' => '1', 'payment_onepaycx_api_key' => '', 'payment_onepaycx_title' => 'Pay', 'payment_onepaycx_order_status_id' => '2', 'payment_onepaycx_sort_order' => '1'];
$a->response->reset();
$admin->save();
$json = json_decode($a->response->getOutput(), true);
check('save() enabling with no key -> error.api_key', isset($json['error']['api_key']) && !isset($json['success']));

// save(): malformed key
$a->request->post['payment_onepaycx_api_key'] = 'not a key!';
$a->response->reset();
$admin->save();
$json = json_decode($a->response->getOutput(), true);
check('save() malformed key -> error.api_key, /v1/me not called', isset($json['error']['api_key']) && !in_array('me', FakeApi::$calls, true));

// save(): key rejected upstream
FakeApi::$meOk = false;
$a->request->post['payment_onepaycx_api_key'] = 'pk_test.secret';
$a->response->reset();
$admin->save();
$json = json_decode($a->response->getOutput(), true);
check('save() key rejected by /v1/me -> error.api_key, nothing stored', isset($json['error']['api_key']) && str_contains($json['error']['api_key'], 'unauthorized') && $settings->settings === []);
FakeApi::$meOk = true;

// save(): good key
$a->response->reset();
$admin->save();
$json = json_decode($a->response->getOutput(), true);
check('save() verified key -> success', isset($json['success']) && !isset($json['error']));
check('save() stores key, webhook secret and merchant id', ($settings->settings['payment_onepaycx_api_key'] ?? '') === 'pk_test.secret'
	&& ($settings->settings['payment_onepaycx_webhook_secret'] ?? '') === 'whsec_test'
	&& ($settings->settings['payment_onepaycx_merchant_id'] ?? '') === 'm_1'
	&& ($settings->settings['payment_onepaycx_status'] ?? null) === 1
	&& ($settings->settings['payment_onepaycx_order_status_id'] ?? null) === 2);

// Simulate the settings now being loaded into config (as OpenCart does on the next request).
foreach ($settings->settings as $k => $v) {
	$a->config->set($k, $v);
}

// save(): blank key on re-save keeps key + secret
$a->request->post['payment_onepaycx_api_key'] = '';
$a->request->post['payment_onepaycx_title'] = 'Pay by card or crypto';
FakeApi::$calls = [];
$a->response->reset();
$admin->save();
$json = json_decode($a->response->getOutput(), true);
check('save() blank key on re-save keeps the stored key + secret, no /v1/me call', isset($json['success'])
	&& ($settings->settings['payment_onepaycx_api_key'] ?? '') === 'pk_test.secret'
	&& ($settings->settings['payment_onepaycx_webhook_secret'] ?? '') === 'whsec_test'
	&& FakeApi::$calls === []);

// save(): same key re-entered is not re-verified
$a->request->post['payment_onepaycx_api_key'] = 'pk_test.secret';
$a->response->reset();
$admin->save();
check('save() same key re-entered -> no /v1/me call, secret kept', FakeApi::$calls === [] && ($settings->settings['payment_onepaycx_webhook_secret'] ?? '') === 'whsec_test');

// index() after connecting never echoes the key or secret
$admin->index();
$view = $a->load->views['extension/onepaycx/payment/onepaycx'];
check('index() shows "connected" with the merchant id', $view['connected'] === true && str_contains($view['text_connection'], 'm_1'));
check('index() never passes the API key or webhook secret to the view', !contains_deep($view, 'pk_test.secret') && !contains_deep($view, 'whsec_test'));
check('index() shows the reconcile URL carrying the derived token', $view['reconcile_url'] === 'https://shop.example/index.php?route=extension/onepaycx/payment/onepaycx.reconcile&token=' . Api::reconcileToken('whsec_test'));

// Non-English admin user: the shipped en-gb strings must still resolve.
$de = registry('admin', $upload);
$de->load->code = 'de-de';
$de->session->data['user_token'] = 'tok';
$de->load->models['localisation/order_status'] = new FakeOrderStatusModel();
$de->load->translations['de-de']['extension/onepaycx/payment/onepaycx'] = ['entry_api_key' => 'API-Schlüssel'];
(new TestAdminController($de))->index();
check('admin index() on a de-de admin loads en-gb first, then the translation on top', $de->load->languageLoads === ['extension/onepaycx/payment/onepaycx@en-gb', 'extension/onepaycx/payment/onepaycx@de-de']
	&& $de->language->get('heading_title') === '1pay.cx - pay by card or crypto' && $de->language->get('entry_api_key') === 'API-Schlüssel' && $de->language->missing === ['text_home']);

// install()/uninstall()
$fresh = registry('admin', $upload);
$fresh->user->permissions[] = 'modify:extension/payment';
$freshSettings = new FakeSettingModel();
$fresh->load->models['setting/setting'] = $freshSettings;
(new TestAdminController($fresh))->install();
check('install() creates the onepaycx_order table', count(array_filter($fresh->db->queries, fn ($q) => str_contains($q, 'CREATE TABLE IF NOT EXISTS `oc_onepaycx_order`'))) === 1);
check('install() writes defaults (disabled, Processing when paid)', ($freshSettings->settings['payment_onepaycx_status'] ?? null) === 0 && ($freshSettings->settings['payment_onepaycx_order_status_id'] ?? null) === 2);
(new TestAdminController($fresh))->uninstall();
check('uninstall() keeps the onepaycx_order table (in-flight payments stay recoverable)', count(array_filter($fresh->db->queries, fn ($q) => stripos($q, 'DROP') !== false)) === 0);
(new TestAdminController($a))->install();
check('install() over an existing key does not wipe the settings', ($settings->settings['payment_onepaycx_api_key'] ?? '') === 'pk_test.secret');

/* ------------------------------------------------------------------ */
echo "4. Catalog model (real class against the DB stub)\n";
/* ------------------------------------------------------------------ */

$c = registry('catalog', $upload);
$c->config->set('payment_onepaycx_status', 1);
$c->config->set('payment_onepaycx_api_key', 'pk_test.secret');
$c->config->set('payment_onepaycx_webhook_secret', 'whsec_test');
$c->config->set('payment_onepaycx_title', 'Pay');
$c->config->set('payment_onepaycx_sort_order', 3);
$c->config->set('payment_onepaycx_order_status_id', 2);
$c->config->set('payment_onepaycx_pending_status_id', 0);

$c->load->model('extension/onepaycx/payment/onepaycx');
$realModel = $c->model_extension_onepaycx_payment_onepaycx;
check('real catalog model instantiated via the loader', $realModel instanceof Opencart\Catalog\Model\Extension\Onepaycx\Payment\Onepaycx);

$methods = $realModel->getMethods(['country_id' => 1, 'zone_id' => 1]);
check('getMethods() (4.0.2+) returns code onepaycx with option onepaycx.onepaycx', $methods['code'] === 'onepaycx' && $methods['option']['onepaycx']['code'] === 'onepaycx.onepaycx' && $methods['name'] === 'Pay' && $methods['sort_order'] === 3);
$method = $realModel->getMethod(['country_id' => 1, 'zone_id' => 1], 12.5);
check('getMethod() (4.0.0/4.0.1) returns code onepaycx with a title', $method['code'] === 'onepaycx' && $method['title'] === 'Pay');
$c->config->set('payment_onepaycx_title', '');
check('empty title falls back to the language heading', $realModel->getMethods()['name'] === 'Pay by card or crypto');
$c->load->code = 'fr-fr';
check('...also on a non-en-gb storefront (en-gb loaded first, then the store language)', $realModel->getMethods()['name'] === 'Pay by card or crypto' && in_array('extension/onepaycx/payment/onepaycx@en-gb', $c->load->languageLoads, true) && end($c->load->languageLoads) === 'extension/onepaycx/payment/onepaycx@fr-fr');
$c->load->code = 'en-gb';
$c->cart->subscription = true;
check('subscription carts hide the method', $realModel->getMethods() === [] && $realModel->getMethod([]) === []);
$c->cart->subscription = false;
$c->config->set('payment_onepaycx_webhook_secret', '');
check('unconnected store hides the method', $realModel->getMethods() === []);
$c->config->set('payment_onepaycx_webhook_secret', 'whsec_test');
$c->config->set('payment_onepaycx_status', 0);
check('disabled extension hides the method', $realModel->getMethods() === []);
$c->config->set('payment_onepaycx_status', 1);

$c->db->queries = [];
$realModel->addPayment("k3'Xm", 7, '12.50', 'usd', str_repeat('a', 32));
$q = end($c->db->queries);
check('addPayment() writes an escaped INSERT with upper-case currency', str_starts_with($q, 'INSERT INTO `oc_onepaycx_order`') && str_contains($q, "k3\\'Xm") && str_contains($q, "`currency` = 'USD'") && str_contains($q, "`order_id` = '7'"));
$c->db->rows = [];
check('getPayment() returns null when unknown', $realModel->getPayment('zzz') === null);
$c->db->rows = [['payment_id' => 'k3Xm9Qp2', 'order_id' => 7, 'status' => 'pending']];
check('getPayment()/getLatestPaymentByOrder() return the row', $realModel->getPayment('k3Xm9Qp2')['order_id'] === 7 && $realModel->getLatestPaymentByOrder(7)['payment_id'] === 'k3Xm9Qp2');
$c->db->affected = 1;
check('markPaid() true when the UPDATE claimed the row', $realModel->markPaid('k3Xm9Qp2') && str_contains(end($c->db->queries), "`status` <> 'paid'"));
$c->db->affected = 0;
check('markPaid() false on redelivery', !$realModel->markPaid('k3Xm9Qp2'));
$realModel->setStatus('k3Xm9Qp2', 'EXPIRED; DROP');
check('setStatus() sanitises the status', str_contains(end($c->db->queries), "`status` = 'expireddrop'"));
$c->db->rows = [['payment_id' => 'p1', 'order_id' => 7, 'status' => 'pending']];
$unsettled = $realModel->getUnsettled(7, 20);
$q = end($c->db->queries);
check('getUnsettled() selects pending/expired rows of the last N days, pending first, bounded', count($unsettled) === 1 && str_contains($q, "`status` IN ('pending', 'expired')") && str_contains($q, 'INTERVAL 7 DAY') && str_contains($q, "(`status` = 'pending') DESC") && str_ends_with($q, 'LIMIT 20'));

/* ------------------------------------------------------------------ */
echo "5. Catalog controller: index + confirm\n";
/* ------------------------------------------------------------------ */

$c = registry('catalog', $upload);
$c->config->set('payment_onepaycx_status', 1);
$c->config->set('payment_onepaycx_api_key', 'pk_test.secret');
$c->config->set('payment_onepaycx_webhook_secret', 'whsec_test');
$c->config->set('payment_onepaycx_order_status_id', 2);
$c->config->set('payment_onepaycx_pending_status_id', 0);
$c->config->set('payment_onepaycx_direct', 0);
$orders = new FakeOrderModel();
$orders->orders[7] = ['order_id' => 7, 'order_status_id' => 0, 'total' => 12.5, 'currency_code' => 'USD', 'currency_value' => 1.0, 'email' => 'buyer@example.com'];
$orders->orders[8] = ['order_id' => 8, 'order_status_id' => 0, 'total' => 10.0, 'currency_code' => 'EUR', 'currency_value' => 0.9, 'email' => 'not-an-email'];
$orders->orders[9] = ['order_id' => 9, 'order_status_id' => 0, 'total' => 10.0, 'currency_code' => 'XYZ', 'currency_value' => 1.0, 'email' => ''];
$c->load->models['checkout/order'] = $orders;
$store = new FakeOnepaycxModel();
$c->load->models['extension/onepaycx/payment/onepaycx'] = $store;
$cat = new TestCatalogController($c);

$html = $cat->index();
$view = $c->load->views['extension/onepaycx/payment/onepaycx'];
check('index() renders the button view with a raw (non-&amp;) confirm URL', $html === '<view:extension/onepaycx/payment/onepaycx>' && $view['confirm'] === 'https://shop.example/index.php?route=extension/onepaycx/payment/onepaycx.confirm&language=en-gb');

function confirm(TestCatalogController $cat, Registry $c): array {
	$c->response->reset();
	$cat->confirm();

	return json_decode($c->response->getOutput(), true) ?: [];
}

$json = confirm($cat, $c);
check('confirm() without an order in the session -> error', isset($json['error']) && $json['error'] === 'No order found in the session!');

$c->session->data['order_id'] = 7;
$json = confirm($cat, $c);
check('confirm() without the method selected -> error', isset($json['error']) && $json['error'] === 'Payment method is incorrect!');

$c->session->data['payment_method'] = ['code' => 'cod.cod', 'name' => 'COD'];
$json = confirm($cat, $c);
check('confirm() with another method selected -> error', isset($json['error']));

$c->session->data['payment_method'] = ['code' => 'onepaycx.onepaycx', 'name' => 'Pay'];
$c->config->set('payment_onepaycx_api_key', '');
$json = confirm($cat, $c);
check('confirm() unconfigured -> error, no API call', isset($json['error']) && str_contains($json['error'], 'not configured') && FakeApi::$lastBody === null);
$c->config->set('payment_onepaycx_api_key', 'pk_test.secret');

$json = confirm($cat, $c);
check('confirm() -> redirect to https://1pay.cx{checkoutUrl}', ($json['redirect'] ?? '') === 'https://1pay.cx/pay/k3Xm9Qp2' && !isset($json['error']));
$b = FakeApi::$lastBody;
check('amount is a two-decimal JSON number, currency upper-case ISO', is_float($b['amount']) && $b['amount'] === 12.5 && $b['currency'] === 'USD');
check('reference = order id, email forwarded, productName decoded', $b['reference'] === '7' && $b['email'] === 'buyer@example.com' && $b['productName'] === 'Demo & Co order #7');
check('notifyUrl -> callback route, redirectUrl -> return route with order id + key, raw &', $b['notifyUrl'] === 'https://shop.example/index.php?route=extension/onepaycx/payment/onepaycx.callback&language=en-gb'
	&& (bool)preg_match('#^https://shop\.example/index\.php\?route=extension/onepaycx/payment/onepaycx\.return&language=en-gb&order_id=7&key=[0-9a-f]{32}$#', $b['redirectUrl']));
$row = $store->getPayment('k3Xm9Qp2');
check('payment stored against the order with its return key', $row !== null && $row['order_id'] === 7 && $row['amount'] === '12.50' && $row['currency'] === 'USD' && str_contains($b['redirectUrl'], 'key=' . $row['return_key']));
check('no order history when the pending status is "leave as missing"', $orders->history === []);
check('confirm() carries the request only to the 1pay.cx hosts', str_starts_with($json['redirect'], 'https://1pay.cx/') && str_starts_with(Api::API_BASE, 'https://api.1pay.cx'));

// 4.0.0/4.0.1 style string payment_method
$c->session->data['payment_method'] = 'onepaycx';
$json = confirm($cat, $c);
check('confirm() accepts the 4.0.0/4.0.1 string payment_method', isset($json['redirect']));
$c->session->data['payment_method'] = ['code' => 'onepaycx.onepaycx', 'name' => 'Pay'];

// Pending status configured -> history added once
$c->config->set('payment_onepaycx_pending_status_id', 1);
$json = confirm($cat, $c);
check('pending status configured -> order moved to it on redirect', count($orders->history) === 1 && $orders->history[0]['order_status_id'] === 1 && str_contains($orders->history[0]['comment'], 'k3Xm9Qp2'));
$json = confirm($cat, $c);
check('...but not again once the order has a status', count($orders->history) === 1);
$c->config->set('payment_onepaycx_pending_status_id', 0);

// Direct mode
$c->config->set('payment_onepaycx_direct', 1);
$json = confirm($cat, $c);
check('direct mode appends ?direct=1 when an email is present', ($json['redirect'] ?? '') === 'https://1pay.cx/pay/k3Xm9Qp2?direct=1');
$c->session->data['order_id'] = 8;
$json = confirm($cat, $c);
check('direct mode without a valid email falls back to the payment page (EUR converted)', ($json['redirect'] ?? '') === 'https://1pay.cx/pay/k3Xm9Qp2' && !isset(FakeApi::$lastBody['email']) && FakeApi::$lastBody['amount'] === 9.0 && FakeApi::$lastBody['currency'] === 'EUR');
$c->config->set('payment_onepaycx_direct', 0);
$c->session->data['order_id'] = 7;

// Unsupported currency
$c->session->data['order_id'] = 9;
$json = confirm($cat, $c);
check('unknown currency -> error before any API call', isset($json['error']) && str_contains($json['error'], 'cannot be paid'));
$c->session->data['order_id'] = 7;

// API failure
FakeApi::$createOk = false;
$json = confirm($cat, $c);
check('API failure -> error carrying the upstream code', isset($json['error']) && str_contains($json['error'], 'invalid') && !isset($json['redirect']));
FakeApi::$createOk = true;

// Foreign checkoutUrl
FakeApi::$payment = ['id' => 'evil1', 'checkoutUrl' => 'https://evil.example/pay'];
$json = confirm($cat, $c);
check('foreign checkoutUrl is never followed', isset($json['error']) && !isset($json['redirect']) && $store->getPayment('evil1') === null);
FakeApi::$payment = ['id' => 'bad/id', 'checkoutUrl' => '/pay/x'];
$json = confirm($cat, $c);
check('malformed payment id is rejected', isset($json['error']) && !isset($json['redirect']));
FakeApi::$payment = ['id' => 'k3Xm9Qp2', 'checkoutUrl' => '/pay/k3Xm9Qp2'];

/* ------------------------------------------------------------------ */
echo "6. Catalog controller: callback\n";
/* ------------------------------------------------------------------ */

function webhook(TestCatalogController $cat, Registry $c, string $body, string $sig, string $sigV2 = ''): Response {
	$c->response->reset();
	$c->request->server = ['SERVER_PROTOCOL' => 'HTTP/1.1', 'HTTP_X_1PAY_SIGNATURE' => $sig];
	if ($sigV2 !== '') {
		$c->request->server['HTTP_X_1PAY_SIGNATURE_V2'] = $sigV2;
	}
	$cat->body = $body;
	$cat->callback();

	return $c->response;
}

// Re-anchor the fixture: the last successful confirm() above was for order 8, so
// create k3Xm9Qp2 for order 7 again (the notify fixture $raw references order 7).
$c->session->data['order_id'] = 7;
confirm($cat, $c);
$orders->history = [];
// The callback re-fetches GET /v1/payments/{id}; by default it confirms "paid".
FakeApi::$status = 'paid';
FakeApi::$amountById = ['pA' => 1.0, 'pB' => 500.0];
$orders->orders[7]['order_status_id'] = 0;
check('fixture: k3Xm9Qp2 belongs to order 7 and is pending', $store->getPayment('k3Xm9Qp2')['order_id'] === 7 && $store->getPayment('k3Xm9Qp2')['status'] === 'pending');

$r = webhook($cat, $c, $raw, 'deadbeef');
check('bad signature -> 401', $r->status() === 401 && $r->getOutput() === 'bad signature');
$r = webhook($cat, $c, $raw, '');
check('missing signature -> 400', $r->status() === 400);
$r = webhook($cat, $c, '', $sig);
check('empty body -> 400', $r->status() === 400);
$r = webhook($cat, $c, $raw . ' ', $sig);
check('signature must match the RAW body -> 401', $r->status() === 401);
$notJson = '{"event":';
$r = webhook($cat, $c, $notJson, hash_hmac('sha256', $notJson, $secret));
check('signed but malformed JSON -> 400', $r->status() === 400);

$other = json_encode(['event' => 'card.issued', 'data' => [], 'at' => 'x']);
$r = webhook($cat, $c, $other, hash_hmac('sha256', $other, $secret));
check('other events acknowledged and ignored -> 200', $r->status() === 200 && $r->getOutput() === 'ignored' && $orders->history === []);

$unknown = json_encode(['event' => 'payment.paid', 'data' => ['paymentId' => 'zzz', 'reference' => '7', 'amount' => 12.5, 'status' => 'paid', 'currency' => 'USD'], 'at' => 'x']);
$r = webhook($cat, $c, $unknown, hash_hmac('sha256', $unknown, $secret));
check('paid event for a payment this store did not create -> 404', $r->status() === 404 && $orders->history === []);

$wrongOrder = json_encode(['event' => 'payment.paid', 'data' => ['paymentId' => 'k3Xm9Qp2', 'reference' => '8', 'amount' => 12.5, 'status' => 'paid', 'currency' => 'USD'], 'at' => 'x']);
$r = webhook($cat, $c, $wrongOrder, hash_hmac('sha256', $wrongOrder, $secret));
check('paid event whose reference does not match the payment\'s order -> 404', $r->status() === 404 && $orders->history === []);

$badRef = json_encode(['event' => 'payment.paid', 'data' => ['paymentId' => 'k3Xm9Qp2', 'reference' => 'abc', 'status' => 'paid', 'currency' => 'USD'], 'at' => 'x']);
$r = webhook($cat, $c, $badRef, hash_hmac('sha256', $badRef, $secret));
check('non-numeric reference -> 400', $r->status() === 400);

// addHistory failure releases the claim so the sender retries
$orders->throwOnHistory = true;
$r = webhook($cat, $c, $raw, $sig);
check('order update failure -> 500 and the paid claim is released', $r->status() === 500 && $store->getPayment('k3Xm9Qp2')['status'] === 'pending');
$orders->throwOnHistory = false;

$r = webhook($cat, $c, $raw, $sig);
check('valid paid event -> 200 ok and addHistory called once with the paid status', $r->status() === 200 && $r->getOutput() === 'ok' && count($orders->history) === 1 && $orders->history[0]['order_id'] === 7 && $orders->history[0]['order_status_id'] === 2);
check('history comment names the payment, amount, currency and source', str_contains($orders->history[0]['comment'], 'k3Xm9Qp2') && str_contains($orders->history[0]['comment'], '12.50 USD') && str_contains($orders->history[0]['comment'], 'webhook') && !str_contains($orders->history[0]['comment'], 'WARNING'));
check('payment row marked paid', $store->getPayment('k3Xm9Qp2')['status'] === 'paid');

$r = webhook($cat, $c, $raw, $sig);
check('redelivery is idempotent -> 200 already paid, no second addHistory', $r->status() === 200 && $r->getOutput() === 'already paid' && count($orders->history) === 1);

// The amount the notify reports is below what the link was created for:
// refused outright, nothing claimed, no history.
$store->addPayment('p2', 7, '12.50', 'USD', str_repeat('c', 32));
$store->rows['p2']['seq'] = 99;
$orders->orders[7]['order_status_id'] = 0;
$mismatch = json_encode(['event' => 'payment.paid', 'data' => ['paymentId' => 'p2', 'reference' => '7', 'amount' => 11, 'status' => 'paid', 'currency' => 'USD'], 'at' => 'x']);
$r = webhook($cat, $c, $mismatch, hash_hmac('sha256', $mismatch, $secret));
check('reported-amount below the quote -> 400, nothing claimed, no history', $r->status() === 400 && $r->getOutput() === 'amount mismatch' && count($orders->history) === 1 && $store->getPayment('p2')['status'] === 'pending');
$within = json_encode(['event' => 'payment.paid', 'data' => ['paymentId' => 'p2', 'reference' => '7', 'amount' => 12.49, 'status' => 'paid', 'currency' => 'USD'], 'at' => 'x']);
FakeApi::$amountById['p2'] = 5.0;
$r = webhook($cat, $c, $within, hash_hmac('sha256', $within, $secret));
check('notify within 0.01 but the API re-fetch reports less -> 400, nothing claimed', $r->status() === 400 && $store->getPayment('p2')['status'] === 'pending' && count($orders->history) === 1);
unset(FakeApi::$amountById['p2']);
$noCur = json_encode(['event' => 'payment.paid', 'data' => ['paymentId' => 'p2', 'reference' => '7', 'amount' => 12.5, 'status' => 'paid'], 'at' => 'x']);
$r = webhook($cat, $c, $noCur, hash_hmac('sha256', $noCur, $secret));
check('notify without a currency -> 400', $r->status() === 400 && $store->getPayment('p2')['status'] === 'pending');
$eur = json_encode(['event' => 'payment.paid', 'data' => ['paymentId' => 'p2', 'reference' => '7', 'amount' => 12.5, 'status' => 'paid', 'currency' => 'EUR'], 'at' => 'x']);
$r = webhook($cat, $c, $eur, hash_hmac('sha256', $eur, $secret));
check('notify in another currency -> 400', $r->status() === 400 && $store->getPayment('p2')['status'] === 'pending');
$p2 = json_encode(['event' => 'payment.paid', 'data' => ['paymentId' => 'p2', 'reference' => '7', 'amount' => 12.5, 'status' => 'paid', 'currency' => 'USD'], 'at' => 'x']);
FakeApi::$statusById['p2'] = 'pending';
$r = webhook($cat, $c, $p2, hash_hmac('sha256', $p2, $secret));
check('signed notify but the API says not paid -> 409, nothing claimed', $r->status() === 409 && $store->getPayment('p2')['status'] === 'pending' && count($orders->history) === 1);
unset(FakeApi::$statusById['p2']);
FakeApi::$getOk = false;
$r = webhook($cat, $c, $p2, hash_hmac('sha256', $p2, $secret));
check('API re-fetch unavailable -> 503, nothing claimed', $r->status() === 503 && $store->getPayment('p2')['status'] === 'pending');
FakeApi::$getOk = true;
FakeApi::$currency = 'EUR';
$r = webhook($cat, $c, $p2, hash_hmac('sha256', $p2, $secret));
check('API re-fetch in another currency -> 400', $r->status() === 400 && $store->getPayment('p2')['status'] === 'pending');
FakeApi::$currency = 'USD';

// Test payments vs a live key.
$c->config->set('payment_onepaycx_api_key', 'pk_live1.secret');
$testPay = json_encode(['event' => 'payment.paid', 'data' => ['paymentId' => 'p2', 'reference' => '7', 'amount' => 12.5, 'status' => 'paid', 'currency' => 'USD', 'livemode' => false], 'at' => 'x']);
$r = webhook($cat, $c, $testPay, hash_hmac('sha256', $testPay, $secret));
check('livemode:false notify with a live key -> 400 test payment', $r->status() === 400 && $r->getOutput() === 'test payment' && $store->getPayment('p2')['status'] === 'pending');
FakeApi::$livemode = false;
$r = webhook($cat, $c, $p2, hash_hmac('sha256', $p2, $secret));
check('live key, API re-fetch says livemode:false -> 400', $r->status() === 400 && $store->getPayment('p2')['status'] === 'pending');
$c->config->set('payment_onepaycx_api_key', 'pk_test_abc.secret');
$r = webhook($cat, $c, $testPay, hash_hmac('sha256', $testPay, $secret));
check('livemode:false notify with a pk_test_ key -> accepted', $r->status() === 200 && $store->getPayment('p2')['status'] === 'paid');
FakeApi::$livemode = true;
$c->config->set('payment_onepaycx_api_key', 'pk_test.secret');

// Timestamped V2 signature.
$store->addPayment('pV', 7, '12.50', 'USD', str_repeat('v', 32));
$store->rows['pV']['seq'] = 98;
$pV = json_encode(['event' => 'payment.paid', 'data' => ['paymentId' => 'pV', 'reference' => '7', 'amount' => 12.5, 'status' => 'paid', 'currency' => 'USD'], 'at' => 'x']);
$now = time();
$v2 = fn (string $body, int $t, string $key = 'whsec_test') => 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $body, $key);
check('verifySignatureV2 accepts a fresh signature and rejects stale/future/tampered/wrong-secret ones',
	Api::verifySignatureV2($pV, $v2($pV, $now), $secret, $now)
	&& !Api::verifySignatureV2($pV, $v2($pV, $now - 301), $secret, $now)
	&& !Api::verifySignatureV2($pV, $v2($pV, $now + 301), $secret, $now)
	&& !Api::verifySignatureV2($pV . ' ', $v2($pV, $now), $secret, $now)
	&& !Api::verifySignatureV2($pV, $v2($pV, $now, 'other'), $secret, $now)
	&& !Api::verifySignatureV2($pV, 'v1=' . hash_hmac('sha256', $pV, $secret), $secret, $now));
$r = webhook($cat, $c, $pV, hash_hmac('sha256', $pV, $secret), $v2($pV, $now - 400));
check('stale V2 signature -> 401 even with a valid legacy header (V2 decides alone)', $r->status() === 401 && $store->getPayment('pV')['status'] === 'pending');
$r = webhook($cat, $c, $pV, '', 't=' . $now . ',v1=' . str_repeat('0', 64));
check('bad V2 signature -> 401', $r->status() === 401);
$r = webhook($cat, $c, $pV, '', $v2($pV, $now));
check('fresh V2 signature alone -> 200 ok', $r->status() === 200 && $r->getOutput() === 'already paid' && $store->getPayment('pV')['status'] === 'paid');

// Edited order: link A created for a $1.00 cart, buyer goes back, grows the cart to $500,
// re-confirms (OpenCart edits order 20 in place; a second link B is created) and pays A.
$orders->orders[20] = ['order_id' => 20, 'order_status_id' => 0, 'total' => 500.0, 'currency_code' => 'USD', 'currency_value' => 1.0, 'email' => 'buyer@example.com'];
$store->addPayment('pA', 20, '1.00', 'USD', str_repeat('e', 32));
$store->rows['pA']['seq'] = 200;
$store->addPayment('pB', 20, '500.00', 'USD', str_repeat('f', 32));
$store->rows['pB']['seq'] = 201;
$before = count($orders->history);
$pA = json_encode(['event' => 'payment.paid', 'data' => ['paymentId' => 'pA', 'reference' => '20', 'amount' => 1, 'status' => 'paid', 'currency' => 'USD'], 'at' => 'x']);
$r = webhook($cat, $c, $pA, hash_hmac('sha256', $pA, $secret));
check('paying the stale $1.00 link for a now-$500.00 order -> 200, row claimed, order NOT moved to the paid status', $r->status() === 200 && $r->getOutput() === 'ok' && $store->getPayment('pA')['status'] === 'paid'
	&& $orders->orders[20]['order_status_id'] !== 2 && count($orders->history) === $before + 1);
check('...the note names the amount paid and the current order total', end($orders->history)['order_status_id'] === 1 && str_contains(end($orders->history)['comment'], 'WARNING') && str_contains(end($orders->history)['comment'], '1.00 USD') && str_contains(end($orders->history)['comment'], '500.00 USD'));
$r = webhook($cat, $c, $pA, hash_hmac('sha256', $pA, $secret));
check('...and a repeat of that notify is still idempotent', $r->getOutput() === 'already paid' && count($orders->history) === $before + 1);
$pB = json_encode(['event' => 'payment.paid', 'data' => ['paymentId' => 'pB', 'reference' => '20', 'amount' => 500, 'status' => 'paid', 'currency' => 'USD'], 'at' => 'x']);
$r = webhook($cat, $c, $pB, hash_hmac('sha256', $pB, $secret));
check('the matching $500.00 link moves the order to the paid status', $r->getOutput() === 'ok' && $orders->orders[20]['order_status_id'] === 2 && !str_contains(end($orders->history)['comment'], 'WARNING'));

// Currency mismatch (order re-confirmed in another currency) is treated the same way.
$orders->orders[21] = ['order_id' => 21, 'order_status_id' => 1, 'total' => 12.5, 'currency_code' => 'EUR', 'currency_value' => 0.9, 'email' => ''];
$store->addPayment('pC', 21, '12.50', 'USD', str_repeat('g', 32));
$store->rows['pC']['seq'] = 202;
$pC = json_encode(['event' => 'payment.paid', 'data' => ['paymentId' => 'pC', 'reference' => '21', 'amount' => 12.5, 'status' => 'paid', 'currency' => 'USD'], 'at' => 'x']);
$r = webhook($cat, $c, $pC, hash_hmac('sha256', $pC, $secret));
check('currency mismatch -> WARNING note on the current status, not the paid status', $r->status() === 200 && $orders->orders[21]['order_status_id'] === 1 && str_contains(end($orders->history)['comment'], '11.25 EUR'));

// Late money on an older attempt must never regress a fulfilled order:
// A expires, B pays (Processing), merchant completes the order, then A is paid late.
$orders->orders[22] = ['order_id' => 22, 'order_status_id' => 0, 'total' => 12.5, 'currency_code' => 'USD', 'currency_value' => 1.0, 'email' => ''];
$store->addPayment('pOld', 22, '12.50', 'USD', str_repeat('h', 32));
$store->rows['pOld']['seq'] = 203;
$store->setStatus('pOld', 'expired');
$store->addPayment('pNew', 22, '12.50', 'USD', str_repeat('i', 32));
$store->rows['pNew']['seq'] = 204;
$pNew = json_encode(['event' => 'payment.paid', 'data' => ['paymentId' => 'pNew', 'reference' => '22', 'amount' => 12.5, 'status' => 'paid', 'currency' => 'USD'], 'at' => 'x']);
webhook($cat, $c, $pNew, hash_hmac('sha256', $pNew, $secret));
check('fixture: order 22 paid by the newer attempt', $orders->orders[22]['order_status_id'] === 2);
$orders->orders[22]['order_status_id'] = 5; // merchant: Complete
$before = count($orders->history);
$pOld = json_encode(['event' => 'payment.paid', 'data' => ['paymentId' => 'pOld', 'reference' => '22', 'amount' => 12.5, 'status' => 'paid', 'currency' => 'USD'], 'at' => 'x']);
$r = webhook($cat, $c, $pOld, hash_hmac('sha256', $pOld, $secret));
check('late payment of the expired attempt -> 200 already paid, order stays Complete, double-payment note added', $r->status() === 200 && $r->getOutput() === 'already paid' && $orders->orders[22]['order_status_id'] === 5
	&& count($orders->history) === $before + 1 && end($orders->history)['order_status_id'] === 5 && str_contains(end($orders->history)['comment'], 'pOld') && str_contains(end($orders->history)['comment'], 'double payment') && $store->getPayment('pOld')['status'] === 'paid');
$orders->orders[22]['order_status_id'] = 3; // a processing-list status that is not the configured paid one
$store->addPayment('pOld2', 22, '12.50', 'USD', str_repeat('j', 32));
$store->rows['pOld2']['seq'] = 205;
$pOld2 = json_encode(['event' => 'payment.paid', 'data' => ['paymentId' => 'pOld2', 'reference' => '22', 'amount' => 12.5, 'status' => 'paid', 'currency' => 'USD'], 'at' => 'x']);
$r = webhook($cat, $c, $pOld2, hash_hmac('sha256', $pOld2, $secret));
check('an order in any config_processing_status counts as paid too', $r->getOutput() === 'already paid' && $orders->orders[22]['order_status_id'] === 3 && end($orders->history)['order_status_id'] === 3);
$c->config->set('config_processing_status', json_encode([2, 3]));
$store->addPayment('pOld3', 22, '12.50', 'USD', str_repeat('k', 32));
$store->rows['pOld3']['seq'] = 206;
$pOld3 = json_encode(['event' => 'payment.paid', 'data' => ['paymentId' => 'pOld3', 'reference' => '22', 'amount' => 12.5, 'status' => 'paid', 'currency' => 'USD'], 'at' => 'x']);
$r = webhook($cat, $c, $pOld3, hash_hmac('sha256', $pOld3, $secret));
check('...also when the status list arrives as a JSON string', $r->getOutput() === 'already paid' && $orders->orders[22]['order_status_id'] === 3);
$c->config->set('config_processing_status', [2, 3]);

$c->config->set('payment_onepaycx_status', 0);
$r = webhook($cat, $c, $raw, $sig);
check('disabled extension -> 503', $r->status() === 503);
$c->config->set('payment_onepaycx_status', 1);

$c->config->set('payment_onepaycx_webhook_secret', '');
$r = webhook($cat, $c, $raw, $sig);
check('no stored secret -> 400 (never accepts unsigned)', $r->status() === 400);
$c->config->set('payment_onepaycx_webhook_secret', 'whsec_test');

/* ------------------------------------------------------------------ */
echo "7. Catalog controller: return page\n";
/* ------------------------------------------------------------------ */

function returnPage(TestCatalogController $cat, Registry $c, array $get): ?string {
	$c->response->reset();
	$c->request->get = $get;
	try {
		$cat->return();
	} catch (StubRedirect $e) {
		return $e->url;
	}

	return null;
}

// Fresh pending payment for order 10
$orders->orders[10] = ['order_id' => 10, 'order_status_id' => 0, 'total' => 12.5, 'currency_code' => 'USD', 'currency_value' => 1.0, 'email' => 'buyer@example.com'];
$store->addPayment('p10', 10, '12.50', 'USD', str_repeat('b', 32));
$store->rows['p10']['seq'] = 100;

$url = returnPage($cat, $c, ['order_id' => 10, 'key' => 'wrong']);
check('wrong key -> back to the cart', $url === 'https://shop.example/index.php?route=checkout/cart&language=en-gb');
$url = returnPage($cat, $c, ['order_id' => 999, 'key' => str_repeat('b', 32)]);
check('unknown order -> back to the cart', $url !== null && str_contains($url, 'checkout/cart'));
$url = returnPage($cat, $c, []);
check('no params -> back to the cart', $url !== null && str_contains($url, 'checkout/cart'));

FakeApi::$status = 'pending';
$url = returnPage($cat, $c, ['order_id' => 10, 'key' => str_repeat('b', 32)]);
$view = $c->load->views['extension/onepaycx/payment/onepaycx_return'] ?? null;
check('pending payment renders the waiting page that refreshes', $url === null && $view !== null && $view['state'] === 'pending' && $view['refresh_seconds'] === 5 && str_contains($view['refresh'], 'onepaycx.return&language=en-gb&order_id=10&key=' . str_repeat('b', 32)));
check('waiting page is sent with no-store', in_array('Cache-Control: no-store', $c->response->getHeaders(), true));

FakeApi::$status = 'expired';
$url = returnPage($cat, $c, ['order_id' => 10, 'key' => str_repeat('b', 32)]);
$view = $c->load->views['extension/onepaycx/payment/onepaycx_return'];
check('expired payment renders the retry state and records it', $url === null && $view['state'] === 'expired' && $view['refresh_seconds'] === 0 && $store->getPayment('p10')['status'] === 'expired' && str_contains($view['retry'], 'checkout/checkout'));

FakeApi::$status = 'failed';
$url = returnPage($cat, $c, ['order_id' => 10, 'key' => str_repeat('b', 32)]);
check('failed payment renders the retry state', $url === null && $c->load->views['extension/onepaycx/payment/onepaycx_return']['state'] === 'failed');

FakeApi::$status = 'paid';
$historyBefore = count($orders->history);
$url = returnPage($cat, $c, ['order_id' => 10, 'key' => str_repeat('b', 32)]);
check('paid on poll -> order confirmed once and buyer sent to checkout/success', $url === 'https://shop.example/index.php?route=checkout/success&language=en-gb' && count($orders->history) === $historyBefore + 1 && end($orders->history)['order_id'] === 10 && str_contains(end($orders->history)['comment'], 'return'));
$url = returnPage($cat, $c, ['order_id' => 10, 'key' => str_repeat('b', 32)]);
check('returning again after payment -> success page without touching the order', $url !== null && str_contains($url, 'checkout/success') && count($orders->history) === $historyBefore + 1);

// Webhook after the return page already confirmed -> already paid
$p10 = json_encode(['event' => 'payment.paid', 'data' => ['paymentId' => 'p10', 'reference' => '10', 'amount' => 12.5, 'status' => 'paid', 'currency' => 'USD'], 'at' => 'x']);
$r = webhook($cat, $c, $p10, hash_hmac('sha256', $p10, $secret));
check('late webhook after the return page confirmed -> 200 already paid, no extra history', $r->status() === 200 && $r->getOutput() === 'already paid' && count($orders->history) === $historyBefore + 1);

// Unconfigured store on return
$c->config->set('payment_onepaycx_api_key', '');
$store->addPayment('p11', 11, '1.00', 'USD', str_repeat('d', 32));
$store->rows['p11']['seq'] = 101;
$url = returnPage($cat, $c, ['order_id' => 11, 'key' => str_repeat('d', 32)]);
check('return with no API key renders the error state', $url === null && $c->load->views['extension/onepaycx/payment/onepaycx_return']['state'] === 'error');
$c->config->set('payment_onepaycx_api_key', 'pk_test.secret');

/* ------------------------------------------------------------------ */
echo "8. Catalog controller: reconcile (cron catch-up)\n";
/* ------------------------------------------------------------------ */

function reconcile(TestCatalogController $cat, Registry $c, array $get): Response {
	$c->response->reset();
	$c->request->get = $get;
	$c->request->server = ['SERVER_PROTOCOL' => 'HTTP/1.1'];
	$cat->reconcile();

	return $c->response;
}

$token = Api::reconcileToken('whsec_test');
$r = reconcile($cat, $c, []);
check('reconcile without a token -> 401', $r->status() === 401);
$r = reconcile($cat, $c, ['token' => str_repeat('0', 64)]);
check('reconcile with a wrong token -> 401, no API call', $r->status() === 401 && !array_filter(FakeApi::$calls, fn ($x) => $x === 'get:p30'));
$c->config->set('payment_onepaycx_webhook_secret', '');
$r = reconcile($cat, $c, ['token' => $token]);
check('reconcile on an unconnected store -> 503', $r->status() === 503);
$c->config->set('payment_onepaycx_webhook_secret', 'whsec_test');

// Order 30: notify never arrived (store was down); order 31: still pending upstream;
// order 32: expired upstream. The already-paid rows above must not be polled again.
$orders->orders[30] = ['order_id' => 30, 'order_status_id' => 0, 'total' => 12.5, 'currency_code' => 'USD', 'currency_value' => 1.0, 'email' => ''];
$orders->orders[31] = ['order_id' => 31, 'order_status_id' => 0, 'total' => 12.5, 'currency_code' => 'USD', 'currency_value' => 1.0, 'email' => ''];
$orders->orders[32] = ['order_id' => 32, 'order_status_id' => 0, 'total' => 12.5, 'currency_code' => 'USD', 'currency_value' => 1.0, 'email' => ''];
$store->addPayment('p30', 30, '12.50', 'USD', str_repeat('l', 32));
$store->addPayment('p31', 31, '12.50', 'USD', str_repeat('m', 32));
$store->addPayment('p32', 32, '12.50', 'USD', str_repeat('n', 32));
FakeApi::$statusById = ['p30' => 'paid', 'p31' => 'pending', 'p32' => 'expired', 'p10' => 'expired', 'p11' => 'expired'];
FakeApi::$status = 'pending';
FakeApi::$calls = [];
$before = count($orders->history);
$r = reconcile($cat, $c, ['token' => $token]);
$polled = array_values(array_filter(FakeApi::$calls, fn ($x) => str_starts_with($x, 'get:')));
check('reconcile polls only the unsettled rows', $r->status() === 200 && !in_array('get:k3Xm9Qp2', $polled, true) && !in_array('get:pA', $polled, true) && in_array('get:p30', $polled, true) && in_array('get:p31', $polled, true) && in_array('get:p32', $polled, true));
check('reconcile completes the paid one through the same path (source "reconcile")', $orders->orders[30]['order_status_id'] === 2 && $store->getPayment('p30')['status'] === 'paid' && count($orders->history) === $before + 1 && str_contains(end($orders->history)['comment'], 'reconcile'));
check('reconcile records expired, leaves pending alone, and reports counts', $store->getPayment('p32')['status'] === 'expired' && $store->getPayment('p31')['status'] === 'pending' && $orders->orders[31]['order_status_id'] === 0
	&& (bool)preg_match('/^checked=\d+ paid=1 pending=1 expired=\d+ failed=0 errors=0$/', $r->getOutput()));
FakeApi::$calls = [];
$r = reconcile($cat, $c, ['token' => $token]);
check('a second run does not touch the completed order again', $r->status() === 200 && count($orders->history) === $before + 1 && !in_array('get:p30', FakeApi::$calls, true) && in_array('get:p32', FakeApi::$calls, true));
FakeApi::$statusById = [];

/* ------------------------------------------------------------------ */
echo "9. Storefront language fallback\n";
/* ------------------------------------------------------------------ */

$d = registry('catalog', $upload);
$d->load->code = 'de-de';
$d->config->set('config_language', 'de-de');
$d->config->set('payment_onepaycx_status', 1);
$d->config->set('payment_onepaycx_api_key', 'pk_test.secret');
$d->config->set('payment_onepaycx_webhook_secret', 'whsec_test');
$d->config->set('payment_onepaycx_order_status_id', 2);
$dOrders = new FakeOrderModel();
$dOrders->orders[7] = $orders->orders[7];
$d->load->models['checkout/order'] = $dOrders;
$d->load->models['extension/onepaycx/payment/onepaycx'] = new FakeOnepaycxModel();
$d->load->translations['de-de']['extension/onepaycx/payment/onepaycx'] = ['button_confirm' => 'Weiter zur Zahlung'];
$d->session->data['order_id'] = 7;
$d->session->data['payment_method'] = ['code' => 'onepaycx.onepaycx', 'name' => 'Pay'];
$dCat = new TestCatalogController($d);
$dCat->index();
confirm($dCat, $d);
check('de-de storefront: en-gb strings resolve, translation overrides, productName is not a raw key', $d->language->get('button_confirm') === 'Weiter zur Zahlung' && $d->language->get('text_product') === '%s order #%s'
	&& FakeApi::$lastBody['productName'] === 'Demo & Co order #7' && $d->load->languageLoads[0] === 'extension/onepaycx/payment/onepaycx@en-gb' && $d->load->languageLoads[1] === 'extension/onepaycx/payment/onepaycx@de-de');
check('de-de storefront: no extension key is missing', array_diff($d->language->missing, ['text_home', 'button_save', 'button_back']) === []);

/* ------------------------------------------------------------------ */
echo "10. Secrets never leave the server\n";
/* ------------------------------------------------------------------ */

$leak = false;
foreach ([$a, $c, $de, $d] as $reg) {
	foreach ($reg->load->views as $data) {
		if (contains_deep($data, 'pk_test.secret') || contains_deep($data, 'whsec_test')) {
			$leak = true;
		}
	}
	if (contains_deep($reg->log->lines, 'pk_test.secret') || contains_deep($reg->log->lines, 'whsec_test')) {
		$leak = true;
	}
	if (contains_deep($reg->response->getOutput(), 'pk_test.secret') || contains_deep($reg->response->getOutput(), 'whsec_test')) {
		$leak = true;
	}
}
check('no view data, log line or response body contains the API key or webhook secret', !$leak);

/* ------------------------------------------------------------------ */
echo "11. Language parity\n";
/* ------------------------------------------------------------------ */

$core = ['text_home', 'button_save', 'button_back'];
$missing = array_values(array_unique(array_diff(array_merge($a->language->missing, $c->language->missing, $de->language->missing, $d->language->missing), $core)));
check('every language key the controllers use is defined (core keys aside)', $missing === []);
if ($missing) {
	echo '       missing: ' . implode(', ', $missing) . "\n";
}

echo "\n" . ($failures === 0 ? 'PASS' : "FAIL ($failures)") . "\n";
exit($failures === 0 ? 0 : 1);
