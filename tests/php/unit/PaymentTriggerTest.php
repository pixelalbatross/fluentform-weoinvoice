<?php

namespace Outstand\WP\FluentForm\Addons\WeoInvoice\Tests\Unit;

use Outstand\WP\FluentForm\Addons\WeoInvoice\PaymentTrigger;
use Outstand\WP\FluentForm\Addons\WeoInvoice\EntryStatus;
use Outstand\WP\FluentForm\Addons\WeoInvoice\Feed;
use ReflectionMethod;
use RuntimeException;
use WP_UnitTestCase;

/**
 * Payment-gated emission and per-feed idempotency.
 *
 * @covers \Outstand\WP\FluentForm\Addons\WeoInvoice\PaymentTrigger
 * @covers \Outstand\WP\FluentForm\Addons\WeoInvoice\EntryStatus
 */
class PaymentTriggerTest extends WP_UnitTestCase {

	/**
	 * Skips when Fluent Forms is not loaded.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		if ( ! class_exists( \FluentForm\App\Http\Controllers\IntegrationManagerController::class ) ) {
			$this->markTestSkipped( 'Fluent Forms is not active.' );
		}
	}

	/**
	 * A non-paid status change makes no outbound request.
	 *
	 * @return void
	 */
	public function test_non_paid_status_is_a_noop(): void {
		$emitted = false;
		add_filter(
			'pre_http_request',
			function () use ( &$emitted ) {
				$emitted = true;
				return [
					'response' => [ 'code' => 200 ],
					'body'     => '{}',
				];
			},
			10,
			3
		);

		( new PaymentTrigger() )->maybe_emit(
			'pending',
			(object) [
				'id'       => 1,
				'form_id'  => 1,
				'response' => '{}',
			]
		);
		$this->assertFalse( $emitted );
	}

	/**
	 * Each feed on an entry tracks its own outcome: a later failing feed never
	 * overwrites an already-issued feed, so a re-run re-emits only the failed one.
	 *
	 * This is the regression guard for the single-row idempotency bug.
	 *
	 * @return void
	 */
	public function test_per_feed_status_is_isolated(): void {
		$entry_id = 4242;
		$form_id  = 7;

		EntryStatus::store_feed(
			$entry_id,
			$form_id,
			11,
			[
				'status'     => 'issued',
				'invoice_id' => 'FR 1/7',
			]
		);
		EntryStatus::store_feed(
			$entry_id,
			$form_id,
			22,
			[
				'status' => 'failed',
				'error'  => 'boom',
			]
		);

		$this->assertSame( 'issued', EntryStatus::feed_status( $entry_id, 11 ) );
		$this->assertSame( 'failed', EntryStatus::feed_status( $entry_id, 22 ) );

		// Re-storing the failing feed must leave the issued feed untouched.
		EntryStatus::store_feed(
			$entry_id,
			$form_id,
			22,
			[
				'status' => 'failed',
				'error'  => 'boom again',
			]
		);

		$this->assertSame( 'issued', EntryStatus::feed_status( $entry_id, 11 ) );
		$this->assertSame( '', EntryStatus::feed_status( $entry_id, 33 ) );
	}

	/**
	 * A submission missing its id or form id makes no outbound request.
	 *
	 * @return void
	 */
	public function test_process_missing_ids_is_a_noop(): void {
		update_option( Feed::OPTION_KEY, [ 'api_key' => 'KEY' ] );

		$emitted = $this->count_http();

		( new PaymentTrigger() )->process(
			(object) [
				'id'      => 0,
				'form_id' => 0,
			]
		);

		$this->assertSame( 0, $emitted() );

		delete_option( Feed::OPTION_KEY );
	}

	/**
	 * An unconfigured API key short-circuits before any HTTP call.
	 *
	 * @return void
	 */
	public function test_process_without_api_key_makes_no_request(): void {
		update_option( Feed::OPTION_KEY, [ 'api_key' => '' ] );

		$feed_id = $this->seed_feed( 7, $this->enabled_feed_settings() );
		$emitted = $this->count_http();

		( new PaymentTrigger() )->process( $this->submission( 6101, 7 ) );

		$this->assertSame( 0, $emitted() );
		$this->assertSame( '', EntryStatus::feed_status( 6101, $feed_id ) );

		delete_option( Feed::OPTION_KEY );
	}

