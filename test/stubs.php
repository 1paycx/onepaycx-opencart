<?php
/**
 * Minimal stand-ins for the OpenCart 4.0.2.x engine/library classes the
 * 1pay.cx extension touches, mirroring the real signatures (Controller/Model
 * __get(string): object, Registry get/set/has, Loader, Request, Response,
 * Session, Config, Url, Language, DB result objects, Cart, Currency, User,
 * Document). Only the members the extension actually uses are implemented.
 *
 * The point is to load the extension against *some* parent so a compile-time
 * mismatch (abstract method not implemented, property visibility narrowed,
 * signature incompatibility) fails here instead of on a live store.
 *
 * @license MIT
 */

namespace Opencart\System\Engine {
	class Registry {
		private array $data = [];

		public function __get(string $key): object|null {
			return $this->get($key);
		}

		public function __set(string $key, object $value): void {
			$this->set($key, $value);
		}

		public function get(string $key): object|null {
			return $this->data[$key] ?? null;
		}

		public function set(string $key, object $value): void {
			$this->data[$key] = $value;
		}

		public function has(string $key): bool {
			return isset($this->data[$key]);
		}

		public function unset(string $key): void {
			unset($this->data[$key]);
		}
	}

	class Controller {
		protected $registry;

		public function __construct(Registry $registry) {
			$this->registry = $registry;
		}

		public function __get(string $key): object {
			if ($this->registry->has($key)) {
				return $this->registry->get($key);
			}
			throw new \Exception('Error: Could not call registry key ' . $key . '!');
		}

		public function __set(string $key, object $value): void {
			$this->registry->set($key, $value);
		}
	}

	class Model {
		protected $registry;

		public function __construct(Registry $registry) {
			$this->registry = $registry;
		}

		public function __get(string $key): object {
			if ($this->registry->has($key)) {
				return $this->registry->get($key);
			}
			throw new \Exception('Error: Could not call registry key ' . $key . '!');
		}

		public function __set(string $key, object $value): void {
			$this->registry->set($key, $value);
		}
	}

	/**
	 * Resolves routes the way the real loader does for an installed extension:
	 * language files are the real ones from upload/<app>/language/<code>/
	 * (only en-gb ships, and like the real Language::load() a missing file is
	 * silently skipped - there is no fallback), models are either harness
	 * fakes (core models) or the real extension model, views record their
	 * data and return a marker string.
	 */
	class Loader {
		private Registry $registry;
		private string $app;      // 'admin' | 'catalog'
		private string $upload;   // path to the extension's upload/ folder

		/** The store/admin language code (config_language) used when language() gets no explicit code. */
		public string $code = 'en-gb';

		/** @var array<string,array<string,array<string,string>>> simulated translation files: code => route => strings */
		public array $translations = [];

		/** @var string[] every (route, code) pair language() was asked for */
		public array $languageLoads = [];

		/** @var array<string,object> harness-provided models by route */
		public array $models = [];

		/** @var array<string,array> last data passed to each view route */
		public array $views = [];

		public function __construct(Registry $registry, string $app, string $upload) {
			$this->registry = $registry;
			$this->app = $app;
			$this->upload = rtrim($upload, '/');
		}

		public function controller(string $route, mixed ...$args): mixed {
			return '<controller:' . $route . '>';
		}

		public function model(string $route): void {
			$key = 'model_' . str_replace('/', '_', $route);
			if ($this->registry->has($key)) {
				return;
			}
			if (isset($this->models[$route])) {
				$this->registry->set($key, $this->models[$route]);

				return;
			}
			if (str_starts_with($route, 'extension/onepaycx/')) {
				$class = 'Opencart\\' . ucfirst($this->app) . '\\Model\\' . str_replace(['_', '/'], ['', '\\'], ucwords($route, '_/'));
				if (!class_exists($class)) {
					throw new \Exception('Error: Could not load model ' . $route . ' (' . $class . ')');
				}
				$this->registry->set($key, new $class($this->registry));

				return;
			}
			throw new \Exception('Error: Could not load model ' . $route . '!');
		}

		public function view(string $route, array $data = [], string $code = ''): string {
			$this->views[$route] = $data;

			return '<view:' . $route . '>';
		}

		public function language(string $route, string $prefix = '', string $code = ''): array {
			$code = $code !== '' ? $code : $this->code;
			$this->languageLoads[] = $route . '@' . $code;
			$language = $this->registry->get('language');
			$_ = $language->load($this->file($route, $code), $prefix);
			foreach ($this->translations[$code][$route] ?? [] as $k => $v) {
				$language->set($prefix . $k, $v);
				$_[$k] = $v;
			}

			return $_;
		}

		private function file(string $route, string $code): string {
			if (!str_starts_with($route, 'extension/onepaycx/')) {
				return '';
			}

			return $this->upload . '/' . $this->app . '/language/' . $code . '/' . substr($route, strlen('extension/onepaycx/')) . '.php';
		}
	}
}

namespace Opencart\System\Library {
	/** Thrown by Response::redirect() (the real one calls exit) so the harness can observe it. */
	class StubRedirect extends \Exception {
		public string $url;

