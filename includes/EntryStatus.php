<?php

namespace Outstand\WP\FluentForm\Addons\WeoInvoice;

defined( 'ABSPATH' ) || exit;

use FluentForm\App\Models\Submission;

/**
 * Stores the WeoInvoice emit result on a Fluent Forms submission and exposes a
 * capability-gated manual retry.
 *
 * Results are written to one `fluentform_submission_meta` row keyed by
 * self::META_KEY, holding a per-feed map (`{ "<feed_id>": { status, ... } }`) so a
 * form with several enabled feeds tracks each feed's outcome independently — a later
 * failing feed never un-gates an already-issued one. PaymentTrigger writes each result to the
 * entry's activity log in Fluent Forms (`fluentform/log_data`). The retry is an `admin-post` endpoint whose
 * signed URL is produced by self::retry_url(); it re-runs the emit and, thanks to the
 * per-feed gate, only re-emits feeds that have not already issued. PaymentTrigger emits only
 * for an entry whose payment status is `paid`.
 */
class EntryStatus extends BaseModule {

	/**
	 * Submission-meta key holding the per-feed emit result map.
	 *
	 * @var string
	 */
	public const META_KEY = '_os_ff_weoinvoice_emit';

	/**
	 * Registers the capability-gated manual retry admin-post handler.
	 */
	public function register(): void {
		add_action( 'admin_post_outstand_fluentform_weoinvoice_retry', [ $this, 'handle_retry' ] );
	}

	/**
	 * Reads the stored emit status for a single feed on an entry.
	 *
	 * @param  int        $entry_id Submission id.
	 * @param  int|string $feed_id  Feed identifier (the form-meta row id).
	 * @return string One of `issued`, `failed`, or `''` when never attempted.
	 */
	public static function feed_status( int $entry_id, $feed_id ): string {
		$map  = self::read_map( $entry_id );
		$feed = $map[ (string) $feed_id ] ?? null;

		return is_array( $feed ) ? (string) ( $feed['status'] ?? '' ) : '';
	}

	/**
	 * Writes (or overwrites) the emit result for a single feed on an entry.
	 *
	 * @param  int                 $entry_id Submission id.
	 * @param  int                 $form_id  Form id.
	 * @param  int|string          $feed_id  Feed identifier (the form-meta row id).
	 * @param  array<string,mixed> $result   The typed emit result.
	 * @return void
	 */
	public static function store_feed( int $entry_id, int $form_id, $feed_id, array $result ): void {
		$map                      = self::read_map( $entry_id );
		$map[ (string) $feed_id ] = $result;

		self::write_map( $entry_id, $form_id, $map );
	}

	/**
	 * The signed admin-post URL that re-runs the emit for an entry.
	 *
	 * @param  int $entry_id Submission id.
	 * @return string
	 */
	public static function retry_url( int $entry_id ): string {
		$url = add_query_arg(
			[
				'action'   => 'outstand_fluentform_weoinvoice_retry',
				'entry_id' => $entry_id,
			],
			admin_url( 'admin-post.php' )
		);

		return wp_nonce_url( $url, 'outstand_fluentform_weoinvoice_retry_' . $entry_id );
	}

	/**
	 * Re-runs the emit for an entry (used by the manual retry action).
	 *
	 * @param  int $entry_id Submission id.
	 * @return void
	 */
	public static function retry( int $entry_id ): void {
		if ( ! class_exists( Submission::class ) ) {
			return;
		}

		$submission = Submission::find( $entry_id );
		if ( ! $submission ) {
			return;
		}

		( new PaymentTrigger() )->process( $submission );
	}

	/**
	 * Handles the capability-gated, nonce-checked manual retry admin action.
	 *
	 * @return void
	 */
	public function handle_retry(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'fluentform-weoinvoice' ), 403 );
		}

		$entry_id = isset( $_GET['entry_id'] ) ? absint( wp_unslash( $_GET['entry_id'] ) ) : 0;
		$nonce    = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		if ( ! $entry_id || ! wp_verify_nonce( $nonce, 'outstand_fluentform_weoinvoice_retry_' . $entry_id ) ) {
			wp_die( esc_html__( 'Invalid retry request.', 'fluentform-weoinvoice' ), 400 );
		}

		self::retry( $entry_id );

		$redirect = wp_get_referer();
		if ( ! $redirect ) {
			$redirect = admin_url();
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Reads the per-feed result map for an entry.
	 *
	 * @param  int $entry_id Submission id.
	 * @return array<string,mixed>
	 */
	private static function read_map( int $entry_id ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'fluentform_submission_meta';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT value FROM {$table} WHERE response_id = %d AND meta_key = %s ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$entry_id,
				self::META_KEY
			)
		);

		if ( ! $value ) {
			return [];
		}

		$decoded = json_decode( (string) $value, true );

		return is_array( $decoded ) ? $decoded : [];
	}

	/**
	 * Writes the per-feed result map for an entry, upserting the single meta row.
	 *
	 * @param  int                 $entry_id Submission id.
	 * @param  int                 $form_id  Form id.
	 * @param  array<string,mixed> $map      The per-feed result map.
	 * @return void
	 */
	private static function write_map( int $entry_id, int $form_id, array $map ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'fluentform_submission_meta';
		$value = (string) wp_json_encode( $map );
		$now   = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE response_id = %d AND meta_key = %s ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$entry_id,
				self::META_KEY
			)
		);

		if ( $existing_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$table,
				[
					'value'      => $value,
					'updated_at' => $now,
				],
				[ 'id' => $existing_id ]
			);

			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$table,
			[
				'response_id' => $entry_id,
				'form_id'     => $form_id,
				'meta_key'    => self::META_KEY,
				'value'       => $value,
				'created_at'  => $now,
				'updated_at'  => $now,
			]
		);
	}
}