	/**
	 * A paid status with one enabled feed emits and records the issued document.
	 *
	 * @return void
	 */
	public function test_paid_status_emits_and_stores_issued(): void {
		update_option( Feed::OPTION_KEY, [ 'api_key' => 'KEY' ] );

		$feed_id = $this->seed_feed( 7, $this->enabled_feed_settings() );
		$emitted = $this->stub_http_success();

		( new PaymentTrigger() )->maybe_emit( 'paid', $this->submission( 6102, 7 ) );

		$this->assertSame( 1, $emitted() );
		$this->assertSame( 'issued', EntryStatus::feed_status( 6102, $feed_id ) );

		delete_option( Feed::OPTION_KEY );
	}

	/**
	 * A feed already marked issued is not re-emitted (per-feed idempotency gate).
	 *
	 * @return void
	 */
	public function test_already_issued_feed_is_not_reemitted(): void {
		update_option( Feed::OPTION_KEY, [ 'api_key' => 'KEY' ] );

		$feed_id = $this->seed_feed( 7, $this->enabled_feed_settings() );
		EntryStatus::store_feed( 6103, 7, $feed_id, [ 'status' => 'issued' ] );

		$emitted = $this->count_http();

		( new PaymentTrigger() )->process( $this->submission( 6103, 7 ) );

		$this->assertSame( 0, $emitted() );

		delete_option( Feed::OPTION_KEY );
	}

	/**
	 * A disabled feed is skipped during loading.
	 *
	 * @return void
	 */
	public function test_disabled_feed_is_skipped(): void {
		update_option( Feed::OPTION_KEY, [ 'api_key' => 'KEY' ] );

		$settings            = $this->enabled_feed_settings();
		$settings['enabled'] = false;
		$feed_id             = $this->seed_feed( 7, $settings );
		$emitted             = $this->count_http();

		( new PaymentTrigger() )->process( $this->submission( 6104, 7 ) );

		$this->assertSame( 0, $emitted() );
		$this->assertSame( '', EntryStatus::feed_status( 6104, $feed_id ) );

		delete_option( Feed::OPTION_KEY );
	}

	/**
	 * A feed row whose stored settings are not valid JSON is skipped.
	 *
	 * @return void
	 */
	public function test_malformed_feed_settings_are_skipped(): void {
		global $wpdb;

		update_option( Feed::OPTION_KEY, [ 'api_key' => 'KEY' ] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'fluentform_form_meta',
			[
				'form_id'  => 7,
				'meta_key' => Feed::SETTINGS_KEY,
				'value'    => 'not-json{',
			]
		);

		$emitted = $this->count_http();

		( new PaymentTrigger() )->process( $this->submission( 6105, 7 ) );

		$this->assertSame( 0, $emitted() );

		delete_option( Feed::OPTION_KEY );
	}

	/**
	 * A feed carrying an active, failing conditional does not emit.
	 *
	 * Note: the fail-closed branch (ConditionAssesor missing) is unreachable in this
	 * environment because Fluent Forms ships ConditionAssesor; this exercises the
	 * evaluate()-returns-false path instead.
	 *
	 * @return void
	 */
	public function test_failing_conditional_feed_does_not_emit(): void {
		update_option( Feed::OPTION_KEY, [ 'api_key' => 'KEY' ] );

		$settings                 = $this->enabled_feed_settings();
		$settings['conditionals'] = [
			'status'     => true,
			'type'       => 'all',
			'conditions' => [
				[
					'field'    => 'names',
					'operator' => '=',
					'value'    => 'Someone Else',
				],
			],
		];
		$feed_id                  = $this->seed_feed( 7, $settings );
		$emitted                  = $this->count_http();

		( new PaymentTrigger() )->process( $this->submission( 6106, 7 ) );

		$this->assertSame( 0, $emitted() );
		$this->assertSame( '', EntryStatus::feed_status( 6106, $feed_id ) );

		delete_option( Feed::OPTION_KEY );
	}

	/**
	 * A transport exception during emit is caught and stored as a failed result.
	 *
	 * @return void
	 */
	public function test_emit_exception_is_caught_and_stored_failed(): void {
		update_option( Feed::OPTION_KEY, [ 'api_key' => 'KEY' ] );

		$feed_id = $this->seed_feed( 7, $this->enabled_feed_settings() );

		add_filter(
			'pre_http_request',
			static function () {
				throw new RuntimeException( 'boom during emit' );
			},
			10,
			3
		);

		( new PaymentTrigger() )->process( $this->submission( 6107, 7 ) );

		$this->assertSame( 'failed', EntryStatus::feed_status( 6107, $feed_id ) );

		delete_option( Feed::OPTION_KEY );
	}

