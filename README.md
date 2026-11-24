# Fluent Forms - WeoInvoice

**Fluent Forms** invoicing with **WeoInvoice**.

When a Fluent Forms payment reaches `paid` (via the ifthenpay gateway or any
other Fluent Forms payment method), this plugin builds a pre-digested `client` +
`ordercart` payload and POSTs it directly to WeoInvoice's order-agnostic
`emit_invoice.php` endpoint. No WooCommerce order is created.

## Requirements

- WordPress 6.7+
- PHP 8.2+
- **Fluent Forms** (free), with payments turned on and a payment method that marks the entry paid (for example ifthenpay).
- A **WeoInvoice** account with an API key.

## Installation

### Manual Installation

1. Download the latest release ZIP from the [Releases page](https://github.com/pixelalbatross/fluentform-weoinvoice/releases/latest).
2. Go to Plugins > Add New > Upload Plugin in your WordPress admin area.
3. Upload the ZIP file and click Install Now.
4. Activate the plugin.

### Install with Composer

To include this plugin as a dependency in your Composer-managed WordPress project:

1. Add the plugin to your project using the following command:

```bash
composer require outstand/fluentform-weoinvoice
```

1. Run `composer install`.
2. Activate the plugin from your WordPress admin area or using WP-CLI.

## Setup

1. In **Fluent Forms → Settings → Integrations**, open **WeoInvoice** and enter your API key. Optionally set the default VAT exemption M-code (default `M07`) and default country (default `PT`).
2. On a Fluent Forms **payment** form, add the **WeoInvoice** integration feed.
3. Map the form fields to the WeoInvoice client fields (name, company/entity, email, NIF, address, postcode, city, country), and set a line-item label (e.g. `Donativo`). Add conditional logic if needed.
4. Enable the feed.

When a submission on that form is paid, a WeoInvoice document is issued
automatically. The document has one line: the line-item label at the amount
Fluent Forms charged (`payment_total`, after coupons, for every payment item and
quantity). The result (issued reference or error) is recorded on the submission
and written to the entry's logs in Fluent Forms. A failed emission's log entry
links to the **retry** admin action, which needs the `manage_options`
capability.

## Idempotency

The emit result is stored on the submission (`_os_ff_weoinvoice_emit`), keyed per
feed. A second `paid` event — for example a delayed Multibanco reconfirmation — or a
manual retry will not re-issue a feed that has already issued, even when another feed
on the same form failed. A per-entry database lock stops two emits for the same entry
from running at once. The Fluent Forms submission id is sent as the WeoInvoice
`order_id`.

## Limitations

- **The document type is not selectable.** `emit_invoice.php` takes no document-type parameter — your WeoInvoice **account/plan** decides whether an invoice, receipt, or donation receipt is issued. This plugin cannot choose it. For donation receipts, the WeoInvoice account must be configured to issue the correct document.
- **Undocumented endpoint.** `emit_invoice.php` is an internal WeoInvoice endpoint and may change without notice. This risk is accepted; there is no documented order-agnostic API.
- **Manual retry only.** There is no async auto-retry queue in this version. A retry issues nothing for an entry whose payment status is no longer `paid`.
- **A timeout is stored as a failure.** When WeoInvoice issues the document but the response does not arrive within 30 seconds, the result is `failed`. Check WeoInvoice before you retry, or the retry can issue a second document.
- **EUR only.** The document is issued in euros. A payment in another currency is stored as failed and nothing is issued.
- **No credit notes.** A refund in Fluent Forms does not cancel the document. Issue the credit note in WeoInvoice.

## For Developers

### Debugging

Define `OUTSTAND_FLUENTFORM_WEOINVOICE_DEBUG` (truthy) to log the outbound
request and response to the PHP error log. Sensitive values are redacted: the API
key and any other key, token, secret or credential, and the client's `nif`,
`email`, `name`, `entity`, `address`, `postcode` and `city`.

### Uninstall

Deleting the plugin deletes its global settings, including the API key. The
feeds and the emit results stay in the Fluent Forms tables until Fluent Forms
deletes their form or entry.

### Building from Source

```
composer install
npm install
npm run test:setup   # starts wp-env (bundles Fluent Forms for integration tests)
npm run test:unit
composer lint
```

## Changelog

All notable changes to this project are documented in [CHANGELOG.md](https://github.com/pixelalbatross/fluentform-weoinvoice/blob/main/CHANGELOG.md).

## License

This project is licensed under the [GPL-3.0-or-later](https://spdx.org/licenses/GPL-3.0-or-later.html).
