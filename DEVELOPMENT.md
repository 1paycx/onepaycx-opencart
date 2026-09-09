# Development notes

OpenCart 4 payment extension for 1pay.cx: "Pay by card or crypto" at checkout, buyer pays on
the hosted checkout, the order is confirmed by a signed webhook. Version 1.0.0, MIT licence.

```
upload/            the extension exactly as it is zipped (see "Layout")
  install.json
  admin/controller/payment/onepaycx.php     settings page: verifies the API key, stores the webhook secret
  admin/model/payment/onepaycx.php          creates the onepaycx_order table (kept on uninstall)
  admin/language/en-gb/payment/onepaycx.php
  admin/view/template/payment/onepaycx.twig
  catalog/controller/payment/onepaycx.php   index (button) / confirm / callback / return / reconcile
  catalog/model/payment/onepaycx.php        getMethods() + the payment<->order table
  catalog/language/en-gb/payment/onepaycx.php
  catalog/view/template/payment/onepaycx.twig          the button on the confirm step
  catalog/view/template/payment/onepaycx_return.twig   the waiting / retry page
  system/library/api.php                    HTTP client for api.1pay.cx (+ signature/amount helpers)
test/              stub-parent compile harness - run before every release
build.sh           lints, runs the harness, zips upload/ + LICENSE into onepaycx.ocmod.zip
LICENSE            MIT (ships at the archive root)
```

## Layout chosen (be precise when changing it)

Target: **OpenCart 4.0.x, verified against the 4.0.2.3 sources**. The 4.0.2 platform floor
is PHP 8.0; the extension code under `upload/` itself only uses PHP 7.4-level syntax (typed
properties, `?array`, arrow functions) - nothing newer. Only the test harness uses 8.0
features (`mixed`, `str_starts_with`).

- The archive is the **4.0.x `.ocmod.zip` extension package**: `install.json` at the root
  plus `admin/`, `catalog/`, `system/` folders. `admin/controller/marketplace/installer.php`
  takes the extension *code* from the file name (`onepaycx.ocmod.zip` -> `onepaycx`) and
  copies every entry to `extension/onepaycx/<entry>` (`install.json` and `LICENSE` included,
  which is why the MIT text can ship at the archive root). The archive must therefore be
  named exactly `onepaycx.ocmod.zip` and must not wrap the folders in a top-level directory.
- Classes follow the 4.0 extension namespaces the startup autoloader registers
  (`admin|catalog/controller/startup/extension.php`):
  `Opencart\Admin\Controller\Extension\Onepaycx\Payment\Onepaycx`,
  `Opencart\Admin\Model\Extension\Onepaycx\Payment\Onepaycx`,
  `Opencart\Catalog\Controller\Extension\Onepaycx\Payment\Onepaycx`,
  `Opencart\Catalog\Model\Extension\Onepaycx\Payment\Onepaycx`, and the client
  `Opencart\System\Library\Extension\Onepaycx\Api` (autoloaded from
  `extension/onepaycx/system/library/api.php` on 4.0.2+; both controllers also
  `require_once` it by path so 4.0.0/4.0.1, whose autoloader registration differs, work).
- Routes: `extension/onepaycx/payment/onepaycx` (admin page and catalog button),
  `.save` / `.install` / `.uninstall` (admin), `.confirm` / `.callback` / `.return` /
  `.reconcile` (catalog). 4.0.2 changed the method separator from `|` to `.`; the controllers pick the
  right one from the `VERSION` constant when they build URLs.
- Payment-method discovery: the catalog model implements both the 4.0.2+ `getMethods()`
  (option code `onepaycx.onepaycx`) and the 4.0.0/4.0.1 `getMethod()` (code `onepaycx`);
  `confirm()` accepts either session shape.
- Settings live under the `payment_onepaycx_*` keys: `status`, `api_key`,
  `webhook_secret`, `merchant_id`, `title`, `order_status_id` (paid), `pending_status_id`
  (0 = leave as a missing order until paid), `direct`, `sort_order`.