	/**
	 * The paid hook passes the response already decoded: the mapped client fields and the charged
	 * total reach the payload.
	 *
	 * @return void
	 */
	public function test_decoded_response_and_charged_total_reach_the_payload(): void {
		update_option( Feed::OPTION_KEY, [ 'api_key' => 'KEY' ] );

		$feed_id = $this->seed_feed( 7, $this->enabled_feed_settings() );
		$payload = [];

		add_filter(
			'pre_http_request',
			static function ( $pre, $args ) use ( &$payload ) {
				$payload = json_decode( (string) $args['body'], true );

				return [
					'response' => [ 'code' => 200 ],
					'body'     => wp_json_encode(
						[
							'success'    => true,
							'invoice_id' => 'FR 1/7',
						]
					),
				];
			},
			10,
			2
		);

		$submission           = $this->submission( 6110, 7 );
		$submission->response = json_decode( $submission->response, true );

		( new PaymentTrigger() )->maybe_emit( 'paid', $submission );

		$this->assertSame( 'issued', EntryStatus::feed_status( 6110, $feed_id ) );
		$this->assertSame( 'Ana Example', $payload['client']['name'] );
		$this->assertSame( 'ana@example.test', $payload['client']['email'] );
		$this->assertSame( '12.50', $payload['ordercart'][0]['price'] );
		$this->assertSame( 0, $payload['currency_code'] );

		delete_option( Feed::OPTION_KEY );
	}

	/**
	 * A payment in a currency other than EUR is stored as failed and makes no request.
	 *
	 * @return void
	 */
	public function test_non_eur_payment_is_not_emitted(): void {
		update_option( Feed::OPTION_KEY, [ 'api_key' => 'KEY' ] );

		$feed_id = $this->seed_feed( 7, $this->enabled_feed_settings() );
		$emitted = $this->count_http();

		$submission           = $this->submission( 6111, 7 );
		$submission->currency = 'USD';

		( new PaymentTrigger() )->process( $submission );

		$this->assertSame( 0, $emitted() );
		$this->assertSame( 'failed', EntryStatus::feed_status( 6111, $feed_id ) );

		delete_option( Feed::OPTION_KEY );
	}

	/**
	 * An entry without a payment total is stored as failed and makes no request.
	 *
	 * @return void
	 */
	public function test_entry_without_payment_total_is_not_emitted(): void {
		update_option( Feed::OPTION_KEY, [ 'api_key' => 'KEY' ] );

		$feed_id = $this->seed_feed( 7, $this->enabled_feed_settings() );
		$emitted = $this->count_http();

		$submission                = $this->submission( 6112, 7 );
		$submission->payment_total = 0;

		( new PaymentTrigger() )->process( $submission );

		$this->assertSame( 0, $emitted() );
		$this->assertSame( 'failed', EntryStatus::feed_status( 6112, $feed_id ) );

		delete_option( Feed::OPTION_KEY );
	}

	/**
	 * A retry on an entry that is no longer paid issues nothing.
	 *
	 * @return void
	 */
	public function test_unpaid_entry_is_not_emitted(): void {
		update_option( Feed::OPTION_KEY, [ 'api_key' => 'KEY' ] );

		$feed_id = $this->seed_feed( 7, $this->enabled_feed_settings() );
		$emitted = $this->count_http();

		$submission                 = $this->submission( 6113, 7 );
		$submission->payment_status = 'refunded';

		( new PaymentTrigger() )->process( $submission );

		$this->assertSame( 0, $emitted() );
		$this->assertSame( '', EntryStatus::feed_status( 6113, $feed_id ) );

		delete_option( Feed::OPTION_KEY );
	}

	/**
	 * An issued result writes a success entry log carrying the invoice reference.
	 *
	 * @return void
	 */
	public function test_log_result_issued_writes_success_log(): void {
		$captured = $this->capture_log();

		$method = new ReflectionMethod( PaymentTrigger::class, 'log_result' );
		$method->setAccessible( true );
		$method->invoke(
			new PaymentTrigger(),
			6108,
			7,
			[
				'status'     => 'issued',
				'invoice_id' => 'FR 1/7',
			]
		);

		$log = $captured();

		$this->assertSame( 'success', $log['status'] );
		$this->assertSame( 'submission_item', $log['source_type'] );
		$this->assertSame( 6108, $log['source_id'] );
		$this->assertSame( 7, $log['parent_source_id'] );
		$this->assertStringContainsString( 'FR 1/7', $log['description'] );
	}

