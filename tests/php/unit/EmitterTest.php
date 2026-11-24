<?php

namespace Outstand\WP\FluentForm\Addons\WeoInvoice\Tests\Unit;

use Outstand\WP\FluentForm\Addons\WeoInvoice\Emitter;
use WP_Error;
use WP_UnitTestCase;

/**
 * Payload shape and response parsing for the WeoInvoice emitter.
 *
 * @covers \Outstand\WP\FluentForm\Addons\WeoInvoice\Emitter
 */
class EmitterTest extends WP_UnitTestCase {

	/**
	 * A sample client.
	 *
	 * @var array<string,string>
	 */
	private array $client = [
		'name'     => 'Ana Example',
		'email'    => 'ana@example.test',
		'nif'      => 'PT123456789',
		'address'  => 'Rua 1',
		'postcode' => '8000-000',
		'city'     => 'Faro',
		'country'  => 'PT',
	];

	/**
	 * A single sample line item.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $lines = [
		[
			'item'     => 'Donativo',
			'quantity' => 1,
			'price'    => '10.00',
		],
	];

	/**
	 * The payload carries the order id, api key, a normalized NIF, and the line item.
	 *
	 * @return void
	 */
	public function test_payload_shape(): void {
		$body = Emitter::build_payload( 'KEY', 42, $this->client, $this->lines, 'M07' );
		$this->assertSame( 42, $body['order_id'] );
		$this->assertSame( 'KEY', $body['api_key'] );
		$this->assertSame( '123456789', $body['client']['nif'] ); // Normalized.
		$this->assertSame( 'Donativo', $body['ordercart'][0]['item'] );
		$this->assertSame( 'M07', $body['ordercart'][0]['taxreason'] );
	}

	/**
	 * A success response is parsed into an issued result with the invoice reference.
	 *
	 * @return void
	 */
	public function test_emit_success_parses_invoice(): void {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				$this->assertSame( Emitter::ENDPOINT, $url );
				$sent = json_decode( $args['body'], true );
				$this->assertSame( 'KEY', $sent['api_key'] );
				return [
					'response' => [ 'code' => 200 ],
					'body'     => wp_json_encode(
						[
							'success'    => true,
							'invoice_id' => 'FR 1/7',
							'url'        => 'https://x/inv',
						]
					),
				];
			},
			10,
			3
		);

		$res = Emitter::emit( 'KEY', 42, $this->client, $this->lines, 'M07' );
		$this->assertSame( 'issued', $res['status'] );
		$this->assertSame( 'FR 1/7', $res['invoice_id'] );
	}

	/**
	 * A success=false body is parsed into a failed result with the error message.
	 *
	 * @return void
	 */
	public function test_emit_failure_returns_error(): void {
		add_filter(
			'pre_http_request',
			fn() => [
				'response' => [ 'code' => 200 ],
				'body'     => wp_json_encode(
					[
						'success' => false,
						'error'   => 'bad key',
					]
				),
			],
			10,
			3
		);
		$res = Emitter::emit( 'KEY', 42, $this->client, $this->lines, 'M07' );
		$this->assertSame( 'failed', $res['status'] );
		$this->assertSame( 'bad key', $res['error'] );
	}

	/**
	 * A checksum-failing NIF is cleared to an empty string in the payload.
	 *
	 * @return void
	 */
	public function test_build_payload_clears_invalid_nif(): void {
		// 111111111 fails the PT checksum, so the payload must not carry it.
		$client        = $this->client;
		$client['nif'] = '111111111';

		$body = Emitter::build_payload( 'KEY', 42, $client, $this->lines, 'M07' );

		$this->assertSame( '', $body['client']['nif'] );
	}

	/**
	 * An empty client name defaults to the PT final-consumer label.
	 *
	 * @return void
	 */
	public function test_build_payload_defaults_empty_name(): void {
		$client         = $this->client;
		$client['name'] = '';

		$body = Emitter::build_payload( 'KEY', 42, $client, $this->lines, 'M07' );

		$this->assertSame( 'Consumidor Final', $body['client']['name'] );
	}

	/**
	 * An empty country defaults to PT.
	 *
	 * @return void
	 */
	public function test_build_payload_defaults_empty_country(): void {
		$client            = $this->client;
		$client['country'] = '';

		$body = Emitter::build_payload( 'KEY', 42, $client, $this->lines, 'M07' );

		$this->assertSame( 'PT', $body['client']['country'] );
	}

	/**
	 * A WP_Error transport failure yields a failed result carrying the transport message.
	 *
	 * @return void
	 */
	public function test_emit_wp_error_transport_failure(): void {
		add_filter(
			'pre_http_request',
			fn() => new WP_Error( 'http_request_failed', 'Connection timed out' ),
			10,
			3
		);

		$res = Emitter::emit( 'KEY', 42, $this->client, $this->lines, 'M07' );

		$this->assertSame( 'failed', $res['status'] );
		$this->assertSame( 'Connection timed out', $res['error'] );
	}

	/**
	 * A non-2xx response with no error body falls back to the HTTP-status message.
	 *
	 * @return void
	 */
	public function test_emit_non_2xx_uses_http_status_fallback(): void {
		add_filter(
			'pre_http_request',
			fn() => [
				'response' => [ 'code' => 500 ],
				'body'     => '{}',
			],
			10,
			3
		);

		$res = Emitter::emit( 'KEY', 42, $this->client, $this->lines, 'M07' );

		$this->assertSame( 'failed', $res['status'] );
		$this->assertSame( 'HTTP 500', $res['error'] );
	}

	/**
	 * A 2xx response whose body is not valid JSON falls back to the "Unknown error" default.
	 *
	 * @return void
	 */
	public function test_emit_malformed_json_body_uses_unknown_error(): void {
		add_filter(
			'pre_http_request',
			fn() => [
				'response' => [ 'code' => 200 ],
				'body'     => 'not-json',
			],
			10,
			3
		);

		$res = Emitter::emit( 'KEY', 42, $this->client, $this->lines, 'M07' );

		$this->assertSame( 'failed', $res['status'] );
		$this->assertSame( 'Unknown error', $res['error'] );
	}
}
