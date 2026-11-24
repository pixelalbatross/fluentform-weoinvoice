<?php

namespace Outstand\WP\FluentForm\Addons\WeoInvoice\Tests\Unit;

use Outstand\WP\FluentForm\Addons\WeoInvoice\Logger;
use ReflectionMethod;
use WP_UnitTestCase;

/**
 * PII redaction in log messages and context.
 *
 * @covers \Outstand\WP\FluentForm\Addons\WeoInvoice\Logger
 */
class LoggerTest extends WP_UnitTestCase {

	/**
	 * Sensitive context keys are redacted; benign keys pass through.
	 *
	 * @return void
	 */
	public function test_sensitive_context_keys_are_redacted(): void {
		$method   = new ReflectionMethod( Logger::class, 'redact_context' );
		$redacted = $method->invoke(
			null,
			[
				'nif'    => '123456789',
				'entity' => 'ACME LDA',
				'city'   => 'Example City',
				'status' => 'issued',
			]
		);
		$this->assertSame( '[REDACTED]', $redacted['nif'] );
		$this->assertSame( '[REDACTED]', $redacted['entity'] );
		$this->assertSame( '[REDACTED]', $redacted['city'] );
		$this->assertSame( 'issued', $redacted['status'] );
	}

	/**
	 * An api_key in a message string is scrubbed.
	 *
	 * @return void
	 */
	public function test_api_key_in_message_is_scrubbed(): void {
		$method   = new ReflectionMethod( Logger::class, 'redact_message' );
		$scrubbed = $method->invoke( null, 'calling with api_key=SECRET123 now' );
		$this->assertStringNotContainsString( 'SECRET123', $scrubbed );
	}
}