	/**
	 * A failed result writes a failed entry log with a link to the signed retry URL.
	 *
	 * @return void
	 */
	public function test_log_result_failed_writes_log_with_retry_link(): void {
		$captured = $this->capture_log();

		$method = new ReflectionMethod( PaymentTrigger::class, 'log_result' );
		$method->setAccessible( true );
		$method->invoke(
			new PaymentTrigger(),
			6109,
			7,
			[
				'status' => 'failed',
				'error'  => 'bad key',
			]
		);

		$log = $captured();

		$this->assertSame( 'failed', $log['status'] );
		$this->assertStringContainsString( 'bad key', $log['description'] );
		$this->assertStringContainsString( '<a href=', $log['description'] );
		$this->assertStringContainsString( 'outstand_fluentform_weoinvoice_retry', $log['description'] );
	}

	/**
	 * A minimal enabled feed mapping for the seeded submission fields.
	 *
	 * @return array<string,mixed>
	 */
	private function enabled_feed_settings(): array {
		return [
			'enabled'      => true,
			'name_field'   => 'names',
			'email_field'  => 'email',
			'nif_field'    => 'nif',
			'item_label'   => 'Donativo',
			'conditionals' => [
				'status'     => false,
				'type'       => 'all',
				'conditions' => [],
			],
		];
	}

	/**
	 * A paid EUR submission stub whose response carries the fabricated mapped fields.
	 *
	 * @param  int $entry_id Submission id.
	 * @param  int $form_id  Form id.
	 * @return object
	 */
	private function submission( int $entry_id, int $form_id ): object {
		return (object) [
			'id'             => $entry_id,
			'form_id'        => $form_id,
			'payment_status' => 'paid',
			'currency'       => 'EUR',
			'payment_total'  => 1250,
			'response'       => (string) wp_json_encode(
				[
					'names' => 'Ana Example',
					'email' => 'ana@example.test',
					'nif'   => '123456789',
				]
			),
		];
	}

	/**
	 * Captures the last entry log that the plugin writes.
	 *
	 * @return callable(): array<string,mixed> A closure returning the captured log.
	 */
	private function capture_log(): callable {
		$log = [];
		add_action(
			'fluentform/log_data',
			static function ( $data ) use ( &$log ) {
				$log = $data;
			},
			1
		);

		return static function () use ( &$log ): array {
			return $log;
		};
	}

	/**
	 * Inserts a WeoInvoice feed row and returns its form-meta row id (the feed id).
	 *
	 * @param  int                 $form_id  Form id.
	 * @param  array<string,mixed> $settings Feed settings.
	 * @return int
	 */
	private function seed_feed( int $form_id, array $settings ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'fluentform_form_meta',
			[
				'form_id'  => $form_id,
				'meta_key' => Feed::SETTINGS_KEY,
				'value'    => (string) wp_json_encode( $settings ),
			]
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Registers a pre_http_request counter that leaves the request unhandled.
	 *
	 * @return callable(): int A closure returning the number of intercepted requests.
	 */
	private function count_http(): callable {
		$count = 0;
		add_filter(
			'pre_http_request',
			static function () use ( &$count ) {
				++$count;
				return [
					'response' => [ 'code' => 200 ],
					'body'     => '{}',
				];
			},
			10,
			3
		);

		return static function () use ( &$count ): int {
			return $count;
		};
	}

	/**
	 * Registers a pre_http_request stub returning a WeoInvoice success body.
	 *
	 * @return callable(): int A closure returning the number of intercepted requests.
	 */
	private function stub_http_success(): callable {
		$count = 0;
		add_filter(
			'pre_http_request',
			static function () use ( &$count ) {
				++$count;
				return [
					'response' => [ 'code' => 200 ],
					'body'     => wp_json_encode(
						[
							'success'    => true,
							'invoice_id' => 'FR 1/7',
							'url'        => 'https://example.test/inv',
						]
					),
				];
			},
			10,
			3
		);

		return static function () use ( &$count ): int {
			return $count;
		};
	}
}
