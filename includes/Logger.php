<?php

namespace Outstand\WP\FluentForm\Addons\WeoInvoice;

defined( 'ABSPATH' ) || exit;

/**
 * Static debug logger for Fluent Forms - WeoInvoice.
 *
 * Writes to the PHP error log only when the plugin debug constant
 * (OUTSTAND_FLUENTFORM_WEOINVOICE_DEBUG) is defined and truthy. Redacts
 * sensitive data from both the message string (regex) and the context array
 * (key-based) before writing.
 *
 * Extend per plugin by adding to SENSITIVE_KEYS (context-array keys) and
 * SENSITIVE_PATTERNS (message-string regex). Includes outbound HTTP
 * request/response helpers because this plugin POSTs to the WeoInvoice API.
 */
class Logger {

	/**
	 * Log prefix identifying this plugin's entries.
	 *
	 * @var string
	 */
	private const PREFIX = '[Fluent Forms WeoInvoice]';

	/**
	 * Debug constant gating all output.
	 *
	 * @var string
	 */
	private const DEBUG_CONSTANT = 'OUTSTAND_FLUENTFORM_WEOINVOICE_DEBUG';

	/**
	 * Context-array keys whose values are redacted (case-insensitive substring match).
	 *
	 * @var array<string>
	 */
	private const SENSITIVE_KEYS = [
		'password',
		'token',
		'secret',
		'authorization',
		'auth',
		'credential',
		'api_key',
		'apikey',
		'key',
		'nif',
		'email',
		'name',
		'entity',
		'address',
		'postcode',
		'city',
	];

	/**
	 * Regex patterns scrubbing secrets from the message string.
	 *
	 * @var array<string, string>
	 */
	private const SENSITIVE_PATTERNS = [
		'/(access_token"?\s*[:=]\s*"?)[^"\s,&}]+/i'  => '$1[REDACTED]',
		'/(refresh_token"?\s*[:=]\s*"?)[^"\s,&}]+/i' => '$1[REDACTED]',
		'/(id_token"?\s*[:=]\s*"?)[^"\s,&}]+/i'      => '$1[REDACTED]',
		'/(client_secret"?\s*[:=]\s*"?)[^"\s,&}]+/i' => '$1[REDACTED]',
		'/(password"?\s*[:=]\s*"?)[^"\s,&}]+/i'      => '$1[REDACTED]',
		'/(Bearer\s+)[A-Za-z0-9._\-]+/i'             => '$1[REDACTED]',
		'/(api_key"?\s*[:=]\s*"?)[^"\s,&}]+/i'       => '$1[REDACTED]',
	];

	/**
	 * Check whether logging is enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		return defined( self::DEBUG_CONSTANT ) && (bool) constant( self::DEBUG_CONSTANT );
	}

	/**
	 * Write a debug-level message.
	 *
	 * @param string       $message Log message.
	 * @param array<mixed> $context Optional context data.
	 */
	public static function debug( string $message, array $context = [] ): void {
		self::log( 'DEBUG', $message, $context );
	}

	/**
	 * Write an info-level message.
	 *
	 * @param string       $message Log message.
	 * @param array<mixed> $context Optional context data.
	 */
	public static function info( string $message, array $context = [] ): void {
		self::log( 'INFO', $message, $context );
	}

	/**
	 * Write an error-level message.
	 *
	 * @param string       $message Log message.
	 * @param array<mixed> $context Optional context data.
	 */
	public static function error( string $message, array $context = [] ): void {
		self::log( 'ERROR', $message, $context );
	}

	/**
	 * Log an outbound HTTP request.
	 *
	 * @param string       $method   HTTP method.
	 * @param string       $endpoint Request URL.
	 * @param array<mixed> $params   Request parameters (redacted).
	 */
	public static function request( string $method, string $endpoint, array $params ): void {
		self::log(
			'DEBUG',
			sprintf( '%s %s', strtoupper( $method ), self::redact_url( $endpoint ) ),
			$params
		);
	}

	/**
	 * Log an inbound HTTP response.
	 *
	 * @param int          $status  HTTP status code.
	 * @param bool         $success Whether the request was successful.
	 * @param array<mixed> $context Response context (redacted).
	 */
	public static function response( int $status, bool $success, array $context ): void {
		self::log(
			$success ? 'INFO' : 'ERROR',
			sprintf( 'Response %d (%s)', $status, $success ? 'success' : 'failed' ),
			$context
		);
	}

	/**
	 * Build and write a log entry when enabled.
	 *
	 * @param string       $level   Log level label.
	 * @param string       $message Log message.
	 * @param array<mixed> $context Optional context data.
	 */
	private static function log( string $level, string $message, array $context = [] ): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		$entry = sprintf( '%s[%s] %s', self::PREFIX, $level, self::redact_message( $message ) );

		$safe_context = self::redact_context( $context );
		if ( ! empty( $safe_context ) ) {
			$entry .= ' ' . wp_json_encode( $safe_context, JSON_UNESCAPED_SLASHES );
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( $entry );
	}

	/**
	 * Scrub secrets from a message string.
	 *
	 * @param string $message Raw message.
	 * @return string Redacted message.
	 */
	private static function redact_message( string $message ): string {
		return (string) preg_replace(
			array_keys( self::SENSITIVE_PATTERNS ),
			array_values( self::SENSITIVE_PATTERNS ),
			$message
		);
	}

	/**
	 * Recursively redact sensitive keys from a context array.
	 *
	 * @param mixed $data Data to redact.
	 * @return mixed Redacted data.
	 */
	private static function redact_context( mixed $data ): mixed {
		if ( ! is_array( $data ) ) {
			return $data;
		}

		$redacted = [];
		foreach ( $data as $key => $value ) {
			if ( self::is_sensitive_key( $key ) ) {
				$redacted[ $key ] = '[REDACTED]';
			} elseif ( is_array( $value ) ) {
				$redacted[ $key ] = self::redact_context( $value );
			} else {
				$redacted[ $key ] = $value;
			}
		}

		return $redacted;
	}

	/**
	 * Redact query-string secrets from a URL.
	 *
	 * @param string $url Raw URL.
	 * @return string Redacted URL.
	 */
	private static function redact_url( string $url ): string {
		return self::redact_message( $url );
	}

	/**
	 * Check whether a context key is sensitive.
	 *
	 * @param mixed $key Key to check.
	 * @return bool
	 */
	private static function is_sensitive_key( mixed $key ): bool {
		if ( ! is_string( $key ) ) {
			return false;
		}

		$key_lower = strtolower( $key );
		foreach ( self::SENSITIVE_KEYS as $sensitive ) {
			if ( str_contains( $key_lower, $sensitive ) ) {
				return true;
			}
		}

		return false;
	}
}
