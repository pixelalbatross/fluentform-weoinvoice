<?php

namespace Outstand\WP\FluentForm\Addons\WeoInvoice;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the order-agnostic WeoInvoice payload and POSTs it to emit_invoice.php.
 *
 * No WooCommerce order is created. The WeoInvoice server accepts a pre-digested client +
 * ordercart payload authenticated by the api_key alone. The document type is not sent: the
 * WeoInvoice account decides it. The payload has `currency_code` 0, which WeoInvoice reads as
 * EUR; PaymentTrigger emits EUR payments only.
 */
class Emitter {

	/**
	 * Order-agnostic WeoInvoice emit endpoint.
	 *
	 * @var string
	 */
	public const ENDPOINT = 'https://weoinvoice.com/plugins/woocommerce/emit_invoice.php';

	/**
	 * Builds the JSON body posted to WeoInvoice.
	 *
	 * @param  string              $api_key       WeoInvoice API key.
	 * @param  int                 $submission_id Fluent Forms submission id (used as order_id / dedup ref).
	 * @param  array<string,mixed> $client       Client fields (name, entity, email, nif, address, postcode, city, country).
	 * @param  array<int,array>    $lines         Line items, each with item/quantity/price.
	 * @param  string              $taxreason     PT VAT exemption M-code applied to each line.
	 * @return array<string,mixed>
	 */
	public static function build_payload( string $api_key, int $submission_id, array $client, array $lines, string $taxreason ): array {
		$nif = Nif::normalize( (string) ( $client['nif'] ?? '' ) );
		if ( ! Nif::is_valid( $nif ) ) {
			$nif = '';
		}

		$name = trim( (string) ( $client['name'] ?? '' ) );
		if ( '' === $name ) {
			$name = 'Consumidor Final';
		}

		$country = (string) ( $client['country'] ?? '' );
		if ( '' === $country ) {
			$country = 'PT';
		}

		$client_payload = [
			'name'     => $name,
			'entity'   => (string) ( $client['entity'] ?? '' ),
			'email'    => (string) ( $client['email'] ?? '' ),
			'nif'      => $nif,
			'address'  => (string) ( $client['address'] ?? '' ),
			'postcode' => (string) ( $client['postcode'] ?? '' ),
			'city'     => (string) ( $client['city'] ?? '' ),
			'country'  => $country,
		];

		$ordercart = [];
		foreach ( $lines as $line ) {
			$ordercart[] = [
				'pid'       => '',
				'quantity'  => $line['quantity'] ?? 1,
				'item'      => (string) ( $line['item'] ?? '' ),
				'price'     => (string) ( $line['price'] ?? '0' ),
				'discount'  => '0',
				'type'      => 'P',
				'tax'       => 0,
				'taxreason' => $taxreason,
			];
		}

		return [
			'store_url'      => site_url(),
			'api_key'        => $api_key,
			'order_id'       => $submission_id,
			'client'         => $client_payload,
			'ordercart'      => $ordercart,
			'footer'         => '',
			'payment_method' => '',
			'currency_code'  => 0,
			'exch_rate'      => '',
		];
	}

	/**
	 * Builds the payload and POSTs it to WeoInvoice, returning a typed result.
	 *
	 * @param  string              $api_key       WeoInvoice API key.
	 * @param  int                 $submission_id Fluent Forms submission id.
	 * @param  array<string,mixed> $client       Client fields.
	 * @param  array<int,array>    $lines         Line items.
	 * @param  string              $taxreason     PT VAT exemption M-code.
	 * @return array{status:string,invoice_id?:string,url?:string,error?:string}
	 */
	public static function emit( string $api_key, int $submission_id, array $client, array $lines, string $taxreason ): array {
		$payload = self::build_payload( $api_key, $submission_id, $client, $lines, $taxreason );

		Logger::request( 'POST', self::ENDPOINT, $payload );

		$response = wp_remote_post(
			self::ENDPOINT,
			[
				'timeout' => 30,
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode( $payload ),
			]
		);

		if ( is_wp_error( $response ) ) {
			$error = $response->get_error_message();
			Logger::response( 0, false, [ 'error' => $error ] );

			return [
				'status' => 'failed',
				'error'  => $error,
			];
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			$body = [];
		}

		if ( $status < 200 || $status >= 300 ) {
			$error = (string) ( $body['error'] ?? sprintf( 'HTTP %d', $status ) );
			Logger::response( $status, false, [ 'error' => $error ] );

			return [
				'status' => 'failed',
				'error'  => $error,
			];
		}

		if ( ! empty( $body['success'] ) ) {
			$invoice_id = (string) ( $body['invoice_id'] ?? '' );
			Logger::response( $status, true, [ 'invoice_id' => $invoice_id ] );

			return [
				'status'     => 'issued',
				'invoice_id' => $invoice_id,
				'url'        => (string) ( $body['url'] ?? '' ),
			];
		}

		$error = (string) ( $body['error'] ?? 'Unknown error' );
		Logger::response( $status, false, [ 'error' => $error ] );

		return [
			'status' => 'failed',
			'error'  => $error,
		];
	}
}