- Table `<prefix>onepaycx_order` (`payment_id` PK, `order_id`, `amount`, `currency`,
  `status`, `return_key`, dates) maps a 1pay.cx payment to its order; `status` is the
  idempotency latch. It is **kept on uninstall** (core only deletes the settings): it is the
  only link from a payment id back to an order, and uninstall/reinstall is a common
  troubleshooting step - dropping it would strand every payment in flight.
- Language files: OpenCart loads only `<current language>/payment/onepaycx.php` and has no
  en-gb fallback, so every entry point loads the shipped `en-gb` file first
  (`$this->load->language(route, '', 'en-gb')`) and the store/admin language on top; a
  translation only overrides the keys it actually provides.

## Payment flow

1. Checkout confirm step: OpenCart loads `index()`, which renders the "Continue to payment"
   button.
2. The button POSTs to `confirm()`: the order in the session is loaded, its total converted
   to the order currency and rounded to two decimals, and `POST https://api.1pay.cx/v1/payments`
   is called with `{ amount, currency, reference: <order_id>, productName, email?,
   notifyUrl: ...onepaycx.callback, redirectUrl: ...onepaycx.return&order_id=&key= }`.
   The payment id, amount, currency and a random return key are stored; the buyer is sent
   to `https://1pay.cx{checkoutUrl}` (any other host is refused).
3. 1pay.cx POSTs `{ event, data, at }` to `callback()` with `X-1pay-Signature` = hex
   HMAC-SHA256 of the **raw** body under the merchant's webhook secret (fetched from
   `GET /v1/me` when the API key was saved). Verified with `hash_equals`; only
   `payment.paid` for a payment this store created for that order is acted on. The payment
   row is claimed atomically (`UPDATE ... WHERE status <> 'paid'`) so a repeat or a
   concurrent return-page poll answers `200 already paid` without adding history. If the
   order update fails the claim is released and `500` is answered - but **1pay.cx sends the
   notify exactly once and does not retry** (`packages/api/src/webhooks.ts` `deliverNotify()`
   is a single best-effort POST); the order is then finished by step 4 or step 5.
   Before the paid status is applied the payment is checked against the order **as it is
   now**: OpenCart edits an unpaid order in place (`checkout/confirm` calls `editOrder()`
   while the status is 0), so a buyer can open a $1 link, grow the cart to $500, re-confirm
   and pay the old link. If the row's amount/currency differ from the current order total
   (converted exactly as `confirm()` did) or from the amount 1pay.cx reports, the order
   only gets a `WARNING` history note on its current status (a still-"missing" order is
   moved to the awaiting-payment / default status so the note is visible) and is **not**
   moved to the paid status. An order already in the paid status or in any
   `config_processing_status` / `config_complete_status` is never regressed: a late payment
   of an older attempt adds a "possible double payment" note to the current status.
4. The buyer lands on `return()`, which checks the return key and polls
   `GET /v1/payments/{id}`: `paid` -> confirms the order (same idempotent path) and redirects
   to `checkout/success`; `pending` -> a waiting page that refreshes every 5 s;
   `failed`/`expired` -> "back to checkout". Expired links can still be paid late, so the row
   stays pollable.
5. `reconcile()` (`index.php?route=extension/onepaycx/payment/onepaycx.reconcile&token=...`)
   is the catch-up for a notify that was lost (store down, maintenance mode, extension
   disabled) when the buyer never came back - e.g. a slow-confirming crypto payment after
   the tab was closed. It re-polls `GET /v1/payments/{id}` for up to 20 rows still
   `pending`/`expired` from the last 7 days (pending first) and runs the same
   `completeOrder()` path. The token is `HMAC-SHA256("onepaycx-reconcile", webhookSecret)`
   (derived, never the secret), compared with `hash_equals`; the settings page shows the
   full URL for a cron job / URL monitor. It answers `200 checked=.. paid=.. pending=..
   expired=.. failed=.. errors=..`, `401 bad token`, or `503 not configured`.

