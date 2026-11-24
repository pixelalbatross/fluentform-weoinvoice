<?php

namespace Outstand\WP\FluentForm\Addons\WeoInvoice;

defined( 'ABSPATH' ) || exit;

use FluentForm\App\Http\Controllers\IntegrationManagerController;
use FluentForm\App\Models\FormMeta;
use FluentForm\App\Services\ConditionAssesor;

/**
 * Emits a WeoInvoice document when a Fluent Forms payment reaches `paid`.
 *
 * The form's saved `os_ff_weoinvoice_feed` rows are read straight from
 * `fluentform_form_meta`, because the `fluentform/integration_notify_*` dispatcher runs on
 * submission and the emit must wait for the later payment-status change. The document total is
 * the amount Fluent Forms charged (`payment_total`). Idempotent per feed via EntryStatus (keyed by
 * the feed's form-meta row id), so a delayed Multibanco reconfirmation or a manual retry never
 * re-issues an already-issued feed even when a sibling feed on the same form failed.
 */
class PaymentTrigger extends BaseModule {

	/**
	 * Currency that WeoInvoice receives as `currency_code` 0.
	 *
	 * @var string
	 */
	public const CURRENCY = 'EUR';

	/**
	 * Registers nothing unless the Fluent Forms integration base class is present.
	 */
	public function can_register(): bool {
		return class_exists( IntegrationManagerController::class );
	}

	/**
	 * Hooks emission onto the payment-status change.
	 */
	public function register(): void {
		add_action( 'fluentform/after_payment_status_change', [ $this, 'maybe_emit' ], 20, 2 );
	}

	/**
	 * Emits only when the new payment status is `paid`.
	 *
	 * @param  string $new_status The new payment status.
	 * @param  object $submission The submission (has ->id, ->form_id, ->response).
	 * @return void
	 */
	public function maybe_emit( string $new_status, $submission ): void {
		if ( 'paid' !== $new_status ) {
			return;
		}

		$this->process( $submission );
	}

	/**
	 * Loads the enabled feeds for the submission's form and emits for each.
	 *
	 * @param  object $submission The submission (has ->id, ->form_id, ->response).
	 * @return void
	 */
	public function process( $submission ): void {
		$entry_id       = (int) ( $submission->id ?? 0 );
		$form_id        = (int) ( $submission->form_id ?? 0 );
		$payment_status = (string) ( $submission->payment_status ?? '' );

		if ( ! $entry_id || ! $form_id ) {
			return;
		}

		// A retry on a pending, failed or refunded entry must not issue a document.
		if ( 'paid' !== $payment_status ) {
			return;
		}

		$api_key = Feed::global_api_key();
		if ( '' === $api_key ) {
			Logger::error( 'WeoInvoice API key is not configured; skipping emit.', [ 'entry_id' => $entry_id ] );
			return;
		}

		// Serialize concurrent emits for one entry (duplicate webhook, or a retry racing the
		// original payment hook) so the per-feed idempotency gate below cannot be read stale by
		// two callers at once and issue the same document twice. `false` means another emit for
		// this entry is already in flight and will process every feed, so this caller yields.
		$lock = $this->acquire_entry_lock( $entry_id );
		if ( false === $lock ) {
			return;
		}

		try {
			$form_data = $this->form_data( $submission );
			$feeds     = $this->enabled_feeds( $form_id, $form_data );
			$payment   = $this->payment( $submission );

			foreach ( $feeds as $feed_id => $feed ) {
				$feed_status = EntryStatus::feed_status( $entry_id, $feed_id );
				if ( 'issued' === $feed_status ) {
					continue;
				}

				$this->emit_feed( $entry_id, $form_id, $feed_id, $feed, $form_data, $payment, $api_key );
			}
		} finally {
			if ( true === $lock ) {
				$this->release_entry_lock( $entry_id );
			}
		}
	}

	/**
	 * The submission response as a form-data array.
	 *
	 * The payment hook passes a submission whose response Fluent Forms has already decoded; a
	 * submission read from the database carries the JSON string.
	 *
	 * @param  object $submission The submission.
	 * @return array<string,mixed>
	 */
	private function form_data( $submission ): array {
		$response = $submission->response ?? '';

		if ( is_array( $response ) ) {
			return $response;
		}

		$decoded = json_decode( (string) $response, true );

		return is_array( $decoded ) ? $decoded : [];
	}

