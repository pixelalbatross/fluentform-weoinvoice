# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-11-24

### Added

- A "WeoInvoice" integration in Fluent Forms has a global-settings tab (API key, VAT M-code, default country) and a per-form feed that maps form fields to the WeoInvoice client.
- When a payment reaches `paid`, each enabled feed whose conditional logic matches issues one WeoInvoice document. The document total is the amount Fluent Forms charged.
- The payload goes to WeoInvoice's `emit_invoice.php` endpoint, so no WooCommerce order is created.
- A Portuguese NIF is normalized (a leading `PT` is removed) and checked by length and checksum. An invalid NIF is dropped, and the document still issues.
- Each feed's result is stored on the entry and written to the entry's logs. A feed that issued is never issued again, also when the payment is confirmed twice.
- A failed feed can be retried from a link in the entry log. The retry needs the `manage_options` capability and issues nothing for an entry that is no longer paid.
- Payments in a currency other than EUR are stored as failed and issue nothing.
- Debug logging is turned on with `OUTSTAND_FLUENTFORM_WEOINVOICE_DEBUG` and redacts personal data.
- Uninstalling the plugin deletes its global settings.

[Unreleased]: https://github.com/pixelalbatross/fluentform-weoinvoice/compare/1.0.0...HEAD
[1.0.0]: https://github.com/pixelalbatross/fluentform-weoinvoice/releases/tag/1.0.0