Outbound traffic goes to `https://api.1pay.cx` (API) and `https://1pay.cx` (buyer redirect)
only. The API key and webhook secret are never logged, never rendered, and never returned in
JSON; the settings page shows only "connected (merchant id)".

## Install (merchant)

1. Admin -> Extensions -> Installer -> Upload `onepaycx.ocmod.zip`, then press Install.
2. Extensions -> Extensions -> choose type Payments -> 1pay.cx -> Install, then Edit.
3. Paste the API key (dashboard: Settings -> API keys), pick the paid order status, enable,
   Save. The key is verified against `GET /v1/me` as you save.
4. The store must be reachable from the internet over HTTPS at
   `index.php?route=extension/onepaycx/payment/onepaycx.callback` and must not be in
   maintenance mode, or orders will only be confirmed when the buyer returns to the store.
5. Recommended: fetch the reconciliation URL shown on the settings page every 5-15 minutes
   from a cron job (`curl -fsS '<url>'`) or an uptime monitor. It catches any payment whose
   single notification did not get through and whose buyer never returned.

Uninstalling removes the settings (OpenCart core does that) but keeps the `onepaycx_order`
table on purpose, so a reinstall can still complete payments that were in flight. Drop the
table by hand if you want it gone.

## Checks (must pass before a release)

```bash
for f in $(find . -name '*.php'); do php -l "$f" >/dev/null || echo "LINT FAIL $f"; done
php test/compile-check.php
```

`test/compile-check.php` loads minimal stand-ins for the OpenCart 4.0.2 engine and library
classes the extension touches (`Opencart\System\Engine\{Controller,Model,Registry,Loader}`,
`Opencart\System\Library\{Request,Response,Session,Config,Url,Language,Document,Log,DB,User}`,
`Cart\{Cart,Currency}`), requires the client, both controllers and both models, instantiates
every class, then drives the admin save/install flow and the storefront confirm / callback /
return / reconcile flow with fake requests, checking signature verification, host pinning,
idempotency, the edited-order and late-double-payment guards, the non-en-gb language
fallback and that no secret reaches a view, log or response. It exists because a mismatch against a
platform parent class is an uncatchable compile-time fatal that white-screens a live shop; the
harness turns that into a failing command on this machine. The stubs deliberately implement
only the members the extension calls; calling a new OpenCart API means adding it to
`test/stubs.php`.

## Packaging

```bash
./build.sh            # -> onepaycx.ocmod.zip
```

## Contract this extension relies on

- `POST https://api.1pay.cx/v1/payments` -> `{ id, checkoutUrl }`; `checkoutUrl` is a path on
  `https://1pay.cx`. `amount` is a JSON number rounded to two decimals (the API schema is
  `z.number()`), `currency` an upper-case ISO code, `reference` the order id.
- `GET /v1/payments/{id}` -> `status` in `pending | paid | failed | expired`.
- `GET /v1/me` -> `{ id, email, webhookSecret }`.
- Notify: `POST notifyUrl` with `{ event, data, at }`; `data` carries `paymentId`,
  `reference`, `amount`, `status`; `X-1pay-Signature` is the hex HMAC-SHA256 of the raw body
  under `webhookSecret`. Only `payment.paid` is acted on. It is sent **once** per payment
  (no retry on a non-2xx answer), and `data.amount` is the amount the buyer was charged: what the extension sent plus the merchant's optional customer surcharge (set in the 1pay.cx dashboard), so it can exceed the order total. The extension treats any reported amount at or above the order amount as paid and flags only underpayment or a currency change as a mismatch.
- User-Agent `onepaycx-opencart/1.0.0` (the API's `viaFromUserAgent` does not yet map it to
  a platform name; it records `api` until that is added server-side).