	/**
	 * The charged amount of a submission, or the reason it cannot be invoiced.
	 *
	 * Fluent Forms stores the charged total in cents, after coupons and with every payment item
	 * and quantity. WeoInvoice receives the document in euros only.
	 *
	 * @param  object $submission The submission.
	 * @return array{amount:string,error:string}
	 */
	private function payment( $submission ): array {
		$currency = strtoupper( (string) ( $submission->currency ?? '' ) );
		$cents    = (float) ( $submission->payment_total ?? 0 );

		if ( self::CURRENCY !== $currency ) {
			return [
				'amount' => '',
				/* translators: %s: the currency code of the payment. */
				'error'  => sprintf( __( 'The payment currency %s is not supported. WeoInvoice documents are issued in EUR only.', 'fluentform-weoinvoice' ), '' !== $currency ? $currency : '-' ),
			];
		}

		if ( $cents <= 0 ) {
			return [
				'amount' => '',
				'error'  => __( 'The entry has no payment total.', 'fluentform-weoinvoice' ),
			];
		}

		return [
			'amount' => number_format( $cents / 100, 2, '.', '' ),
			'error'  => '',
		];
	}

	/**
	 * Reads the form's saved WeoInvoice feeds and returns those enabled + condition-matched.
	 *
	 * @param  int                 $form_id   Form id.
	 * @param  array<string,mixed> $form_data Submission data.
	 * @return array<int,array<string,mixed>> Enabled feeds keyed by their form-meta row id.
	 */
	private function enabled_feeds( int $form_id, array $form_data ): array {
		if ( ! class_exists( FormMeta::class ) ) {
			return [];
		}

		$rows = FormMeta::where( 'form_id', $form_id )
			->where( 'meta_key', Feed::SETTINGS_KEY )
			->orderBy( 'id', 'ASC' )
			->get();

		$enabled = [];
		foreach ( $rows as $row ) {
			$settings = json_decode( $row->value, true );
			if ( ! is_array( $settings ) || ! $this->is_enabled_value( $settings['enabled'] ?? false ) ) {
				continue;
			}

			if ( ! $this->conditions_match( $settings, $form_data ) ) {
				continue;
			}

			$enabled[ (int) $row->id ] = $settings;
		}

		return $enabled;
	}

	/**
	 * Evaluates a feed's conditional logic against the submission data.
	 *
	 * @param  array<string,mixed> $settings  The feed settings (carries `conditionals`).
	 * @param  array<string,mixed> $form_data Submission data.
	 * @return bool
	 */
	private function conditions_match( array $settings, array $form_data ): bool {
		$conditionals = $settings['conditionals'] ?? [];
		$has_status   = is_array( $conditionals ) && ! empty( $conditionals['status'] );

		if ( ! $has_status ) {
			return true;
		}

		// Fail closed: an active condition we cannot evaluate must NOT issue a document.
		if ( ! class_exists( ConditionAssesor::class ) ) {
			Logger::error( 'ConditionAssesor unavailable; skipping conditional feed to avoid an unintended emit.' );
			return false;
		}

		return (bool) ConditionAssesor::evaluate( $settings, $form_data );
	}

	/**
	 * Fluent Forms stores `enabled` as a real bool or the string 'true'/'false'.
	 *
	 * @param  mixed $value The raw enabled value.
	 * @return bool
	 */
	private function is_enabled_value( $value ): bool {
		if ( is_string( $value ) ) {
			$normalized = strtolower( trim( $value ) );

			return 'true' === $normalized || '1' === $normalized;
		}

		return (bool) $value;
	}