		public function __construct(string $url) {
			parent::__construct('redirect: ' . $url);
			$this->url = $url;
		}
	}

	class Request {
		public array $get = [];
		public array $post = [];
		public array $cookie = [];
		public array $files = [];
		public array $server = [];
	}

	class Response {
		public array $headers = [];
		public string $output = '';

		public function addHeader(string $header): void {
			$this->headers[] = $header;
		}

		public function getHeaders(): array {
			return $this->headers;
		}

		public function redirect(string $url, int $status = 302): void {
			throw new StubRedirect($url);
		}

		public function setOutput(string $output): void {
			$this->output = $output;
		}

		public function getOutput(): string {
			return $this->output;
		}

		public function reset(): void {
			$this->headers = [];
			$this->output = '';
		}

		/** The numeric status from a raw "HTTP/1.1 401 Unauthorized" header, or 200. */
		public function status(): int {
			foreach ($this->headers as $h) {
				if (preg_match('/^HTTP\/[0-9.]+ ([0-9]{3}) /', $h, $m)) {
					return (int)$m[1];
				}
			}

			return 200;
		}
	}

	class Session {
		public array $data = [];
	}

	class Config {
		private array $data = [];

		public function get(string $key): mixed {
			return $this->data[$key] ?? null;
		}

		public function set(string $key, mixed $value): void {
			$this->data[$key] = $value;
		}

		public function has(string $key): bool {
			return isset($this->data[$key]);
		}

		public function all(): array {
			return $this->data;
		}
	}

	class Url {
		private string $url;

		public function __construct(string $url) {
			$this->url = $url;
		}

		public function link(string $route, string|array $args = '', bool $js = false): string {
			$url = $this->url . 'index.php?route=' . $route;
			if ($args) {
				$url .= '&' . (is_array($args) ? http_build_query($args) : trim($args, '&'));
			}

			return $js ? $url : str_replace('&', '&amp;', $url);
		}
	}

	class Language {
		private array $data = [];

		/** @var string[] keys requested that no language file defined (core keys are expected here) */
		public array $missing = [];

		public function get(string $key): string {
			if (!isset($this->data[$key])) {
				$this->missing[] = $key;
			}

			return $this->data[$key] ?? $key;
		}

		public function set(string $key, string $value): void {
			$this->data[$key] = $value;
		}

		public function load(string $file, string $prefix = ''): array {
			$_ = [];
			if ($file !== '' && is_file($file)) {
				require $file;
			}
			$this->data = array_merge($this->data, $_);

			return $_;
		}
	}

	class Document {
		public string $title = '';

		public function setTitle(string $title): void {
			$this->title = $title;
		}
	}

	class Log {
		public array $lines = [];

		public function write(mixed $message): void {
			$this->lines[] = is_scalar($message) ? (string)$message : print_r($message, true);
		}
	}

	class StubResult {
		public array $row = [];
		public array $rows = [];
		public int $num_rows = 0;

		public function __construct(array $rows) {
			$this->rows = $rows;
			$this->num_rows = count($rows);
			$this->row = $rows[0] ?? [];
		}
	}

	/**
	 * Records every statement; SELECTs answer from $rows, UPDATEs report $affected.
	 * (The harness drives the flow through an in-memory fake model; the real
	 * model is exercised once against this stub to check its SQL builds.)
	 */
	class DB {
		public array $queries = [];
		public array $rows = [];
		public int $affected = 0;

		public function query(string $sql): bool|object {
			$this->queries[] = $sql;
			if (str_starts_with(ltrim($sql), 'SELECT')) {
				return new StubResult($this->rows);
			}

			return true;
		}

		public function escape(string $value): string {
			return addslashes($value);
		}

		public function countAffected(): int {
			return $this->affected;
		}

		public function getLastId(): int {
			return 1;
		}
	}

	class User {
		public array $permissions = [];

		public function hasPermission(string $key, string $value): bool {
			return in_array($key . ':' . $value, $this->permissions, true) || in_array($key . ':*', $this->permissions, true);
		}
	}
}

namespace Opencart\System\Library\Cart {
	class Cart {
		public bool $subscription = false;

		public function hasSubscription(): bool {
			return $this->subscription;
		}
	}

	class Currency {
		/** @var array<string,array{value:float,decimal_place:int}> */
		public array $currencies = [
			'USD' => ['value' => 1.0, 'decimal_place' => 2],
			'EUR' => ['value' => 0.9, 'decimal_place' => 2],
		];

		public function format(float $number, string $currency, float $value = 0, bool $format = true): string {
			if (!isset($this->currencies[$currency])) {
				return '';
			}
			if (!$value) {
				$value = $this->currencies[$currency]['value'];
			}
			$amount = round($value ? $number * $value : $number, $this->currencies[$currency]['decimal_place']);

			return $format ? number_format($amount, 2) : (string)$amount;
		}

		public function has(string $currency): bool {
			return isset($this->currencies[$currency]);
		}
	}
}
