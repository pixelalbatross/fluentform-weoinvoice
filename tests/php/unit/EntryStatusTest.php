<?php

namespace Outstand\WP\FluentForm\Addons\WeoInvoice\Tests\Unit;

use Outstand\WP\FluentForm\Addons\WeoInvoice\EntryStatus;
use WPDieException;
use WP_UnitTestCase;

/**
 * Per-feed emit-status storage, the signed retry URL, and the gated retry handler.
 *
 * @covers \Outstand\WP\FluentForm\Addons\WeoInvoice\EntryStatus
 */
class EntryStatusTest extends WP_UnitTestCase {

	/**
	 * Skips when Fluent Forms is not loaded (the meta store lives on an FF table).
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
	 * A stored feed result round-trips back through feed_status.
	 *
	 * @return void
	 */
	public function test_store_and_read_round_trip(): void {
		$entry_id = 5001;

		EntryStatus::store_feed(
			$entry_id,
			9,
			101,
			[
				'status'     => 'issued',
				'invoice_id' => 'FR 1/7',
			]
		);

		$this->assertSame( 'issued', EntryStatus::feed_status( $entry_id, 101 ) );
	}

	/**
	 * A never-attempted feed reports an empty status.
	 *
	 * @return void
	 */
	public function test_unknown_feed_status_is_empty(): void {
		$this->assertSame( '', EntryStatus::feed_status( 5002, 999 ) );
	}

	/**
	 * A second feed on the same entry does not clobber the first — the map is per-feed.
	 *
	 * @return void
	 */
	public function test_second_feed_does_not_clobber_first(): void {
		$entry_id = 5003;
		$form_id  = 9;

		EntryStatus::store_feed( $entry_id, $form_id, 111, [ 'status' => 'issued' ] );
		EntryStatus::store_feed( $entry_id, $form_id, 222, [ 'status' => 'failed' ] );

		$this->assertSame( 'issued', EntryStatus::feed_status( $entry_id, 111 ) );
		$this->assertSame( 'failed', EntryStatus::feed_status( $entry_id, 222 ) );
	}

	/**
	 * Storing the same feed twice upserts a single row: the latest value wins and no
	 * duplicate meta row is created (the ORDER BY id DESC LIMIT 1 upsert path).
	 *
	 * @return void
	 */
	public function test_store_twice_upserts_single_row(): void {
		global $wpdb;

		$entry_id = 5004;
		$form_id  = 9;

		EntryStatus::store_feed( $entry_id, $form_id, 333, [ 'status' => 'failed' ] );
		EntryStatus::store_feed( $entry_id, $form_id, 333, [ 'status' => 'issued' ] );

		$this->assertSame( 'issued', EntryStatus::feed_status( $entry_id, 333 ) );

		$table = $wpdb->prefix . 'fluentform_submission_meta';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE response_id = %d AND meta_key = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$entry_id,
				EntryStatus::META_KEY
			)
		);

		$this->assertSame( 1, $count );
	}

	/**
	 * The retry URL carries the admin-post action, the entry id, and a signing nonce.
	 *
	 * @return void
	 */
	public function test_retry_url_is_signed(): void {
		$url = EntryStatus::retry_url( 5005 );

		$this->assertStringContainsString( 'action=outstand_fluentform_weoinvoice_retry', $url );
		$this->assertStringContainsString( 'entry_id=5005', $url );
		$this->assertStringContainsString( '_wpnonce=', $url );
	}

	/**
	 * Without manage_options the retry handler dies with a 403.
	 *
	 * @return void
	 */
	public function test_handle_retry_denied_without_capability(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $user_id );

		$this->expectException( WPDieException::class );
		( new EntryStatus() )->handle_retry();
	}

	/**
	 * With the capability but an invalid nonce the retry handler dies.
	 *
	 * @return void
	 */
	public function test_handle_retry_dies_on_bad_nonce(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );

		$_GET['entry_id'] = '5006';
		$_GET['_wpnonce'] = 'not-a-valid-nonce';

		try {
			$this->expectException( WPDieException::class );
			( new EntryStatus() )->handle_retry();
		} finally {
			unset( $_GET['entry_id'], $_GET['_wpnonce'] );
		}
	}
}