	/**
	 * Builds the payload for one feed, emits it, stores the result, and logs to the entry.
	 *
	 * @param  int                  $entry_id  Submission id.
	 * @param  int                  $form_id   Form id.
	 * @param  int                  $feed_id   Feed identifier (form-meta row id).
	 * @param  array<string,mixed>  $feed      The feed settings.
	 * @param  array<string,mixed>  $form_data Submission data.
	 * @param  array<string,string> $payment   The charged amount, or the reason it cannot be invoiced.
	 * @param  string               $api_key   WeoInvoice API key.
	 * @return void
	 */
	private function emit_feed( int $entry_id, int $form_id, int $feed_id, array $feed, array $form_data, array $payment, string $api_key ): void {
		if ( '' !== $payment['error'] ) {
			$result = [
				'status' => 'failed',
				'error'  => $payment['error'],
			];

			EntryStatus::store_feed( $entry_id, $form_id, $feed_id, $result );
			$this->log_result( $entry_id, $form_id, $result );

			return;
		}

		try {
			$mapped = Feed::map_submission( $feed, $form_data, $payment['amount'] );
			$client = $mapped['client'];

			$country = (string) ( $client['country'] ?? '' );
			if ( '' === $country ) {
				$client['country'] = Feed::global_country();
			}

			$result = Emitter::emit( $api_key, $entry_id, $client, $mapped['lines'], Feed::global_taxreason() );
			EntryStatus::store_feed( $entry_id, $form_id, $feed_id, $result );

			$this->log_result( $entry_id, $form_id, $result );
		} catch ( \Throwable $e ) {
			Logger::error( 'WeoInvoice emission threw an exception.', [ 'error' => $e->getMessage() ] );

			$result = [
				'status' => 'failed',
				'error'  => $e->getMessage(),
			];

			EntryStatus::store_feed( $entry_id, $form_id, $feed_id, $result );
			$this->log_result( $entry_id, $form_id, $result );
		}
	}

	/**
	 * Writes the emit result to the entry's activity log in Fluent Forms.
	 *
	 * On failure the log carries a link to the signed retry URL.
	 *
	 * @param  int                 $entry_id Submission id.
	 * @param  int                 $form_id  Form id.
	 * @param  array<string,mixed> $result   The typed emit result.
	 * @return void
	 */
	private function log_result( int $entry_id, int $form_id, array $result ): void {
		$status = (string) ( $result['status'] ?? '' );
		$log    = [
			'parent_source_id' => $form_id,
			'source_type'      => 'submission_item',
			'source_id'        => $entry_id,
			'component'        => 'WeoInvoice',
		];

		if ( 'issued' === $status ) {
			$invoice_id = (string) ( $result['invoice_id'] ?? '' );

			$log['status'] = 'success';
			$log['title']  = __( 'WeoInvoice document issued', 'fluentform-weoinvoice' );
			/* translators: %s: the issued WeoInvoice document reference. */
			$log['description'] = esc_html( sprintf( __( 'Document reference: %s', 'fluentform-weoinvoice' ), $invoice_id ) );

			do_action( 'fluentform/log_data', $log );

			return;
		}

		$error = (string) ( $result['error'] ?? __( 'Unknown error.', 'fluentform-weoinvoice' ) );

		$log['status']      = 'failed';
		$log['title']       = __( 'WeoInvoice emission failed', 'fluentform-weoinvoice' );
		$log['description'] = sprintf(
			'%1$s <a href="%2$s">%3$s</a>',
			esc_html( $error ),
			esc_url( EntryStatus::retry_url( $entry_id ) ),
			esc_html__( 'Retry', 'fluentform-weoinvoice' )
		);

		do_action( 'fluentform/log_data', $log );
	}

	/**
	 * The MySQL named-lock key for one entry's emit run.
	 *
	 * MySQL named locks share one server-wide namespace across every database on the connection,
	 * so the key carries a hash of the table prefix. The hash keeps the key within MySQL's
	 * 64-character limit for any prefix length.
	 *
	 * @param  int $entry_id Submission id.
	 * @return string
	 */
	private function lock_name( int $entry_id ): string {
		global $wpdb;

		return 'os_ff_weoinvoice_' . md5( $wpdb->prefix ) . '_' . $entry_id;
	}

	/**
	 * Tries to grab the per-entry emit lock without waiting.
	 *
	 * @param  int $entry_id Submission id.
	 * @return bool|null True when acquired (caller must release), false when another emit
	 *                   holds it (caller must yield), null when the lock could not be
	 *                   evaluated (caller proceeds unlocked rather than dropping the emit).
	 */
	private function acquire_entry_lock( int $entry_id ): ?bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $this->lock_name( $entry_id ), 0 ) );

		if ( null === $got ) {
			return null;
		}

		return '1' === (string) $got;
	}

	/**
	 * Releases the per-entry emit lock.
	 *
	 * @param  int $entry_id Submission id.
	 * @return void
	 */
	private function release_entry_lock( int $entry_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->lock_name( $entry_id ) ) );
	}
}
