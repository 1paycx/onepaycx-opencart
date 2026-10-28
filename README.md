# 1pay.cx for OpenCart 4

Accept card and crypto payments in OpenCart 4.0.x with [1pay.cx](https://1pay.cx).
"Pay by card or crypto" on the confirm step, the buyer pays on the hosted checkout, the
order is confirmed by a signed webhook. Card sales settle to you in USDC on Polygon, crypto at
a flat 1%. No KYB, no rolling reserves, no chargebacks.

- **Version 1.0.0** for OpenCart 4.0.x (verified against 4.0.2.3), PHP 8.0+
- Licence: MIT (see `LICENSE`)

```
upload/              the extension exactly as it is packaged
test/                stub-parent compile harness, run before every release
build.sh             lints, runs the harness, builds onepaycx.ocmod.zip
DEVELOPMENT.md       layout, flow, API contract, release checks
```

## Install

1. Download https://1pay.cx/plugins/onepaycx.ocmod.zip (or run `./build.sh`).
2. Admin: **Extensions → Installer → Upload** the zip, then press **Install**.
3. **Extensions → Extensions → Payments → 1pay.cx → Install**, then **Edit**.
4. Paste your API key (1pay.cx dashboard → **Settings → API keys**), pick the paid order
   status, enable, save. The key is verified as you save.
5. The store must be reachable over HTTPS from the internet and not in maintenance mode,
   or orders will only be confirmed when the buyer returns to the store.
6. Recommended: fetch the reconciliation URL shown on the settings page every 5 to 15
   minutes from a cron job or an uptime monitor. It catches any payment whose notification
   did not get through and whose buyer never returned.

Uninstalling keeps the `onepaycx_order` table on purpose so a reinstall can still complete
payments that were in flight. Drop it by hand if you want it gone.

## Support

- Documentation and every integration: https://1pay.cx/docs
- Service status: https://1pay.cx/status
- Contact: `hello@1pay.cx` on Jabber/XMPP, or the chat on any 1pay.cx page
